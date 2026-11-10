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
- **The quarry writ is heard of** (`quarry_writ_fate`). Each line plays once.
  - `filed`: Gest Pyle and Dorn Ackley hear of it. In Act II, Ashe is asked twice
    this quarter when the east quarry stopped being the town's, and for once has
    the answer written down. During the costing, Hesper copies the date into the
    margin, as it falls under no head.
  - `stone`: drovers argue at the fourth face. Ackley approves. Alder Pyke hands
    in a sheet with both readings "and a line between". Hesper writes both, with
    a query.
  - `sold`: the polite man looked at the stone and at Pyle, and had asked
    Ackley first.
  - Nobody says who holds the writ.
- **The priory, the reliquary and the arch are heard of.** Each line plays once.
  - **Priory:** when the party walked Arden and asked nothing
    (`priory_fate` = `unentered`), the prior noticed. Aldric is grateful it was
    not you who asked about the ford.
  - **Reliquary:** Aldric speaks to `sold` ("eating its own memory a shelf at a
    time") and to `paper`. In Act II a held cartulary can be deposited with
    Dorcas Fenn, entered as Arden's and still the priory's to ask back
    (`cartulary_deposited`). Venn then has "the first good letter in four
    winters".
  - **Sealed arch:** for `left`, Aggie asks whether the two counts are kept dry,
    "now there are two".
  - **Other readers:** Sera speaks to `sold_to_the_polite_man` ("it's the second
    time that tells you what you are"). `mearing_recut` sends Pyke's
    two-reading sheet to the Record Office, filed under Disputed.
  - Nothing identifies the polite man, the buyer, or the first person to write
    about the wall.
- **The circulated figure goes up the valley.** Reed gets a twelfth copy "for
  information" and reads it in the watch house. Hesper is written to by one
  chair: is a sixth head usual (whole), or were five heads all there was
  (given)? If the figure is to be read first, she would like to hear it read
  uninterrupted.
- **Quarter-day eve** (Mara, the Flagon). This needs the meeting to be over and
  at least one thing set up: a vote, a minute, an item-seven choice, a
  circulated or held figure, the Uncounted read or entered, or the cartulary
  deposited. Mara sits the party down on the night before the last packet that makes the quarter-day (four days down). Three beats then pick the first match:
  the outcome (the AGAINST — 1 / OF 12 / SO FAR slate, Reed in the good coat,
  the reader-list under the door copy, a cup raised to the pencil, Reed's
  twelfth copy open at the bread, Hesper's shut book like a coffin in the next
  room); then Tull on the Uncounted; then Arden, the filed date, or the stone
  argument. It ends with "whatever they decide has not been decided yet" and
  sets `quarter_eve`. The vote is not resolved.
- **Later visitors** (after `quarter_eve`, once each). A clerk who gave no
  house reads only the Arden bounds and asks whether a deposit can be withdrawn
  (it can't). The prior is asked to recall her press and "will consider it". A
  Ford Road widow reads the Disputed sheet and takes a copy home.
- **Valley loose ends heard of.** Reed: the causeway under at four points
  (`fen_road_closing`), the north ford left unposted (`north_ford_open`).
  Ballow: eleven wagons to the warren (`warren_fed`), wagons over the ford dry
  to the axle (`ford_road_open`). Osric: the camp's first mark on paper
  (`fen_trade_on_paper`), the beds drying (`fen_draining`), root by the
  causeway "without a story" (`fen_road_open_paid`). Ashe: the common water
  right cannot be bought from one man (`mill_race_right_common`).
- **Companions on the eve.** Once-only asides, matched to what was set up:
  - Sera and Kessa on the AGAINST slate; Sera on a vote kept.
  - Ilse and Sera on Reed going down with the proxy.
  - Aldric on the door copy, the shelved minute, the reasons letter, the
    Uncounted entered ("a parish") and the Arden lay brother.
  - Kessa on the pencil and on a withdrawn item.
  - Aldric and Sera on the circulated figure; Sera on the read-first figure.
  - Ilse on the Uncounted read and on the stone argument.
  - Each companion has one line at the stair.
- **The packet at first light** (Tull, after `quarter_eve`). The last packet
  that makes the quarter-day loads: Reed with the strongbox and proxy, the
  lodged vote already carried, Hesper going down to hear it read, the reasons
  letter riding on top, or the door copy pinned to the mast. Tull casts off
  ("whatever they say down there, they have not said it yet") and sets
  `packet_gone`. Companions have asides. A party keeping its vote for the day
  doesn't get this scene, so the scene never decides whether they go.
- **More of the valley is heard of.** The drover sleeps at the mill shelter
  (`mill_is_shelter`). Ballow reads the Flagon muster list every morning
  (`rivermark_mustering`). Gray's people come to Osric by the back way
  (`stilt_camp_taken`). Reed: "Nobody's ever taken that camp by walking at it"
  (`stilt_camp_lost`). `pool_dead_named` is skipped, as it would point at
  where the dead lie. `sluice_wage_intercepted` is skipped, as it would point
  at who pays.
- **The four days, and the plank.** After `packet_gone` (and not `vote_aboard`) the town waits once each without resolving anything: Mara faces east, Ashe rules a page with no heading, Pyke chains what he already knows, Pyle is asked for news a boat will bring, Osric sells chamomile, Brenna sells no hinges, Ballow watches the west road (or minds the watch house when Reed took the proxy). Reed is off the square while she is downriver with the proxy unless one of her quests is still open; Hesper is off Granary Row if she went down to read the figure first. A kept vote is walked to `the_gangplank` by Tull and stops there: *Step aboard* (`vote_aboard`, travel to Brackwater, Moss and Minna keep the chair and ask nothing) or *Step back* (vote uncast; Reed and Sera remark). Neither sets or casts the vote. Readers added for `mill_pit_cleared`, `root_beds_cleared`, `ford_record` (generic for all three endings), `commons_question_raised`, `pit_closed`, `broken_floor_shored`.
- **The room sat.** Each waiting line marks `days_waited`; after it, Tull brings the packet back round the second bend with one sheet in Minna's hand: *The house sat on the quarter-day at ten o'clock... The minute will follow.* No outcome is reported anywhere. Choosing either reply sets `room_sat`; Mara, Ashe (who finally heads his page *The house sat.*), Pyke, Ballow and Aldric answer once. Reed (proxy) and Hesper (read first) come home with the packet: Reed cast it as given and will not say what the room did; Hesper read and the minute has whether they heard. Not shown to a party that went down aboard. More readers: `drowned_nave_clear` and `black_pools_cleared` (Cully; no word on what floats), `pump_gallery_cleared` (Dale), `pit_gate_beaten` (Vessa), `oldcity_goblins_broken` (Bram Ockley), `fen_water_rising` (Tull Barrow), `rivermark_valued` (Ashe). Skipped `hills_raiders_beaten` (points at the granary), `reliquary_agents_beaten`, `salt_wagons_taken`, `tarrow_bravos_beaten`, `writ_changed_hands`, `long_room_*`.
- **Between the sheet and the minute.** With `room_sat` (and not `vote_aboard`) the town lives with not knowing, once each: Tull says the minute is being fair-copied and is not on this boat; Dorcas clears an empty *Quarter-day* shelf every morning; Brenna made the first hinge since the packet went; Osric sells for headaches from arguing; Mrs Coyle says a sheet does not move the pegs; Aggie Slate fines two men for arguing over 'in its order' without saying who was right; Sera, Kessa and Ilse wait in their own ways. The minute is never delivered. Readers for the safe `*_known` flags: `fen_road_known` (Pyle counts carts west), `grazing_bought_known` (Pyke was never asked to chain Winter Field; no buyer named), `warren_numbers_known` (Pyke), `mill_race_right_known` (Wick), `ford_huts_known` (Mara will not talk about that winter), `warren_nests_known_to_watch` (Ballow). Skipped `priory_refusal_known` (who gave the instruction) and `sluice_wage_known` (who pays).
- **People remember you.** Twenty-one people whose fallback greeting was a first meeting now have a return greeting on their `met_*` flag, with the same replies (Ackley's bench, Coyle's peg number, Cully's four inches, Petch's crane, Pyke's shilling, Tull's board, and so on). A stranger still gets the first meeting. `met_pyle` is left (his fallback already greets a returner) and `met_fessick` is held. What Corse told you travels: Pyke on *the narrow part* and on a name he never chained for (`knows_corse_name`, until you have met Corse), Pyle on *most of the valley*, Dale on *lawful*, Mara on *a head in a column*, Kessa on the question he declined, Aldric on *for*. `corse_doubts_the_dig` is left unread (it is about who instructs the dig).
- **In its order.** After `room_sat`, Corrin Dale asks for the sheet: nobody in the canteen has seen it and nobody there could read it (`in_its_order`, giver Dale). Ashe copies it in his best hand and advises reading it as written. At the canteen sixty men stop eating, and the player chooses: read it word for word (`order_fate` read; Rourke hears "taken in its order" said down the shaft), tell them what you think it means (guessed; pressure +1, Sera disapproves, Rourke hears your voice quoted, and nothing in the text says what the guess was), or tell them the minute is not here yet (not_yet; Dale keeps the copy with his indenture). Coyle writes *a reading* in the day book. The minute is never delivered, and no outcome is named. The town also gains once lines after the sheet from Corse (he got the same four lines on the same boat and will not guess), Petch (the crane has not stopped) and Pyle (now they ask him if the minute has come).
- **The edges hear the sheet.** Once each after `room_sat` (not aboard): Nesh Gray (news crosses the fen last; the causeway wants mending whatever they wrote; not if the camp was turned), Cully (took a copper to pole the sheet out to the stilt camp), Joss Frayle (carters say it at the crossing like the river was up, and still stop at the stone), Prioress Hale (read in chapter like everything through the gate; the minute will be read the same way), Venn with a deposited cartulary (a shelf does not know what a room did), Dale after the Uncounted was read (the canteen thinks of the fifteen and the sheet at the same time). **The Uncounted, entered late:** with `uncounted_fate` kept, Dorcas offers once to enter the count with today's date, *Received after the sitting of the quarter-day* (`uncounted_entered_late`, pressure +1; the fate stays kept). Coyle notes the date is what they will look at. Nothing says what the room did.
- **The pit, the farm, the warren; walkers and minuters after the sheet.** Once each after `room_sat` (not aboard): Vessa will not open a book on the minute (a thing already ended where nobody can see is not a bet); Orm says nobody on the rim bet on the sheet; Hesk tells carters the road has not moved; Sten Harrow keeps the lamp lit though no letter has come; Nakka tells her scouts the copied sheet is the opposite of a weapon (pit lines skip a broken pit; Hesk skips a paid ground; Harrow skips a gone farm; Nakka skips a taken hall). Walked out: `stands` - Mara says item seven is in that order or it isn't, the sheet doesn't say; `withdrawn` - Mara watched you read the sheet like everyone else; `reasons` - Tull hands over Minna's slip, *Correspondence under item seven: received, and entered* (`item_seven_receipt`; Kessa reads it). Minuted: `copied` - Ballow pinned the sheet beside your copy and folk read last quarter's minute like a forecast; `shelved` - Dorcas has had three readers of the old minute this week. Nothing says what the room did.
- **Brackwater, the night before.** A party that boarded at the gangplank (`vote_aboard`, no `room_sat`) gets the town before the room: Jory Kemp, Hester Brindle, Coll Darrow and Dorcas Fenn each have one line, and Hollin Sare is in the empty long room checking carafes, where he says your chair is the fourth on the left and won't ask you anything tonight. Sera, Aldric, Kessa and Ilse each have a once-only line. Nobody asks how you'll vote, and nothing reaches the room. After the sheet (`room_sat`, not aboard), Wick, Dorn Ackley, a drover and Tull Barrow each have one line. Wick's is skipped if he's in town, and Tull's if the drove went out.
- **The town's own errands after the sheet.** Rivermark quests with no `room_sat` reader now have one once-only line each, gated on their fate. Dagen Wyke has one for each of fed, recorded and work. Nan Vetch has one for stopped and one for turned. Tobin has one for kept, and none if his stall is gone. Nell Quist has one for carter and one for stone. Nan's traced, Tobin's watch and refused, and Nell's silence get no line, because each points at who pays. The Waerhaven quests are a separate module and don't hear of the sheet.
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
