#!/usr/bin/env python3
"""Cross-reference checker for the browser client.

There is no build step, no bundler and no type checker here, so a typo in an
API route or in a global's name is invisible until somebody walks into it — and
the paths that break first are the rare ones: a parley, a companion's
interjection, the third ending of a quest.

Four passes, all cheap:

  1. `node --check` on every JS file, which is the only real parser available.
  2. Every route the client asks for, resolved against the actions
     `api/index.php` actually handles. A route is a string literal of the form
     `resource/action`, optionally with `&key=` query tail, so it can be read
     straight out of the source without evaluating anything.
  3. Every `window.X` global the client reads, resolved against the globals the
     client assigns. This is what catches a screen renamed in one file and
     still called by its old name in another.
  4. Comment lines stranded inside template literals. `node --check` is perfectly
     happy with these — they are valid string content — and the result is a `//`
     and a paragraph of reasoning rendered into the page. It happened on the
     character sheet: a note about how the modal slot works was added just above
     the closing backtick and shipped as visible text in the panel.

Deliberately stdlib-only and dependency-free, like tools/lint_php.py, and it
reports rather than rewrites.

    python3 tools/lint_js.py            # report and exit non-zero on errors
    python3 tools/lint_js.py --quiet    # errors only
"""

from __future__ import annotations

import glob
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
JS_DIR = os.path.join(ROOT, "assets", "js")
API_FILE = os.path.join(ROOT, "api", "index.php")

# The page templates, which set globals in inline <script> blocks before the
# bundles load -- create.php injects ART_VER that way, for instance. Without
# these the globals check reports a PHP-supplied global as never assigned.
#
# Globbed rather than listed. It was a four-name tuple, and a tuple of
# filenames is a list somebody has to remember to add to: characters.php was
# written with its own inline script calling two API routes and this walked
# straight past it. A page that is not scanned is not "clean", it is unchecked,
# and the two look identical from here.
PAGE_FILES = tuple(sorted(
    os.path.basename(p) for p in glob.glob(os.path.join(ROOT, "*.php"))
))

# Globals the browser supplies, or that come from somewhere this cannot see.
BROWSER_GLOBALS = {
    "location", "innerWidth", "innerHeight", "matchMedia", "addEventListener",
    "removeEventListener", "scrollTo", "getComputedStyle", "requestAnimationFrame",
    "setTimeout", "clearTimeout", "setInterval", "clearInterval", "fetch",
    "document", "console", "Image", "Map", "Set", "JSON", "Math",
    "devicePixelRatio", "screen",
    # Reached through `window.` on purpose rather than bare, so that the
    # try/catch around them reads as deliberate: both throw rather than
    # returning null when storage is disabled, which is the case the party
    # rail's approval arrows are wrapped against.
    "localStorage", "sessionStorage",
    # `CSS.escape` is reached as `window.CSS && CSS.escape` because it is absent
    # in older engines and the graph would rather fall back than throw.
    "CSS",
}


def js_files() -> list[str]:
    return sorted(
        os.path.join(JS_DIR, name)
        for name in os.listdir(JS_DIR)
        if name.endswith(".js")
    )


def api_routes() -> set[str]:
    """Every `resource/action` api/index.php answers.

    Read from the front controller's own shape: the top-level switch names the
    resources and each handler compares `$action` against string literals. That
    is a pattern rather than a parse, but it is the pattern the file is written
    in, and a handler that stopped following it would be worth noticing anyway.
    """
    src = open(API_FILE, encoding="utf-8").read()
    routes: set[str] = set()

    # `case 'map': handle_map($action);` ties a resource to its handler.
    resources = {}
    for m in re.finditer(r"case\s+'(\w+)':\s*\n\s*handle_(\w+)\(", src):
        resources[m.group(2)] = m.group(1)

    for handler, resource in resources.items():
        m = re.search(rf"function\s+handle_{handler}\s*\(", src)
        if not m:
            continue
        # The handler runs to the next top-level `function ` declaration.
        nxt = re.search(r"\nfunction\s+\w+\s*\(", src[m.end():])
        body = src[m.end(): m.end() + (nxt.start() if nxt else len(src))]
        for a in re.finditer(r"\$action\s*===?\s*'([\w.]+)'", body):
            routes.add(f"{resource}/{a.group(1)}")
        # `$action === 'current' || $action === 'load'` is already covered, and
        # so are the in_array forms, if any appear later.
        for a in re.finditer(r"in_array\(\$action,\s*\[([^\]]+)\]", body):
            for lit in re.findall(r"'([\w.]+)'", a.group(1)):
                routes.add(f"{resource}/{lit}")

    return routes


