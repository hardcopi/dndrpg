<?php
/**
 * One module, gathered into the shape a published adventure is written in.
 *
 * The database is organised for play — a location knows its exits, an NPC knows
 * where it stands, a quest knows its stages — and a book is organised for
 * reading: front matter, a chapter per region, a numbered entry per place, then
 * the cast, the bestiary and the quests as appendices. This class is that
 * rearrangement and nothing else. It renders no HTML and it decides no access;
 * `adventure_print.php` does both, the same division CharacterSheet and
 * sheet_print.php already use.
 *
 * ONE MODULE, AND EVERYTHING IN IT REACHED THROUGH THE MODULE. Every query
 * below starts at `regions.module_id` and joins outward. That is not tidiness:
 * a book that gathered "every NPC with a dialogue" or "every monster in the
 * bestiary" would print the Old City's cast into Rivermark's adventure, and the
 * module boundary is the one CLAUDE.md says is kept by construction rather than
 * by convention. The bestiary is the exception that proves it — a goblin is a
 * goblin, deliberately unscoped — so the monsters here are the ones this
 * module's own encounters actually send, found through those encounters and
 * never by asking the bestiary what it holds.
 *
 * PURE READS. Nothing here writes, and nothing here is player state: a book is
 * of the adventure as authored, not of anybody's playthrough. That is why no
 * party id reaches this file — a printed adventure that hid what one party had
 * not found yet would be a strange object.
 */

declare(strict_types=1);

final class AdventureBook
{
    /**
     * How many rows a wandering table has, and therefore what you roll on it.
     *
     * Six, because that is the size a table wants to be when somebody is
     * rolling it one-handed with the other on a page.
     */
    private const WANDER_DIE = 6;

    /** The table a published adventure assumes it is being read at. */
    private const PARTY_SIZE = 4;

    /** How hard a wandering table tries for a row it has not already got. */
    private const WANDER_TRIES = 12;

    private PDO $db;

    /** The bestiary, fetched at most once however many floors ask for it. */
    private ?array $delveRoster = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The whole book for one module key, or null if there is no such module.
     *
     * `$delveSeed` is the seed for the specimen dungeon printed with a module
     * whose levels are generated at play. Passed in rather than rolled here so
     * that this class stays deterministic — the same arguments always produce
     * the same book, which is what lets a printed copy carry its seed and be
     * reprinted exactly. The page decides where the number comes from.
     *
     * @return array<string, mixed>|null
     */
    public function build(string $moduleKey, ?int $delveSeed = null): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM modules WHERE module_key = ?');
        $stmt->execute([$moduleKey]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$module) {
            return null;
        }
        $moduleId = (int) $module['id'];

        $regions = $this->regions($moduleId);
        $locationIds = [];
        foreach ($regions as $r) {
            foreach ($r['locations'] as $l) {
                $locationIds[] = (int) $l['id'];
            }
        }

        $encounters = $this->encounters($moduleId, $regions);
        $delve = $this->delve($moduleKey, $delveSeed, $module);

        // The bestiary stats everything the book SENDS, and a specimen delve
        // sends creatures no encounter row knows about — it is rolled for the
        // page, not stored. Without this the Undervault printed five levels of
        // fights, thirty wandering rows, and "0 creatures": every monster named
        // in the appendix and not one stat block for any of them.
        $monsters = $this->monsters($encounters, self::delveMonsterKeys($delve));

