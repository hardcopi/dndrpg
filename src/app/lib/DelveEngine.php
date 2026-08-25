<?php
/**
 * A generated dungeon level, written into the world and taken out again.
 *
 * DungeonGen decides the shape and knows nothing about the database.
 * This decides nothing and writes everything — the same split PitEngine draws
 * between "how big should this fight be" and "which creatures make it", and
 * the reason a thousand levels can be checked in a millisecond upstairs.
 *
 * WHAT IT WRITES ARE ORDINARY ROWS. A generated floor is a `regions` row with
 * `locations` and `location_exits` hanging off it, exactly like an authored
 * one — so travel, the region chart, encounters, searching and every per-party
 * table work on it with no special case anywhere. The only thing that marks it
 * out is the key, which is prefixed `_dg_` the way PitEngine's scratch
 * encounter is prefixed `_pit_`.
 *
 * WHICH IS ALSO HOW IT IS CLEANED UP — ALMOST. Deleting the region cascades
 * through locations, exits and every party_* row that references them, because
 * the schema says ON DELETE CASCADE on all of those.
 *
 * Encounters are the exception and cost a second statement. `encounters`
 * references `locations` with ON DELETE **SET NULL**, which is the right rule
 * for the authored world — removing a location should not destroy a
 * hand-written fight — and the wrong one here, where the fight exists only to
 * stand in a room that is about to stop existing. See dropRegion().
 *
 * THE MODULE BOUNDARY IS ENFORCED HERE, AT RUNTIME, AND THAT IS NEW.
 * Everywhere else in this game two games are kept apart by tools/load_content.py,
 * which BFSes each module from its own start and errors on an exit that joins
 * two of them. That check reads files. It will never see a row written here.
 * So the guarantee has to be restated in code: every exit this class writes has
 * both ends inside the delving party's own module, and `assertContained()` is
 * run over the finished level before the party is allowed to walk into it.
 */

declare(strict_types=1);

final class DelveEngine
{
    /**
     * The authored module that ships a delve of its own.
     *
     * Kept because the printed book knows it — AdventureBook prints the
     * Undervault's floors — but no longer what decides where a delve may
     * happen. That is `locations.has_delve` now, and the module a delve writes
     * into is read from the mouth it began at.
     */
    public const MODULE_KEY = 'undervault';

    /** The Undervault's own mouth. One of several now; see has_delve. */
    public const MOUTH_KEY = 'uv_mouth';

    /**
     * How many floors down the stair goes.
     *
     * Not a rule, a stopping point: past this the profiles stop changing and a
     * dungeon that never ends is a dungeon nobody finishes. Reaching it is the
     * closest thing this module has to winning.
     */
    public const MAX_DEPTH = 5;

    /**
     * What the map service is asked to draw.
     *
     * Keeps only, for now. The service also makes caves, and caves come back as
     * one to four chambers with no doors between them — which is a fine cave
     * and a poor dungeon, because every room here becomes a place you walk to
     * and a floor with two of them is not a delve. See the note in the plan.
     */
    private const KIND_FOR_DEPTH = MapService::KIND_KEEP;

    /** How many foes a generated room may hold, matching the pit's ceiling. */
    private const MAX_FOES = 6;

    /**
     * The most a found item may be worth, indexed by band — see treasureFor().
     *
     * Index 0 is unused (a band is depth plus at least zero, and depth starts
     * at one). The top of the table is the ceiling for everything below it, so
     * a party who somehow reach a deeper band than this is authored for get the
     * best band rather than nothing.
     */
    private const TREASURE_CEILING = [25, 25, 60, 150, 350, 700, 1300];

    /**
     * Leaving a passage at either end.
     *
     * Deliberately not a door: the door is on the room end of the join and is
     * written there. Stepping out of a corridor into the room it runs to is
     * just walking, and calling it "A stuck door" a second time would have the
     * player forcing the same door twice.
     */
    private const PASSAGE_LABEL = 'On, to the far end';

    /**
     * The high-water mark: the deepest floor this party has ever stood on.
     *
     * `dungeon_delves.depth` is where they are, and it goes away when they
     * climb out. This is what they have DONE, and it is the only thing about a
     * generated dungeon that authored content can talk about — a floor has no
     * key, no name and no history, so there is nothing else down there to
     * write a quest against.
     *
     * Deliberately one dumb number rather than a hook. Nothing here knows what
     * a quest is; content reads the flag with `{"flag": "uv_deepest",
     * "at_least": 3}` and decides for itself what that is worth. A DelveEngine
     * that advanced named quest stages would be a generator with a plot in it.
     */
    public const DEPTH_FLAG = 'uv_deepest';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =======================================================================
    // Reading a delve
    // =======================================================================

