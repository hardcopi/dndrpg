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
  armour chain; the Deepworks dead (now counted, if the party chooses, but not where they lie); whether the Concern is wrong — no crime is
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

**The crate lid** (the thread moves on and stays open). Searching the warren
stores turns up `fenmoor_crate_lid`: planed pine, *GREYHYTHE. LOT 41* stencilled
inside, and Abe Treen's chalk, *1. as asked. pd.* Once `armour_chain_ended` is
set, Dorcas Fenn (who pencils *lid seen* in the margin of the return: "the chain
is whole… it has a hole in the middle where a name would go"), Hester Brindle,
Lise Treen (who knows Abe's hooked one), Coll Darrow, Nakka ("he said he was
paid not to wonder") and Ilse each react once. The wood proves the chain end to
end and names nobody.

## After the meeting

- **Companions read `meeting_fate`.** Sera, Kessa, Aldric and Ilse each have a
  once-only greeting for `costed` · `minuted` · `subscribed` · `walked`.
- **The house calls in Kessa's favour.** If `kessa_the_house_that_bought_her`
  ended `took_the_favour` (`house_favour_owed`), Minna Crale asks for it the first
  time she is spoken to after the meeting: witness that forty-one conveyances
  signed by mark were read aloud to the sellers. They were. `kessa_favour_fate`
  = `signed` (clears the favour) · `read_again` (she reads all forty-one aloud
  first; clears it) · `refused` (the blank stays open). Kessa reads all three.
- **Rivermark hears.** Captain Reed has a once-only `briefing` line per
  `meeting_fate`: a letter saying purchases on Granary Row are *held* for the
  quarter (`costed`), nothing at all (`minuted`), cold suspicion of a party now on
  the house's books (`subscribed`), and dry approval (`walked`). Held, not
  stopped: the Row is not saved.
- **The rest of Rivermark hears.** Four more once-only greetings per
  `meeting_fate`, after any quest that has them busy and before the Concern
  pressure lines:
  - **Tibald Ashe** copied the held letter (`costed`) and finds nothing to file
    (`minuted`). For `subscribed` he gets the subscriber list "for information",
    and for `walked` one line, "left before the close of business", in the hand
    he has never met.
  - **Hollis Marrow** knows what held does to a price on his own street. He
    shakes a subscriber's hand. He is skipped while in the lockup (`quarry_fate`
    = `pact`).
  - **Garrow Finch:** a held debt still grows; objections keep; he buys shares
    at a discount; he writes a party that walked out a line lower in the front
    of his book.
  - **Mott Wenn** carried the sealed letter up the stair and counts who climbed
    it after. The boatmen carry the rest.
  - **Granary Row itself**, as three more once-only greetings each. None of them
    shows while that NPC's own quest is open.
    - **Dagen Wyke**, almoner to the granary families, moves nine packing
      families into pencil (`costed`).
    - **Pol Reddick** counts a quarter as thirteen weeks of weighing.
    - **Nell Quist** gives a seed cake to anybody who argues anyway (`minuted`),
      and charges subscribers full price.
  - **The rest of the town**, with the same rules:
    - **Brenna Coalwright** would like to be asked about her own forge.
    - **Serjeant Ballow** has watched families leave by the west gate all year.
    - **Tobin** waits for the second letter. He is skipped once the town has
      fallen and he is at the milestone.
    - **Sib Marrell** says the Row drains the same whoever owns it.
    - **Nan Vetch** saw the word coming up the river and guessed it wrong.
    - **Alder Pyke** is asked to measure the Row for leaving with the same chain
      (`costed`), and would take a rise from a subscriber but would not ask. He is
      skipped once the house is answering (`concern_answering`), because by then
      his chain is gone.
    - **Osric Fen** points out that nobody costed a year's remedies, and asks a
      subscriber to write down that the fen-root beds do not move.
    - **A market farmer** whose bread was sold forward, **the minstrel** who
      cannot find the next verse, and **the east gate guard** told to write down
      anybody who comes to count roofs.
    - **Everyone else on the streets** gets one line per outcome: the market miner
      (costed by the yard all his life; nothing about the quarry, whose ending
      varies), the drover, the scholar, the stranger, the beggar, the jester, the
      sweeper, and the three watch posts (relief, square, street).
    - **Corbin Waite** hears it from the boatmen, but only if he took the work at
      the Flagon (`room_fate` = `work`). Asleep at the table, or gone, he hears
      nothing.
  - None of them knows who asked for the Fenmoor crate. That thread stays open.
- **Brackwater and Greyhythe hear.** One once-only line per `meeting_fate`,
  after any quest lines and before each NPC's ordinary greeting (for Minna Crale,
  after the favour and Sera lines and before "It's in the minutes"):
  - **Minna Crale**: the costing is commissioned and will be honest; she read the
    objection back twice more; a subscriber's card (the widow's chair by the
    window); "Left the room during item seven."
  - **Abel Moss** will see the costing's paper through his window.
  - **Dorcas Fenn** keeps a shelf space for the bound minute, and owners still pay
    a penny a page.
  - **Osgar Tull** will carry the costing up, and has never had an owner aboard.
  - **Gillian Petch** at the Greyhythe landing logs a subscriber in.