        return [
            'module'     => $module,
            'regions'    => $regions,
            'cast'       => $this->cast($locationIds),
            'quests'     => $this->quests($locationIds),
            'encounters' => $encounters,
            'monsters'   => $monsters,
            'delve'      => $delve,
            'counts'     => [
                'regions'    => count($regions),
                'locations'  => count($locationIds),
                'encounters' => count($encounters),
                'monsters'   => count($monsters),
                // What the book actually asks a referee to run, which for a
                // generated module is nearly all of it and none of it is an
                // `encounters` row.
                'rolled'     => self::delveFightCount($delve),
            ],
        ];
    }

    /** Every monster key a specimen delve sends, from its rooms and its tables. */
    private static function delveMonsterKeys(array $delve): array
    {
        $keys = [];
        foreach (($delve['floors'] ?? []) as $floor) {
            foreach ($floor['rooms'] as $room) {
                if (!empty($room['holds']['key'])) {
                    $keys[(string) $room['holds']['key']] = true;
                }
            }
            foreach ($floor['wandering']['rows'] as $row) {
                if (!empty($row['key'])) {
                    $keys[(string) $row['key']] = true;
                }
            }
        }
        return array_keys($keys);
    }

    /** How many fights a specimen delve holds, rooms and wandering rows alike. */
    private static function delveFightCount(array $delve): int
    {
        $n = 0;
        foreach (($delve['floors'] ?? []) as $floor) {
            foreach ($floor['rooms'] as $room) {
                $n += empty($room['holds']) ? 0 : 1;
            }
            $n += count($floor['wandering']['rows']);
        }
        return $n;
    }

    /**
     * A specimen dungeon, for a module whose levels are made at play.
     *
     * The Undervault has four authored locations and a generator, so a book
     * built only from the database prints the stair head and stops — which is
     * an accurate account of the rows and a useless account of the adventure.
     * What is authored here IS the generator, and the only honest way to put
     * that on paper is to roll one and say which one: every floor carries its
     * seed, and `?seed=` reprints the same book to the letter.
     *
     * One seed, every floor, because that is what a delve is — DelveEngine
     * rolls the seed once and derives each level from it plus the depth, so
     * these five ARE a party's five, not five unrelated dungeons.
     *
     * Pure: DungeonGen has no PDO and neither does this. The only thing read
     * from the database is which module was asked for, and that was read by
     * the caller. Which is why the answer for every other module is null — a
     * specimen delve in Rivermark's book would be a dungeon that does not
     * exist in it.
     *
     * @return array<string, mixed> empty when this module has nothing generated
     */
    private function delve(string $moduleKey, ?int $seed, array $module): array
    {
        if ($moduleKey !== DelveEngine::MODULE_KEY || $seed === null) {
            return [];
        }

        // What the module says it goes up to, so the nominal party cannot be
        // sent past the levels this game will actually grant them.
        $ceiling = max(1, (int) ($module['level_max'] ?? DelveEngine::MAX_DEPTH + 1));

        $floors = [];
        for ($depth = 1; $depth <= DelveEngine::MAX_DEPTH; $depth++) {
            $level = DungeonGen::generate($seed, $depth);
            $plan = DungeonGen::plan($level);

            // Areas numbered as the chapters number them, in room-id order so
            // the entry, the plan label and any cross reference agree. The
            // number is what the plan draws, not the name: a room name is nine
            // words wide at floor-plan scale and the book has the name in the
            // entry directly beneath.
            $party = $this->nominalParty($depth, $ceiling);
            $held = $this->stocked($level, $party);

            $numbers = [];
            $rooms = [];
            foreach ($level['rooms'] as $i => $room) {
                $numbers[(int) $room['id']] = $i + 1;
                $rooms[] = $room + [
                    'number' => $i + 1,
                    'holds'  => $held[(int) $room['id']] ?? null,
                ];
            }

            $ways = [];
            foreach ($level['corridors'] as $corridor) {
                $ways[] = $corridor + [
                    'from' => $numbers[(int) $corridor['a']] ?? null,
                    'to'   => $numbers[(int) $corridor['b']] ?? null,
                ];
            }

            $floors[] = [
                'depth'      => $depth,
                'seed'       => $seed,
                'profile'    => $level['profile'],
                'atmosphere' => $level['atmosphere'],
                'entrance'   => $numbers[(int) $level['entrance']] ?? null,
                'stair'      => $numbers[(int) $level['stair']] ?? null,
                'rooms'      => $rooms,
                'corridors'  => $ways,
                'map'        => self::planPayload($level, $plan, $numbers),
                'party'      => $party,
                'wandering'  => $this->wanderers($level, $party),
            ];
        }

        return [
            'seed'   => $seed,
            'floors' => $floors,
            'party'  => [
                'size' => self::PARTY_SIZE,
                'from' => $this->nominalParty(1, $ceiling)['level'],
                'to'   => $this->nominalParty(DelveEngine::MAX_DEPTH, $ceiling)['level'],
            ],
        ];
    }

    /**
     * Who the printed fights are sized for.
     *
     * A published adventure says who it is for, and an encounter budget has to
     * be a budget for somebody. Four characters is the assumed table, and the
     * level walks down with the floors — a fight on level 5 sized for the party
     * that would be standing on level 1 is not a fight anybody will have — but
     * stops at what the module advertises, because a book must not send a
     * referee a level the game will not grant.
     *
     * @return array{size:int, level:int, levels:int[]}
     */
    private function nominalParty(int $depth, int $ceiling): array
    {
        $level = max(1, min($ceiling, $depth + 1));
        return [
            'size'   => self::PARTY_SIZE,
            'level'  => $level,
            'levels' => array_fill(0, self::PARTY_SIZE, $level),
        ];
    }

    /**
     * The floor's wandering monster table, as a referee rolls it.
     *
     * The game already has wandering monsters down here — DelveEngine::wander()
     * stocks two or three per floor into the region's `is_random` pool, and
     * every passage carries a roll for them. What it does not have is a TABLE:
     * the pool is drawn by the travel roll and nobody ever sees the list. A
     * book has to print one, because the person running it is the die.
     *
     * ROLLED FROM THE SEED, not from `random_int`. This is the one place where
     * the book's needs and the engine's differ in kind. A delve's stocking is
     * rolled when a party walks down the stair and written into rows, because
     * it cannot be recovered from the seed afterwards; a printed page has to
     * come out identical every time the same seed is asked for, or the promise
     * on the cover of this appendix is false for half of it. So the sizing rule
     * is shared — DelveEngine::chooseGroup, with the chance passed in — and
     * only the stream differs.
     *
     * Six rows, so it is a d6: the size a table wants to be when somebody is
     * rolling it at a table with one hand.
     *
     * @return array{die:int, chance:int, rows:list<array{roll:int, what:string}>}
     */
    private function wanderers(array $level, array $party): array
    {
        $depth = (int) $level['depth'];
        $tier = DungeonGen::PROFILES[$level['profile']]['tier'];
        $budget = DelveEngine::wanderBudget($party['levels'], $tier, $depth);

        // Its own stream, seeded from the delve's seed and the depth, so the
        // table is a property of the printed dungeon and not of when it was
        // printed. Offset clear of the one DungeonGen::generate() uses for the
        // same seed and depth, or the table would be correlated with the
        // layout it sits beside.
        $rng = (((int) $level['seed'] ^ ($depth * 0x85EBCA6B)) + 0x27D4EB2F) & 0xFFFFFFFF;

        $roster = $this->delveRoster();
        $rand = static function (int $lo, int $hi) use (&$rng): int {
            return DungeonGen::rint($rng, $lo, $hi);
        };

        // Six DIFFERENT things, which the picker does not promise on its own.
        // It rolls a size and then takes the top third of what fits, so on a
        // shallow floor — where the affordable band is a handful of creatures —
        // a straight six draws came out as three Stirges and two Centipedes.
        // That is fine for the game, where each wandering group is met once and
        // hours apart, and useless in a book, where all six are read at once
        // and a d6 with three identical results is a d4 with extra steps.
        // Re-drawn rather than shuffled from a deck: the roster is not a table
        // of fixed length, it is whatever the budget can afford. Bounded, and
        // a repeat is accepted at the bound, because a small bestiary must
        // still fill the table.
        $rows = [];
        $seen = [];
        for ($roll = 1; $roll <= self::WANDER_DIE; $roll++) {
            $group = [];
            for ($try = 0; $try < self::WANDER_TRIES; $try++) {
                $group = DelveEngine::chooseGroup($roster, $budget, $rand);
                if (!$group || !isset($seen[(string) $group[0]['name']])) {
                    break;
                }
            }
            if (!$group) {
                continue;
            }
            $seen[(string) $group[0]['name']] = true;
            $doing = DelveEngine::DOINGS[$rand(0, count(DelveEngine::DOINGS) - 1)];
            $rows[] = [
                'roll'     => $roll,
                'quantity' => (int) $group[0]['quantity'],
                'key'      => (string) ($group[0]['key'] ?? ''),
                'monster'  => (string) $group[0]['name'],
                'doing'    => $doing,
                'what'     => $group[0]['quantity'] . ' × ' . $group[0]['name'] . ', ' . $doing,
            ];
        }

        return [
            'die'    => self::WANDER_DIE,
            // The real number the engine puts on every passage of this floor,
            // read from the engine rather than restated: a book that told a
            // referee the wrong odds would be wrong where nobody can check it.
            'chance' => DelveEngine::wanderChance($depth),
            'rows'   => $rows,
        ];
    }

    /**
     * What is standing in each of the floor's occupied rooms.
     *
     * The stocking table calls a third of every level `monster`, `hoard` or
     * `boss`, and the printed entries said "Something lives here" and stopped —
     * which is a description of the generator's intent rather than of a room a
     * referee can run. Worse, it left the book's bestiary EMPTY: the appendix
     * is built from the encounters a module sends, the Undervault's authored
     * surface sends none, and so a book with five levels of fights in it
     * printed "0 creatures" and not one stat block.
     *
     * Its OWN seeded stream, separate from the wandering table's. Sharing one
     * would couple them — adding a room to a profile would silently change what
     * wanders on that floor — and the two are independent facts about the same
     * dungeon.
     *
     * Deliberately not the same fight the game would put there: a delve's rooms
     * are stocked with `random_int` against the real party's levels at the
     * moment somebody descends, and cannot be recovered from the seed. This is
     * the same rule (DelveEngine::chooseGroup, DelveEngine::roomBudget) rolled
     * for the nominal party the book is written for, which is what a published
     * adventure states anyway.
     *
     * @return array<int, array{quantity:int, key:string, name:string}>
     */
    private function stocked(array $level, array $party): array
    {
        $depth = (int) $level['depth'];
        $tier = DungeonGen::PROFILES[$level['profile']]['tier'];
        $roster = $this->delveRoster();

        $rng = (((int) $level['seed'] ^ ($depth * 0xC2B2AE35)) + 0x165667B1) & 0xFFFFFFFF;
        $rand = static function (int $lo, int $hi) use (&$rng): int {
            return DungeonGen::rint($rng, $lo, $hi);
        };

        $out = [];
        foreach ($level['rooms'] as $room) {
            if (!in_array($room['kind'], ['monster', 'hoard', 'boss'], true)) {
                continue;
            }
            // A boss is the floor's fight and gets the whole hard budget
            // whatever tier the floor is otherwise on — DelveEngine::stock()'s
            // rule, because it is the same room.
            $budget = DelveEngine::roomBudget(
                $party['levels'],
                $room['kind'] === 'boss' ? 'hard' : $tier,
                $depth
            );
            $group = DelveEngine::chooseGroup($roster, $budget, $rand);
            if ($group) {
                $out[(int) $room['id']] = $group[0];
            }
        }
        return $out;
    }

    /** The bestiary a delve draws from — DelveEngine's, not a second query. */
    private function delveRoster(): array
    {
        return $this->delveRoster ??= (new DelveEngine($this->db))->roster();
    }

    /**
     * The floor plan in the shape `location/state` sends, so the page can hand
     * it to the shipped renderer instead of growing a second one.
     *
     * `window.WorldMap.svg` draws this today for a party standing in it, and
     * tools/floorplan_preview.php already proves it can be driven from a plain
     * page with no game behind it. A PHP re-implementation of that drawing —
     * doors in the old-school grammar, stair ticks, graph-paper ruling — would
     * be several hundred lines that agree with the screen only until one of
     * them is fixed. The book skins it for paper in CSS instead.
     *
     * Two deliberate differences from what the game sends, and they are the
     * same difference: this is the GM's copy.
     *
     *   - NO FOG. `seen`/`glimpsed` are simply absent, which the renderer's
     *     `!== false` reads as "draw it" — the case an authored region already
     *     relies on. The screen draws what one party has found; a book draws
     *     the floor.
     *   - No location ids anywhere, so nothing is clickable and nothing is
     *     "here". Room ids stand in, exactly as the preview bench does, and
     *     the only thing the renderer needs is that they agree across the
     *     nodes, the edges and the plan.
     */
    private static function planPayload(array $level, array $plan, array $numbers): array
    {
        // Passages are locations too, and their ids number from zero
        // independently of the rooms'. Offset past them exactly as
        // tools/floorplan_preview.php and DelveEngine's `c` prefix do.
        $hallId = static fn (int $corridor): int => 1000 + $corridor;

        // Rooms only. A passage node renders as nothing at all unless the
        // party is standing in it, and there is no party — the run itself is
        // drawn by floorplan() and carries the passage's whole significance.
        $nodes = [];
        foreach ($level['rooms'] as $room) {
            $id = (int) $room['id'];
            $nodes[] = [
                'id'   => $id,
                'key'  => 'r' . $id,
                // The number, not the name. A room name is nine words wide at
                // floor-plan scale and the entry beneath the plan has it.
                'name' => (string) ($numbers[$id] ?? ''),
                'type' => $room['role'] === 'stair' ? 'gate' : 'room',
                'x'    => $room['x'],
                'y'    => $room['y'],
                // The label is withheld for an unvisited room, which is the
                // fog again, one layer up. A book has no fog.
                'visited' => true,
                'current' => false,
            ];
        }

        $edges = [];
        foreach ($plan['corridors'] as $corridor) {
            $edges[] = ['from' => (int) $corridor['a'], 'to' => $hallId((int) $corridor['id'])];
            $edges[] = ['from' => $hallId((int) $corridor['id']), 'to' => (int) $corridor['b']];
        }

        foreach ($plan['rooms'] as $i => $room) {
            $plan['rooms'][$i]['location_id'] = (int) $room['id'];
        }
        foreach ($plan['corridors'] as $i => $corridor) {
            $plan['corridors'][$i]['location_id'] = $hallId((int) $corridor['id']);
            // A trap arrives from DungeonGen as a bare key and is drawn only
            // as {kind, state}: planFor() does that conversion for a party,
            // according to what they have met. Everything is `found` here,
            // which is the GM's copy — the same view the preview bench calls
            // `?spoilers=1`. Nothing is hidden in a book: a secret door the
            // reader cannot see is a secret kept from the referee.
            if (!empty($corridor['trap'])) {
                $plan['corridors'][$i]['trap'] = ['kind' => $corridor['trap'], 'state' => 'found'];
            }
        }

        return [
            'region_id'   => 0,
            'region_key'  => 'specimen_' . (int) ($level['depth'] ?? 0),
            'name'        => 'The Undervault, level ' . (int) ($level['depth'] ?? 0),
            'region_type' => 'dungeon',
            'nodes'       => $nodes,
            'edges'       => $edges,
            'neighbors'   => [],
            // DelveEngine::fog() is deliberately NOT applied. It is what makes
            // the screen draw one party's partial knowledge, and there is no
            // party here.
            'floorplan'   => $plan,
        ];
    }

    /** Every module that could be printed, for the picker. */
    public function catalogue(): array
    {
        return $this->db->query(
            'SELECT module_key, name, blurb, level_min, level_max, is_active
               FROM modules ORDER BY sort_order, id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The chapters: a region each, with its places in reading order.
     *
     * Numbered as a published adventure numbers them — 1, 2, 3 within the
     * chapter — and that number is carried on the location so the cross
     * references elsewhere in the book can say "area 4" and mean it. The
     * ordering is the authored `sort_order`, which is the order the content
     * files are written in and therefore the order somebody meant.
     */
    /**
     * The module's authored regions, and only those.
     *
     * `_dg_%` is a party's generated delve floor, written into the Undervault's
     * own module id by DelveEngine, and it has no business in a book: it exists
     * for a few hours, it belongs to one party, and it will not be there
     * tomorrow. Printing the Undervault while three parties were underground
     * put three extra chapters in the book — each one another party's scratch
     * rooms, with their traps and secret doors — and the book changed depending
     * on who happened to be delving when somebody pressed Print.
     *
     * encounters() has excluded the same rows by the same pattern since it was
     * written; this is that rule, applied where it was missed. What a book
     * SHOULD say about a generated dungeon is a specimen rolled for the page —
     * see delve().
     */
    private function regions(int $moduleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM regions
              WHERE module_id = ? AND region_key NOT LIKE '\\_dg\\_%'
              ORDER BY sort_order, id"
        );
        $stmt->execute([$moduleId]);
        $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($regions as $i => $region) {
            $regions[$i]['locations'] = $this->locations((int) $region['id']);
            $regions[$i]['map'] = $this->authoredMap($regions[$i]);
        }
        return $regions;
    }

    /**
     * An authored dungeon's compiled plan, in the shape the book already
     * hands to WorldMap.svg for a rolled floor.
     *
     * The plan is the source; this is a reading of it. A town or a wilderness
     * has a painted plate and no plan, and returns null so the chapter falls
     * through to pins on that painting — two maps of one place would disagree.
     */
    private function authoredMap(array $region): ?array
    {
        $raw = $region['plan_json'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $plan = json_decode($raw, true);
        if (!is_array($plan) || empty($plan['rooms'])) {
            return null;
        }
        try {
            $compiled = PlanAuthored::compile($plan);
        } catch (InvalidArgumentException $e) {
            return null;
        }

        $byKey = [];
        foreach ($region['locations'] ?? [] as $loc) {
            $byKey[(string) $loc['location_key']] = (int) $loc['number'];
        }
        $numbers = [];
        foreach ($compiled['level']['rooms'] as $room) {
            $id = (int) $room['id'];
            $numbers[$id] = $byKey[(string) ($room['key'] ?? '')] ?? ($id + 1);
        }

        $map = self::planPayload($compiled['level'], $compiled['plan'], $numbers);
        $map['region_key'] = (string) $region['region_key'];
        $map['name'] = (string) $region['name'];
        return $map;
    }

    private function locations(int $regionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM locations WHERE region_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$regionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $row) {
            $id = (int) $row['id'];
            $rows[$i]['number']     = $i + 1;
            $rows[$i]['ambience']   = $this->jsonList($row['ambience_json'] ?? null);
            $rows[$i]['trap']       = $this->jsonMap($row['trap_json'] ?? null);
            $rows[$i]['exits']      = $this->exits($id);
            $rows[$i]['people']     = $this->peopleAt($id);
            $rows[$i]['items']      = $this->itemsAt($id);
        }
        return $rows;
    }

    /**
     * The ways out, named as the book needs them: where to, and what the door
     * is like. A hidden or locked way is stated plainly — this is the GM's copy,
     * and the whole point of the entry is that it says what the players will
     * have to find.
     */
    private function exits(int $locationId): array
    {
        $stmt = $this->db->prepare(
            // `conditions_json` is where a lock, a required flag and a forceable
            // door all live — one column, decoded below, because the schema
            // keeps them together and a book that invented three columns would
            // be describing a table that does not exist.
            'SELECT e.label, e.is_hidden, e.conditions_json,
                    l.name AS to_name, l.location_key AS to_key,
                    r.name AS to_region
               FROM location_exits e
               INNER JOIN locations l ON l.id = e.to_location_id
               INNER JOIN regions r ON r.id = l.region_id
              WHERE e.from_location_id = ?
              ORDER BY e.sort_order, e.id'
        );
        $stmt->execute([$locationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $i => $row) {
            $rows[$i]['conditions'] = $this->jsonMap($row['conditions_json'] ?? null);
        }
        return $rows;
    }

    /** Who is standing here, ambient walk-ons included — a room is its people. */
    private function peopleAt(int $locationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT npc_key, name, role, description, is_merchant, is_quest_giver,
                    is_ambient, sprite_key
               FROM npcs WHERE location_id = ? ORDER BY is_ambient, name'
        );
        $stmt->execute([$locationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** What is lying here to be found. */
    private function itemsAt(int $locationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.name, i.item_type, i.rarity, i.value_gp, i.description
               FROM location_items li
               INNER JOIN items i ON i.id = li.item_id
              WHERE li.location_id = ?
              ORDER BY i.name'
        );
        $stmt->execute([$locationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The cast, as an appendix.
     *
     * Found through the module's own locations, so nobody from another game
     * walks into this book. An NPC with no location is left out rather than
     * guessed at: there is nowhere in the adventure to print them.
     */
    private function cast(array $locationIds): array
    {
        if (!$locationIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $locationIds));
        return $this->db->query(
            "SELECT n.npc_key, n.name, n.role, n.description, n.is_merchant,
                    n.is_quest_giver, n.is_ambient, n.sprite_key,
                    l.name AS location_name, l.location_key
               FROM npcs n
               INNER JOIN locations l ON l.id = n.location_id
              WHERE n.location_id IN ({$in}) AND n.is_ambient = 0
              ORDER BY n.name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The quests, with their stages in order.
     *
     * A quest belongs to this module if its giver stands in it or its target is
     * in it — the same derivation QuestService::postable() uses to decide what a
     * job board may show, and deliberately the same, because two answers to
     * "whose quest is this" is exactly the fault that put another module's work
     * on a board once already.
     */
    private function quests(array $locationIds): array
    {
        if (!$locationIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $locationIds));
        $rows = $this->db->query(
            "SELECT DISTINCT q.*, g.name AS giver_name, g.npc_key AS giver_key,
                    t.name AS target_name
               FROM quests q
               LEFT JOIN npcs g ON g.id = q.giver_npc_id
               LEFT JOIN locations t ON t.id = q.target_location_id
              WHERE g.location_id IN ({$in}) OR q.target_location_id IN ({$in})
              ORDER BY q.act, q.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $q) {
            $stmt = $this->db->prepare(
                'SELECT stage_key, title, objective, journal_entry, is_terminal,
                        resolution, outcome
                   FROM quest_stages WHERE quest_id = ? ORDER BY sort_order, id'
            );
            $stmt->execute([(int) $q['id']]);
            $rows[$i]['stages'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    /**
     * Every fight the module can start, with its roster.
     *
     * Both kinds: one pinned to a location, and one in a region's wandering
     * pool. Generated delve encounters are excluded by key — `_dg_%` rows are a
     * party's scratch floor, which exists for hours and belongs in nobody's
     * book.
     */
    private function encounters(int $moduleId, array $regions): array
    {
        $regionIds = array_map(static fn ($r) => (int) $r['id'], $regions);
        if (!$regionIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $regionIds));
        $rows = $this->db->query(
            "SELECT e.*, l.name AS location_name, r1.name AS region_name,
                    COALESCE(r1.name, r2.name) AS chapter
               FROM encounters e
               LEFT JOIN locations l ON l.id = e.location_id
               LEFT JOIN regions r1 ON r1.id = l.region_id
               LEFT JOIN regions r2 ON r2.id = e.region_id
              WHERE (l.region_id IN ({$in}) OR e.region_id IN ({$in}))
                AND e.encounter_key NOT LIKE '\\_dg\\_%'
              ORDER BY COALESCE(r1.sort_order, r2.sort_order), e.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $e) {
            $stmt = $this->db->prepare(
                'SELECT em.quantity, m.monster_key, m.name, m.challenge_rating,
                        m.experience_points
                   FROM encounter_monsters em
                   INNER JOIN monsters m ON m.id = em.monster_id
                  WHERE em.encounter_id = ? ORDER BY m.experience_points DESC'
            );
            $stmt->execute([(int) $e['id']]);
            $rows[$i]['roster'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    /**
     * The bestiary appendix: the monsters this module's encounters send, once
     * each.
     *
     * Gathered from the encounters rather than from `monsters`, which is not
     * scoped to a module and never will be — "a goblin is a goblin" is the rule
     * CLAUDE.md states. So the book prints the sixteen creatures its own fights
     * use, not the whole bestiary.
     */
    private function monsters(array $encounters, array $alsoKeys = []): array
    {
        $keys = [];
        foreach ($encounters as $e) {
            foreach ($e['roster'] as $m) {
                $keys[$m['monster_key']] = true;
            }
        }
        foreach ($alsoKeys as $k) {
            if ((string) $k !== '') {
                $keys[(string) $k] = true;
            }
        }
        if (!$keys) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM monsters WHERE monster_key IN ({$marks})
              ORDER BY challenge_rating, name"
        );
        $stmt->execute(array_keys($keys));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $m) {
            // Which file the creature's picture is in. Its own sprite key —
            // never assumed to be the monster key, because the pack's art is
            // keyed by sprite and several creatures shared one until the
            // bestiary appendix made that visible.
            $rows[$i]['art_key'] = trim((string) ($m['sprite_key'] ?? '')) ?: $m['monster_key'];
            $rows[$i]['traits_list']  = $this->blocks($m['traits'] ?? null);
            $rows[$i]['actions_list'] = $this->blocks($m['actions'] ?? null);
            $rows[$i]['saves']        = $this->jsonMap($m['saves_json'] ?? null);
            $rows[$i]['resistances']  = $this->jsonList($m['resistances_json'] ?? null);
            $rows[$i]['immunities']   = $this->jsonList($m['immunities_json'] ?? null);
        }
        return $rows;
    }

    /**
     * A monster's traits or actions, as a list of {name, text}.
     *
     * The column holds either a JSON list of objects or a blob of prose,
     * depending on how old the row is — the seeded bestiary and the authored
     * one disagree, and a stat block that printed `[{"name":...` because it
     * guessed wrong is worse than one that prints a paragraph.
     */
    private function blocks(?string $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $key => $entry) {
                if (is_array($entry)) {
                    $out[] = [
                        'name' => (string) ($entry['name'] ?? (is_string($key) ? $key : '')),
                        'text' => (string) ($entry['desc'] ?? $entry['text'] ?? $this->attackLine($entry)),
                    ];
                } else {
                    $out[] = ['name' => is_string($key) ? $key : '', 'text' => (string) $entry];
                }
            }
            return $out;
        }
        return [['name' => '', 'text' => $text]];
    }

    /**
     * An attack, in the sentence a stat block writes it in.
     *
     * The bestiary's `actions` column is not prose: it is the row CombatEngine
     * swings with — `{"name":"Scimitar","type":"melee","bonus":3,
     * "damage":"1d6+1","damage_type":"slashing"}`. Printed as-is that comes out
     * as a bare "Scimitar." with nothing after it, which is what the first
     * draft of this book did for every creature in it.
     *
     * NO ARITHMETIC. The dice expression is printed as stored rather than
     * averaged into "4 (1d6+1)", which is how the published books write it —
     * because an average is a rule, this file is not where rules live (see
     * sheet_print.php making the same promise), and the game itself rolls the
     * expression rather than the average. Reach is the one number stated, and
     * it is stated only when the row does not carry a range of its own.
     */
    private function attackLine(array $a): string
    {
        if (!isset($a['damage']) && !isset($a['bonus']) && !isset($a['type'])) {
            return '';
        }
        $ranged = ($a['type'] ?? '') === 'ranged';
        $parts = [];
        $parts[] = $ranged ? 'Ranged attack' : 'Melee attack';
        if (isset($a['bonus'])) {
            $parts[] = sprintf('%+d to hit', (int) $a['bonus']);
        }
        // No full stop on this clause: the sentence closes below, and putting
        // one here printed "range 80/320 ft..".
        $parts[] = $ranged && !empty($a['range'])
            ? 'range ' . $a['range'] . ' ft'
            : 'reach ' . ($a['reach'] ?? 5) . ' ft';

        $line = implode(', ', $parts) . '.';
        $damage = trim((string) ($a['damage'] ?? ''));
        if ($damage !== '' && $damage !== '0') {
            $line .= ' Hit: ' . $damage
                . ($a['damage_type'] ? ' ' . $a['damage_type'] : '') . ' damage.';
        }
        if (!empty($a['note'])) {
            $line .= ' ' . $a['note'];
        }
        return $line;
    }

    /** @return list<string> */
    private function jsonList(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            if (is_scalar($v)) {
                $out[] = (string) $v;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function jsonMap(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
