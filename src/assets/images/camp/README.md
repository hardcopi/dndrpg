# Camp scenes

Generated separately (Grok or similar) and dropped in here. **Everything in this
directory is optional** — a companion with no camp art falls back to their
portrait in the camp screen, and the conversation simply has no scene behind it.
Nothing checks whether a file exists; the `<img>` swaps or removes itself on
error, so adding art is a matter of copying a file in.

## Spec

| | |
|---|---|
| Aspect ratio | **16:9** |
| Size | **1536 × 864** (1280 × 720 acceptable) |
| Format | PNG |

## Files

| Filename | What |
|---|---|
| `fire.png` | the empty camp — fire, bedrolls, packs, nobody in frame |
| `aldric.png` | Brother Aldric, seated at the fire |
| `ilse.png` | Ilse Thornwood, seated at the fire |
| `kessa.png` | Kessa Dunmar, seated at the fire |
| `sera.png` | Sera Vance, seated at the fire |
| `<key>_warm.png` | optional high-approval variant, unguarded |

The name is the **`companion_key`** from `content/companions/*.json`, not the
sprite key — `kessa.png`, not `npc_kessa.png`.

## Direction

Night camp in a wooded river valley. A low campfire is the only light: warm
orange key from below and to one side, deep blue-black beyond. Painterly
fantasy realism, weathered and grounded — no glow effects, no lens flare.
Character seated on the ground or a low log, three-quarter view, upper body and
lap in frame.

These will not match the character busts, which are 3D renders. That is
accepted: they are used in a different place and at a different size. They must
match **each other**.

Reference portraits for likeness are in `../npcs/<sprite_key>_bust.png`.