def stranded_comments(src: str) -> list[tuple[int, str]]:
    """
    Lines that look like code comments but are inside a template literal.

    Walks the source tracking which kind of quote it is inside, because there is
    no way to tell `//` the operator-free comment from `//` the two characters of
    a URL without knowing that. Template literals nest: `${...}` drops back into
    real code, where a comment is legitimate again, so the depth is tracked rather
    than a boolean.

    Only whole lines whose first non-space characters are `//` or `/*` are
    reported. A `//` mid-line inside a template is almost always a URL, and
    flagging those would make the check noise.
    """
    findings: list[tuple[int, str]] = []
    # A stack of contexts: 'code' or 'template'. Entering ${} pushes 'code'.
    stack = ['code']
    i = 0
    n = len(src)
    line = 1
    at_line_start = True

    while i < n:
        ch = src[i]

        if ch == '\n':
            line += 1
            at_line_start = True
            i += 1
            continue

        if stack[-1] == 'code':
            # Skip over things that can contain quote-like characters.
            if src.startswith('//', i):
                j = src.find('\n', i)
                i = n if j < 0 else j
                continue
            if src.startswith('/*', i):
                j = src.find('*/', i + 2)
                end = n if j < 0 else j + 2
                line += src.count('\n', i, end)
                i = end
                continue
            if ch in '"\'':
                i += 1
                while i < n and src[i] != ch:
                    if src[i] == '\\':
                        i += 1
                    elif src[i] == '\n':
                        break          # unterminated; let node --check complain
                    i += 1
                i += 1
                continue
            if ch == '`':
                stack.append('template')
                i += 1
                at_line_start = False
                continue
            if ch == '}' and len(stack) > 1:
                stack.pop()            # leaving a ${...}
                i += 1
                at_line_start = False
                continue
            if not ch.isspace():
                at_line_start = False
            i += 1
            continue

        # Inside a template literal.
        if ch == '\\':
            i += 2
            at_line_start = False
            continue
        if src.startswith('${', i):
            stack.append('code')
            i += 2
            at_line_start = False
            continue
        if ch == '`':
            stack.pop()
            i += 1
            at_line_start = False
            continue
        if at_line_start and (src.startswith('//', i) or src.startswith('/*', i)):
            end = src.find('\n', i)
            text = src[i:(n if end < 0 else end)].strip()
            findings.append((line, text[:72]))
            at_line_start = False
            i += 1
            continue
        if not ch.isspace():
            at_line_start = False
        i += 1

    return findings


# A route literal: `resource/action`, both lowercase words. Deliberately
# narrow — it must not match a path like `assets/images` or a CSS class.
ROUTE_LITERAL = re.compile(r"'([a-z][a-z_]*/[a-z][a-z_]*)'")

# Resource groups the API used to have.
#
# A string is treated as a route when its first segment is either a resource
# the API currently answers or one of these. Without the second half, a call
# to a group that has been DELETED — the exact thing this check is for — is
# filtered out as "not route-shaped" and reported as fine. `map/*` became
# `location/*` and `editor/*` went with the tile editor; both left callers
# behind.
RETIRED_PREFIXES = {"map", "editor"}


def requested_routes(src: str, known: set[str]) -> list[tuple[int, str]]:
    """
    Routes the client asks for, with the line each was found on.

    Matches route-shaped strings ANYWHERE, not only the ones sitting directly
    inside an `API.get(...)` call. The narrow version missed

        const rest = (route) => async () => { await API.post(route, {}); };
        el.onclick = rest('map/camp');

    which is how the camp and inn buttons went on calling a route group that
    had been deleted, through a linter reporting no errors, until somebody
    pressed Camp and got "Unknown API resource". A helper taking the route as
    an argument is ordinary code; a linter that only understands the literal
    spelling is the thing at fault.

    The cost of matching more broadly is that a non-route string of the same
    shape would be reported, so only strings whose first segment names a
    resource — live or retired — are considered.
    """
    prefixes = {r.split("/")[0] for r in known} | RETIRED_PREFIXES
    out = []
    for m in ROUTE_LITERAL.finditer(src):
        route = m.group(1).split("&")[0].strip()
        if route.split("/")[0] not in prefixes:
            continue
        out.append((src[: m.start()].count("\n") + 1, route))
    return out


