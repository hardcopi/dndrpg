# Act II — handoff note

> Written 2026-08-01 alongside the Act I spine. This is a handoff, not a second
> bible. It records what Act I hands over, what each finale resolution means,
> and what was deliberately left unanswered so that Act II can answer it.

---

## What Act I actually established

The player was never told the following. They were shown it five times and given
one document at the end.

- A mercantile house downriver — **the Corse Concern** — has spent three years
  buying the valley: grain forward, debt paper, quarry writs, water rights, and
  most of the ground. Not conquest. Purchase, at market, lawfully.
- Its factor is **Ludo Corse**, at Greyhythe. He answers every question honestly
  and has never once lied in the player's hearing. He does not know what is
  under the quarry either, has said on paper twice that the dig does not pay,
  and has been instructed to continue.
- The tell is a surveyor's mark, a plumb-bob inside a square. It is genuinely
  just a house mark. That is why it is everywhere.
- Beneath the goblin warren, the **Deepworks**: two hundred indentured people,
  three shifts, a canteen, a timekeeper, six faces cut at a costed rate toward
  one point under the collapsed quarry. The goblins were never the antagonist —
  they are the first casualty, pushed up onto the road by the excavation.
- **The Survey** — nine sheets in an unlocked rack in the overseer's office.
  Rivermark is on sheet six, entered in the margin under the head *clearance*,
  as a cost, with a figure revised downward twice.

Act I never says the word "Concern" until `the_survey`. Act II may say it freely.

---

## The four resolutions of `the_survey`

`survey_fate` carries the branch. Every resolution also sets `act_two_open`.

### `took_the_survey` — `survey_fate = "took"`

The party holds the document and Corse knows it. Nobody stopped them and nobody
will. Corse's own line is the thesis: a record is not the thing recorded, and
the field books still exist.

- Act II opens with the party carrying evidence that no court in the valley has
  jurisdiction over and no buyer they trust.
- The obvious move — take it downriver to whoever is above Corse — is the road
  Act II is built on, and it is a trap: the meeting is eleven subscribers who
  already know.
- Corse remains reachable, cordial, and useful. He was never the obstacle.

### `burned_the_survey` — `survey_fate = "burned"`

Three years and four hundred and eleven days of chain-work gone. The Concern
starts again immediately; Alder Pyke takes the work, and says so to the party's
face before they can be surprised by it.

- **The Concern is now looking for who did it.** Corse's own words in the
  aftermath scene are a schedule, not a threat: he will be asked for a name, will
  truthfully say he does not know, will not be believed, and a man who is not a
  factor will come up the river.
- Act II's pressure in this branch is a manhunt conducted entirely through
  paperwork, wages and questions asked of friends.
- Rivermark gains eighteen months. Nobody in Rivermark knows it gained anything.

### `sold_it_back` — `survey_fate = "sold"`, `corse_retains_us = 1`

Five hundred gold and articles of retainer at forty a quarter for services not
further described. The articles were already written — not for the party, for
whoever came.

- Act II opens with the party **on the Concern's books**, holding a retainer
  they did not quite mean to accept, with a first quarter already lodged.
- There is no clause about ending it, because nobody has ever asked for one.
  Corse is explicit that the trap is not the paper: it is that they took it and
  he knows.
- The interesting Act II question in this branch is not escape. It is what the
  Concern eventually asks for, and how long the party spends being extremely
  well paid to do nothing first.
- Companion cost is heaviest here (`aldric` −20, `ilse` −18). Aldric and Ilse
  should be at or near walking distance by the act break.

### `warned_the_town` — `survey_fate = "warned"`, `rivermark_warned = 1`

Alder Pyke read it out on the granary steps in the rain, because he can read a
survey and the party cannot, and because half of it was his chain. Reed wrote it
down. Forty-one people listened.

- **Rivermark knows, and now knows it can do nothing.** No law is broken; there
  is nobody to arrest.
- Corse's reaction is the sting: a town that knows what it is worth sells sooner
  and argues less, and he has written to say so, because it would be dishonest
  to let anyone think the party cost the house anything.
- What was actually taken off him — he says so himself — is the only thing in
  nineteen years he could not put back.
- Pyke is out of work. That is the price, it is paid by somebody else, and it is
  not reversible.

---

## Flags that carry forward

### The branch

| Flag | Values |
|---|---|
| `survey_fate` | `took` · `burned` · `sold` · `warned` |
| `act_two_open` | set by all four |
| `corse_retains_us` | `sold_it_back` only |
| `rivermark_warned` | `warned_the_town` only |

### The spine's own trail

| Flag | Meaning |
|---|---|
| `grain_stamp_seen` | mark on the Flagon cellar grain (pre-existing, reused) |
| `mark_seen_paper` | mark on Finch's assignment of Kessa's debt |
| `mark_seen_book` | mark in Marrow's second book |
| `mark_seen_plate` | mark punched inside Nakka's plate / the raiders' gear |
| `mark_seen_stone` | mark chiselled on the ledge below the deep shaft |
| `same_mark_fate` | `counting` · `let_alone` |
| `paper_town_fate` | `followed` · `lie` |
| `knows_corse_name` | the name exists to the party |
| `polite_man_fate` | `asked` · `nothing` |
| `met_corse` | |

