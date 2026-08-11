#!/usr/bin/env python3
"""
Every custom property in the stylesheets, and whether both ends of it exist.

WHY THIS EXISTS

tokens.css opens with a rule: nothing outside it holds a raw colour, size,
radius, shadow, duration or z-index. Nothing enforced that, and both ways of
breaking it were live in the file at once.

A PHANTOM is `var(--text-soft, var(--text-dim))` — a token referenced, never
declared, quietly running on the fallback written at the callsite. CSS does
not warn: it resolves the fallback and renders something reasonable, so the
rule looks like it reads a token and does not. Six of these had accumulated,
and two of them were the SAME name with two different fallbacks in two rules,
which is the failure this catches best — the value had already forked and
nothing said so.

An ORPHAN is the mirror image: a token declared and read by nothing. Harmless
to render and not harmless to trust. `--portrait` sat at 38px while the party
rail hard-coded 42px in two places; a token nothing reads cannot disagree with
anything, which is exactly why it was free to be wrong.

WHAT IT CANNOT KNOW, AND HOW IT IS TOLD

Custom properties have three other legitimate homes, and a checker that only
reads tokens.css calls all of them phantoms:

  - generated stylesheets — assets/css/dice.css is written by gen_dice_css.py
    and declares --die-box and the d6 rest/tumble angles;
  - JavaScript, via setProperty — dice3d.js sets --die-throw per throw,
    ui-battlemap.js sets --dx/--dy per cell;
  - the stylesheet itself, scoped to a rule rather than :root — style.css
    declares --pd-cell and --pd-zoom on the paperdoll and --orn-w on the
    ornament rule, and their descendants read them. These are correct CSS and
    deliberately NOT tokens: they are local to one component.

So all three are searched. What is left over is a real finding.

    tools/token_check.py            report; exit 1 if anything is unresolved
    tools/token_check.py --orphans  also list declared-and-unread tokens

Orphans are off by default because a design system is allowed to name a value
before the first rule uses it — the type sub-scales are in that position. They
are worth reading occasionally, not worth failing a build over.

Reads the tree and writes nothing.
"""

import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.dirname(HERE)

TOKENS = os.path.join(SRC, 'assets/css/tokens.css')
STYLE = os.path.join(SRC, 'assets/css/style.css')
CSS_DIR = os.path.join(SRC, 'assets/css')
JS_DIR = os.path.join(SRC, 'assets/js')

DECL = re.compile(r'(?<![\w-])(--[\w-]+)\s*:')
USE = re.compile(r'var\(\s*(--[\w-]+)\s*(,)?')
# Two ways JavaScript sets one. `el.style.setProperty('--die-throw', …)` is
# the obvious one; the other is a custom property written into an inline
# `style="…"` inside a template literal, which is how ui-battlemap.js gives
# every cell its --dx/--dy. The second pattern is loose on purpose — a `--x:`
# anywhere in a .js file counts as evidence the script sets it — because the
# cost of missing one is a false alarm on every run, and the cost of a false
# accounting is a phantom going unreported until somebody reads the rule.
JS_SET = re.compile(r"""setProperty\(\s*['"](--[\w-]+)['"]""")
JS_INLINE = re.compile(r'(--[\w-]+)\s*:')


def read(path):
    with open(path, encoding='utf-8') as fh:
        return fh.read()


def strip_comments(css):
    """Blank out /* */ so prose cannot look like a declaration.

    Replaced with spaces rather than removed, so byte offsets still line up
    with the original if a caller wants them.
    """
    return re.sub(r'/\*.*?\*/', lambda m: ' ' * (m.end() - m.start()), css, flags=re.S)


def scan():
    tokens_css = strip_comments(read(TOKENS))
    style_css = strip_comments(read(STYLE))

    declared_tokens = set(DECL.findall(tokens_css))

    # Declared anywhere else: a generated stylesheet, or scoped to a rule
    # inside style.css itself.
    elsewhere = {}
    for name in os.listdir(CSS_DIR):
        if not name.endswith('.css') or name == 'tokens.css':
            continue
        for tok in set(DECL.findall(strip_comments(read(os.path.join(CSS_DIR, name))))):
            elsewhere.setdefault(tok, name)
    for tok in set(DECL.findall(style_css)):
        elsewhere.setdefault(tok, 'style.css (scoped to a rule)')

    # Set at runtime.
    runtime = {}
    if os.path.isdir(JS_DIR):
        for name in sorted(os.listdir(JS_DIR)):
            if not name.endswith('.js'):
                continue
            js = read(os.path.join(JS_DIR, name))
            for tok in set(JS_SET.findall(js)):
                runtime.setdefault(tok, name)
            for tok in set(JS_INLINE.findall(js)):
                runtime.setdefault(tok, name + ' (inline style)')

    # Usage, across every SHIPPED stylesheet — not style.css alone. The first
    # draft read only style.css and tokens.css, and reported as unread a dozen
    # tokens that sheet-print.css and adventure-print.css use 148 times
    # between them. An orphan list that is wrong in that direction is worse
    # than none: it invites deleting a token the print sheets depend on.
    #
    # tools/ is deliberately NOT counted. system_preview.html reads nearly
    # every token in the file because its job is to DRAW the scale, so
    # counting it would empty the orphan list by construction. A token whose
    # only reader is the catalogue of tokens is still a token nothing uses.
    used = {}
    for name in sorted(os.listdir(CSS_DIR)):
        if not name.endswith('.css'):
            continue
        css = strip_comments(read(os.path.join(CSS_DIR, name)))
        for m in USE.finditer(css):
            rec = used.setdefault(m.group(1), {'n': 0, 'fallback': False, 'where': set()})
            rec['n'] += 1
            rec['fallback'] |= bool(m.group(2))
            rec['where'].add(name)

    phantoms = {
        k: v for k, v in used.items()
        if k not in declared_tokens and k not in elsewhere and k not in runtime
    }
    orphans = sorted(k for k in declared_tokens if k not in used)
    return declared_tokens, used, phantoms, orphans, elsewhere, runtime


def main(argv):
    show_orphans = '--orphans' in argv
    declared, used, phantoms, orphans, elsewhere, runtime = scan()

    print(f'tokens.css declares {len(declared)}; the stylesheets reference {len(used)}.')

    accounted = sorted(
        (k, elsewhere.get(k) or runtime.get(k))
        for k in used if k not in declared and (k in elsewhere or k in runtime)
    )
    if accounted:
        print(f'\n{len(accounted)} referenced from outside tokens.css, and accounted for:')
        for tok, where in accounted:
            print(f'  {tok:16} {where}')

    if phantoms:
        print(f'\n{len(phantoms)} PHANTOM(S) — referenced, declared nowhere:')
        for tok, rec in sorted(phantoms.items()):
            how = 'running on a fallback' if rec['fallback'] \
                else 'NO fallback: this resolves to nothing'
            print(f'  {tok:16} x{rec["n"]:<3} {how}')
    else:
        print('\nNo phantoms: every var() reference resolves to a declaration.')

    if show_orphans:
        print(f'\n{len(orphans)} declared and read by nothing:')
        for tok in orphans:
            print(f'  {tok}')
    elif orphans:
        print(f'\n({len(orphans)} declared and read by nothing — --orphans to list them.)')

    return 1 if phantoms else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