- **Jory Kemp** has a line for each outcome. His ordinary post-meeting greeting now
  reads "By the second pint they'd all heard what, and no two of them the same"
  instead of "Nobody heard what", so it no longer contradicts them.
- **The valley roads hear** (Ford Road and the quarry wilds), one line per
  outcome: Joss Frayle, Sten Harrow (not once `harrow_gone`), Tull Barrow, Wick
  Lorrimer (not while `wick_in_town`), Dorn Ackley, Gest Pyle, Hesk Dray (not
  after `salt_fate` = `paid`, when his wagons have gone), Orm Lisser and Vessa
  Dann. Tarrow is in the Waerhaven module and does not hear of a Rivermark
  meeting.
- **The Deepworks hears.** The works can still be reached after Act II (the
  broken-floor stair has no Act II gate), and the house's word comes down with
  the carriers. Rourke (not if his office was taken, the works are alarmed, or
  `office_fate` = `took`), Wenna Coyle (not while the works are alarmed) and
  Corrin Dale (not once he was bought out) each have a line per outcome.
  Fessick in the undercut hears nothing.
- **The Costing** (`the_costing`, Granary Row). A costed meeting (`clearance_costed`)
  sends **Hesper Amsel**, assessor to the meeting, up the valley with Hollin
  Sare's five heads: carts, roofs, wages, a year's bread, the burying ground. She
  counts what is under each head and nothing else.
  - **Some things aren't under any head**: the party walks her round Osric's
    remedies, Dagen Wyke's nine families, Pol's floor and the fen-root beds. She
    writes a sixth head, *Not otherwise provided for* (`costing_fate` = `whole`,
    pressure 2).
  - **Count it your way**: five heads, nine days, every line true
    (`costing_fate` = `given`).
  - **Put down double**: she refuses, because a figure she did not count would
    void the whole costing (`costing_padding_asked`, Aldric and Sera −4,
    Kessa +2). She mentions it once afterwards.
  - Osric, Dagen Wyke and Minna Crale read `costing_fate`. The costing goes before
    the meeting at the next quarter-day; what the meeting decides is left open.
- **One Vote in Twelve** (`subscribed`). Minna Crale gives notice of the next
  quarter-day: item four, Rivermark, sheet six, clearance, as revised. The chair
  may lodge its vote early (`vote_fate` = `against`), keep it for the day
  (`kept`), or give a written proxy to Captain Reed (`proxy`). Reed takes the
  proxy without saying how she will cast it, and Mara reacts to the other two.
  How the meeting votes is left for the quarter-day.
- **The Bound Minute** (`minuted`). The quarter's minutes come down bound to
  Dorcas Fenn's Record Office. Item seven reads: the objection word for word,
  "The meeting noted the objection. No resolution was moved." The party can copy
  it up the valley, where it is pinned on the Flagon door (`minute_fate` =
  `copied`), or leave it on the shelf, where Abel Moss reads it on his dinner hour
  (`shelved`).
- **Item Seven, Adjourned** (`walked`). A letter from Minna Crale waits behind
  Mara's till: item seven stands adjourned unless withdrawn. The party can
  withdraw it, say why they walked out (to be read to the meeting as
  correspondence), or let it stand in pencil on the next agenda (`walked_fate` =
  `withdrawn` / `reasons` / `stands`). Minna reads all three back.
