# The Concern's pressure

The valley's antagonist is an economic one — a company with a ledger, not a cult
with a plan — and until now it has been entirely passive. A party can shut the
sluice, steal the survey, free the diggers and expose the Deepworks, and nothing
anywhere notices. This is the design for the thing that notices.

Not written yet. This file is the two decisions that were blocking it, so the
implementation does not have to re-argue them.

## One number, valley-wide

Pressure is a single party-scoped value, not one per holding.

The Concern is one organisation reading one set of books. A per-holding split
would ask the player to model an invisible piece of bookkeeping — that the mill
is annoyed with them but the quarry is not — and the moment two holdings react
differently to the same act, the fiction stops being about a company and starts
being about a scoreboard. One number is also the honest reading of what the
content already says: every holding reports to the same office in Greyhythe.

It lives where every other party fact lives, in `world_flags`, so nothing new
has to be stored, scoped or cleaned up.

## It never comes down

Pressure rises and never falls.

A decaying counter would mean the correct play is to interfere and then wait,
which turns an antagonist into a chore and rewards doing nothing. More to the
point it would be wrong about this antagonist: a debt is not forgotten, it is
called in, and attention from an organisation like this is not something you
outlast.

This also matches the one number the engine already writes for content to read
— `DelveEngine::DEPTH_FLAG`, which `markDepth()` raises and never lowers, for
the same reason: a fact the player earned should not be revoked by inactivity.

## The shape, when it is built

- Raised by the acts that actually cost the Concern something, through the
  `effects_json` on a quest stage — not by an engine that decides what hurt.
  Content states the price; the engine only adds up.
- Named thresholds, each setting a flag. Reactions are authored against those
  flags exactly as every other conditional line is: a dialogue variant, a new
  board posting, a locked door, a face in a room that was empty.
- Nothing about it is generated. The reactions are written, so they keep the
  prose voice — which is the thing most easily lost the moment consequences are
  produced by code rather than authored.

The engine's whole contribution is one rising number. What that number is worth
is content's business, which is the rule the Undervault's depth flag already
established and the reason it survived contact with the rest of the game.
