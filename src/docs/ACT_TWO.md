# Act II — The Quarterly Meeting

> Shipped 2026-11-09. Built on `ACT_TWO_HOOK.md`; read that first. This is what
> exists now, which flags it writes, and what it still deliberately leaves open.

## The road in

`passage_booked` + `act_two_open` (every `the_survey` ending sets the second).
Two ways down, both landing at `brackwater_wharf`:

- Osgar Tull's top greeting variant: **Go aboard** starts `the_quarterly_meeting`
  and travels.
- The packet stair's exit **Board Tull's packet downriver** (same two flags).
  A party that walks down instead gets the quest from Abel Moss at the yard.

The wharf's own exit takes the packet back to the packet stair; Act I stays open.

## Brackwater (`content/locations/brackwater.json`, module `rivermark`)

| Location | Who | Notes |
|---|---|---|
| `brackwater_wharf` | — | arrival hub |
| `the_yard` | Abel Moss | every manifest goes through his window; `the_yard_gate` fight if you insist |
| `house_street` | — | thirty brass plates |
| `number_nine` | Minna Crale; Hollin Sare (only once the meeting sits) | the long room, eleven chairs |
| `record_office` | Dorcas Fenn | field books, a penny a page |
| `the_fortieth` | Jory Kemp (inn, 8 gp); Mr Pellow (burned); Alder Pyke (`pyke_downriver`, warned) | |
| `the_mudside` | — | camp; random `mudside_knives` |

Scenes for all seven are in `assets/js/scenes-data.js` / `assets/images/scenes/`.

## The spine: `the_quarterly_meeting`

`four_days_flat` → (Abel Moss) `the_secretary` → (Minna Crale opens the branch
quest that matches `survey_fate`) `your_matter` → (branch quest completed)
`the_meeting` → item seven with Hollin Sare → one of four endings:

| Stage | resolution | `meeting_fate` | Also |
|---|---|---|---|
| `costed` | `asked_for_the_costing` | `costed` | `clearance_costed`, pressure 3 |
| `minuted` | `entered_an_objection` | `minuted` | |
| `subscribed` | `took_a_share` | `subscribed` | `we_are_subscribers`, −300 gp |
| `walked` | `walked_out` | `walked` | |

Mara, Corse, Hollin Sare and Jory Kemp read the endings.

## The four branches

| `survey_fate` | Quest | Endings → flag |
|---|---|---|
| `took` | `a_record_is_not_the_thing` (Dorcas Fenn) | `field_books_fate` = `compared` · `filed` |
| `burned` | `who_stood_by_the_fire` (Mr Pellow) | `fire_named` = `us` · `hobley` · `none` (Investigation DC 14) |
| `sold` | `services_not_further_described` (Minna Crale) | `retainer_fate` = `serving` · `clause` · `returned` (clears `corse_retains_us`) |
| `warned` | `what_a_town_knows` (Pyke, then Abel Moss) | `pyke_fate` = `hired` · `home` · `gone` |

## What Act II answered, and what it did not

- **Who instructs Corse** — answered as the hook asked: the meeting, eleven
  subscribers (twelve chairs, one lapsed share), reasonable, unassailable.
  "Accepting a figure is not the same thing as deciding."
- **Where a cleared town goes** — *asked*, by the party, if they choose. It is
  costed, which raises the figure; it is not refused.
- **Still open, on purpose:** what the quarry fell on (the meeting does not know
  either and says so; the Record Office holds only Corse's two letters); the
  armour chain; the Deepworks dead; whether the Concern is wrong — no crime is
  discovered anywhere in the act.

## Side quest: `the_fenmoor_lot` (the armour chain)

Answers hook item 4 the way the hook asked: four names and a dead carter, and
no conspiracy that anybody can prove. Offered by Dorcas Fenn to any party with
`mark_seen_plate` (from `nakkas_plate`).

`lot_forty_one` (Record Office: the house → T. Vass, Greyhythe, 31 gp → Brindle
& Daughter, 40 gp) → `brindle_and_daughter` (Hester Brindle, new location
`brindles_chandlery` off the wharf; plate went upriver on spec) →
`the_packman` (Coll Darrow, the mudside; sold at Weir Landing to Wat Fenner for
11 gp; Insight DC 13 sets `fenmoor_asked_for`) → `the_day_book` (Lise Fenner,
the mudside, only once the trail reaches her). Wat's day-book: *one crate left
at the hill store, as asked. Paid.* Investigation DC 15 sets `fenmoor_no_name`:
39 of 40 "as asked" entries name a buyer in the margin; this one does not.

| Stage | `armour_chain_fate` | Also |
|---|---|---|
| `kept_the_book` | `kept` | item `wat_fenners_day_book` |
| `left_the_book` | `left` | |
| `paid_the_burying` | `paid` | −14 gp (needs 14) |

All three set `armour_chain_ended`. Dorcas Fenn, Hester Brindle and Lise Fenner
read the endings back. Still open on purpose: who asked. Nakka was "told to take
it" by somebody who was not a goblin; that was Wat, and nobody knows who asked
Wat. Test: `tools/test_fenmoor_lot.php`.

## After the meeting

- **Companions read `meeting_fate`.** Sera, Kessa, Aldric and Ilse each have a
  once-only greeting for `costed` · `minuted` · `subscribed` · `walked`.
- **The house calls in Kessa's favour.** If `kessa_the_house_that_bought_her`
  ended `took_the_favour` (`house_favour_owed`), Minna Crale asks for it the first
  time she is spoken to after the meeting: witness that forty-one conveyances
  signed by mark were read aloud to the sellers. They were. `kessa_favour_fate`
  = `signed` (clears the favour) · `read_again` (she reads all forty-one aloud
  first; clears it) · `refused` (the blank stays open). Kessa reads all three.
- Test: `tools/test_act_two_aftermath.php`.

## Tests

`tools/test_act_two.php` (in `verify.sh`) plays all four branches to an ending
by choosing replies by their words, and checks the wrong branch's replies are
never offered.