    /** The party's delve, or null if they are not down a hole. */
    public function current(?int $partyId): ?array
    {
        if (!$partyId) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM dungeon_delves WHERE party_id = ?');
        $stmt->execute([$partyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * The furnishing standing in a room, read out of the stored floor.
     *
     * THE LEVEL IS THE ONLY COPY. A chest could have been written into a
     * column on `locations` when the floor was drawn, and then there would be
     * two accounts of what is in the room — the one the chart and the raster
     * are built from, and the one the verbs act on — which is the argument
     * `level_json` itself was added for. So this parses the room number back
     * out of the location key and asks the level, and everything about a
     * furnishing that the generator decided stays decided in one place.
     *
     * What is NOT here is what the party has done to it. Opened, found,
     * disarmed and sprung are all party facts and live in WorldState, keyed by
     * the location, exactly as a barricade and a passage trap do — the level
     * is what the dungeon is, not what has happened in it.
     *
     * @return array{location_key:string,depth:int,room:int,furnishing:array}|null
     */
    public function furnishingAt(?int $partyId, int $locationId, ?string $locationKey = null): ?array
    {
        if (!$partyId || $locationId <= 0) {
            return null;
        }
        // The key if the caller already has it. Every location look goes
        // through here and all but a handful are authored places whose key
        // cannot possibly match — reading it back out of the table to find
        // that out is a query per look for nothing.
        $key = $locationKey;
        if ($key === null) {
            $stmt = $this->db->prepare('SELECT location_key FROM locations WHERE id = ?');
            $stmt->execute([$locationId]);
            $key = $stmt->fetchColumn();
        }
        if (!is_string($key)
            || !preg_match('/^_dg_(\d+)_(\d+)_r(\d+)$/', $key, $m)
            || (int) $m[1] !== $partyId) {
            return null;
        }
        $delve = $this->current($partyId);
        // The room key carries the depth, so a stale key — a location row from
        // a floor already dropped — is refused rather than answered from the
        // floor the party is on now.
        if ($delve === null || (int) $delve['depth'] !== (int) $m[2]) {
            return null;
        }
        $room = (int) $m[3];
        foreach ($this->levelFor($delve)['rooms'] ?? [] as $r) {
            if ((int) ($r['id'] ?? -1) === $room && !empty($r['furnishing'])) {
                return [
                    'location_key' => $key,
                    'depth'        => (int) $m[2],
                    'room'         => $room,
                    'furnishing'   => $r['furnishing'],
                ];
            }
        }
        return null;
    }

    /** The flag one furnishing's lid lives under: shut until it says otherwise. */
    public static function furnishingFlag(string $locationKey): string
    {
        return 'dg_furn_' . $locationKey;
    }

    /**
     * The flag its trap lives under.
     *
     * Suffixed, because the ROOM may have a trap of its own in `trap_json` and
     * that one is already keyed on the bare location key. Two mechanisms in one
     * place needed two names, and finding the one in the lid must not mark the
     * one in the floor as found.
     */
    public static function furnishingTrapFlag(string $locationKey): string
    {
        return self::trapFlag($locationKey . '#furn');
    }

    /**
     * Whether this party may start one from where they are standing.
     *
     * A location with a stair in it, and nowhere else. A delve begun from the
     * camp would put the party underground without walking to the stair, which
     * is the class of thing the module boundary rules exist to stop — the rule
     * has not changed, only the number of places that satisfy it.
     */
    public function canDescendHere(int $characterId): bool
    {
        return $this->mouthFor($characterId) !== null;
    }

    /**
     * The stair the party is standing on, or null if they are not on one.
     *
     * Returns the location id rather than a yes: a delve records where it began
     * so it knows where to put the party when they climb out, and which module
     * its floors belong to. With one authored mouth those were constants; with
     * a mouth wherever content puts one, they are facts about this delve.
     */
    private function mouthFor(int $characterId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT l.id FROM characters c
               INNER JOIN locations l ON l.id = c.current_location_id
              WHERE c.id = ? AND l.has_delve = 1'
        );
        $stmt->execute([$characterId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Where a delve in progress began.
     *
     * A row written before delves recorded their mouth has none, and every such
     * delve is in the Undervault because that is the only place one could
     * happen — so that is what it falls back to. Without this the party climbs
     * out of a floor that is then deleted underneath them and ends up standing
     * nowhere at all.
     */
    private function mouthOf(array $delve): ?int
    {
        $id = $delve['mouth_location_id'] ?? null;
        if ($id !== null) {
            return (int) $id;
        }
        return $this->locationIdByKey(self::MOUTH_KEY);
    }

    /**
     * The module a delve's floors are written into: the one its mouth stands in.
     *
     * Read from the world rather than named, which is what lets a stair exist in
     * more than one game. assertContained() still checks every generated exit
     * against this id, so the boundary is enforced exactly as before — it is the
     * source of the id that changed, not the rule.
     */
    private function moduleForMouth(int $mouthId): int
    {
        $stmt = $this->db->prepare(
            'SELECT r.module_id FROM locations l
               INNER JOIN regions r ON r.id = l.region_id
              WHERE l.id = ?'
        );
        $stmt->execute([$mouthId]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException('That stair does not stand in any module.');
        }
        return $id;
    }

    /**
     * What the stair is offering from where the party is standing.
     *
     * Carried on the scene the way the pit's card is, and for the same reason:
     * there is nobody down here to talk to, so standing in the right place has
     * to be enough to see the option. Null everywhere the question is
     * meaningless, which is most of the world — a party in Rivermark should not
     * be told about a hole they cannot reach.
     *
     * @return array{depth:int, max_depth:int, can_descend:bool,
     *               can_deeper:bool, can_leave:bool}|null
     */
    public function status(int $characterId, ?int $partyId): ?array
    {
        if (!$partyId) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT l.location_key, l.has_delve
               FROM characters c
               INNER JOIN locations l ON l.id = c.current_location_id
              WHERE c.id = ?'
        );
        $stmt->execute([$characterId]);
        $here = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$here) {
            return null;
        }

        $delve = $this->current($partyId);
        if ($delve === null) {
            // Nothing to say anywhere but on a stair. Most of the world is not
            // a stair, and a party in a tavern should not be told about a hole
            // they cannot reach.
            if (empty($here['has_delve'])) {
                return null;
            }
            return [
                'depth'       => 0,
                'max_depth'   => self::MAX_DEPTH,
                'can_descend' => true,
                'can_deeper'  => false,
                'can_leave'   => false,
            ];
        }

        // Deeper only from the room the stair is actually in. Read from the
        // floor this delve is on rather than recomputed — see levelFor(). It
        // used to be recomputed, on the argument that DungeonGen is
        // deterministic and a stored copy is a second thing that can disagree;
        // that argument holds for DungeonGen and not for a floor drawn by a
        // service that is allowed to be absent.
        $depth = (int) $delve['depth'];
        $level = $this->levelFor($delve);
        $stairKey = self::roomKey($partyId, $depth, (int) $level['stair']);
        $entranceKey = self::roomKey($partyId, $depth, (int) $level['entrance']);

        // Out from the entrance room only, the same rule that already governs
        // going deeper — and the same rule the level itself already keeps.
        //
        // This was `true`, everywhere. The reasoning was that climbing out
        // walks nowhere: it ends the delve and the floor stops existing, so
        // there is no journey to model and no obvious place to stand while
        // doing it. But a way out that needs no journey is a way out that
        // costs nothing, and a floor you can leave from its deepest corner is
        // a floor you can never be cut off in — which is most of what the
        // loops in it are for.
        //
        // The exit was always there, besides. writeExits() cuts one from the
        // entrance room back to the Mouth and from nowhere else; this button
        // was a second way out that bypassed it. Now the two agree.
        return [
            'depth'       => $depth,
            'max_depth'   => self::MAX_DEPTH,
            'can_descend' => false,
            'can_deeper'  => $here['location_key'] === $stairKey && $depth < self::MAX_DEPTH,
            'can_leave'   => $here['location_key'] === $entranceKey,
        ];
    }

    /**
     * The floor plan for a generated region, in the chart's own coordinates.
     *
     * A generated level has no painted map and never will — the plates under
     * the authored regions are drawn once by a tool and committed, and a floor
     * that exists only for as long as a party is standing on it cannot be. So
     * the chart draws the level's actual shape instead: the rooms as rooms and
     * the passages between them, which is what the region art was standing in
     * for anyway.
     *
     * Regenerated from the seed rather than stored, which is the rule this
     * whole engine follows and the same reason status() gives for recomputing
     * the stair: DungeonGen is deterministic, and a stored copy of the layout
     * is a second thing that can disagree with the first. `dungeon_delves`
     * holds the seed and the depth, and those two numbers are the level.
     *
     * Returns null for anything that is not a generated floor, which is how
     * the chart decides whether to draw a plan or fall back to parchment.
     */
    public function planFor(int $regionId): ?array
    {
        $stmt = $this->db->prepare('SELECT region_key FROM regions WHERE id = ?');
        $stmt->execute([$regionId]);
        $key = (string) $stmt->fetchColumn();

        // `_dg_<party>_<depth>`. Matched rather than assumed: an authored
        // region that happened to be passed here would otherwise be looked up
        // as a delve and come back with somebody else's floor.
        if (!preg_match('/^_dg_(\d+)_(\d+)$/', $key, $m)) {
            return null;
        }
        $partyId = (int) $m[1];
        $depth = (int) $m[2];

        $delve = $this->current($partyId);
        // Depth as well as party: a region left behind by a failed drop is not
        // the floor this party is on, and drawing it would be a plan of
        // somewhere else.
        if ($delve === null || (int) $delve['depth'] !== $depth) {
            return null;
        }

        $level = $this->levelFor($delve);
        $plan = DungeonGen::plan($level);

        // Room ids mean nothing to the client; location ids are what its nodes
        // are keyed by. Resolved through the room key, which is stable across
        // a regeneration by construction — the same argument the content
        // pipeline makes for keys over ids.
        $ids = [];
        $rows = $this->db->prepare(
            'SELECT id, location_key FROM locations WHERE region_id = ?'
        );
        $rows->execute([$regionId]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[$row['location_key']] = (int) $row['id'];
        }

        $idFor = function (int $room) use ($ids, $partyId, $depth): ?int {
            return $ids[self::roomKey($partyId, $depth, $room)] ?? null;
        };

        foreach ($plan['rooms'] as $i => $r) {
            $plan['rooms'][$i]['location_id'] = $idFor((int) $r['id']);
        }
        // A passage carries its OWN location id now, because it is a place. It
        // used to carry the ids of the two rooms either end, so that ui-map.js
        // could match the drawn run against an edge in the graph; there is no
        // such edge any more — the graph goes room, passage, room — and the run
        // is the node rather than the line between two of them.
        foreach ($plan['corridors'] as $i => $c) {
            $plan['corridors'][$i]['location_id'] =
                $ids[self::passageKey($partyId, $depth, (int) $c['id'])] ?? null;
            $plan['corridors'][$i]['from'] = $idFor((int) $c['a']);
            $plan['corridors'][$i]['to'] = $idFor((int) $c['b']);
        }

        $seen = $this->playersMap($plan, $partyId, $ids);

        // The raster rides alongside the plan rather than replacing it: the
        // chart is still what a player reads a floor from, and the toggle to
        // the first-person view has to be able to go back. Censored from the
        // plan's OWN fog answer, so the two can never show different floors.
        $seen['tiles'] = self::fogTiles(DungeonGen::tiles($level), $seen);

        // Which lids this party has had up. Stamped on both drawings from one
        // read, so the chart glyph and the box in the corridor cannot disagree
        // about whether the thing is open — the fault the shipped `ui` block in
        // combat exists to prevent, in a smaller place.
        $world = new WorldState($this->db);
        $keyOf = array_flip($ids);   // id => location key, flipped once
        $isOpen = static function (?int $locationId) use ($world, $partyId, $keyOf): bool {
            return $locationId !== null
                && isset($keyOf[$locationId])
                && $world->isSet($partyId, self::furnishingFlag((string) $keyOf[$locationId]));
        };
        foreach ($seen['rooms'] as $i => $r) {
            if (!empty($r['furnishing'])) {
                $seen['rooms'][$i]['furnishing_open'] = $isOpen($r['location_id'] ?? null);
            }
        }
        foreach ($seen['tiles']['props'] as $i => $prop) {
            $seen['tiles']['props'][$i]['o'] = $isOpen($prop['l'] ?? null);
        }

        return $seen;
    }

    /**
     * The GM's plan, censored down to what this party has earned.
     *
     * DungeonGen::plan() knows the whole truth of the floor — every secret
     * door, every rigged flag — because it IS the floor. What the chart may
     * draw is a different question, answered here where the party is known,
     * so the generator stays pure and the map stops spoiling:
     *
     *  - A stuck door whose exits are still unfound loses its glyph, and the
     *    passage behind it is not drawn at all — see fog(), which is where
     *    that is decided. It used to be drawn dimmed and doorless; the fog is
     *    what made that wrong. Drawing the dashed glyph regardless was the
     *    behaviour before THAT, and it was the map answering a question the
     *    player had not earned: donjon ships a "player's map" for exactly this
     *    reason.
     *  - A trapped door reads as a plain door until the trap is met.
     *  - A trap mark ships only once the party has found it (or been found
     *    by it), with which of those it was.
     *  - And the fog: a room or a passage the party has not walked into is not
     *    on the map at all. See `seen` and `glimpsed` below.
     */
    private function playersMap(array $plan, int $partyId, array $ids): array
    {
        $found = [];
        $stmt = $this->db->prepare(
            'SELECT f.exit_id FROM party_exits_found f
              INNER JOIN location_exits e ON e.id = f.exit_id
              WHERE f.party_id = ?'
        );
        $stmt->execute([$partyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $exitId) {
            $found[(int) $exitId] = true;
        }
        // Which hidden exits guard which passage: hidden rows point AT the
        // passage location (room → passage is the hidden half), so the
        // passage's location id is the join key back to a corridor.
        $hidden = $this->db->prepare(
            'SELECT e.id, e.to_location_id FROM location_exits e
              WHERE e.is_hidden = 1 AND e.to_location_id IN (' .
                (count($ids) ? implode(',', array_map('intval', $ids)) : '0') . ')'
        );
        $hidden->execute();
        $hiddenByPassage = [];
        foreach ($hidden->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hiddenByPassage[(int) $row['to_location_id']][] = (int) $row['id'];
        }

        $world = new WorldState($this->db);
        $visited = [];
        // (one WorldState for the whole pass; its per-party cache makes the
        // per-corridor reads below free)
        $vs = $this->db->prepare('SELECT location_id FROM party_locations_visited WHERE party_id = ?');
        $vs->execute([$partyId]);
        foreach ($vs->fetchAll(PDO::FETCH_COLUMN) as $lid) {
            $visited[(int) $lid] = true;
        }

        foreach ($plan['corridors'] as $i => $c) {
            $locId = $c['location_id'];

            if ($c['door'] === 'stuck') {
                $exits = $locId !== null ? ($hiddenByPassage[$locId] ?? []) : [];
                $anyFound = false;
                foreach ($exits as $eid) {
                    if (isset($found[$eid])) {
                        $anyFound = true;
                    }
                }
                // Walked through counts as found the hard way round.
                if ($locId !== null && isset($visited[$locId])) {
                    $anyFound = true;
                }
                if (!$anyFound) {
                    $plan['corridors'][$i]['door'] = null;
                    $plan['corridors'][$i]['found'] = false;
                }
            }

            if ($c['trap'] !== null) {
                $key = array_search($locId, $ids, true);
                $state = $key !== false
                    ? $world->get($partyId, self::trapFlag((string) $key))
                    : null;
                if ($state === 'found' || $state === 'sprung') {
                    $plan['corridors'][$i]['trap'] = ['kind' => $c['trap'], 'state' => $state];
                } else {
                    $plan['corridors'][$i]['trap'] = null;
                }
            }

            if ($plan['corridors'][$i]['door'] === 'trapped'
                && !is_array($plan['corridors'][$i]['trap'])) {
                // The door is drawn as what the party believes it is.
                $plan['corridors'][$i]['door'] = 'door';
            }
        }

        return self::fog($plan, $visited);
    }

    /**
     * The fog: what of the floor has been walked, and what can be seen from it.
     *
     * The plan is the whole level, and it was shipped whole — a party stepping
     * off the stair got the map a DM draws before play, every room, every
     * passage, the way to the stair down included. There was nothing to find,
     * and the loops that make a level a place you can be cut off in were
     * legible from the first room.
     *
     * Three states, and they are what a party with a lamp and a pencil actually
     * has:
     *
     *  - `seen`      — you have stood in it. Drawn whole, ruled, named.
     *  - `glimpsed`  — you have stood somewhere that opens onto it. A passage
     *                  off a room you have been in, or the room at the far end
     *                  of a passage you have walked: you have seen the doorway
     *                  and not what is past it. Drawn as an outline, unnamed.
     *  - neither     — not drawn at all. It is rock until you go and look.
     *
     * WHICH IS DECIDED HERE AND NOT ON THE CLIENT, for the reason the combat
     * board computes nothing: ui-map.js would need the room-to-passage graph to
     * work out what is adjacent to what, and a second copy of the level's
     * topology in JavaScript is a copy that disagrees with the one the exits
     * are built from. It gets two booleans per shape instead.
     *
     * The names are withheld by the same fact, one layer up: a room the party
     * has not visited is `visited: false` on its node, and the chart already
     * refuses to name one of those.
     *
     * Static and PDO-free, like DungeonGen and BattleGrid and for their reason:
     * it is arithmetic over a plan and a set of ids, so it can be swept in a
     * test and drawn on a bench without a party, a database or a delve.
     * tools/floorplan_preview.php calls this one rather than describing what it
     * does — a bench that reimplements the rule it is previewing is a bench
     * that agrees with itself and nothing else.
     *
     * @param array<int,bool> $visited location id => true
     */
    public static function fog(array $plan, array $visited): array
    {
        $seenRoom = [];
        foreach ($plan['rooms'] as $i => $r) {
            $id = $r['location_id'] ?? null;
            $seen = $id !== null && isset($visited[$id]);
            $plan['rooms'][$i]['seen'] = $seen;
            $plan['rooms'][$i]['glimpsed'] = false;
            $seenRoom[(int) $r['id']] = $seen;
        }

        // A passage is glimpsed from either room it joins; a room is glimpsed
        // from a passage that has been walked, which is the doorway at the end
        // of it. One pass each, in that order, because the second reads the
        // first — and no further: standing in a room does not show you what is
        // beyond the passage leaving it.
        $glimpsedRoom = [];
        foreach ($plan['corridors'] as $i => $c) {
            $id = $c['location_id'] ?? null;
            $seen = $id !== null && isset($visited[$id]);
            $touching = !empty($seenRoom[(int) $c['a']]) || !empty($seenRoom[(int) $c['b']]);
            // A PASSAGE BEHIND A SECRET DOOR IS NOT ON THE MAP. `found` is set
            // false above for a stuck door whose exits are still unfound, and
            // the run used to be drawn anyway — dimmed and doorless, on the
            // chart's standing rule that a way may be shown going somewhere it
            // will not name.
            //
            // That rule was written when the whole floor was drawn, where
            // hiding one run would have been the odd thing out. With the fog it
            // is the other way round: the map shows what has been walked, so a
            // dim line into the rock is the one mark on it that could only have
            // come from the GM's copy — and worse, a secret off a room you have
            // been in is exactly what `glimpsed` lights up as a way onward.
            // Finding it is the whole of what a secret door is for.
            $secret = ($c['found'] ?? true) === false;
            $plan['corridors'][$i]['seen'] = $seen;
            $plan['corridors'][$i]['glimpsed'] = !$seen && !$secret && $touching;
            if ($seen) {
                $glimpsedRoom[(int) $c['a']] = true;
                $glimpsedRoom[(int) $c['b']] = true;
            }
        }
        foreach ($plan['rooms'] as $i => $r) {
            if (!$plan['rooms'][$i]['seen'] && !empty($glimpsedRoom[(int) $r['id']])) {
                $plan['rooms'][$i]['glimpsed'] = true;
            }
        }

        return $plan;
    }

    /** Rock, in the tile layer. A space, so a row of it reads as nothing. */
    private const TILE_ROCK = ' ';

    /**
     * One character per location in the tile layer, in the order first met.
     *
     * A floor has at most eleven rooms and fourteen passages, so sixty-two
     * keys is room to spare; a level that somehow wanted more would ship the
     * overflow as rock rather than as the wrong place.
     */
    private const TILE_KEYS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * The raster, censored to what this party has earned, on the wire.
     *
     * Static and PDO-free beside fog(), for fog()'s reason — it is arithmetic
     * over a plan and a raster, so a test can sweep it without a party.
     *
     * IT READS FOG()'S ANSWER RATHER THAN ASKING THE QUESTION AGAIN. Whether a
     * shape may be drawn is already decided, on the server, from the room-to-
     * passage graph; a second rule here would be a second copy of the level's
     * topology, and the two would disagree the first time either was fixed. So
     * this only turns "may this shape be drawn" into "is there floor in this
     * tile", which is the same fact at a finer grain. Three things fall out of
     * that rather than being written:
     *
     *  - An unfound secret door's passage is neither seen nor glimpsed, so its
     *    tiles are rock AND its doorway is not in the list — the chamber wall
     *    is blank until somebody searches it, which is the whole of what a
     *    secret door is.
     *  - A glimpsed shape ships its floor. On the chart that state is an
     *    outline; in here there is no outline to draw, and a corridor you can
     *    see the mouth of is a corridor you can see down. The information is
     *    the same as the chart's, which is the line that has to hold.
     *  - A door whose far side is NOT shipped still ships, and still carries
     *    the location beyond it. That is not a leak: an unfound door is already
     *    gone, so every doorway left is one the exit graph will honour, and
     *    without the id on it a party could see an opening and not walk
     *    through. What they see past it is darkness, because the tiles beyond
     *    really are not there yet.
     *
     * ONE LAYER, NOT TWO. `rows` says both whether a tile is floor and whose it
     * is, because those are one fact and shipping them separately is two things
     * that can disagree. Two strings of 29 characters by 23 rows, a key list,
     * and a handful of faces: under two kilobytes, against the fifteen an array
     * of tile objects would cost.
     *
     * @param  array $tiles from DungeonGen::tiles(), uncensored
     * @param  array $plan  after fog(), with location ids resolved
     */
    public static function fogTiles(array $tiles, array $plan): array
    {
        $w = (int) $tiles['w'];
        $h = (int) $tiles['h'];

        // Every shape's location id, and the subset this party may see.
        $idOf = [];
        $show = [];
        $doorKind = [];

        foreach ($plan['rooms'] as $r) {
            $key = 'room:' . (int) $r['id'];
            $id = $r['location_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $idOf[$key] = (int) $id;
            if (($r['seen'] ?? true) || ($r['glimpsed'] ?? false)) {
                $show[$key] = (int) $id;
            }
        }
        foreach ($plan['corridors'] as $c) {
            $key = 'corridor:' . (int) $c['id'];
            $doorKind[(int) $c['id']] = $c['door'];
            $id = $c['location_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $idOf[$key] = (int) $id;
            if (($c['seen'] ?? true) || ($c['glimpsed'] ?? false)) {
                $show[$key] = (int) $id;
            }
        }

        $rows = [];
        $locs = [];
        $keyFor = [];
        for ($y = 0; $y < $h; $y++) {
            $row = '';
            for ($x = 0; $x < $w; $x++) {
                $o = $tiles['owner'][$y * $w + $x] ?? null;
                $id = $o === null ? null : ($show[$o[0] . ':' . $o[1]] ?? null);
                if ($id === null) {
                    $row .= self::TILE_ROCK;
                    continue;
                }
                if (!isset($keyFor[$id])) {
                    $ch = self::TILE_KEYS[count($keyFor)] ?? null;
                    if ($ch === null) {
                        $row .= self::TILE_ROCK;
                        continue;
                    }
                    $keyFor[$id] = $ch;
                    $locs[$ch] = $id;
                }
                $row .= $keyFor[$id];
            }
            $rows[] = $row;
        }

        $lit = static fn (int $tile): bool =>
            ($rows[intdiv($tile, $w)][$tile % $w] ?? self::TILE_ROCK) !== self::TILE_ROCK;

        $doors = [];
        foreach ($tiles['doors'] as $d) {
            // The side you would be standing on has to be somewhere you can be.
            if (!isset($show[$d['from'][0] . ':' . $d['from'][1]])) {
                continue;
            }
            // A stuck door nobody has found is not a door yet. playersMap()
            // nulls its kind; both sides of the threshold read the same field.
            foreach ([$d['from'], $d['to']] as $side) {
                if ($side[0] === 'corridor' && ($doorKind[$side[1]] ?? null) === null) {
                    continue 2;
                }
            }
            $doors[] = [
                't' => (int) $d['tile'],
                'd' => (int) $d['dir'],
                'k' => (string) $d['kind'],
                'to' => $idOf[$d['to'][0] . ':' . $d['to'][1]] ?? null,
            ];
        }

        // A partition only matters where there is floor on this side of it.
        $walls = [];
        foreach ($tiles['walls'] as $wall) {
            if ($lit((int) $wall['tile'])) {
                $walls[] = ['t' => (int) $wall['tile'], 'd' => (int) $wall['dir']];
            }
        }

        $stairs = [];
        foreach ($tiles['stairs'] as $s) {
            if ($lit((int) $s['tile'])) {
                $stairs[] = ['t' => (int) $s['tile'], 'd' => (string) $s['dir']];
            }
        }

        // Furnishings, censored by the same `$lit` the floor is.
        //
        // A chest in a room the party has not found is not shipped, so neither
        // renderer can draw it and neither has to know about fog — the same
        // arrangement that makes an unfound secret door a blank wall for free.
        $props = [];
        foreach ($tiles['props'] ?? [] as $prop) {
            if (!$lit((int) $prop['tile'])) {
                continue;
            }
            // The LOCATION, not the room number. Whether this one has been
            // opened is a party fact and is stamped on by map() a moment later,
            // which has the party and this does not — and the only handle it
            // has to stamp it with is the id every other shipped block is
            // keyed by.
            $props[] = [
                't' => (int) $prop['tile'],
                'k' => (string) $prop['kind'],
                'l' => $show['room:' . (int) $prop['room']] ?? null,
            ];
        }

        // Where a party arriving in a place is put down, by location id.
        $spines = [];
        foreach (['room', 'corridor'] as $kind) {
            foreach ($tiles['spines'][$kind] ?? [] as $shapeId => $tile) {
                $id = $show[$kind . ':' . $shapeId] ?? null;
                if ($id !== null) {
                    $spines[$id] = (int) $tile;
                }
            }
        }

        return [
            'w' => $w,
            'h' => $h,
            'sub' => (int) $tiles['sub'],
            'rows' => $rows,
            'locs' => $locs,
            'doors' => $doors,
            'walls' => $walls,
            'stairs' => $stairs,
            'props' => $props,
            'spines' => $spines,
        ];
    }

    // =======================================================================
    // Going down
    // =======================================================================

    /**
     * Begin a delve, or go one floor deeper.
     *
     * The seed is rolled once, when the delve begins, and every floor is
     * derived from it plus the depth — so a delve is one dungeon rather than a
     * series of unrelated ones, and the whole of it can be rebuilt from the two
     * numbers in `dungeon_delves`.
     *
     * The previous floor is dropped as the party leaves it. That is what makes
     * this affordable: at most one generated region exists per party at a time,
     * however deep they go. It also means there is no way back UP a floor,
     * which is a deliberate reading of a stair you have already come down —
     * the way out is the way out, from wherever you are.
     */
    public function descend(int $characterId, int $partyId): array
    {
        $delve = $this->current($partyId);

        if ($delve === null) {
            $mouthId = $this->mouthFor($characterId);
            if ($mouthId === null) {
                throw new InvalidArgumentException('There is no stair here.');
            }
            $seed = random_int(1, 0x7FFFFFFF);
            $depth = 1;
            // The mouth is recorded now, at the only moment anybody knows it:
            // the party is standing on it. Everything afterwards — which module
            // the floors belong to, where climbing out puts them — is read back
            // from this row rather than from a constant.
            $this->db->prepare(
                'INSERT INTO dungeon_delves (party_id, seed, depth, mouth_location_id) VALUES (?, ?, ?, ?)'
            )->execute([$partyId, $seed, $depth, $mouthId]);
            $delve = ['mouth_location_id' => $mouthId];
        } else {
            $seed = (int) $delve['seed'];
            $depth = (int) $delve['depth'] + 1;
            if ($depth > self::MAX_DEPTH) {
                throw new InvalidArgumentException('The stair ends here. There is nothing below.');
            }
        }

        $mouthId = $this->mouthOf($delve);
        $level = $this->draw($seed, $depth);
        if (!DungeonGen::isWalkable($level)) {
            // Cannot happen — the layout is joined by a spanning tree before
            // anything else — but a generated thing that reaches the database
            // should say so rather than write a floor nobody can cross.
            throw new RuntimeException('Generated a level that cannot be walked. Seed ' . $seed);
        }

        $old = (int) ($delve['region_id'] ?? 0) ?: null;
        $regionId = $this->write($partyId, $level, $mouthId);

        // The floor is kept, not just its seed. See the migration and levelFor():
        // a floor the map service drew cannot be recomputed on demand, because
        // the service is allowed to be missing.
        $this->db->prepare(
            'UPDATE dungeon_delves SET seed = ?, depth = ?, region_id = ?, level_json = ? WHERE party_id = ?'
        )->execute([
            $seed,
            $depth,
            $regionId,
            json_encode($level, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $partyId,
        ]);

        $entranceId = $this->locationIdOf($partyId, $depth, $level['entrance']);
        $this->moveParty($partyId, $entranceId);

        // Only after everybody is standing on the new floor, or dropping the
        // old one would take the ground out from under them.
        if ($old) {
            $this->dropRegion($old);
        }

        $this->markDepth($partyId, $depth);

        return [
            'ok' => true,
            'depth' => $depth,
            'seed' => $seed,
            'rooms' => count($level['rooms']),
            // Reported separately from the rooms because they are a separate
            // kind of place, and because the two together are what the region
            // actually holds — see test_delve.php, which counts location rows.
            'passages' => count($level['corridors']),
            'location_id' => $entranceId,
            'message' => $depth === 1
                ? 'You go down the stair. The cold closes over you.'
                : "You take the stair down. Level {$depth}.",
        ];
    }

    /**
     * The floor this delve is standing on.
     *
     * The stored one if there is one, and there is one for every floor written
     * since the map service arrived. Falls back to regenerating from the seed,
     * which is right for the delves that predate the column and for every floor
     * DungeonGen drew — those ARE their seed, and always will be.
     *
     * Everything that draws or asks about a floor comes through here, so the
     * chart, the stair and the room the party is standing in cannot disagree
     * about which dungeon they are in.
     */
    private function levelFor(array $delve): array
    {
        $stored = $delve['level_json'] ?? null;
        if (is_string($stored) && $stored !== '') {
            $level = json_decode($stored, true);
            if (is_array($level) && !empty($level['rooms'])) {
                return $level;
            }
            error_log('DelveEngine: stored level for party ' . ($delve['party_id'] ?? '?')
                . ' is unreadable; rebuilding from the seed.');
        }
        return DungeonGen::generate((int) $delve['seed'], (int) $delve['depth']);
    }

    /**
     * A floor: the map service's if it can draw one, this game's otherwise.
     *
     * The fallback is not an error path, it is the other half of the design.
     * A delve must be enterable on an install with no map service at all, on
     * one where the container is restarting, and on one where the service
     * answered with something that could not be made into a level — and in all
     * three the answer is the generator that drew every floor before it.
     */
    private function draw(int $seed, int $depth): array
    {
        if (MapService::available()) {
            $dungeon = MapService::floor($seed, $depth, self::KIND_FOR_DEPTH, [
                // Sized to the delve rather than to the service's defaults: a
                // floor is a night's work, not a fortress.
                //
                // The field is bigger than the floor needs to be, and that is
                // the point. It was 48x36 with the service's default pad of 1,
                // which packed the rooms in until the gaps between them were
                // one or two tiles — about 1.7 chart units once projected,
                // against a passage drawn 2.4 wide. A passage shorter than it
                // is wide is not a passage, it is a doorway, and the whole
                // level read as rooms sharing walls.
                //
                // Spreading the same rooms over 72x54 and holding them five
                // tiles apart puts the shortest passage at 5.5 to 6.6 units —
                // two and a half times its width — with the middle of the
                // range around 12 to 17. Measured over six seeds; it also
                // reaches the room count it asks for, which at 48x36 it
                // managed on one seed in four.
                //
                // Rooms shrink to about 10 units across, still four times the
                // width of a passage, so nothing has to be squinted at. The
                // grid travels with the level in `grid_w`/`grid_h` and
                // DungeonGen::gridOf reads it, so the chart, the fog raster
                // and the router all follow without being told.
                'cols' => 72,
                'rows' => 54,
                'roomPad' => 5,
                'roomCount' => DungeonGen::PROFILES[DungeonGen::profileForDepth($depth)]['rooms'][1],
            ]);
            if ($dungeon !== null) {
                $level = GeneratedLevel::fromDungeon($dungeon, $seed, $depth);
                if ($level !== null) {
                    return $level;
                }
                error_log("DelveEngine: the service's floor for seed {$seed} depth {$depth} "
                    . 'could not be made into a level; using DungeonGen.');
            }
        }
        return DungeonGen::generate($seed, $depth);
    }

    /**
     * Raise the high-water mark, never lower it.
     *
     * A party who reached the fifth floor, climbed out and started a fresh
     * delve is standing on level 1 and has still been to the bottom. Writing
     * the current depth unconditionally would take that back off them, and
     * would take it back off the quest they had already turned in.
     */
    private function markDepth(int $partyId, int $depth): void
    {
        $world = new WorldState($this->db);
        if ($world->number($partyId, self::DEPTH_FLAG) < $depth) {
            $world->set($partyId, self::DEPTH_FLAG, (string) $depth);
        }
    }

    /**
     * Leave, from wherever you are.
     *
     * Used by climbing out on purpose and by being carried out after a defeat,
     * which are the same operation: the party stands at the Mouth and the floor
     * they were on stops existing. Safe to call when there is no delve.
     */
    public function end(int $partyId, bool $moveParty = true): void
    {
        $delve = $this->current($partyId);
        if ($delve === null) {
            return;
        }
        if ($moveParty) {
            // Back to the stair they came down, not to a named one. A party
            // that walked into a hole in the Proving Yard must not surface in
            // the Undervault, which is what a constant here would have done the
            // moment a second mouth existed.
            $mouth = $this->mouthOf($delve);
            if ($mouth) {
                $this->moveParty($partyId, $mouth);
            }
        }
        // The region first, then the delve row: dropping the region while the
        // delve still points at it is what the ON DELETE SET NULL is for, and
        // doing it the other way round would leave the region unreferenced and
        // uncollected if the second statement failed.
        if (!empty($delve['region_id'])) {
            $this->dropRegion((int) $delve['region_id']);
        }
        // Every floor's forced-door and found-trap flags, not just the current
        // depth's: a delve that ended on level 4 walked through three others.
        // Its quests go the same way and for the same reason.
        for ($d = 1; $d <= self::MAX_DEPTH; $d++) {
            $this->clearFloorFlags($partyId, $d);
            $this->dropQuest(self::questKey($partyId, $d));
        }
        // Barricades, which clearFloorFlags cannot reach: they are keyed on the
        // two LOCATION IDS a doorway joins (see DoorEngine::barricadeFlag), not
        // on the _dg_party_depth_ pattern the other floor flags share. Ids are
        // never reused, so a stale one could not attach itself to a new door —
        // but it would sit in the table for ever, and a party that delves fifty
        // times would carry fifty floors of dead furniture around with it.
        $this->db->prepare(
            "DELETE FROM world_flags WHERE party_id = ? AND flag_key LIKE 'dg\\_barr\\_%'"
        )->execute([$partyId]);
        $this->db->prepare('DELETE FROM dungeon_delves WHERE party_id = ?')->execute([$partyId]);
    }

    // =======================================================================
    // Writing a floor
    // =======================================================================

    /**
     * Turn a generated level into rows, and hand back the region id.
     *
     * Everything is keyed from the party and the depth, so two parties delving
     * at once cannot collide and one party's floors cannot collide with each
     * other. The keys are deliberately readable — `_dg_12_3_room7` — because
     * the first thing anybody does with a broken delve is look in the database.
     */
    private function write(int $partyId, array $level, ?int $mouthId): int
    {
        $depth = (int) $level['depth'];
        $regionKey = self::regionKey($partyId, $depth);
        $moduleId = $mouthId === null ? $this->moduleId() : $this->moduleForMouth($mouthId);

        // Anything left from a previous visit to this depth. dropRegion() should
        // already have taken it, but a region removed by any other route — a
        // hand-run DELETE, a half-finished request — leaves encounter keys
        // behind, and the insert below is unique on that key. The dg_* flag
        // families go with it: a door forced and a trap sprung belong to the
        // FLOOR, and the keys are built from party and depth rather than the
        // seed, so a fresh delve reaching this depth would otherwise inherit
        // them — doors standing open on a floor where they were never forced.
        $this->db->prepare('DELETE FROM regions WHERE region_key = ?')->execute([$regionKey]);
        $this->db->prepare('DELETE FROM encounters WHERE encounter_key LIKE ?')
            ->execute(['_dg_' . $partyId . '_' . $depth . '_e%']);
        // And the floor's quest, for the same reason: its stages point at rooms
        // that are about to be deleted and rewritten.
        $this->dropQuest(self::questKey($partyId, $depth));
        $this->clearFloorFlags($partyId, $depth);
        $this->db->prepare(
            'INSERT INTO regions (region_key, module_id, name, description, region_type, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $regionKey,
            $moduleId,
            $this->floorName($mouthId, $depth),
            // The level's one atmosphere, stated where the floor announces
            // itself — the same pick that flavours the entrance and a third
            // of the passages, so the region says what they will then show.
            'Cut stone giving way to older cut stone: ' . $level['atmosphere']['label']
                . '. Nothing down here was meant to be found.',
            'dungeon',
            1000 + $depth,
        ]);
        $regionId = (int) $this->db->lastInsertId();

        $insert = $this->db->prepare(
            'INSERT INTO locations
                (location_key, region_id, name, description, first_visit_text,
                 location_type, map_x, map_y, allow_camp, sort_order,
                 trap_json, random_encounter_pct)
             VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($level['rooms'] as $room) {
            $insert->execute([
                self::roomKey($partyId, $depth, (int) $room['id']),
                $regionId,
                $room['name'],
                $room['description'],
                $room['role'] === 'stair' ? 'gate' : 'room',
                $room['x'],
                $room['y'],
                // You may catch your breath in an empty room, and nowhere else.
                // A long rest in the room you just cleared would make the whole
                // descent free, and a short one is the interesting decision.
                $room['kind'] === 'empty' ? 1 : 0,
                (int) $room['id'] * 10,
                null,
                // Rooms do not roll wandering fights: what stands in a room was
                // stocked there and cleared once. The corridors are where the
                // floor's own traffic finds you.
                0,
            ]);
        }

        // The passages, which are places now rather than lines between places.
        //
        // Positioned at the midpoint of their own drawn run, which comes from
        // plan() — the only thing in this file that knows where anything is on
        // the chart, and deliberately so. A passage that computed its own
        // position would be a second copy of the projection.
        //
        // No camping in a corridor, whatever is in it. Stopping for the night
        // in the passage outside the room you just cleared is the sort of thing
        // a rule should simply not allow.
        $plan = DungeonGen::plan($level);
        $mids = [];
        foreach ($plan['corridors'] as $c) {
            $mids[(int) $c['id']] = $c['mid'];
        }
        foreach ($level['corridors'] as $corridor) {
            $id = (int) $corridor['id'];
            // A corridor whose rooms overlap on the plan is not drawn — see
            // clipEnds() — and a passage with no shape has nowhere to be. Its
            // two rooms are joined directly instead; see writeExits(). A trap
            // dressed onto such a corridor goes with it: a trap with no
            // passage has nowhere to stand and nobody to bite.
            if (!isset($mids[$id])) {
                continue;
            }
            $insert->execute([
                self::passageKey($partyId, $depth, $id),
                $regionId,
                $corridor['name'],
                $corridor['description'],
                'passage',
                $mids[$id][0],
                $mids[$id][1],
                0,
                1000 + $id * 10,
                isset($corridor['trap']) ? json_encode($corridor['trap'], JSON_UNESCAPED_SLASHES) : null,
                self::wanderChance($depth),
            ]);
        }

        $this->writeExits($partyId, $level, $regionId, $mouthId);
        $this->stock($partyId, $level, $regionId);
        $this->writeQuest($partyId, $level);
        $this->assertContained($regionId, $moduleId);

        return $regionId;
    }

    /**
     * The floor's own errand, in the journal.
     *
     * The map service invents a quest with every floor it draws — a name, a
     * hook, a few steps pinned to particular rooms, and a twist. Written here
     * as ordinary `quests` and `quest_stages` rows, so the journal, the tracker
     * and the map markers all work on it with no special case, exactly the way
     * the floor itself is ordinary `locations`.
     *
     * NOTHING IS INVENTED AS A REWARD. Zero XP and zero gold, deliberately, and
     * for the reason the rest of this engine gives: the pay for a floor is the
     * monsters standing on it, counted by CombatEngine. A quest that paid on top
     * would make the delve the only content worth playing.
     *
     * Keyed `_dg_q_<party>_<depth>` and deleted with the floor — see
     * dropQuest(). A quest whose rooms have been deleted is a journal entry
     * pointing at nowhere.
     */
    private function writeQuest(int $partyId, array $level): void
    {
        $depth = (int) $level['depth'];
        $quest = $level['quest'] ?? null;
        if (!is_array($quest) || empty($quest['steps'])) {
            return;
        }

        // Only the steps that are on THIS floor. The generator writes quests for
        // a building with storeys and can point a step at the one below; a delve
        // draws each floor separately, so a step that names another one has no
        // room here to stand in.
        $steps = [];
        foreach ($quest['steps'] as $step) {
            $room = (int) ($step['room'] ?? -1);
            $text = trim((string) ($step['text'] ?? ''));
            $where = $this->locationIdByKey(self::roomKey($partyId, $depth, $room));
            if ($room < 0 || $text === '' || $where === null) {
                continue;
            }
            $steps[] = ['text' => $text, 'location' => $where];
        }
        if ($steps === []) {
            return;
        }

        $key = self::questKey($partyId, $depth);
        $this->dropQuest($key);

        $this->db->prepare(
            'INSERT INTO quests
                (quest_key, title, description, act, on_job_board,
                 required_level, reward_xp, reward_gold, target_location_id, is_active)
             VALUES (?, ?, ?, 1, 0, 1, 0, 0, ?, 1)'
        )->execute([
            $key,
            mb_substr(trim((string) ($quest['name'] ?? 'Unfinished business')), 0, 150),
            trim((string) ($quest['hook'] ?? '')),
            $steps[count($steps) - 1]['location'],
        ]);
        $questId = (int) $this->db->lastInsertId();

        $insert = $this->db->prepare(
            'INSERT INTO quest_stages
                (quest_id, stage_key, title, objective, journal_entry,
                 target_location_id, is_terminal, outcome, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($steps as $i => $step) {
            $last = $i === count($steps) - 1;
            $insert->execute([
                $questId,
                's' . ($i + 1),
                mb_substr($step['text'], 0, 150),
                $step['text'],
                // The twist is kept back for the last step, which is the only
                // place it can land as a turn rather than as a spoiler in the
                // journal from the moment the party walks in.
                $last && !empty($quest['twist'])
                    ? $step['text'] . ' — ' . trim((string) $quest['twist'])
                    : $step['text'],
                $step['location'],
                $last ? 1 : 0,
                'success',
                ($i + 1) * 10,
            ]);
        }

        // Begun at once, rather than waiting to be found. There is nobody down
        // here to hand out work: the party goes down the stair already knowing
        // what the place is for, which is what the hook says. Advancing to the
        // first stage is what starts it — see QuestService::advance.
        (new QuestService($this->db))->advance($partyId, $key, 's1');
    }

    /** A generated quest's key. Readable, and prefixed like everything else generated. */
    public static function questKey(int $partyId, int $depth): string
    {
        return "_dg_q_{$partyId}_{$depth}";
    }

    /**
     * Take a generated quest out again, stages, progress and all.
     *
     * `quest_stages.target_location_id` is ON DELETE SET NULL, which is right
     * for the authored world — deleting a room should not destroy a hand-written
     * quest — and wrong here, where the quest exists only to point at rooms that
     * are about to stop existing. The same argument dropRegion() makes about
     * encounters, and the same answer.
     */
    private function dropQuest(string $key): void
    {
        $stmt = $this->db->prepare('SELECT id FROM quests WHERE quest_key = ?');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return;
        }
        $id = (int) $id;
        foreach (['party_quest_stages', 'party_quests'] as $table) {
            $this->db->prepare("DELETE FROM {$table} WHERE quest_id = ?")->execute([$id]);
        }
        $this->db->prepare('DELETE FROM quest_stages WHERE quest_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM quests WHERE id = ?')->execute([$id]);
    }

    /**
     * Room to passage to room, both ways, plus the way back up.
     *
     * Authored content declares each direction separately and the loader warns
     * about a one-way exit; a generated dungeon has no author to warn, so both
     * halves are written from one edge here.
     *
     * A PASSAGE SITS IN THE MIDDLE OF EVERY JOIN. Rooms used to be wired to
     * each other and the corridor was a line drawn between them; now the
     * corridor is a location and the wiring is A→C, C→B and back. That is the
     * whole of what makes a hallway somewhere you can be — travel is a BFS over
     * these rows, so a passage in the graph is a passage you walk through,
     * with its own description, its own search, and its own chance to be
     * standing in it when something comes the other way.
     *
     * The door belongs to the room end of the join rather than to both. A stuck
     * door is a way you have to force, and forcing it should get you out of the
     * room; having to force it again to leave the corridor would be the same
     * door twice.
     */
    private function writeExits(int $partyId, array $level, int $regionId, ?int $mouthId): void
    {
        $depth = (int) $level['depth'];
        $id = fn (int $room) => $this->locationIdOf($partyId, $depth, $room);

        $insert = $this->db->prepare(
            'INSERT INTO location_exits
                (from_location_id, to_location_id, label, is_hidden, sort_order, conditions_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($level['corridors'] as $i => $corridor) {
            $a = $id((int) $corridor['a']);
            $b = $id((int) $corridor['b']);
            $label = self::doorLabel((string) $corridor['door']);
            // A stuck door is the only kind that hides an exit: a way you have
            // to force is a way you have to find. Searching reveals it, which
            // is what `is_hidden` already means everywhere else.
            $hidden = $corridor['door'] === 'stuck' ? 1 : 0;
            // Locked and portcullis BLOCK instead: the way is drawn, named and
            // shut, until the party forces it. The gate is a per-party flag
            // through the same conditions_json every authored locked way uses —
            // Requirements::pass reads it, exitsFrom marks the exit locked, the
            // chart greys it, and travel refuses it, all for free. Both room-end
            // rows share one flag because they are one door: forced is forced
            // from either side. api `location/force_door` is what sets it.
            $conds = in_array($corridor['door'], ['locked', 'portcullis'], true)
                // A LIST of one condition — Requirements::pass iterates a
                // list, and a bare object would read as key=>string and fail
                // closed forever, which is a door no die can open.
                ? json_encode([['flag' => self::openFlag($partyId, $depth, (int) $corridor['id'])]])
                : null;

            // A join whose passage was not drawable — two rooms overlapping on
            // the plan — has no location to route through, so the rooms are
            // wired to each other as they always were. Rare, and better than a
            // way through the level that silently does not exist.
            $mid = $this->locationIdByKey(self::passageKey($partyId, $depth, (int) $corridor['id']));
            if ($mid === null) {
                $insert->execute([$a, $b, $label, $hidden, $i * 10, $conds]);
                $insert->execute([$b, $a, $label, $hidden, $i * 10, $conds]);
                continue;
            }

            $on = self::PASSAGE_LABEL;
            $insert->execute([$a, $mid, $label, $hidden, $i * 10, $conds]);
            $insert->execute([$mid, $a, $on, 0, $i * 10, null]);
            $insert->execute([$mid, $b, $on, 0, $i * 10 + 1, null]);
            $insert->execute([$b, $mid, $label, $hidden, $i * 10 + 1, $conds]);
        }

        // Out. From the entrance room only, and back to the authored Mouth —
        // the one exit in a generated level that leaves the generated region,
        // and the reason assertContained() checks the module rather than the
        // region.
        if ($mouthId) {
            $insert->execute([
                $id((int) $level['entrance']),
                $mouthId,
                $depth === 1 ? 'Back up the stair, into the daylight' : 'Back up the stair, and keep going up',
                0,
                -10,
                null,
            ]);
        }
    }

    /**
     * Put something in the rooms that hold something.
     *
     * The budget is the pit's, because it is the game's: a room's tier comes
     * from how deep the floor is, and EncounterBudget turns that plus the
     * party's levels into an XP allowance. Nothing here invents a reward — the
     * XP and the gold are the monsters' own, paid by CombatEngine exactly as
     * for an authored fight, which is the rule PitEngine states and the reason
     * a delve cannot out-earn the world.
     */
    private function stock(int $partyId, array $level, int $regionId): void
    {
        $depth = (int) $level['depth'];
        $tier = DungeonGen::PROFILES[$level['profile']]['tier'];
        $levels = $this->partyLevels($partyId);
        $budget = self::roomBudget($levels, $tier, $depth);
        $roster = $this->roster();
        if (!$roster) {
            return;                       // a bestiary with nothing in it
        }

        $encounter = $this->db->prepare(
            'INSERT INTO encounters
                (encounter_key, name, description, location_id, region_id,
                 is_random, is_ambush, difficulty, allow_flee, allow_parley)
             VALUES (?, ?, ?, ?, NULL, 0, 1, ?, 1, 0)'
        );
        $member = $this->db->prepare(
            'INSERT INTO encounter_monsters (encounter_id, monster_id, quantity) VALUES (?, ?, ?)'
        );

        foreach ($level['rooms'] as $room) {
            if (!in_array($room['kind'], ['monster', 'hoard', 'boss'], true)) {
                continue;
            }
            // A boss is the floor's fight and gets the whole hard budget
            // whatever tier the floor is otherwise on.
            $roomBudget = $room['kind'] === 'boss'
                ? self::roomBudget($levels, 'hard', $depth)
                : $budget;
            $group = $this->pick($roster, $roomBudget);
            if (!$group) {
                continue;
            }

            $locationId = $this->locationIdOf($partyId, $depth, (int) $room['id']);
            $encounter->execute([
                self::encounterKey($partyId, $depth, (int) $room['id']),
                $room['name'],
                'Something is already here.',
                $locationId,
                $room['kind'] === 'boss' ? 'hard' : $tier,
            ]);
            $encounterId = (int) $this->db->lastInsertId();
            foreach ($group as $g) {
                $member->execute([$encounterId, $g['id'], $g['quantity']]);
            }
        }

        $this->wander($partyId, $level, $regionId, $levels);
        $this->strew($partyId, $level);
    }

    /**
     * What a wanderer is doing when you meet it.
     *
     * The name of a wandering encounter carries this — "4 x Rat, hunting for
     * food" — which is the work donjon's tables do: an encounter with a reason
     * to exist rather than a die result. Shared with the printed book, whose
     * wandering table is the same list read by a referee instead of by the
     * travel roll.
     */
    public const DOINGS = [
        'hunting for food',
        'fleeing something worse',
        'fighting over the spoils when you interrupt',
        'that heard the door',
        'dragging something back the way you came',
    ];

    /**
     * The chance, per hop through a passage, that something is already in it.
     *
     * Deeper floors are busier — their traffic is what the deep table's names
     * are about — but the chance stays a seasoning: at 6 + 2xdepth a full
     * crossing of a middle floor springs roughly one interrupt.
     *
     * A named number rather than a literal in write() because the printed book
     * has to state it, and a book that told a referee the wrong odds would be
     * wrong in the one place nobody can check it against the code.
     */
    public static function wanderChance(int $depth): int
    {
        return 6 + 2 * $depth;
    }

    /**
     * What a stocked room is sized against.
     *
     * Named rather than inline because the printed book stocks a specimen
     * delve's rooms too, and it has to arrive at the same numbers this does.
     *
     * @param int[] $levels one per party member
     */
    public static function roomBudget(array $levels, string $tier, int $depth): int
    {
        return self::scaled(EncounterBudget::forParty($levels, $tier), $depth);
    }

    /**
     * What a wandering group is sized against: six tenths of a room fight.
     *
     * An interrupt pays no treasure and guards no stair, so it should not cost
     * what a stocked room costs. Shared with the book for the same reason
     * wanderChance() is.
     *
     * @param int[] $levels one per party member
     */
    public static function wanderBudget(array $levels, string $tier, int $depth): int
    {
        return intdiv(self::roomBudget($levels, $tier, $depth) * 6, 10);
    }

    /**
     * The floor's own traffic: two or three fights with no address.
     *
     * These are the region's `is_random` pool, which LocationEngine's travel
     * roll draws from per hop — machinery that has existed all along and that
     * generated floors simply never fed. The passages carry the roll chance
     * (see write()); rooms deliberately do not.
     *
     * The names carry what the wanderers are DOING, which is doing the work
     * donjon's wandering tables do: "4 x Rat, hunting for food" is an
     * encounter with a reason to exist, where "Rats" is a die result. Sized
     * under the room fights — an interrupt pays no treasure and guards no
     * stair, so it should not cost what a stocked room costs.
     */
    private function wander(int $partyId, array $level, int $regionId, array $levels): void
    {
        $roster = $this->roster();
        if (!$roster) {
            return;
        }
        $depth = (int) $level['depth'];
        $tier = DungeonGen::PROFILES[$level['profile']]['tier'];
        $budget = self::wanderBudget($levels, $tier, $depth);


        $encounter = $this->db->prepare(
            'INSERT INTO encounters
                (encounter_key, name, description, location_id, region_id,
                 is_random, is_ambush, difficulty, allow_flee, allow_parley)
             VALUES (?, ?, ?, NULL, ?, 1, 0, ?, 1, 0)'
        );
        $member = $this->db->prepare(
            'INSERT INTO encounter_monsters (encounter_id, monster_id, quantity) VALUES (?, ?, ?)'
        );

        $count = random_int(2, 3);
        for ($n = 0; $n < $count; $n++) {
            $group = $this->pick($roster, $budget);
            if (!$group) {
                continue;
            }
            $doing = self::DOINGS[random_int(0, count(self::DOINGS) - 1)];
            $encounter->execute([
                self::wanderKey($partyId, $depth, $n),
                $group[0]['name'] . ', ' . $doing,
                'They were not waiting for you. That is the only comfort in it.',
                $regionId,
                $tier,
            ]);
            $encounterId = (int) $this->db->lastInsertId();
            foreach ($group as $g) {
                $member->execute([$encounterId, $g['id'], $g['quantity']]);
            }
        }
    }

    /**
     * Put something in the rooms that promise something.
     *
     * The stocking table calls nearly a quarter of every floor `treasure` or
     * `hoard`, and their prose says so out loud — "There is something here
     * worth stooping for", "Something has made a nest here, and lined it".
     * Nothing was ever placed. A `treasure` room was the worse of the two: it
     * is not in the encounter list either, so it had no fight, no item and a
     * description promising one. The whole of a delve's reward was XP, the
     * gold every fight pays out, and nothing you could carry.
     *
     * This is the same fault the Undervault's surface shipped with, which
     * CLAUDE.md records: prose describing a thing to do, and no row behind it.
     *
     * Value bands by depth rather than a flat roll, so descending is worth
     * something. A `hoard` is what a monster was sitting on and is drawn from
     * the same band a level deeper — that is what makes fighting for it
     * different from finding it.
     */
    private function strew(int $partyId, array $level): void
    {
        $depth = (int) $level['depth'];
        $insert = $this->db->prepare(
            'INSERT INTO location_items (location_id, item_id) VALUES (?, ?)'
        );

        // Nothing found twice on one floor. The bands are narrow at the top of
        // the dungeon — a dozen items are worth twenty-five gold or less — so
        // independent draws put the same Drainman's Oilskin in both of a
        // level's two treasure rooms often enough to notice, and a floor whose
        // two finds are the same object reads as a generator repeating itself
        // rather than as a place.
        $taken = [];

        foreach ($level['rooms'] as $room) {
            $kind = (string) $room['kind'];
            if ($kind !== 'treasure' && $kind !== 'hoard' && $kind !== 'boss') {
                continue;
            }
            // A hoard is guarded, so it is worth a level more than loose
            // treasure; a boss is sitting on the best thing on the floor.
            $band = $depth + ($kind === 'treasure' ? 0 : 1) + ($kind === 'boss' ? 1 : 0);
            $items = $this->treasureFor($band, $kind === 'boss' ? 2 : 1, $taken);
            if (!$items) {
                continue;
            }
            $locationId = $this->locationIdOf($partyId, $depth, (int) $room['id']);
            foreach ($items as $itemId) {
                $insert->execute([$locationId, $itemId]);
                $taken[$itemId] = true;
            }
        }
    }

    /**
     * Items worth roughly what this depth should be paying.
     *
     * Drawn from the item table by value rather than from a hand-written drop
     * list, for the reason the monster picker is: a list here would be a
     * second copy of the catalogue, and an item added to the world would never
     * find its way underground.
     *
     * The band is generous at the bottom — anything cheaper than the ceiling
     * is eligible — because a floor that only ever pays its exact rate reads
     * as a vending machine. What descending buys is a higher ceiling, not a
     * higher floor.
     *
     * @param  array<int,true> $taken ids already found on this floor
     * @return list<int> item ids
     */
    private function treasureFor(int $band, int $count, array $taken = []): array
    {
        $ceiling = self::TREASURE_CEILING[min($band, count(self::TREASURE_CEILING) - 1)];

        $sql = 'SELECT id FROM items WHERE value_gp > 0 AND value_gp <= ?';
        $args = [$ceiling];
        if ($taken) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($taken), '?')) . ')';
            $args = array_merge($args, array_keys($taken));
        }
        $sql .= ' ORDER BY RAND() LIMIT ' . max(1, $count);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // A band small enough to be exhausted by one floor falls back to
        // repeating rather than to an empty room, because the room's own prose
        // has already promised there is something here.
        if (!$ids && $taken) {
            return $this->treasureFor($band, $count);
        }
        return $ids;
    }

    /**
     * The same budget, weighed against how far down it is being spent.
     *
     * The tier table stops at `deep`, which every floor from the third down
     * shares — so without this, floor three and floor nine are the same fight
     * against a party who have levelled in between and are now walking through
     * it. The tiers themselves already went up a step (see DungeonGen::PROFILES);
     * this is what keeps descending meaningful once they have.
     *
     * An eighth per floor below the first, and capped at double. The cap is the
     * point: a delve has no bottom, the multiplier is compound-looking, and a
     * floor twenty that asked for eight times a hard fight would not be
     * difficulty, it would be a wall. Two hard fights' worth of XP through 5e's
     * group multiplier is already the far end of what a party can take.
     */
    private static function scaled(int $budget, int $depth): int
    {
        $factor = 1.0 + 0.125 * max(0, $depth - 1);
        return (int) round($budget * min($factor, 2.0));
    }

    /**
     * How many bodies a room holds, before what they are.
     *
     * Weighted toward a handful: mostly two to four, sometimes a lone big
     * thing, occasionally a crowd. Read by pick(), which rolls the SHAPE of
     * the fight first and only then asks what fits it.
     */
    private const GROUP_SIZES = [1, 2, 2, 3, 3, 3, 4, 4, 5, 6];

    /**
     * What stands in one room, against one budget.
     *
     * ROLL THE SIZE FIRST. This used to take the top third of everything the
     * budget could afford and then ask how many of it fit — which, since the
     * top of that list is by definition the thing that eats the budget on its
     * own, answered "one" almost every time. Every stocked room on every floor
     * was the party against a single large creature: the picker's own comment
     * said it was biased toward the largest thing that fits, and that bias
     * turned out to be the whole of the fight. Raising the budgets made it
     * worse, because a bigger budget only widens the list whose top third gets
     * picked from.
     *
     * So a size is rolled, the per-body allowance is worked out for that size —
     * through EncounterBudget's own multiplier, because six bodies cost more
     * than six times one — and the species is chosen from the top third of what
     * fits AT THAT SIZE. The original intent survives intact: a party that has
     * outgrown rats still meets the biggest thing the room can hold, there are
     * just several of it.
     *
     * Falls down through the sizes rather than giving up: a budget that cannot
     * afford six of anything can usually afford three, and a room the map has
     * promised is occupied must not come out empty.
     *
     * @return list<array{id:int, quantity:int}>
     */
    private function pick(array $roster, int $budget): array
    {
        return self::chooseGroup($roster, $budget, static fn (int $lo, int $hi): int
            => random_int($lo, $hi));
    }

    /**
     * pick(), with the chance passed in rather than taken from the system.
     *
     * The RULE is the same wherever a group is chosen; the SOURCE of the roll
     * is not. A delve stocks itself from `random_int` at the moment a party
     * walks down the stair, and the result is written into rows because it
     * cannot be recovered from the seed. The printed book has the opposite
     * requirement — a wandering table has to come out the same every time the
     * same seed is printed — so it deals from DungeonGen's seeded stream
     * instead. Splitting the two here is what stops the book growing a second,
     * drifting copy of the sizing rule; see AdventureBook::wanderers().
     *
     * Pure but for `$rand`, so nothing about it needs a database.
     *
     * @param  callable(int,int):int $rand inclusive, both ends
     * @return list<array{id:int, name:string, quantity:int}>
     */
    public static function chooseGroup(array $roster, int $budget, callable $rand): array
    {
        if (!$roster) {
            return [];
        }
        $want = self::GROUP_SIZES[$rand(0, count(self::GROUP_SIZES) - 1)];

        for ($n = min($want, self::MAX_FOES); $n >= 1; $n--) {
            $each = (int) floor($budget / ($n * EncounterBudget::multiplier($n)));
            $fits = array_values(array_filter(
                $roster,
                static fn ($m) => (int) $m['xp'] > 0 && (int) $m['xp'] <= $each
            ));
            if (!$fits) {
                continue;
            }
            // The top third of what fits at this size, so a room is not always
            // the biggest thing affordable and not always the smallest. The
            // roster arrives sorted by XP descending.
            $band = array_slice($fits, 0, max(1, (int) ceil(count($fits) / 3)));
            $pick = $band[$rand(0, count($band) - 1)];
            // Asked rather than assumed: bestCount() re-measures the group at
            // each step through the same multiplier, so it is the authority on
            // whether the size that was rolled actually fits.
            $qty = EncounterBudget::bestCount((int) $pick['xp'], $budget, $n, 0, 0, true);
            return [[
                'id' => (int) $pick['id'],
                'key' => (string) ($pick['monster_key'] ?? ''),
                'name' => (string) $pick['name'],
                'quantity' => max(1, $qty),
            ]];
        }

        // Nothing fits at any size: send the cheapest thing in the bestiary
        // rather than an empty room that the map has already promised is
        // occupied.
        $cheapest = $roster[count($roster) - 1];
        return [[
            'id' => (int) $cheapest['id'],
            'key' => (string) ($cheapest['monster_key'] ?? ''),
            'name' => (string) $cheapest['name'],
            'quantity' => 1,
        ]];
    }

    // =======================================================================
    // The boundary, restated in code
    // =======================================================================

    /**
     * Every way out of this region stays inside this module.
     *
     * The check tools/load_content.py performs over the authored world, done
     * over one generated region at the moment it is written. It exists because
     * that loader will never see these rows: the static guarantee stops at the
     * file system, and without this the first bug that wrote a stray exit would
     * put a party into another game with nothing to catch it.
     *
     * Throws rather than repairing. A generated level that reaches out of its
     * module is a programming error, not a bad roll, and the right answer to it
     * is a failed request rather than a quietly mended dungeon.
     */
    private function assertContained(int $regionId, int $moduleId): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM location_exits e
               INNER JOIN locations lf ON lf.id = e.from_location_id
               INNER JOIN locations lt ON lt.id = e.to_location_id
               INNER JOIN regions rf ON rf.id = lf.region_id
               INNER JOIN regions rt ON rt.id = lt.region_id
              WHERE (rf.id = ? OR rt.id = ?)
                AND (rf.module_id <> ? OR rt.module_id <> ?)'
        );
        $stmt->execute([$regionId, $regionId, $moduleId, $moduleId]);
        $strays = (int) $stmt->fetchColumn();
        if ($strays > 0) {
            $this->dropRegion($regionId);
            throw new RuntimeException(
                "A generated level joined another module ({$strays} exit(s)). Refusing to open it."
            );
        }
    }

    // =======================================================================
    // Plumbing
    // =======================================================================

    public static function regionKey(int $partyId, int $depth): string
    {
        return "_dg_{$partyId}_{$depth}";
    }

    public static function roomKey(int $partyId, int $depth, int $room): string
    {
        return "_dg_{$partyId}_{$depth}_r{$room}";
    }

    /**
     * A passage's key. `c` where a room is `r`, and the two never collide.
     *
     * Rooms and passages number from zero independently, so the prefix letter
     * is the only thing separating room 3 from passage 3 — which is why it is
     * a letter and not, say, an offset added to the id. An offset is a rule you
     * have to remember; a letter is one you can read off the key in a table.
     */
    public static function passageKey(int $partyId, int $depth, int $corridor): string
    {
        return "_dg_{$partyId}_{$depth}_c{$corridor}";
    }

    public static function encounterKey(int $partyId, int $depth, int $room): string
    {
        return "_dg_{$partyId}_{$depth}_e{$room}";
    }

    /**
     * A wandering encounter's key. `ew` sorts into the same `_e%` family the
     * pre-clean and dropRegion sweeps already delete by pattern, which is not
     * an accident: a key scheme the cleanup does not know about is a leak.
     */
    public static function wanderKey(int $partyId, int $depth, int $n): string
    {
        return "_dg_{$partyId}_{$depth}_ew{$n}";
    }

    /**
     * The flag a forced door sets, and travel's conditions read.
     *
     * Built on the passage key so it names the door it opens, and cleared with
     * the floor by clearFloorFlags() — the key carries party and depth but not
     * the seed, so left behind it would unlock a door on the NEXT delve's
     * version of this floor, one nobody forced.
     */
    public static function openFlag(int $partyId, int $depth, int $corridor): string
    {
        return 'dg_open_' . self::passageKey($partyId, $depth, $corridor);
    }

    /**
     * The flag a trap's state lives under: unset, 'found', or 'sprung'.
     * Keyed by the passage's location_key — the trap row is on that location.
     */
    public static function trapFlag(string $locationKey): string
    {
        return 'dg_trap_' . $locationKey;
    }

    /**
     * The dg_open_ flag of a forceable exit, or null for every other kind.
     *
     * "Forceable" means the exit's WHOLE condition is the one flag this
     * engine writes: a list of exactly one bare flag test, named to the
     * convention. An authored locked way — gating on story, or on anything
     * more than the flag — returns null and is never offered to a shoulder.
     * The one recognizer, used by the offer and by the exit listing, so the
     * two cannot drift about what counts as a door you may put a boot to.
     */
    public static function forceFlagOf(?string $conditionsJson): ?string
    {
        if (!$conditionsJson) {
            return null;
        }
        $conds = json_decode($conditionsJson, true);
        if (!is_array($conds) || count($conds) !== 1 || !isset($conds[0])) {
            return null;
        }
        $one = $conds[0];
        if (!is_array($one) || count($one) !== 1) {
            return null;
        }
        $flag = $one['flag'] ?? null;
        return is_string($flag) && preg_match('/^dg_open__dg_\d+_\d+_c\d+$/', $flag)
            ? $flag
            : null;
    }

    /**
     * Forget everything the party did TO one floor: doors forced, traps found.
     *
     * LIKE with the underscores escaped, because every one of these keys is
     * mostly underscores and an unescaped pattern would happily match a
     * different party's floor ten depths away.
     */
    private function clearFloorFlags(int $partyId, int $depth): void
    {
        $suffix = str_replace('_', '\\_', "_dg_{$partyId}_{$depth}_") . '%';
        $this->db->prepare(
            "DELETE FROM world_flags WHERE party_id = ?
              AND (flag_key LIKE ? OR flag_key LIKE ?)"
        )->execute([$partyId, 'dg\\_open\\_' . $suffix, 'dg\\_trap\\_' . $suffix]);
    }

    /** What the player clicks to go through a door. */
    private static function doorLabel(string $door): string
    {
        return match ($door) {
            'door'       => 'Through the door',
            'trapped'    => 'Through the door',   // the surprise is the point
            'stuck'      => 'Force the jammed door',
            'arch'       => 'Under the arch',
            'locked'     => 'Through the locked door',
            'portcullis' => 'Under the portcullis',
            default      => 'Along the passage',
        };
    }

    /**
     * What a generated floor is called.
     *
     * Named after the stair it hangs from — "Below the Proving Yard, level 2" —
     * because a delve is now a thing any place can have and "The Undervault"
     * was only ever true of one of them. The mouth's own name is the one word
     * the player already associates with the hole they walked into.
     */
    private function floorName(?int $mouthId, int $depth): string
    {
        $where = null;
        if ($mouthId !== null) {
            $stmt = $this->db->prepare('SELECT name FROM locations WHERE id = ?');
            $stmt->execute([$mouthId]);
            $where = $stmt->fetchColumn() ?: null;
        }
        return $where === null
            ? 'The deep, level ' . $depth
            : 'Below ' . $where . ', level ' . $depth;
    }

    private function moduleId(): int
    {
        $stmt = $this->db->prepare('SELECT id FROM modules WHERE module_key = ?');
        $stmt->execute([self::MODULE_KEY]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException('The Undervault module is not loaded.');
        }
        return $id;
    }

    private function locationIdOf(int $partyId, int $depth, int $room): int
    {
        return $this->locationIdByKey(self::roomKey($partyId, $depth, $room))
            ?? throw new RuntimeException("Generated room {$room} is missing from the database.");
    }

    private function locationIdByKey(string $key): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM locations WHERE location_key = ?');
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return int[] */
    private function partyLevels(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.level FROM characters c
               INNER JOIN character_party cp ON cp.character_id = c.id
              WHERE cp.party_id = ? AND c.is_active = 1'
        );
        $stmt->execute([$partyId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The bestiary, dearest first, so `pick()` can take from the top. */
    public function roster(): array
    {
        // `monster_key` is not for the engine — a delve refers to monsters by
        // id — but for the printed book, which has to find a stat block for
        // everything a specimen delve sends. Carried here rather than looked up
        // again so that the bestiary in the book and the creature in the room
        // cannot be two different answers.
        return $this->db->query(
            'SELECT id, monster_key, name, experience_points AS xp FROM monsters
              WHERE experience_points > 0 ORDER BY experience_points DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function moveParty(int $partyId, int $locationId): void
    {
        $this->db->prepare(
            'UPDATE characters c
               INNER JOIN character_party cp ON cp.character_id = c.id
                SET c.current_location_id = ?
              WHERE cp.party_id = ?'
        )->execute([$locationId, $partyId]);
    }

    /**
     * Take a floor away, and everything that only existed to be on it.
     *
     * Locations and exits cascade from the region. Encounters DO NOT: their
     * foreign key is ON DELETE SET NULL, which is right for authored content —
     * deleting a location should not destroy a hand-written fight — and wrong
     * for a generated one, which exists only to stand in a room that is about
     * to stop existing.
     *
     * Left alone they outlive the region as orphans with a null location,
     * accumulate a set per delve, and then collide by key the next time the
     * same party reaches the same depth. That is exactly how this was found.
     */
    private function dropRegion(int $regionId): void
    {
        $this->db->prepare(
            'DELETE FROM encounters
              WHERE location_id IN (SELECT id FROM locations WHERE region_id = ?)'
        )->execute([$regionId]);
        // The wandering pool is scoped by region and has no location, so the
        // delete above never sees it — and its foreign key is the same
        // ON DELETE SET NULL that orphaned the placed fights before it.
        $this->db->prepare('DELETE FROM encounters WHERE region_id = ?')->execute([$regionId]);
        $this->db->prepare('DELETE FROM regions WHERE id = ?')->execute([$regionId]);
    }
}