- **Companions on the three outcomes.** One once-only line each: Sera on
  `vote_fate` (against, kept, proxy) and on a sixth head (`costing_fate` =
  `whole`); Aldric on `minute_fate` (copied, shelved) and on `walked_fate` =
  `reasons`; Kessa on `walked_fate` (withdrawn, stands, reasons) and on Reed's
  proxy; Ilse on Reed's proxy.
- **The house hears the costing and the vote.** Concern pressure: a vote lodged
  against clearance +1, a proxy to Reed +2, the minute copied to the Flagon door
  +1 (the sixth head was already +2). Keeping the vote and shelving the minute
  cost nothing. Corse has a once-only line for each `costing_fate` (given, whole)
  and `vote_fate`. He costs what Mrs Amsel counts, and sends the figure
  without a recommendation. How the quarter-day vote goes stays open.
- **The Uncounted** (`the_uncounted`, hook item 5). After the meeting, if the
  party has seen the Deepworks and the works are not alarmed, Osgar Tull has
  copied two springs out of his book: 223 names, as given on the step. Wenna
  Coyle lays it against her board. 204 are on it, 7 of her 211 came by the
  warren side, and 19 are struck: 4 walked up the stair (2 of those since dead,
  "not my column") and 15 are incidents (roof 6, water 4, fever 3, stair 2).
  Each was paid to the day and "carried out". Where they were carried is
  past the top tread and stays open, and no crime is found. Endings:
  `read_out` in the canteen (pressure +1), `entered` at the Record Office by
  Dorcas Fenn as a record, not a finding (pressure +1), or `kept`. Coyle,
  Tull, Dorcas, Corrin Dale, Ilse, Aldric, Sera and Kessa react once. Fessick
  in the undercut still hears nothing.
- **The house answers the Uncounted.** Only if the count was read or entered
  (`uncounted_fate`), and only once pressure has crossed a tier:
  - `concern_asking`: a man from the house reads the Record Office entry and
    asks about a fee for a correction, and Finch is asked about the canteen
    reading.
  - `concern_answering`: Coyle's incident form gains a line, *Place of burial*,
    "from now on", with nothing said about the fifteen. Dorcas is asked to close
    the entry and refuses (a record given is open; only the informant may amend
    it). Corse's letter calls the count "unhelpful", not wrong.
  - A kept count draws none of this.
- **Corse's figure reaches Minna.** After `the_costing`, Minna has Corse's
  costed figure, with no recommendation. She leaves the secretary's discretion to
  the party: circulate it to the eleven chairs now (`costing_figure` =
  `circulated`, pressure +1, three chairs write to Corse), or read it first on
  the day (`read_first`). Sera and Aldric disagree. The vote stays open.
- **Companions place the mark.** Once Act II is open, Ilse (`mark_seen_stone`),
  Kessa (`mark_seen_paper`) and Sera (`mark_seen_book`) each have one greeting
  that recognises the mark they saw in Act I as the house's mark: the ledge cuts,
  the wax heading the canteen's against-wages column, the survey's habits. Aldric
  has one line per `indentured_fate`. They recognise the mark and go no further.
- **Sera's second offer.** If Sera turned Corse down (`sera_post_fate` =
  `stayed`) and is in the party, Minna Crale offers her the empty second desk at
  Number Nine (sixty a year, rooms on House Street, read anything filed).
  "That's Sera's to answer": at approval 40+ she declines (+6); below it she takes
  the desk and leaves the party (`sera_second_offer` = `declined` / `took`;
  Dorcas Fenn then mentions her downstairs). "She isn't for hire" answers for
  her (`spoken_for`, -10). Sera reads `declined` and `spoken_for`.
- **Sera's eight lines.** If Sera kept the retainer (`retained`), Minna Crale
  shows the party her year of monthly reports, read and initialled by four people.
- Test: `tools/test_act_two_aftermath.php`.

## Tests

`tools/test_act_two.php` (in `verify.sh`) plays all four branches to an ending
by choosing replies by their words, and checks the wrong branch's replies are
never offered.

> Naming note: the dead carter is **Abe Treen** (widow **Lise Treen**). The
> first cut called him Wat Fenner, which is the flood-gate keeper's name in
> Act I; the internal keys `lise_fenner` and `wat_fenners_day_book` were kept
> so live saves holding the book are untouched. Dialogue text renders plain:
> no `*emphasis*` markup.
