# The Concern's pressure

The valley's antagonist is an economic one — a company with a ledger, not a cult
with a plan — and until now it has been entirely passive. A party can shut the
sluice, steal the survey, free the diggers and expose the Deepworks, and nothing
anywhere notices. This is the design for the thing that notices.

Built. The two decisions below were the ones blocking it and they are settled;
what shipped is at the bottom.

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

---

# What was built

## The engine, which is one class and one verb

`app/lib/ConcernPressure.php` holds the counter's name, the thresholds, and
`raise()`. It is otherwise pure, so `tools/test_pressure.php` can walk the whole
scale a point at a time without a database.

`{"pressure": n}` is the effect content writes. It is its own verb rather than
an `increment_flag` on the counter, and that is the second decision above turned
into something a rule can check: `increment_flag` takes a negative `by`,
`set_flag` takes any value at all and `clear_flag` takes it away entirely, so a
counter reachable through them is a counter that can come down. Content cannot
reach it by any of those routes — `tools/load_content.py` refuses all three by
name, reading the names out of the PHP so that renaming a constant cannot leave
the check quietly agreeing with nothing.

Prices are 1 to 5. Zero is refused because an act that cost the Concern nothing
is written by writing no effect, and the ceiling is not arithmetic — it is a
reminder that no single act is the whole of what the company can be made to
notice.

## Three bands, and why three

| Flag | At | What the Concern has done |
|---|---|---|
| `concern_noticed` | 3 | written the cost down. Nobody has been told to do anything |
| `concern_asking` | 8 | sent somebody polite up from downriver with your description |
| `concern_answering` | 15 | stopped asking. Doors shut, prices move, your name is out |

Three because each band has to be worth writing reactions for and a fourth would
be a band nobody wrote anything into. The totals are expected to move, which is
why content gates on the flags: a condition written against the raw number pins
the tuning into a dialogue file.

The dearest possible playthrough is 43, so the top band is about a third of what
a maximally obstructive party can spend. `tools/test_pressure.php` asserts both
ends of that — that the corpus can still pay for the top band at all, and that
the top band is not so dear that only a completionist crosses it — so re-tuning
a threshold fails there rather than as a band of writing nobody reaches.

## The ledger

Twenty-four quests price an ending, across every tier of the act. The rule for
whether an act has a price is whether it took **money, ground, labour, paper or
secrecy** from the Concern — not whether it was hostile, and not whether the
party noticed anything.

- The town tier is 1s and one 2. Blocking the purchase of Harrow Farm costs
  them a farm; voiding Brenna's note costs them a note. A party that goes out
  of its way is `concern_noticed` before it leaves Rivermark.
- The wilds and the fen are 1s and 2s with one 3 — shutting the sluice, which
  undoes forty years of paying a man to keep four thousand acres under water.
- The works and the landing carry the 3s and both 4s: reading the indentures
  back to the diggers, taking the overseer's office, cutting the face out,
  bringing it down, and the survey.

Selling the survey back is priced at 1 rather than nothing. It cost them a
purse, and they know you had it.

Deliberately not priced: `the_pit_champion`, which reads like a Concern holding
because its wardens reuse the `concern_warden` stat block, and is Vessa Dann's
bond and nobody else's; and `the_fen_wardens`, where closing the fen road may
well be what the Concern wanted.

## The reactions

Eight people and one door, all authored, all gated on the flags.

The band is read by the people who handle the Concern's paper rather than by
anyone who works for it, because at three points nobody works for it yet. Tibald
Ashe reads the same hand every quarter and notices a thirteenth correction that
is not about money. Garrow Finch keeps two books and has always been straight
about which one you are in. Alder Pyke has a line on his docket that is not
about his wages.

At eight, somebody is asking: Mara has let the corner room to a man with a list,
Finch has been taken through the front of his book a name at a time, Coyle has a
sheet that is not a time sheet, and Pyke's `they_are_asking` — written for
exactly this beat and reachable until now only by burning the survey, which is
the last act of the act — has a second and much earlier way in.

At fifteen the cost lands on other people. Mara has put a penny on the ale
because the cart that has come for fourteen years has been changed. Tobin cannot
sell to you and will not risk the four men on the carriage. Pyke's coil-hook is
empty. Ashe is instructed — not refused, instructed — not to draw for you. Petch
is not to log you in, which is not the same as keeping you off the quay and she
knows exactly how much worse it is. Corse has a letter about you that went round
him, and he has checked the total, because he checks totals.

Every one is `once`. The beat is "this has just happened", and `once` is
remembered by a hash of the variant's text rather than by its position, so
inserting these into eight long greeting lists could not disturb what a save had
already seen.

**The door is the Salt House**, the only bed in Greyhythe, gated on
`concern_answering` from both ways in. It is a lodging house rather than a
Concern holding — "nobody asks your business, and everybody hears it" — which is
the point: the reaction is a landlord who has been spoken to, not a barred gate.
Nothing is stranded behind it and no quest targets it.

## What was left alone

**A board posting.** `QuestService::postable()` filters by module, level and
`on_job_board` and has no condition vocabulary, so a quest that appears when the
Concern answers would need a new gate on the quests table. That is a real engine
change for one reaction, and a dialogue node gated on the flag can start a quest
today. The examples in "The shape" are a list of shapes, not a checklist.

**A face in a room that was empty.** An NPC's `place` block takes a location and
nothing else — no conditions — so this one is not authorable either without an
engine change. Worth doing when something else wants it too.

## What the checkers had to be told

Both flag-wiring checks work by reading every `set_flag` in the corpus, so a
flag written in PHP looks exactly like a flag nobody sets and every gate on it
reads as a dead scene. `DialogueLint::ENGINE_FLAGS` unpacks the thresholds from
the constant that declares them, and `tools/load_content.py` greps them out of
the source and lends the list to `tools/trace_content.py`, which used to keep a
second grep of its own — two greps for one fact is how one of them ends up
agreeing with nothing after a rename.

The counter needed one thing more. `concern_pressure` is written by PHP and read
straight back by PHP, and content is told to gate on the thresholds instead, so
"nothing in the corpus reads this" is the design working rather than a flag
somebody forgot to finish — it is exempt from the read-nowhere finding, and the
thresholds are pointedly not, because a band of pressure nobody wrote a reaction
for is exactly what that check is for.