### The Deepworks

| Flag | Meaning |
|---|---|
| `deepworks_known` | the party has seen the works |
| `quarry_fell_fate` | `walked` · `back` |
| `dig_fate` | `signed` · `unasked` · `never` — whether the party is on Coyle's board |
| `counted_in` / `coyle_noted_us` | which of Coyle's two sheets they are on |
| `indentured_fate` | `bought` · `read` · `left` |
| `diggers_told` | the reckoning was read aloud in the canteen |
| `office_fate` | `talked` · `took` |
| `works_alarmed` | Rourke was put down; the works know |
| `undercut_fate` | `cut` · `down` · `left` |
| `saw_what_it_is` | the party opened the face |

### Greyhythe

| Flag | Meaning |
|---|---|
| `landing_fate` | `counted` · `left` |
| `factors_house_fate` | `asked` · `out` |
| `long_room_open` | the party has read the Concern's records |
| `worth_fate` | `found` · `looked_away` |
| `rivermark_valued` | the party has seen the figure |
| `passage_fate` | `booked` · `waiting` |
| `passage_booked` | the boat downriver is paid for |

`passage_booked` is the literal road into Act II and is deliberately cheap
(sixty gold) and independent of everything else. A party that finished nothing
can still get on the boat.

---

## Deliberately left unanswered

Do not resolve these by accident.

1. **What the quarry fell on.** `the_growth` is the last thing the player sees
   and it explains nothing. Whether it is one of a kind, whether it was put
   there, and whether it is what the Concern was actually looking for are all
   open. Note that the Concern does not know either — that is established on
   the page by Corse and by Rourke independently, and it should stay true.
2. **Who instructs Corse.** He describes "eleven people in three towns, one of
   whom I have met" and a quarterly meeting he is not invited to. He has written
   twice asking what the works are for and been answered with a revised figure.
   The meeting is Act II's antagonist, and it should turn out to be exactly as
   banal and exactly as unassailable as he says.
3. **Where a cleared town goes.** Corse: *"That would be under a different head,
   and it has not been costed, because nobody has asked for it yet."* Somebody
   asks in Act II.
4. **The armour.** `mark_seen_plate` traces to a bankrupt armourer's stock sold
   on at Greyhythe four years ago, and Corse will give the party the first name
   in that chain honestly. It goes four or five deep and ends with a dead
   carter. Whether somebody deliberately armed the warren is unresolved and
   should probably stay a coincidence that looks like a conspiracy — the act's
   whole argument is that a system does not need a conspiracy.
5. **Two hundred and some men up the river and none coming back down.** Osgar
   Tull works this out in front of the party and asks not to be made to do it
   again. Nobody has counted the Deepworks dead.
6. **Whether the Concern is wrong.** Every purchase was lawful, every price was
   paid, every wage was paid on the day. Act II should not solve this by
   discovering a crime.

---

## Threads Act II inherits from other agents' Act I work

Flagged rather than owned, because these belong to other authors:

- `kessa_the_house_that_bought_her` (bible §3) — Kessa's debt paper carries
  `mark_seen_paper`. Garrow Finch collects for the Concern and says himself he
  has never asked whose mark it is. The tier-2 arc is already loaded.
- `sera_what_she_is_for` — Sera's reactions throughout the Deepworks are written
  as recognition, not horror (*"I could have written this"*). Corse offering her
  better work than the party can is set up and unfired.
- `aldric_the_true_record` — Aldric takes his hat off when Pyke reads sheet six.
  That is the only time he does it outside a graveside.
- Ilse's two-year warden's tally is now explained: the goblins were being pushed
  up by the Deepworks, and Rourke says so plainly, with the number, and says he
  is not proud of it and had it counted.

---

## Companion dialogue hooks left for the companion author

The spine sets its flags from **quest stages**, never from a companion's
dialogue file, so nothing here has been edited. Each of these is a place where a
companion greeting could now react, and none of them exist yet:

| Companion | Could read | Line that is missing |
|---|---|---|
| `ilse` | `mark_seen_stone`, `deepworks_known`, `quarry_fell_fate` | The cuts she found on the ledge were survey marks, and she has now stood in the thing that made them. This is the answer to her personal quest and she has not commented on it. |
| `kessa` | `mark_seen_paper` | Her debt was assigned twice and the second assignment is a mark, not a name. Finch told her that was usual. |
| `sera` | `mark_seen_book` | The stamp in the corner of every page of the second book that was not in her hand. She asked once and was told to get on. |
| `aldric` | `survey_fate`, `indentured_fate` | He is the only companion whose approval swings hard on `warned_the_town` (+18) and `sold_it_back` (−20). |
| `nakka` (NPC, not companion) | `mark_seen_plate` | The mark is punched inside her left pauldron. She has no idea it is there; nobody has ever taken the plate off her to look. |
