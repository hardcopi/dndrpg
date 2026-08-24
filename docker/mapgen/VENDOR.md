# Where src/dungeon came from, and what has been changed in it

`src/dungeon/*.ts` is vendored from the TypeScript dungeon generator, taken from
`IXjs2fbx8M0P5c3T-grok-workspace.zip` at `src/lib/dungeon/`.

Copied: `types, rng, generate, caves, wilds, sites, lore`.

Left behind: `render.ts`, `hatch.ts`, `features.ts`, `io.ts`. Those draw to a
canvas or trigger downloads, and the game has its own renderer — `ui-map.js`
plus `DungeonGen`'s geometry. Nothing in the generation path imports them, which
is what makes leaving them out safe rather than lucky.

## The rule

**Local edits are a last resort.** Anything the game wants that the generator
does not do belongs in the adapter — `src/app/lib/GeneratedLevel.php` — or in
`DungeonGen::finish()`, which is the pass that stocks and dresses a floor
whichever generator drew it. The point of a sidecar is that upstream code stays
upstream code and a newer version can be dropped in wholesale.

## Local edits, which a re-vendor will lose

Keep this list exact. Each entry needs porting back into the original workspace,
or reapplying after a re-vendor.

### lore.ts — one twist rewritten (2026-08-21)

    - "The occupants will not follow you in daylight. They will in town."
    + "The occupants will not follow you into daylight. They will follow you home."

Two occurrences, in `twists` and in the `above`-level quest arch.

Two reasons. It was the only twist of the eight that stumbled: the ellipsis
("They will *follow you* in town") elides a verb three words back and across a
sentence boundary, so it reads as a truncation rather than as the clipped style
the rest of the file uses well. And there is no town — the delve now hangs off
the Proving Yard in free play, so "home" is both stronger and true wherever the
stair happens to be.
