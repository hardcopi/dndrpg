# The one webfont

`--font-display` was a system stack — `"Palatino Linotype", "Book Antiqua",
Palatino, Georgia, serif` — and Palatino ships on Windows and macOS and nowhere
else. So the game's *voice* type, which is everything somebody says and every
heading that names a place (`style.css:38-42`), was a different face depending
on who was reading. On Linux and Android it fell through to a generic serif.

This is the one thing in the interface that is not drawn from `tokens.css`
values, and it is here for that reason alone.

## What is here

| File | What |
|---|---|
| `alegreya-var-latin.woff2` | Alegreya, variable, `wght` 400–900, latin subset |
| `alegreya-var-latin-italic.woff2` | the same, italic |
| `OFL.txt` | the licence, which has to travel with the font |

**Alegreya**, by Huerta Tipográfica — a warm old-style serif, SIL Open Font
License 1.1. Fetched as the Fontsource `latin` subset of the upstream variable
font:

```
https://cdn.jsdelivr.net/fontsource/fonts/alegreya:vf@latest/latin-wght-normal.woff2
https://cdn.jsdelivr.net/fontsource/fonts/alegreya:vf@latest/latin-wght-italic.woff2
```

## Three things about the shape of this

- **Variable, not five static cuts.** `--font-display` is asked for at four
  weights — 400 on `.dlg-line` and `.hero p`, `--fw-medium` on the chart's
  labels, `--fw-semibold` on `h1,h2,h3` / `.quest-title` / `.module-name`, and
  `--fw-bold` on `.wm-pc-initial` — plus italic on `.dlg-aside`, `.map-info` and
  `.wm-out-label`. Five static files is ~140 KB; one variable axis plus its
  italic is 88 KB and hits every weight exactly rather than synthesising.

- **The `latin` subset is enough, and that was measured rather than assumed.**
  The whole of `content/**/*.json` contains exactly one non-ASCII character,
  U+2014 EM DASH, 371 times. The subset carries it, along with the curly quotes
  and the accented Latin the interface strings use.

- **The subset has no licence string in its `name` table**, which is why
  `OFL.txt` is a file here rather than something to look up. Deleting it makes
  the fonts undistributable.

## What is deliberately NOT here

`--font-ui` and `--mono` stay as system stacks. Interface text is the game
talking about itself; the serif is opt-in and only for voice, which is the rule
`style.css:33-37` already states.

The three fonts in Synty's Fantasy Menus pack (`~/Downloads/SourceFiles/Fonts/`)
are all OFL and were the obvious candidates, and none of them survived being
looked at:

- **Ortica Bold** is the only serif of the three and draws U+201C/U+2019 as
  *straight* marks. `.dlg-line` and `.dlg-name` are where `--font-display` does
  most of its work and both are full of curly quotes.
- **LT Museum Bold** is Bold-only, and `--font-display` covers running prose —
  every line of dialogue would have rendered heavy. It is also a condensed
  near-grotesque, which is a change to the game's voice rather than a fix to it.
- **Alegreya Sans Medium** is a single 500 cut against the 400/500/600/700 the
  interface asks for, so three of the four would have been synthesised — worse
  than the system stack it would have replaced.

The last of those is why the serif here is *Alegreya*: same foundry, same
skeleton, published as a full family.
