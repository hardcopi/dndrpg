#!/usr/bin/env python3
"""Cross-reference checker for the PHP sources.

There is no build step here and no type checker, so a call to a method that was
renamed — or never existed — is invisible until a player walks into the code
path that makes it. That is a bad way to find out, because the paths that break
first are the rare ones: a companion's romance scene, a quest's third ending.

This resolves every `Class::member` and `$this->member` reference in app/lib,
api and tools against the declarations actually present, and reports the ones
that go nowhere. It is not a parser and it is not a substitute for running the
code; it catches the specific mistake that costs the most to find by hand.

    python3 tools/lint_php.py            # report and exit non-zero on errors
    python3 tools/lint_php.py --quiet    # errors only

Deliberately stdlib-only and dependency-free, like the other tools here.
"""

from __future__ import annotations

import glob
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Members PHP itself provides, or that come from a class we do not own. A
# reference to one of these is not a broken call.
BUILTIN_CLASSES = {
    "PDO", "PDOStatement", "Throwable", "Exception", "RuntimeException",
    "InvalidArgumentException", "LogicException", "JsonException",
    "DateTime", "DateTimeImmutable", "ArrayObject", "Closure", "Generator",
    "self", "static", "parent",
}


def strip_php(src: str) -> str:
    """Blank out strings, comments and heredocs, keeping offsets sane enough.

    Written by hand rather than with a regex because a `//` inside a string and
    a `'` inside a comment each break the naive version, and both appear in
    these files — SQL fragments are full of quotes and the dialogue JSON is
    full of slashes.
    """
    out: list[str] = []
    i, n, state = 0, len(src), None
    while i < n:
        ch = src[i]
        if state is None:
            if src.startswith("//", i) or ch == "#":
                j = src.find("\n", i)
                i = n if j < 0 else j
                continue
            if src.startswith("/*", i):
                j = src.find("*/", i + 2)
                i = n if j < 0 else j + 2
                continue
            if src.startswith("<<<", i):
                m = re.match(r"<<<'?\"?(\w+)'?\"?\r?\n", src[i:])
                if m:
                    tag = m.group(1)
                    end = re.search(r"\n\s*" + tag + r"\b", src[i:])
                    i = n if not end else i + end.end()
                    continue
            if ch in "'\"":
                state = ch
                i += 1
                continue
            out.append(ch)
            i += 1
        else:
            if ch == "\\":
                i += 2
                continue
            if ch == state:
                state = None
            i += 1
    return "".join(out)


def declarations(path: str, src: str) -> dict:
    """What one file declares: its class, and that class's members.

    Anchored to the start of a line because strip_php removes PHP comments but
    not HTML ones, and an unanchored `\\bclass\\s+(\\w+)` happily matched the
    words "class default" in a prose comment in create.php — inventing a
    seventeenth class called `default` whose members were every function in the
    file. It reported no errors, so it cost nothing except a confusing count that
    changed when the comment was reworded.
    """
    cls = re.search(r"^\s*(?:final\s+|abstract\s+)?class\s+(\w+)", src, re.M)
    if not cls:
        return {}
    members: dict[str, dict] = {}
    for m in re.finditer(
        r"\b(?:(public|protected|private)\s+)?(?:(static)\s+)?"
        r"(?:(public|protected|private)\s+)?(?:(static)\s+)?"
        r"function\s+(\w+)\s*\(",
        src,
    ):
        vis = m.group(1) or m.group(3) or "public"
        members[m.group(5)] = {
            "kind": "method",
            "visibility": vis,
            "static": bool(m.group(2) or m.group(4)),
        }
    for m in re.finditer(
        r"\b(?:(public|protected|private)\s+)?const\s+(\w+)\s*=", src
    ):
        members[m.group(2)] = {
            "kind": "const",
            "visibility": m.group(1) or "public",
            "static": True,
        }
    for m in re.finditer(
        r"\b(public|protected|private)\s+(?:static\s+)?(?:\??\w+\s+)?\$(\w+)", src
    ):
        members.setdefault(
            m.group(2),
            {"kind": "property", "visibility": m.group(1), "static": False},
        )
    return {"class": cls.group(1), "members": members, "path": path}


def main() -> int:
    quiet = "--quiet" in sys.argv
    files = []
    for pattern in ("app/**/*.php", "api/**/*.php", "tools/*.php", "*.php"):
        files += glob.glob(os.path.join(ROOT, pattern), recursive=True)
    files = sorted(set(files))

    classes: dict[str, dict] = {}
    stripped: dict[str, str] = {}
    for path in files:
        src = open(path, encoding="utf-8").read()
        s = strip_php(src)
        stripped[path] = s
        d = declarations(path, s)
        if d:
            classes[d["class"]] = d

    errors: list[str] = []
    warnings: list[str] = []

    for path, s in stripped.items():
        rel = os.path.relpath(path, ROOT)
        own = next((c for c in classes.values() if c["path"] == path), None)
        inside_class = own["class"] if own else None

        for m in re.finditer(r"\b(\w+)::(\w+)", s):
            owner, member = m.group(1), m.group(2)
            if owner in BUILTIN_CLASSES:
                continue
            # `Foo::class` is a magic constant — the fully-qualified name of the
            # class — and not a member of it, so no declaration will ever match.
            # Reflection-based tests are written with it and were being reported
            # as three errors apiece.
            if member == "class":
                continue
            line = s[: m.start()].count("\n") + 1
            if owner not in classes:
                warnings.append(f"{rel}:{line}: unknown class {owner}")
                continue
            decl = classes[owner]["members"].get(member)
            if decl is None:
                errors.append(f"{rel}:{line}: {owner}::{member} is not declared")
                continue
            # Calling a private member of another class is a fatal at runtime,
            # which is exactly the sort of thing that only shows up on the one
            # branch nobody plays.
            if decl["visibility"] != "public" and owner != inside_class:
                errors.append(
                    f"{rel}:{line}: {owner}::{member} is {decl['visibility']}"
                )

        if inside_class:
            for m in re.finditer(r"\$this->(\w+)\s*\(", s):
                member = m.group(1)
                if member not in classes[inside_class]["members"]:
                    line = s[: m.start()].count("\n") + 1
                    errors.append(
                        f"{rel}:{line}: $this->{member}() is not declared "
                        f"on {inside_class}"
                    )

    if not quiet:
        print(f"checked {len(files)} files, {len(classes)} classes")
        for w in warnings:
            print(f"  warn  {w}")
    for e in errors:
        print(f"  ERROR {e}")
    print(f"{len(errors)} errors, {len(warnings)} warnings")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