def phantom_fields(files: list[str]):
    """
    Snake_case properties the client reads that nothing anywhere produces.

    The gap this fills: the journal printed a quest's location with
    `q.target_map_name`, and the server has always called that column
    `target_location_name`. The expression is valid JavaScript, the guard in
    front of it is falsy every time, and the line simply never rendered — for
    however long it had been there. No pass above could see it, because it is
    neither a route nor a global nor a syntax error.

    Deliberately crude, and deliberately a warning. A name is suspect only if
    it is snake_case (so it looks like it came from SQL rather than from the
    client's own camelCase), it is read as a property somewhere in the client,
    and it appears nowhere in the PHP in any form — not as a column, an alias,
    an array key or a JSON key — and is never assigned in the JS either. That
    triple miss is hard to arrive at by accident and easy to arrive at by
    renaming one end of a contract.
    """
    # The schema counts as much as the PHP does: most routes hand back rows
    # from `SELECT *`, so a perfectly real column name never appears literally
    # in any PHP file. Leaving sql/ out reported `primary_ability` and
    # `saving_throws` — two columns that have always existed — as phantoms.
    blob = []
    for sub, exts in (("app", (".php",)), ("api", (".php",)), ("sql", (".sql",))):
        base = os.path.join(ROOT, sub)
        for dirpath, _dirs, names in os.walk(base):
            for n in names:
                if n.endswith(exts):
                    blob.append(
                        open(os.path.join(dirpath, n), encoding="utf-8",
                             errors="replace").read()
                    )
    php_names = set(re.findall(r"[a-z][a-z0-9]*(?:_[a-z0-9]+)+", "\n".join(blob)))

    # Anything the client itself builds under that name is not a phantom.
    js_assigned: set[str] = set()
    sources = {}
    for path in files:
        src = open(path, encoding="utf-8").read()
        sources[path] = src
        js_assigned |= set(re.findall(r"\b([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\s*:", src))
        js_assigned |= set(re.findall(r"\.([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\s*=[^=]", src))
        js_assigned |= set(re.findall(r"\[['\"]([a-z][a-z0-9]*(?:_[a-z0-9]+)+)['\"]\]\s*=", src))

    out = []
    for path, src in sources.items():
        rel = os.path.relpath(path, ROOT)
        for m in re.finditer(r"\.([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b", src):
            name = m.group(1)
            if name in php_names or name in js_assigned:
                continue
            out.append((rel, src[: m.start()].count("\n") + 1, name))
    return out


def main() -> int:
    quiet = "--quiet" in sys.argv
    files = js_files()
    errors: list[str] = []
    warnings: list[str] = []

    # --- 1. syntax ---------------------------------------------------------
    for path in files:
        proc = subprocess.run(
            ["node", "--check", path], capture_output=True, text=True
        )
        if proc.returncode != 0:
            first = (proc.stderr or proc.stdout).strip().splitlines()
            errors.append(
                f"{os.path.relpath(path, ROOT)}: syntax: "
                + (first[0] if first else "node --check failed")
            )

    # --- 2. routes ---------------------------------------------------------
    #
    # Over the pages as well as the bundles. A page's inline <script> calls the
    # API exactly like a bundle does -- index.php asks for session/modules,
    # characters.php for session/list -- and a route renamed underneath one of
    # those was invisible here, which is the drift this whole tool exists to
    # catch. The pages are not parsed for syntax (they are PHP, node cannot
    # read them), but a route name is a string literal and reads out fine.
    known = api_routes()
    route_files = files + [
        os.path.join(ROOT, name) for name in PAGE_FILES
        if os.path.isfile(os.path.join(ROOT, name))
    ]
    for path in route_files:
        rel = os.path.relpath(path, ROOT)
        src = open(path, encoding="utf-8").read()
        for line, route in requested_routes(src, known):
            if route not in known:
                errors.append(f"{rel}:{line}: no such API route: {route}")

    # --- 3. globals --------------------------------------------------------
    assigned: set[str] = set()
    for path in files:
        src = open(path, encoding="utf-8").read()
        for m in re.finditer(r"window\.(\w+)\s*=", src):
            assigned.add(m.group(1))
    # api.js declares its global with a bare const, which is still a global in
    # a plain <script>.
    for path in files:
        src = open(path, encoding="utf-8").read()
        for m in re.finditer(r"^const\s+(\w+)\s*=", src, re.M):
            assigned.add(m.group(1))
    # Globals the page templates inject before the bundles load.
    for name in PAGE_FILES:
        path = os.path.join(ROOT, name)
        if not os.path.isfile(path):
            continue
        src = open(path, encoding="utf-8").read()
        for m in re.finditer(r"window\.(\w+)\s*=", src):
            assigned.add(m.group(1))

    for path in files:
        rel = os.path.relpath(path, ROOT)
        src = open(path, encoding="utf-8").read()
        for m in re.finditer(r"window\.(\w+)", src):
            name = m.group(1)
            if name in assigned or name in BROWSER_GLOBALS:
                continue
            line = src[: m.start()].count("\n") + 1
            warnings.append(f"{rel}:{line}: window.{name} is never assigned")

    # --- 4. comments stranded inside template literals ---------------------
    for path in files:
        rel = os.path.relpath(path, ROOT)
        src = open(path, encoding="utf-8").read()
        for line, text in stranded_comments(src):
            errors.append(
                f"{rel}:{line}: comment inside a template literal — this renders "
                f"as visible text: {text}"
            )

    # --- 5. server fields the client reads that the server never sends -----
    for rel, line, name in phantom_fields(files):
        warnings.append(
            f"{rel}:{line}: .{name} is never produced by the PHP and never set "
            f"in JS — a field that is always undefined"
        )

    if not quiet:
        print(f"checked {len(files)} bundles and {len(route_files)} files "
          f"against {len(known)} API routes")
        for w in warnings:
            print(f"  warn  {w}")
    for e in errors:
        print(f"  ERROR {e}")
    print(f"{len(errors)} errors, {len(warnings)} warnings")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
