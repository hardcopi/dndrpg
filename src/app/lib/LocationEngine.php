<?php
/**
 * The world as a graph of described scenes.
 *
 * Replaces MapEngine. There is no grid: a character stands in a location, a
 * location lists its people and names its exits, and travel is a walk over the
 * exit graph — validated, narrated hop by hop, and interruptible by a random
 * encounter on dangerous ground. Everything a party changes about the world
 * (fights cleared, loot taken, places seen, hidden ways found) is per-party
 * state in its own table, so content rows are never mutated by play.
 */

declare(strict_types=1);

class LocationEngine
{
    /**
     * What findPath() is allowed to walk through. The two kinds of gate are
     * separate because they fail differently to the player: a locked exit is
     * drawn on the chart and says so, and a hidden one is not there at all
     * until searched. Only noRouteMessage() ever lifts either.
     */
    private const GATES_NONE   = 0;
    private const GATES_HIDDEN = 1;
    private const GATES_LOCKED = 2;
    private const GATES_ALL    = self::GATES_HIDDEN | self::GATES_LOCKED;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // -----------------------------------------------------------------------
    // Character / party plumbing
    // -----------------------------------------------------------------------

    public function getCharacterPosition(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, current_location_id, current_hp, max_hp, gold, level, experience
             FROM characters WHERE id = ?'
        );
        $stmt->execute([$characterId]);
        $c = $stmt->fetch();
        if (!$c) {
            throw new RuntimeException('Character not found.');
        }
        return $c;
    }

    private function getFullCharacter(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM characters WHERE id = ?');
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) {
            throw new RuntimeException('Character not found.');
        }
        return $c;
    }

    private function partyIdForCharacter(int $characterId): ?int
    {
        $stmt = $this->db->prepare('SELECT party_id FROM character_party WHERE character_id = ? LIMIT 1');
        $stmt->execute([$characterId]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int) $v : null;
    }

    /**
     * Which NPCs are standing somewhere else, because they are with you.
     *
     * A companion is two rows: the authored `npcs` row you meet and talk to,
     * and the `characters` row created when they agree to come. 'active' is
     * travelling with you, 'camp' is waiting at camp — neither is where the
     * content put them. 'left', 'hostile' and 'dead' deliberately do not hide
     * the NPC: somebody who walked out is back in the world, and meeting them
     * again is the point.
     *
     * @return array<int,true>
     */
    private function recruitedNpcIds(?int $partyId): array
    {
        if (!$partyId) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT n.id
               FROM party_companions pc
               INNER JOIN companions cmp ON cmp.companion_key = pc.companion_key
               INNER JOIN npcs n ON n.npc_key = cmp.npc_key
              WHERE pc.party_id = ? AND pc.status IN ('active', 'camp')"
        );
        $stmt->execute([$partyId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    // -----------------------------------------------------------------------
    // Known NPCs
    // -----------------------------------------------------------------------

    /** Mark an NPC as known (met) by this character — their name replaces their role in the people list. */
    public function markNpcKnown(int $characterId, int $npcId): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO character_known_npcs (character_id, npc_id) VALUES (?, ?)'
        );
        $stmt->execute([$characterId, $npcId]);
    }

    /** @return int[] NPC ids this character has met */
    public function knownNpcIds(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT npc_id FROM character_known_npcs WHERE character_id = ?'
        );
        $stmt->execute([$characterId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function getNpc(int $npcId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM npcs WHERE id = ?');
        $stmt->execute([$npcId]);
        $npc = $stmt->fetch();
        if (!$npc) {
            throw new RuntimeException('NPC not found.');
        }
        $npc['dialogue'] = json_decode($npc['dialogue_json'] ?? '{}', true) ?: [];
        return $npc;
    }

    // -----------------------------------------------------------------------
    // The scene payload
    // -----------------------------------------------------------------------

    /**
     * Everything the client needs to draw where the party is standing: the
     * prose, the people, the ground loot, the ways out, and the region chart.
     */
    public function getState(int $characterId, ?int $partyId): array
    {
        $char = $this->getFullCharacter($characterId);
        $locationId = (int) $char['current_location_id'];
        if ($locationId <= 0) {
            throw new RuntimeException('Character is nowhere. Repair the save by selecting a starting location.');
        }
        $location = $this->getLocation($locationId);

        $firstVisit = $partyId ? $this->markVisited($partyId, $locationId) : false;

        // Standing somewhere a generated quest wanted the party to stand is how
        // that quest gets on. Asked on every look rather than only on a first
        // visit: a party that found the third room before the second comes back
        // for the second later, and that arrival is not a first visit.
        // QuestService::arriveAt does nothing at all for authored quests.
        if ($partyId) {
            (new QuestService($this->db))->arriveAt($partyId, $locationId);
        }

        return [
            'character' => $this->getCharacterPosition($characterId),
            'location'  => $this->locationPayload($location, $characterId, $partyId, $firstVisit),
            'region_map'=> $this->regionMap((int) $location['region_id'], $locationId, $partyId),
            'known_npc_ids' => $this->knownNpcIds($characterId),
        ];
    }

    private function getLocation(int $locationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, r.name AS region_name, r.region_key, r.region_type
               FROM locations l
               INNER JOIN regions r ON r.id = l.region_id
              WHERE l.id = ?'
        );
        $stmt->execute([$locationId]);
        $loc = $stmt->fetch();
        if (!$loc) {
            throw new RuntimeException('Location not found.');
        }
        return $loc;
    }

    /** @return bool true when this arrival was the party's first. */
    private function markVisited(int $partyId, int $locationId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO party_locations_visited (party_id, location_id) VALUES (?, ?)'
        );
        $stmt->execute([$partyId, $locationId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int,true> location ids the party has stood in. */
    private function visitedIds(?int $partyId): array
    {
        if (!$partyId) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT location_id FROM party_locations_visited WHERE party_id = ?'
        );
        $stmt->execute([$partyId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function locationPayload(array $location, int $characterId, ?int $partyId, bool $firstVisit): array
    {
        $locationId = (int) $location['id'];
        return [
            'id'           => $locationId,
            'key'          => $location['location_key'],
            'name'         => $location['name'],
            'type'         => $location['location_type'],
            'region_id'    => (int) $location['region_id'],
            'region_key'   => $location['region_key'],
            'region_name'  => $location['region_name'],
            'region_type'  => $location['region_type'],
            'description'  => $location['description'],
            'first_visit'  => $firstVisit,
            'first_visit_text' => $firstVisit ? $location['first_visit_text'] : null,
            'ambience'     => json_decode($location['ambience_json'] ?? '[]', true) ?: [],
            'npcs'         => $this->npcsAt($locationId, $characterId, $partyId),
            'items'        => $this->itemsAt($locationId, $partyId),
            'exits'        => $this->exitsFrom($locationId, $characterId, $partyId),
            // The fight waiting here, if the party has not won it yet. Carried
            // on the scene rather than only announced on arrival: a party that
            // was interrupted on the way in, or that fled and came back, is
            // still standing in front of it.
            'encounter'    => $this->pendingEncounter($locationId, $partyId, $characterId),
            // What the pit is offering tonight, sized to this party. Carried on
            // the scene so standing in the arena is enough to see the card —
            // there is nobody here to talk to and nothing to search for.
            'pit'          => $location['location_type'] === 'arena' && $partyId
                ? (new PitEngine($this->db))->offer($partyId)
                : null,
            // The stair, from wherever the party is standing: whether they may
            // go down, go deeper, or climb out. Null outside the Undervault, so
            // no other module carries a key about a hole it has not got.
            'delve'        => (new DelveEngine($this->db))->status($characterId, $partyId),
            'inn_cost'     => $location['inn_cost'] !== null ? (int) $location['inn_cost'] : null,
            // Sleeping and stopping for an hour are different permissions now,
            // and both are answered here rather than worked out by the client
            // from allow_camp — a room can be sleepable because its doors are
            // braced, which is a fact about world state and not about the row.
            'allow_camp'   => $this->canCampHere($characterId),
            'allow_short_rest' => $this->canShortRestHere($characterId),
            // How the room stands: doors, and how many are braced. The client
            // shows it so a party can see how close they are to being able to
            // sleep here.
            'barricade'    => (new DoorEngine($this->db))
                ->barricadeReport($partyId, (int) $location['id']),
            'job_board'    => (bool) ($location['has_job_board'] ?? 0),
        ];
    }

    /**
     * The fixed encounter standing between the party and this place.
     *
     * At most one at a time — the lowest-id fight not yet cleared — so two
     * encounters authored in one location happen in sequence rather than
     * stacking. Cleared fights stay cleared, per party.
     */
    /**
     * What there is to do in a place, for somebody standing in it.
     *
     * Only what the scene already shows: the authored people who are actually
     * there, and the fight that has not been won yet. Nothing hidden and
     * nothing not yet found — this exists so a quest tracker can finish the
     * sentence it starts, not so it can give the game away.
     *
     * The crowd is left out. "Also about: a baker, a drover, a beggar" is true
     * of every street in Rivermark and tells a player nothing about the job
     * they are on.
     *
     * @return array{npcs: list<string>, encounter: ?string}
     */
    public function whatIsHere(int $locationId, int $characterId, ?int $partyId): array
    {
        $named = [];
        foreach ($this->npcsAt($locationId, $characterId, $partyId) as $n) {
            if (!empty($n['is_ambient'])) {
                continue;
            }
            // The same rule the people rail uses: somebody you have not met
            // shows their role, because learning the name is what talking is.
            $named[] = !empty($n['known'])
                ? (string) $n['name']
                : (string) ($n['role'] ?: $n['name']);
        }
        $enc = $this->pendingEncounter($locationId, $partyId);
        return [
            'npcs'      => $named,
            'encounter' => $enc['name'] ?? null,
        ];
    }

    /**
     * @param ?int $characterId whose party the roster is sized against. Omitted
     *   by callers that want only the fight's name — whatIsHere() is one — so
     *   that a quest tracker finishing a sentence does not pay for a scaling
     *   pass it will not print.
     */
    private function pendingEncounter(int $locationId, ?int $partyId, ?int $characterId = null): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.name, e.description FROM encounters e
              WHERE e.location_id = ? AND e.is_random = 0 AND e.is_ambush = 1
                AND NOT EXISTS (
                    SELECT 1 FROM party_encounters_cleared pc
                     WHERE pc.encounter_id = e.id AND pc.party_id = ?
                )
              ORDER BY e.id LIMIT 1'
        );
        $stmt->execute([$locationId, $partyId ?? 0]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        // Who is over there. The party is looking at them — an ambush the
        // scene already names is not a thing you get to be surprised by the
        // size of — so the roster travels with it, sized to this party by the
        // same code that will deal the fight.
        $foes = $characterId !== null
            ? (new CombatEngine($this->db))->roster((int) $row['id'], $characterId)
            : [];

        return [
            'id'          => (int) $row['id'],
            'name'        => $row['name'],
            'description' => $row['description'],
            'foes'        => $foes,
            'foes_text'   => $foes ? CombatEngine::describeRoster($foes) : null,
        ];
    }

    /**
     * The people list. Authored characters lead; ambient townsfolk follow as
     * the crowd. An NPC the character has not met shows role only — the client
     * renders "A traveling merchant" until first talked to.
     */
    private function npcsAt(int $locationId, int $characterId, ?int $partyId): array
    {
        $stmt = $this->db->prepare(
            // `pose` rides along for the client's 3D room: a bargeman asleep
            // over a table stands differently from one drinking at it, and the
            // column already says which.
            // `map_x`/`map_y` are where this person stands on the region chart,
            // when somebody has said. Null means "with everyone else at the
            // place marker", which is right for almost everyone: a chart shows
            // a region, and only a location drawn large enough to have rooms in
            // it has anywhere for a shopkeeper to be standing that is not just
            // "here".
            'SELECT n.id, n.npc_key, n.name, n.role, n.description, n.image_url,
                    n.sprite_key, n.pose, n.is_ambient, n.is_merchant, n.is_quest_giver,
                    n.map_x, n.map_y
               FROM npcs n
              WHERE n.location_id = ?
              ORDER BY n.is_ambient, n.id'
        );
        $stmt->execute([$locationId]);
        $recruited = $this->recruitedNpcIds($partyId);
        $known = array_fill_keys($this->knownNpcIds($characterId), true);
        // One context for every mark on the street: hasWorkFor walks each
        // giver's dialogue against the party's actual state, and rebuilding
        // the state per person would be the only expensive part of that.
        $ctx = $partyId ? (new WorldState($this->db))->context($partyId, $characterId) : [];
        $out = [];
        foreach ($stmt->fetchAll() as $n) {
            if (isset($recruited[(int) $n['id']])) {
                continue;
            }
            $n['id'] = (int) $n['id'];
            $n['is_ambient'] = (int) $n['is_ambient'];
            $n['is_merchant'] = (int) $n['is_merchant'];
            $n['is_quest_giver'] = (int) $n['is_quest_giver'];
            // Whether they have work for THIS party, which is not the same
            // question as whether they are the sort of person who gives it.
            // `is_quest_giver` is a fact about the character and never changes,
            // so Mara wore a "has work" mark over her head for the rest of the
            // game after the cellar was dealt with.
            $n['has_work'] = $n['is_quest_giver'] === 1
                && $this->hasWorkFor($n, $partyId, $ctx);
            $n['known'] = isset($known[$n['id']]);
            $out[] = $n;
        }
        return $out;
    }

    /**
     * Is there a job left in this person for this party?
     *
     * Only the NPC's own dialogue answers it, and only the part of it this
     * party could actually walk to. `quests.giver_npc_id` deliberately is NOT
     * read here — it names whose quest it is, not where it starts, and marking
     * by it hung a "!" on Osric for a job only the job board gives out, and on
     * two people no quest links to at all.
     *
     * REACHABLE, not merely present. The first version of this fix scanned the
     * tree textually for `start_quest`, which lit Rilk up from level 1 for an
     * offer his dialogue only makes at level 3 with an Undertown flag behind
     * it — the player circles the conversation looking for the line that is
     * not there yet, which is the same bug wearing a subtler face. So the
     * tree is walked with the party's own context and the same
     * Requirements::pass the conversation itself will use: a start_quest on a
     * variant or choice the conditions currently hide does not glow.
     *
     * Anything already taken — in progress, finished or failed — is not work on
     * offer, and neither is a job the party is too green for. Both are exactly
     * what the mark should stop promising.
     */
    private function hasWorkFor(array $npc, ?int $partyId, array $ctx = []): bool
    {
        $raw = (string) ($this->db->query(
            'SELECT dialogue_json FROM npcs WHERE id = ' . (int) $npc['id']
        )->fetchColumn() ?: '');
        $doc = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($doc)) {
            // Flagged as a giver, but their own mouth starts nothing. Whatever
            // the flag meant, there is no line here that hands work over, so
            // there is no mark to wear.
            return false;
        }

        $keys = $this->offeredQuestKeys($doc, $ctx);
        if (!$keys) {
            return false;
        }
        if (!$partyId) {
            return true;
        }

        $in = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM quests q
              LEFT JOIN party_quests pq ON pq.quest_id = q.id AND pq.party_id = ?
              WHERE q.quest_key IN ({$in})
                AND q.is_active = 1
                AND pq.id IS NULL
                AND q.required_level <= ?"
        );
        $stmt->execute(array_merge(
            [$partyId],
            array_keys($keys),
            [$this->partyLeaderLevel($partyId)]
        ));
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Which quests this dialogue could hand over RIGHT NOW.
     *
     * A breadth-first walk from the start node, taking only variants and
     * choices whose conditions pass for this party — the same
     * Requirements::pass verdict DialogEngine will reach when the player sits
     * down. Both sides of a skill check are walked (either die can land), and
     * every passing variant of a node is considered rather than only the one
     * matchVariant would pick: pool rotation makes "which variant today"
     * unknowable from here, and over-counting a rotating pool is a mark shown
     * a conversation early, not a mark that lies.
     *
     * @return array<string,true> quest keys, as a set
     */
    private function offeredQuestKeys(array $doc, array $ctx): array
    {
        $nodes = is_array($doc['nodes'] ?? null) ? $doc['nodes'] : [];
        $queue = [(string) ($doc['start'] ?? 'greeting')];
        $seen = [];
        $keys = [];

        $harvest = function ($effects) use (&$keys): void {
            foreach (is_array($effects) ? $effects : [] as $effect) {
                if (is_array($effect) && isset($effect['start_quest']) && is_string($effect['start_quest'])) {
                    $keys[$effect['start_quest']] = true;
                }
            }
        };

        while ($queue) {
            $nodeKey = array_shift($queue);
            if ($nodeKey === '' || isset($seen[$nodeKey]) || !isset($nodes[$nodeKey])) {
                continue;
            }
            $seen[$nodeKey] = true;

            $variants = is_array($nodes[$nodeKey]) ? $nodes[$nodeKey] : [];
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $conds = $variant['conditions'] ?? null;
                if (is_array($conds) && !Requirements::pass($conds, $ctx)) {
                    continue;
                }
                $harvest($variant['on_enter'] ?? null);
                foreach (is_array($variant['choices'] ?? null) ? $variant['choices'] : [] as $choice) {
                    if (!is_array($choice)) {
                        continue;
                    }
                    $cConds = $choice['conditions'] ?? null;
                    if (is_array($cConds) && !Requirements::pass($cConds, $ctx)) {
                        continue;
                    }
                    $harvest($choice['effects'] ?? null);
                    if (!empty($choice['next'])) {
                        $queue[] = (string) $choice['next'];
                    }
                    $check = $choice['check'] ?? null;
                    if (is_array($check)) {
                        foreach (['on_success', 'on_failure'] as $side) {
                            if (!empty($check[$side]) && is_string($check[$side])) {
                                $queue[] = $check[$side];
                            }
                        }
                    }
                }
            }
        }
        return $keys;
    }

    /** The level a required_level gate is measured against; see QuestService. */
    private function partyLeaderLevel(int $partyId): int
    {
        $stmt = $this->db->prepare(
            'SELECT c.level FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             LEFT JOIN parties p ON p.id = cp.party_id
             WHERE cp.party_id = ? AND c.is_active = 1
             ORDER BY c.id = p.leader_character_id DESC, cp.slot
             LIMIT 1'
        );
        $stmt->execute([$partyId]);
        return max(1, (int) $stmt->fetchColumn());
    }

    /** Ground loot still lying here for this party. */
    private function itemsAt(int $locationId, ?int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT li.id AS location_item_id, i.id AS item_id, i.name, i.description,
                    i.item_type, i.icon
               FROM location_items li
               INNER JOIN items i ON i.id = li.item_id
              WHERE li.location_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM party_items_taken pt
                     WHERE pt.location_item_id = li.id AND pt.party_id = ?
                )
              ORDER BY li.id'
        );
        $stmt->execute([$locationId, $partyId ?? 0]);
        return array_map(static function (array $row): array {
            $row['location_item_id'] = (int) $row['location_item_id'];
            $row['item_id'] = (int) $row['item_id'];
            return $row;
        }, $stmt->fetchAll());
    }

    /** @return array<int,true> exit ids this party has found by searching. */
    private function foundExitIds(?int $partyId): array
    {
        if (!$partyId) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT exit_id FROM party_exits_found WHERE party_id = ?');
        $stmt->execute([$partyId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * The ways out, as the player sees them.
     *
     * A hidden exit is omitted until found (or its destination already
     * visited). A condition-locked exit is listed but marked locked — how the
     * player learns a way exists at all — and travel() refuses it.
     */
    private function exitsFrom(int $locationId, int $characterId, ?int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.to_location_id, e.label, e.conditions_json, e.is_hidden,
                    l.name AS to_name, l.location_key AS to_key, l.region_id AS to_region_id
               FROM location_exits e
               INNER JOIN locations l ON l.id = e.to_location_id
              WHERE e.from_location_id = ?
              ORDER BY e.sort_order, e.id'
        );
        $stmt->execute([$locationId]);
        $visited = $this->visitedIds($partyId);
        $found = $this->foundExitIds($partyId);
        $ctx = $partyId ? (new WorldState($this->db))->context($partyId, $characterId) : [];
        $out = [];
        foreach ($stmt->fetchAll() as $e) {
            $id = (int) $e['id'];
            $toId = (int) $e['to_location_id'];
            if ((int) $e['is_hidden'] === 1 && !isset($found[$id]) && !isset($visited[$toId])) {
                continue;
            }
            $conds = $e['conditions_json'] ? (json_decode($e['conditions_json'], true) ?: null) : null;
            $locked = $conds !== null && !Requirements::pass($conds, $ctx);
            // A generated locked door can be FORCED — its whole condition is
            // the dg_open_ flag DelveEngine wrote and location/force_door
            // sets. DelveEngine::forceFlagOf is the one recognizer; an
            // authored locked way gates on story and gets no such offer. The
            // verb is told from the label because the exit row does not carry
            // its door type, and the label is ours: doorLabel() wrote it.
            $attempt = null;
            if ($locked && DelveEngine::forceFlagOf($e['conditions_json']) !== null) {
                $attempt = str_contains((string) $e['label'], 'portcullis') ? 'lift' : 'force';
            }
            // A door with a lock in it can be picked instead of broken, by a
            // party carrying tools. A portcullis cannot: there is no lock, it
            // is a grate in a slot and the only argument is Strength.
            $canPick = $attempt === 'force' && $this->partyHasThievesTools($partyId);
            $out[] = [
                'id'             => $id,
                'to_location_id' => $toId,
                'to_key'         => $e['to_key'],
                'to_name'        => $e['to_name'],
                'to_region_id'   => (int) $e['to_region_id'],
                'label'          => $e['label'],
                'locked'         => $locked,
                'attempt'        => $attempt,
                'can_pick'       => $canPick,
            ];
        }
        return $out;
    }

    /**
     * The region chart the client draws as an SVG: every node, every edge,
     * and the arrows out to neighbouring regions.
     */
    public function regionMap(int $regionId, int $currentLocationId, ?int $partyId): array
    {
        $region = $this->db->prepare('SELECT * FROM regions WHERE id = ?');
        $region->execute([$regionId]);
        $r = $region->fetch();
        if (!$r) {
            throw new RuntimeException('Region not found.');
        }

        $visited = $this->visitedIds($partyId);

        $locs = $this->db->prepare(
            'SELECT id, location_key, name, location_type, map_x, map_y, hidden_until_visited
               FROM locations WHERE region_id = ? ORDER BY sort_order, id'
        );
        $locs->execute([$regionId]);
        $nodes = [];
        $inRegion = [];
        foreach ($locs->fetchAll() as $l) {
            $id = (int) $l['id'];
            $inRegion[$id] = true;
            $isVisited = isset($visited[$id]);
            // A hidden place the party has not found is not on the chart at
            // all — a "?" node would still say where to look.
            if ((int) $l['hidden_until_visited'] === 1 && !$isVisited) {
                continue;
            }
            $nodes[] = [
                'id'      => $id,
                'key'     => $l['location_key'],
                'name'    => $l['name'],
                'type'    => $l['location_type'],
                'x'       => (float) $l['map_x'],
                'y'       => (float) $l['map_y'],
                'visited' => $isVisited,
                'current' => $id === $currentLocationId,
            ];
        }
        $shown = array_fill_keys(array_column($nodes, 'id'), true);

        // Edges within the region, deduped to one line per pair; exits that
        // leave the region become labelled arrows instead.
        $exits = $this->db->prepare(
            'SELECT e.from_location_id, e.to_location_id, e.label, e.is_hidden,
                    l2.region_id AS to_region_id, r2.name AS to_region_name,
                    l2.name AS to_name
               FROM location_exits e
               INNER JOIN locations l1 ON l1.id = e.from_location_id
               INNER JOIN locations l2 ON l2.id = e.to_location_id
               INNER JOIN regions r2 ON r2.id = l2.region_id
              WHERE l1.region_id = ?'
        );
        $exits->execute([$regionId]);
        $edges = [];
        $seenPair = [];
        $neighbors = [];
        foreach ($exits->fetchAll() as $e) {
            $from = (int) $e['from_location_id'];
            $to = (int) $e['to_location_id'];
            if (!isset($shown[$from])) {
                continue;
            }
            if ((int) $e['to_region_id'] !== $regionId) {
                // `visited` is of the location on the far side: the chart
                // withholds a neighbouring region's name until the party has
                // actually crossed into it, the same rule the nodes follow.
                $neighbors[] = [
                    'from'        => $from,
                    'region_name' => $e['to_region_name'],
                    'to_name'     => $e['to_name'],
                    'label'       => $e['label'],
                    'to_location_id' => $to,
                    'visited'     => isset($visited[$to]),
                ];
                continue;
            }
            if (!isset($shown[$to])) {
                continue;
            }
            $key = min($from, $to) . ':' . max($from, $to);
            if (isset($seenPair[$key])) {
                continue;
            }
            $seenPair[$key] = true;
            $edges[] = ['from' => $from, 'to' => $to];
        }

        return [
            'region_id'   => $regionId,
            'region_key'  => $r['region_key'],
            'name'        => $r['name'],
            'region_type' => $r['region_type'],
            'nodes'       => $nodes,
            'edges'       => $edges,
            'neighbors'   => $neighbors,
            // The drawn shape of a generated dungeon floor, or null for an
            // authored region — which is every region with a painted plate.
            // The chart draws one or the other, never both: a plan under a
            // painting would be two maps of the same place disagreeing.
            'floorplan'   => $this->authoredFloorplan($r)
                ?? (new DelveEngine($this->db))->planFor($regionId),
        ];
    }

    /**
     * An authored dungeon's shape, when the region carries a plan.
     *
     * Generated floors still come from DelveEngine::planFor(). A region with
     * no plan_json returns null so the caller falls through. Every room is
     * seen: fog on an authored hole is a later question, and the Old City
     * already ships its whole shape.
     */
    private function authoredFloorplan(array $region): ?array
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
        $keys = [];
        foreach ($compiled['level']['rooms'] as $r) {
            $keys[(int) $r['id']] = (string) $r['key'];
        }
        $idOf = [];
        if ($keys) {
            $list = array_values($keys);
            $in = implode(',', array_fill(0, count($list), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, location_key FROM locations WHERE location_key IN ({$in})"
            );
            $stmt->execute($list);
            foreach ($stmt->fetchAll() as $row) {
                $idOf[(string) $row['location_key']] = (int) $row['id'];
            }
        }
        $drawn = $compiled['plan'];
        foreach ($drawn['rooms'] as $i => $r) {
            $key = $keys[(int) $r['id']] ?? '';
            $drawn['rooms'][$i]['location_id'] = $idOf[$key] ?? null;
            $drawn['rooms'][$i]['seen'] = true;
        }
        foreach ($drawn['corridors'] as $i => $c) {
            $drawn['corridors'][$i]['seen'] = true;
        }
        return $drawn;
    }

    // -----------------------------------------------------------------------
    // Travel
    // -----------------------------------------------------------------------

    /**
     * Walk the party to another location, however many hops away.
     *
     * The path is found by BFS over the exits the party can actually use —
     * locked and unfound-hidden exits are not edges. The trip resolves hop by
     * hop: each arrival may roll a random encounter, and a hit stops the party
     * where the fight found them. The whole roster moves together; there is
     * one party and it is in one place.
     */
    public function travel(int $characterId, int $toLocationId): array
    {
        $char = $this->getFullCharacter($characterId);
        $fromId = (int) $char['current_location_id'];
        $partyId = $this->partyIdForCharacter($characterId);

        if ($fromId === $toLocationId) {
            throw new InvalidArgumentException('You are already there.');
        }
        // Confirms the destination exists before pathing to it.
        $this->getLocation($toLocationId);

        $path = $this->findPath($fromId, $toLocationId, $characterId, $partyId);
        if ($path === null) {
            throw new InvalidArgumentException(
                $this->noRouteMessage($fromId, $toLocationId, $characterId, $partyId)
            );
        }

        $steps = [];
        $messages = [];
        $interrupted = false;
        $events = [];
        $arrivedId = $fromId;
        // Every place the party physically passed through. Marked as visited
        // AFTER the walk, and deliberately excluding wherever they ended up:
        // getState() marks that one, and its return value is what decides
        // whether the first-visit paragraph is shown. Marking it here would
        // mean the party had "already been" by the time the client asked, and
        // no location would ever read as new.
        $passedThrough = [];

        foreach ($path as $hop) {
            $destId = (int) $hop['to_location_id'];
            $this->moveParty($characterId, $partyId, $destId);
            $arrivedId = $destId;
            $passedThrough[] = $destId;
            $steps[] = [
                'label'    => $hop['label'],
                'to_name'  => $hop['to_name'],
                'to_id'    => $destId,
            ];

            // The ground itself first: a rigged passage bites before anything
            // living gets its turn. A spotted trap is a line in the log and
            // the trip goes on; a sprung one stops the party where it caught
            // them, exactly as a fight would.
            $trap = $this->springTrap($destId, $characterId, $partyId);
            if ($trap !== null) {
                $messages = array_merge($messages, $trap['messages']);
                if ($trap['event'] !== null) {
                    $events[] = $trap['event'];
                    $interrupted = $destId !== $toLocationId;
                    break;
                }
            }

            // Dangerous ground: the hop's destination may throw a fight at the
            // party mid-trip. The roll belongs to the ground being crossed,
            // not the destination clicked.
            $random = $this->rollRandomEncounter($destId, $partyId);
            if ($random !== null) {
                $events[] = $random;
                $interrupted = $destId !== $toLocationId;
                break;
            }
        }

        // Everywhere but the last stop; see $passedThrough above.
        if ($partyId) {
            foreach ($passedThrough as $id) {
                if ($id !== $arrivedId) {
                    $this->markVisited($partyId, $id);
                }
            }
        }

        // Whatever waits where the party actually stopped. Reported even when
        // the trip was cut short, because being dumped somewhere unplanned and
        // finding a fight there is exactly the moment the player needs telling.
        // A random encounter takes precedence: the fixed one is still pending
        // on the scene afterwards, so nothing is lost by letting the wolves go
        // first.
        if (!$events) {
            $events = $this->arrivalEvents($arrivedId, $partyId);
        }

        if ($partyId) {
            $messages = array_merge($messages, $this->questArrivalNotes($partyId, $arrivedId));
        }

        return [
            'ok'          => true,
            'location_id' => $arrivedId,
            'steps'       => $steps,
            'events'      => $events,
            'interrupted' => $interrupted,
            'messages'    => $messages,
            'character'   => $this->getCharacterPosition($characterId),
        ];
    }

    /**
     * Arriving where an active quest's current stage points.
     *
     * Two things happen, and the flag is the important one. A world flag
     * `seen:<quest_key>:<stage_key>` records that the party stood on the spot,
     * so dialogue can gate on having actually gone — "I counted the roofs"
     * should not be sayable from the taproom. And the log says the stage
     * noticed, naming the giver where there is one, because a third of the
     * quest roster sends the party to look at a place with nothing standing
     * on it: without a line here the trip reads as a dead end, when the
     * missing move was always "go back and say what you saw". (Reported as
     * exactly that: "quests seem to be dead ends".)
     *
     * @return string[] lines for the travel log
     */
    private function questArrivalNotes(int $partyId, int $locationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT q.quest_key, q.title, s.stage_key, s.is_terminal, g.name AS giver_name
               FROM party_quests pq
              INNER JOIN quests q ON q.id = pq.quest_id
              INNER JOIN quest_stages s ON s.id = pq.current_stage_id
               LEFT JOIN npcs g ON g.id = q.giver_npc_id
              WHERE pq.party_id = ? AND pq.status = \'active\'
                AND s.target_location_id = ?'
        );
        $stmt->execute([$partyId, $locationId]);

        $world = new WorldState($this->db);
        $notes = [];
        foreach ($stmt->fetchAll() as $row) {
            $flag = 'seen:' . $row['quest_key'] . ':' . $row['stage_key'];
            if ($world->number($partyId, $flag) > 0) {
                continue; // already stood here for this stage; say it once
            }
            $world->set($partyId, $flag);
            if ((int) $row['is_terminal'] === 1) {
                continue;
            }
            $notes[] = $row['giver_name']
                ? "This is the place \"{$row['title']}\" pointed you at. {$row['giver_name']} will want to hear what you find here."
                : "This is the place \"{$row['title']}\" pointed you at. What you see here will matter to whoever set you on it.";
        }
        return $notes;
    }

    /**
     * Why the walk cannot be made, said in terms of what the player can do.
     *
     * "There is no way to get there from here" is true of an unreachable
     * destination and of a destination behind the one door in the room the
     * party has not searched for yet, and only the first of those is the
     * player's fault. The second reads as a broken map — which is exactly how
     * it was reported — because the chart goes on drawing the place as a click
     * target: it draws the graph, and the graph is not what `findPath` walks.
     *
     * The answer is found by asking findPath the same question twice more with
     * the gates lifted, rather than by looking at what leaves the room the
     * party is standing in. A door that has not been found may be three rooms
     * away, and a hint drawn from the local exits would then be confidently
     * wrong about a route it never walked. The searches are cheap — the whole
     * exit graph is a few hundred rows and this is an error path.
     *
     * The hint is withheld unless it would actually pay off, which matters
     * more than it looks: saying "there is a way you have not found" over a
     * destination that is unreachable however much the party searches both
     * wastes their time and announces that a secret exists. Hidden exits are
     * the one thing in the world the player is supposed to have to earn, so
     * the message says a way is missing and never says where.
     */
    private function noRouteMessage(int $fromId, int $toId, int $characterId, ?int $partyId): string
    {
        // Nothing the party can do here opens it: it is simply not connected to
        // where they are standing.
        if ($this->findPath($fromId, $toId, $characterId, $partyId, self::GATES_ALL) === null) {
            return 'There is no way to get there from here.';
        }
        // Searching alone would do it.
        if ($this->findPath($fromId, $toId, $characterId, $partyId, self::GATES_HIDDEN) !== null) {
            return 'No way you have found leads there. Search the places you can reach —'
                . ' there is a way through you have not turned up yet.';
        }
        // A condition-locked exit is on every route. Unlike a hidden one this
        // is already drawn on the chart as shut, so naming it tells no secret.
        return 'There is no way to get there from here that is not shut to you.';
    }

    /**
     * BFS over usable exits. Returns the hops in order, or null when
     * unreachable with what the party currently knows and has done.
     *
     * `$lift` is for noRouteMessage() and for nothing else: it asks the same
     * question with some of the gates taken off, so a failed trip can be told
     * apart from a trip that is only failing because of a door. Travel itself
     * always passes GATES_NONE — a path found with a gate lifted is a path the
     * party cannot walk, and returning one would be the bug this exists to
     * explain.
     *
     * @param  int $lift one of the GATES_* masks
     * @return ?array<int,array{to_location_id:int,label:string,to_name:string}>
     */
    private function findPath(
        int $fromId,
        int $toId,
        int $characterId,
        ?int $partyId,
        int $lift = self::GATES_NONE
    ): ?array {
        $rows = $this->db->query(
            'SELECT e.id, e.from_location_id, e.to_location_id, e.label,
                    e.conditions_json, e.is_hidden, l.name AS to_name
               FROM location_exits e
               INNER JOIN locations l ON l.id = e.to_location_id'
        )->fetchAll();

        $visited = $this->visitedIds($partyId);
        $found = $this->foundExitIds($partyId);
        $ctx = $partyId ? (new WorldState($this->db))->context($partyId, $characterId) : [];
        // Every barricade this party has standing, read once. The walk graph
        // touches every edge on the floor and asking per edge would be a query
        // per door.
        $barred = [];
        if ($partyId) {
            $rowsB = $this->db->prepare(
                "SELECT flag_key FROM world_flags WHERE party_id = ? AND flag_key LIKE 'dg\\_barr\\_%'"
            );
            $rowsB->execute([$partyId]);
            foreach ($rowsB->fetchAll(PDO::FETCH_COLUMN) as $k) {
                $barred[(string) $k] = true;
            }
        }

        $out = [];
        foreach ($rows as $e) {
            $exitId = (int) $e['id'];
            $destId = (int) $e['to_location_id'];
            if (!($lift & self::GATES_HIDDEN)
                && (int) $e['is_hidden'] === 1 && !isset($found[$exitId]) && !isset($visited[$destId])) {
                continue;
            }
            if (!($lift & self::GATES_LOCKED) && $e['conditions_json']) {
                $conds = json_decode($e['conditions_json'], true) ?: null;
                if ($conds !== null && !Requirements::pass($conds, $ctx)) {
                    continue;
                }
            }
            // A door the party braced shut is not a way through until they
            // take it down again. Gated with the locks rather than separately,
            // because to the walk graph it is the same fact: this edge is not
            // available. The doorway is barred from BOTH sides — a barricade
            // does not know which side built it — so this drops the pair.
            if (!($lift & self::GATES_LOCKED) && $partyId
                && isset($barred[DoorEngine::barricadeFlag((int) $e['from_location_id'], $destId)])) {
                continue;
            }
            $out[(int) $e['from_location_id']][] = $e;
        }

        $seen = [$fromId => true];
        $prev = [];
        $queue = [$fromId];
        while ($queue) {
            $here = array_shift($queue);
            if ($here === $toId) {
                break;
            }
            foreach ($out[$here] ?? [] as $e) {
                $next = (int) $e['to_location_id'];
                if (isset($seen[$next])) {
                    continue;
                }
                $seen[$next] = true;
                $prev[$next] = $e;
                $queue[] = $next;
            }
        }
        if (!isset($seen[$toId])) {
            return null;
        }

        $chain = [];
        $cursor = $toId;
        while (isset($prev[$cursor])) {
            $e = $prev[$cursor];
            $chain[] = [
                'to_location_id' => (int) $e['to_location_id'],
                'label'          => (string) $e['label'],
                'to_name'        => (string) $e['to_name'],
            ];
            $cursor = (int) $e['from_location_id'];
        }
        return array_reverse($chain);
    }

    private function moveParty(int $characterId, ?int $partyId, int $locationId): void
    {
        if ($partyId) {
            $this->db->prepare(
                'UPDATE characters c
                 INNER JOIN character_party cp ON cp.character_id = c.id
                 SET c.current_location_id = ?
                 WHERE cp.party_id = ?'
            )->execute([$locationId, $partyId]);
        } else {
            $this->db->prepare(
                'UPDATE characters SET current_location_id = ? WHERE id = ?'
            )->execute([$locationId, $characterId]);
        }
    }

    /**
     * Offer the check that forces a generated locked door.
     *
     * Phase one of the house ceremony: the player sees the DC, the modifier
     * and the boosts before the die moves, exactly as a dialogue check is
     * offered — CheckService owns all of that. This method only establishes
     * that the door is real, is HERE, is still shut, and is the forceable
     * kind: its whole condition is the dg_open_ flag DelveEngine wrote. An
     * authored locked way gates on story, not muscle, and is refused.
     *
     * The DC derives from the floor's depth, read out of the flag key itself —
     * the one place the door's provenance is written down. Strength either
     * way; a lock is Athletics (technique counts for something) where a
     * portcullis is bare lifting (it does not).
     */
    /**
     * How hard a hidden way is to notice.
     *
     * Derived rather than stored: `location_exits` has no column for it, and
     * adding one would want every authored secret door in the game revisited
     * to fill it in. So a secret door on a generated floor is read off the
     * depth it was cut at — deeper floors hide their doors better — and
     * anything authored gets a flat, middling number.
     *
     * If a stored DC is ever wanted, this is the one place that decides, and
     * the column can be read here without any caller changing.
     */
    private static function hiddenExitDc(array $exit): int
    {
        $flag = DelveEngine::forceFlagOf($exit['conditions_json'] ?? null);
        if ($flag !== null && preg_match('/^dg_open__dg_\d+_(\d+)_c\d+$/', $flag, $m)) {
            return 12 + (int) $m[1];
        }
        // An authored secret door. Findable by anyone who thinks to look and
        // is not actively bad at looking.
        return 13;
    }

    /**
     * A way out of the room the character is standing in, by id.
     *
     * Shared by the two ways through a locked door. Scoped to the CURRENT
     * location on purpose: an exit id lifted from somewhere else opens nothing,
     * and that check belongs here rather than in each caller.
     */
    private function exitHereById(int $characterId, int $exitId): array
    {
        $char = $this->getFullCharacter($characterId);
        $stmt = $this->db->prepare(
            'SELECT id, label, conditions_json FROM location_exits
              WHERE id = ? AND from_location_id = ?'
        );
        $stmt->execute([$exitId, (int) $char['current_location_id']]);
        $exit = $stmt->fetch();
        if (!$exit) {
            throw new InvalidArgumentException('No such way out of here.');
        }
        return $exit;
    }

    public function forceDoor(int $characterId, int $exitId): array
    {
        $partyId = $this->partyIdForCharacter($characterId);
        if (!$partyId) {
            throw new InvalidArgumentException('Forcing a door is party work.');
        }

        $exit = $this->exitHereById($characterId, $exitId);
        $flag = DelveEngine::forceFlagOf($exit['conditions_json'] ?? null);
        if ($flag === null || !preg_match('/^dg_open__dg_\d+_(\d+)_c\d+$/', $flag, $m)) {
            throw new InvalidArgumentException('That door will not answer to force.');
        }
        $world = new WorldState($this->db);
        if ($world->isSet($partyId, $flag)) {
            throw new InvalidArgumentException('It is already open.');
        }

        $depth = (int) $m[1];
        $lift = str_contains((string) $exit['label'], 'portcullis');
        $spec = $lift
            ? ['ability' => 'str', 'dc' => 11 + $depth]
            : ['skill' => 'athletics', 'dc' => 10 + $depth];
        $spec['context'] = ['door' => $flag, 'exit_id' => (int) $exit['id']];

        return [
            'ok'    => true,
            'verb'  => $lift ? 'lift' : 'force',
            'check' => (new CheckService($this->db))->request($partyId, $spec, $characterId),
        ];
    }

    /**
     * Does anybody in the party carry a set of picks?
     *
     * Party-wide rather than per-character: the check is made by whoever the
     * check ceremony picks as leader, and a party does not hand the rogue's
     * tools back and forth at the door. Rogues start with them (see
     * CharacterGenerator) and until now nothing in the game ever read the
     * item — it was inventory decoration.
     */
    private function partyHasThievesTools(?int $partyId): bool
    {
        if (!$partyId) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT 1
               FROM character_inventory ci
               JOIN items i ON i.id = ci.item_id
               JOIN character_party cp ON cp.character_id = ci.character_id
               JOIN characters c ON c.id = ci.character_id
              WHERE cp.party_id = ? AND i.item_key = ?
                AND ci.quantity > 0 AND c.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$partyId, 'thieves_tools']);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Pick a lock: the quiet way through a door that would otherwise be broken.
     *
     * The same two-phase ceremony as forcing, and the same flag at the end of
     * it — a door opened either way is open for good, from either side. What
     * differs is the trade. Picking is harder (a lock is a lock, and the DC
     * does not care how big you are) and asks Dexterity by way of Sleight of
     * Hand, but a failure is SILENT: no bar dropped, no boot, nothing for the
     * floor to hear. Forcing is easier and draws on the wandering pool when it
     * goes wrong.
     *
     * That is the whole of the choice, and it is why the tools are worth
     * carrying: not a better lockpick, a quieter one.
     */
    public function pickLock(int $characterId, int $exitId): array
    {
        $partyId = $this->partyIdForCharacter($characterId);
        if (!$partyId) {
            throw new InvalidArgumentException('Picking a lock is party work.');
        }
        $exit = $this->exitHereById($characterId, $exitId);

        $flag = DelveEngine::forceFlagOf($exit['conditions_json'] ?? null);
        if ($flag === null || !preg_match('/^dg_open__dg_\d+_(\d+)_c\d+$/', $flag, $m)) {
            throw new InvalidArgumentException('That door has no lock to pick.');
        }
        if (str_contains((string) $exit['label'], 'portcullis')) {
            throw new InvalidArgumentException('A portcullis has no lock. It has a slot, and a great deal of weight.');
        }
        $world = new WorldState($this->db);
        if ($world->isSet($partyId, $flag)) {
            throw new InvalidArgumentException('It is already open.');
        }
        if (!$this->partyHasThievesTools($partyId)) {
            throw new InvalidArgumentException('Nobody here is carrying tools for it.');
        }

        $depth = (int) $m[1];

        return [
            'ok'    => true,
            'verb'  => 'pick',
            'check' => (new CheckService($this->db))->request($partyId, [
                // Two harder than forcing the same door. A lock is indifferent
                // to how the party is built; the reward for tools is silence,
                // not an easier number.
                'skill'   => 'sleight_of_hand',
                'dc'      => 12 + $depth,
                'context' => ['door' => $flag, 'exit_id' => (int) $exit['id']],
            ], $characterId),
        ];
    }

    /**
     * Phase two of picking: the die lands, quietly either way.
     *
     * Deliberately does not mirror forceDoorResolve's noise. A failed pick
     * costs the attempt and nothing else — which sounds generous until you
     * notice the DC, and that a party without tools has no access to it at all.
     */
    public function pickLockResolve(int $characterId, string $checkId, array $boosts): array
    {
        $partyId = $this->partyIdForCharacter($characterId);
        $result = (new CheckService($this->db))->resolve($checkId, $boosts);

        $flag = (string) ($result['context']['door'] ?? '');
        if (!str_starts_with($flag, 'dg_open_') || (int) ($result['party_id'] ?? 0) !== (int) $partyId) {
            throw new InvalidArgumentException('That check opens no door of yours.');
        }

        $opened = in_array($result['outcome'], ['success', 'critical'], true);
        if ($opened) {
            (new WorldState($this->db))->set($partyId, $flag);
        }

        return [
            'ok'       => true,
            'opened'   => $opened,
            'result'   => $result,
            'events'   => [],
            'messages' => [$opened
                ? 'The last pin gives without a sound. The way is open — and will stay open.'
                : 'The picks slip, and the lock holds. Nobody heard it but you.'],
        ];
    }

    /**
     * Phase two: the die lands, and the door answers.
     *
     * The flag comes out of the check's own stashed context — fixed at request
     * time, server-side — never from the client. The party is re-derived from
     * the session character and must match the check's, so a check_id lifted
     * from someone else's table opens nothing.
     *
     * Failure makes noise, which is the honest cost: one draw against the
     * region's wandering pool, at a flat chance. The room the party stands in
     * has no wandering roll of its own — rooms never do — but a bar hammered
     * at and dropped rings down every corridor on the floor.
     */
    public function forceDoorResolve(int $characterId, string $checkId, array $boosts): array
    {
        $partyId = $this->partyIdForCharacter($characterId);
        $result = (new CheckService($this->db))->resolve($checkId, $boosts);

        $flag = (string) ($result['context']['door'] ?? '');
        if (!str_starts_with($flag, 'dg_open_') || (int) ($result['party_id'] ?? 0) !== (int) $partyId) {
            throw new InvalidArgumentException('That check opens no door of yours.');
        }

        $opened = in_array($result['outcome'], ['success', 'critical'], true);
        $events = [];
        $messages = [];
        if ($opened) {
            (new WorldState($this->db))->set($partyId, $flag);
            $messages[] = 'It gives. The way is open — and will stay open.';
        } else {
            $messages[] = 'It does not give, and the noise of trying runs a long way down the dark.';
            if (random_int(1, 100) <= 25) {
                $char = $this->getFullCharacter($characterId);
                $noise = $this->wanderingFrom((int) $char['current_location_id']);
                if ($noise !== null) {
                    $events[] = $noise;
                }
            }
        }

        return [
            'ok'       => true,
            'opened'   => $opened,
            'result'   => $result,
            'events'   => $events,
            'messages' => $messages,
        ];
    }

    /**
     * One draw from the region's wandering pool, chance already decided.
     * The location supplies only the region; its own pct is not consulted.
     */
    private function wanderingFrom(int $locationId): ?array
    {
        $loc = $this->db->prepare('SELECT region_id FROM locations WHERE id = ?');
        $loc->execute([$locationId]);
        $regionId = (int) $loc->fetchColumn();
        if ($regionId <= 0) {
            return null;
        }
        $enc = $this->db->prepare(
            'SELECT id, name FROM encounters
              WHERE region_id = ? AND is_random = 1
              ORDER BY RAND() LIMIT 1'
        );
        $enc->execute([$regionId]);
        $e = $enc->fetch();
        if (!$e) {
            return null;
        }
        return [
            'type'         => 'encounter',
            'encounter_id' => (int) $e['id'],
            'name'         => $e['name'],
            'random'       => true,
        ];
    }

    /**
     * The floor charging its toll: a rigged passage, met for the first time.
     *
     * The trap is the row's (`locations.trap_json`, written by DelveEngine);
     * whether THIS party has met it is a WorldState flag — unset, `found`, or
     * `sprung` — so the row is never mutated by play, the same split every
     * other per-party fact uses. Either outcome sets the flag: a trap is a
     * question the floor asks once.
     *
     * The leader gets a passive-perception look before anything fires,
     * because a trap in a corridor cannot be searched for first — the passage
     * IS the trap's room, and walking in to look is the trigger. Spotting is
     * quiet: a line in the log, the flag set to `found`, and the map may now
     * mark it. Failing that, it fires on one random walker: a saving throw,
     * rolled server-side with no ceremony — mid-trip and involuntary, the
     * same class of thing as an ambush — half damage on a save, and never
     * below 1 hp. Out-of-combat damage does not kill in this game; the dying
     * rules live in CombatEngine and a corridor is not a fight.
     *
     * @return ?array{event: ?array, messages: string[]}
     */
    private function springTrap(int $locationId, int $characterId, ?int $partyId): ?array
    {
        if (!$partyId) {
            return null;    // trap memory is per-party, and only parties delve
        }
        $stmt = $this->db->prepare('SELECT location_key, trap_json FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $row = $stmt->fetch();
        if (!$row || $row['trap_json'] === null) {
            return null;
        }
        $trap = json_decode((string) $row['trap_json'], true);
        if (!is_array($trap) || empty($trap['name'])) {
            return null;
        }

        $world = new WorldState($this->db);
        $flag = DelveEngine::trapFlag((string) $row['location_key']);
        if ($world->isSet($partyId, $flag)) {
            return null;    // met before, either way round
        }

        // The leader's eyes. Passive: ten plus the modifier, no die — the
        // party did not stop to look, so nobody gets to roll well.
        $leader = $this->getFullCharacter($characterId);
        $passive = 10 + Rules::skillCheck($leader, 'perception')['total'];
        if ($passive >= (int) $trap['dc']) {
            $world->set($partyId, $flag, 'found');
            return [
                'event'    => null,
                'messages' => [$trap['found_text'] ?? "You spot it before it spots you: {$trap['name']}."],
            ];
        }

        // It fires, on whoever happened to be in front.
        $victims = $this->partyWalkers($partyId);
        if (!$victims) {
            $victims = [$leader];
        }
        $victim = $victims[random_int(0, count($victims) - 1)];

        $world->set($partyId, $flag, 'sprung');

        return [
            'event'    => $this->fireTrapOn($partyId, $trap, $victim),
            'messages' => [],
        ];
    }

    /**
     * A trap going off on somebody, wherever it was set off from.
     *
     * Walking into one is not the only way to spring it — a disarm that goes
     * badly wrong sets it off on the hands doing the work (DoorEngine), and
     * that has to be the same trap doing the same damage, not a second
     * implementation that drifts. So the save, the halving, the floor at one
     * hit point and the shape of the event all live here, and the callers
     * decide only WHO and WHEN.
     *
     * The floor at 1 HP is deliberate and predates this: a trap in a corridor
     * maims, it does not kill outright, because dying to a floor tile you had
     * no way to see is not a death anybody learns anything from.
     *
     * @param array $trap  the spec: save, dc, damage, name, text
     * @param array $victim a character row — needs id, name, current_hp
     * @return array the `trap` event the client animates
     */
    public function fireTrapOn(int $partyId, array $trap, array $victim): array
    {
        $save = Rules::savingThrow($victim, (string) $trap['save']);
        $die = random_int(1, 20);
        $total = $die + (int) $save['total'];
        $dc = (int) $trap['dc'];
        $saved = $total >= $dc;

        $rolled = Dice::roll((string) $trap['damage']);
        $damage = max(0, $saved ? intdiv((int) $rolled['total'], 2) : (int) $rolled['total']);
        $newHp = max(1, (int) $victim['current_hp'] - $damage);
        $dealt = (int) $victim['current_hp'] - $newHp;
        $this->db->prepare('UPDATE characters SET current_hp = ? WHERE id = ?')
            ->execute([$newHp, (int) $victim['id']]);

        return [
            'type'   => 'trap',
            'name'   => (string) $trap['name'],
            'text'   => (string) $trap['text'],
            'victim' => (string) $victim['name'],
            'save'   => [
                'ability' => (string) $trap['save'],
                'dc'      => $dc,
                'die'     => $die,
                'total'   => $total,
                'success' => $saved,
            ],
            'damage' => $dealt,
        ];
    }

    /** Everyone in the party still on their feet, full rows. */
    private function partyWalkers(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.* FROM characters c
              INNER JOIN character_party cp ON cp.character_id = c.id
              WHERE cp.party_id = ? AND c.is_active = 1 AND c.current_hp > 0'
        );
        $stmt->execute([$partyId]);
        return $stmt->fetchAll();
    }

    /**
     * Maybe a fight steps out of the scenery.
     *
     * The chance is the location's own; the pool is the region's is_random
     * encounters. Cleared random encounters can recur — they are the weather,
     * not the plot.
     */
    private function rollRandomEncounter(int $locationId, ?int $partyId): ?array
    {
        $loc = $this->db->prepare(
            'SELECT random_encounter_pct FROM locations WHERE id = ?'
        );
        $loc->execute([$locationId]);
        $pct = (int) $loc->fetchColumn();
        if ($pct <= 0 || random_int(1, 100) > $pct) {
            return null;
        }
        return $this->wanderingFrom($locationId);
    }

    /**
     * What happens to the party where it stops (replaces resolveTileEvents).
     *
     * Only ambushes — fights authored as being what a place IS, rather than
     * fights a conversation opens. At most one at a time, so two ambushes in
     * one location happen in sequence. Ground loot is presence, not an
     * interruption; Search and Loot are how it is taken.
     */
    public function arrivalEvents(int $locationId, ?int $partyId): array
    {
        $enc = $this->pendingEncounter($locationId, $partyId);
        if (!$enc) {
            return [];
        }
        return [[
            'type'         => 'encounter',
            'encounter_id' => $enc['id'],
            'name'         => $enc['name'],
        ]];
    }

    // -----------------------------------------------------------------------
    // Search and loot
    // -----------------------------------------------------------------------

    /**
     * Look the place over. Surfaces ground loot and reveals hidden exits
     * leading out of here. Deliberately deterministic — searching is how a
     * careful player is rewarded, not a slot machine.
     */
    public function search(int $characterId): array
    {
        $char = $this->getFullCharacter($characterId);
        $locationId = (int) $char['current_location_id'];
        $partyId = $this->partyIdForCharacter($characterId);

        $messages = [];

        // The die for this search. ONE roll, spent against everything there is
        // to find here.
        //
        // Searching used to be free: every hidden way and every unmet trap
        // gave itself up the moment the button was pressed, so the action had
        // no failure state and nothing to weigh. It rolls now — Investigation,
        // actively, because the party has stopped to look. (The passive check
        // on the way in is the other half of the pair, and stays passive: you
        // do not get to roll well for walking past something.)
        //
        // Rolled once rather than per-thing so a room cannot be farmed by
        // pressing the button until the dice cooperate: a search that fails
        // here fails for all of it, and the party has to come back — which is
        // what makes a second look cost something.
        $look = Rules::skillCheck($char, 'investigation');
        $roll = random_int(1, 20);
        $total = $roll + (int) $look['total'];
        $messages[] = "You search the place. Investigation — {$roll} + " . (int) $look['total'] . " = {$total}.";

        // Reveal hidden exits the roll is good enough for.
        $hidden = $this->db->prepare(
            'SELECT e.id, e.label, e.conditions_json FROM location_exits e
              WHERE e.from_location_id = ? AND e.is_hidden = 1'
        );
        $hidden->execute([$locationId]);
        $foundNew = [];
        $missed = 0;
        if ($partyId) {
            $mark = $this->db->prepare(
                'INSERT IGNORE INTO party_exits_found (party_id, exit_id) VALUES (?, ?)'
            );
            foreach ($hidden->fetchAll() as $e) {
                if ($total < self::hiddenExitDc($e)) {
                    $missed++;
                    continue;
                }
                $mark->execute([$partyId, (int) $e['id']]);
                if ($mark->rowCount() > 0) {
                    $foundNew[] = $e['label'];
                }
            }
        }
        foreach ($foundNew as $label) {
            $messages[] = "You find a way you had not noticed: {$label}.";
        }
        if ($missed > 0) {
            // Said out loud, because "nothing here" and "something here you
            // did not find" are different facts and a player is entitled to
            // know which one they are looking at.
            $messages[] = $missed === 1
                ? 'Something about this room does not add up, and you cannot say what.'
                : 'More than one thing about this room does not add up.';
        }

        // A trap underfoot that nobody has met yet. Rare to reach — the
        // passive look on the way in usually decided this — but a party that
        // walked through lucky and then stopped to search deserves the truth
        // about the floor they are standing on.
        if ($partyId) {
            $loc = $this->db->prepare('SELECT location_key, trap_json FROM locations WHERE id = ?');
            $loc->execute([$locationId]);
            $row = $loc->fetch();
            $trap = $row && $row['trap_json'] !== null ? json_decode((string) $row['trap_json'], true) : null;
            if (is_array($trap) && !empty($trap['name'])) {
                $world = new WorldState($this->db);
                $flag = DelveEngine::trapFlag((string) $row['location_key']);
                if (!$world->isSet($partyId, $flag)) {
                    // Against the trap's own DC — the same number the passive
                    // look on the way in was measured against, so stopping to
                    // search is strictly better than walking through, without
                    // being a guarantee.
                    if ($total >= (int) $trap['dc']) {
                        $world->set($partyId, $flag, 'found');
                        $messages[] = $trap['found_text'] ?? "You find it before it finds you: {$trap['name']}.";
                    } else {
                        $messages[] = 'The floor and the frame look honest enough. You are fairly sure.';
                    }
                }
            }
        }

        $items = $this->itemsAt($locationId, $partyId);
        foreach ($items as $it) {
            $messages[] = "There is something here worth taking: {$it['name']}.";
        }

        // The roll line is always there now, so "nothing" is the case where
        // it is the ONLY thing there.
        if (count($messages) === 1) {
            $messages[] = 'Nothing here you had not already seen.';
        }

        return [
            'ok'       => true,
            'messages' => $messages,
            'items'    => $items,
            'found_exits' => count($foundNew),
        ];
    }

    /** Take one piece of ground loot. Taking is per-party; the content row stays. */
    public function lootItem(int $characterId, int $locationItemId): array
    {
        $char = $this->getFullCharacter($characterId);
        $partyId = $this->partyIdForCharacter($characterId);

        $stmt = $this->db->prepare(
            'SELECT li.id, li.location_id, li.item_id, i.*
               FROM location_items li
               INNER JOIN items i ON i.id = li.item_id
              WHERE li.id = ?'
        );
        $stmt->execute([$locationItemId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Nothing to loot.');
        }
        if ((int) $row['location_id'] !== (int) $char['current_location_id']) {
            throw new InvalidArgumentException('You are not where that lies.');
        }
        if ($partyId) {
            $taken = $this->db->prepare(
                'SELECT 1 FROM party_items_taken WHERE party_id = ? AND location_item_id = ?'
            );
            $taken->execute([$partyId, $locationItemId]);
            if ($taken->fetch()) {
                throw new InvalidArgumentException('You have already taken that.');
            }
        }

        $messages = [];
        $props = json_decode($row['properties'] ?? '{}', true) ?: [];
        if (($row['item_type'] ?? '') === 'treasure' && !empty($props['gold'])) {
            $this->db->prepare('UPDATE characters SET gold = gold + ? WHERE id = ?')
                ->execute([(int) $props['gold'], $characterId]);
            $messages[] = "You collect {$props['gold']} gold pieces.";
        } else {
            $this->db->prepare(
                'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
                 VALUES (?, ?, 1, 0)'
            )->execute([$characterId, (int) $row['item_id']]);
            $messages[] = 'You pick up: ' . $row['name'];
        }

        if ($partyId) {
            $this->db->prepare(
                'INSERT IGNORE INTO party_items_taken (party_id, location_item_id) VALUES (?, ?)'
            )->execute([$partyId, $locationItemId]);
        }

        return [
            'ok'        => true,
            'messages'  => $messages,
            'item'      => ['id' => (int) $row['item_id'], 'name' => $row['name']],
            'character' => $this->getCharacterPosition($characterId),
        ];
    }

    // -----------------------------------------------------------------------
    // Rest
    // -----------------------------------------------------------------------

    /**
     * A long rest, wherever it is taken. `$cost` is the innkeeper's price;
     * camping charges nothing — what it costs instead is that camp is where a
     * companion who has had enough of you walks out.
     */
    /**
     * An hour's breather: one hit die each, and the features that come back.
     *
     * The SRD lets a character spend as many hit dice as they like and choose
     * how many. There is nobody to ask here, so a short rest spends exactly one
     * each and can be taken again — repeating it is how "spend three" is said,
     * and it never wastes a die on somebody who is already whole.
     *
     * Hit dice spent are counted in world_flags, beside the bonus-action
     * features that are counted the same way, and a long rest gives them back.
     * Without that a short rest would be free healing on a timer and there
     * would be no reason ever to sleep.
     */
    public function shortRest(int $characterId): array
    {
        $partyId = $this->partyIdForCharacter($characterId);
        if (!$partyId) {
            throw new InvalidArgumentException('Nobody here to rest.');
        }
        if (!$this->canShortRestHere($characterId)) {
            throw new InvalidArgumentException('There is nowhere here to get your back to a wall.');
        }

        $world = new WorldState($this->db);
        $rows = $this->db->prepare(
            'SELECT c.id, c.name, c.class, c.level, c.constitution, c.current_hp, c.max_hp
               FROM characters c
               INNER JOIN character_party cp ON cp.character_id = c.id
              WHERE cp.party_id = ?'
        );
        $rows->execute([$partyId]);

        $lines = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $id = (int) $c['id'];
            $flag = self::hitDiceFlag($id);
            $spent = (int) ($world->get($partyId, $flag) ?? 0);
            $level = max(1, (int) $c['level']);

            if ((int) $c['current_hp'] >= (int) $c['max_hp']) {
                continue;                       // whole already; keep the die
            }
            if ($spent >= $level) {
                $lines[] = "{$c['name']} has no hit dice left.";
                continue;
            }

            $die = Rules::hitDie((string) $c['class']);
            $conMod = Rules::abilityMod((int) $c['constitution']);
            $roll = Dice::roll('1d' . $die);
            $gain = max(1, (int) $roll['total'] + $conMod);
            $healed = min($gain, (int) $c['max_hp'] - (int) $c['current_hp']);

            $this->db->prepare('UPDATE characters SET current_hp = current_hp + ? WHERE id = ?')
                ->execute([$healed, $id]);
            $world->set($partyId, $flag, (string) ($spent + 1));
            $lines[] = "{$c['name']} spends a hit die and recovers {$healed} HP.";
        }

        // Arcane Recovery: "once per day when you finish a short rest". Taken
        // automatically, because a wizard with expended slots and a recovery
        // going spare has no reason to decline — and there is nobody to ask.
        // Once per LONG rest, so the flag it burns is cleared by sleeping
        // rather than by the rest that spends it.
        $world2 = new WorldState($this->db);
        $rows->execute([$partyId]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $id = (int) $c['id'];
            if (!Rules::hasFeature((string) $c['class'], (int) $c['level'], 'arcane_recovery')) {
                continue;
            }
            $flag = self::arcaneRecoveryFlag($id);
            if ((int) ($world2->get($partyId, $flag) ?? 0) > 0) {
                continue;
            }
            $given = (new Spellbook($this->db))->recover(
                $id,
                Rules::arcaneRecoveryBudget((int) $c['level']),
                Rules::MAX_SLOT_LEVEL
            );
            if ($given === []) {
                continue;                       // nothing spent; keep the recovery
            }
            $world2->set($partyId, $flag, '1');
            $parts = [];
            foreach ($given as $level => $n) {
                $parts[] = "{$n} of level {$level}";
            }
            $lines[] = "{$c['name']} recovers spell slots — " . implode(', ', $parts) . '.';
        }

        (new CheckService($this->db))->resetOnShortRest($partyId);

        return [
            'ok' => true,
            'message' => $lines
                ? 'The party takes an hour. ' . implode(' ', $lines)
                : 'The party takes an hour. Nobody needed it.',
            'character' => $this->getCharacterPosition($characterId),
        ];
    }

    /** Hit dice one character has spent since their last long rest. */
    public static function hitDiceFlag(int $characterId): string
    {
        return 'hit_dice_spent_' . $characterId;
    }

    /** Whether this wizard has already used Arcane Recovery today. */
    public static function arcaneRecoveryFlag(int $characterId): string
    {
        return 'arcane_recovery_used_' . $characterId;
    }

    /** What a Paladin has drawn out of their Lay on Hands pool today. */
    public static function layOnHandsFlag(int $characterId): string
    {
        return 'lay_on_hands_spent_' . $characterId;
    }

    /** Uses of Divine Sense spent today. */
    public static function divineSenseFlag(int $characterId): string
    {
        return 'divine_sense_used_' . $characterId;
    }

    public function longRest(int $characterId, int $cost = 0, string $where = 'camp'): array
    {
        $char = $this->getFullCharacter($characterId);

        if ($cost > 0 && (int) $char['gold'] < $cost) {
            throw new InvalidArgumentException("A room costs {$cost} gp. You cannot afford it.");
        }
        if ($cost > 0) {
            $this->db->prepare('UPDATE characters SET gold = GREATEST(0, gold - ?) WHERE id = ?')
                ->execute([$cost, $characterId]);
        }

        $partyId = $this->partyIdForCharacter($characterId);
        if ($partyId) {
            $this->db->prepare(
                'UPDATE characters c
                 INNER JOIN character_party cp ON cp.character_id = c.id
                 SET c.current_hp = c.max_hp, c.spell_slots_json = NULL
                 WHERE cp.party_id = ?'
            )->execute([$partyId]);

            // And everyone else at the fire.
            //
            // A companion asked to wait here is NOT in `character_party` —
            // that table is the four who march, which is exactly what
            // CompanionService::dismiss() takes them out of. So the statement
            // above, which is the whole of what "wounds close" meant, walked
            // straight past them: a companion sent to camp at 0 hit points
            // stayed at 0 hit points through every long rest the party ever
            // took, for as long as they waited there. Nothing reported it,
            // because their card is not on the party rail to show the bar.
            //
            // Camp is where they are. Resting at it should reach them, and the
            // message the player is shown — "The party makes camp. Wounds close
            // and spells return." — already promised that it did.
            $this->db->prepare(
                'UPDATE characters c
                 INNER JOIN party_companions pc ON pc.character_id = c.id
                 SET c.current_hp = c.max_hp, c.spell_slots_json = NULL
                 WHERE pc.party_id = ? AND pc.status IN (\'active\', \'camp\')'
            )->execute([$partyId]);
            // Guidance and Bardic Inspiration are spent per rest, and the
            // tokens recording that live in world_flags, so they are cleared
            // here too or the d20 prompt keeps refusing a boost the party has
            // slept off.
            (new CheckService($this->db))->resetOnLongRest($partyId);

            // And the hit dice spent on breathers along the way. A night's
            // sleep is what makes them available again; without this a party
            // would run out of short rests permanently.
            $stmt = $this->db->prepare(
                'SELECT flag_key FROM world_flags WHERE party_id = ? AND flag_key LIKE ?'
            );
            // Hit dice, and the three per-day features that sleeping restores:
            // Arcane Recovery's one use, the Paladin's healing pool and their
            // Divine Sense. All four are counted the same way and all four come
            // back at the same moment, which is what a long rest IS.
            $world = new WorldState($this->db);
            foreach (['hit_dice_spent_%', 'arcane_recovery_used_%',
                      'lay_on_hands_spent_%', 'divine_sense_used_%'] as $like) {
                $stmt->execute([$partyId, $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
                    $world->clear($partyId, (string) $key);
                }
            }
        } else {
            $this->db->prepare(
                'UPDATE characters SET current_hp = max_hp, spell_slots_json = NULL WHERE id = ?'
            )->execute([$characterId]);
        }

        $result = [
            'ok'      => true,
            'message' => $cost > 0
                ? "You take a room for {$cost} gp. The party wakes rested — wounds closed, spells returned."
                : 'The party makes camp. Wounds close and spells return.',
            'character' => $this->getFullCharacter($characterId),
        ];

        // Somebody who has had enough of the party leaves while the fire burns
        // down, rather than mid-sentence in the conversation that offended
        // them. A departure is a scene; a disappearance is a glitch.
        if ($partyId) {
            $thresholds = (new CompanionService($this->db))->checkThresholds($partyId);
            $result['messages'] = $thresholds['messages'] ?? [];
            $result['left'] = $thresholds['left'] ?? [];
            $result['hostile'] = $thresholds['hostile'] ?? [];
        }

        return $result;
    }

    /**
     * The innkeeper's paid room. The price is the location's own — a location
     * with no inn_cost has no innkeeper to pay.
     */
    public function restAtInn(int $characterId): array
    {
        $char = $this->getFullCharacter($characterId);
        $loc = $this->getLocation((int) $char['current_location_id']);
        if ($loc['inn_cost'] === null) {
            throw new InvalidArgumentException('There is no inn here. Make camp instead.');
        }
        return $this->longRest($characterId, (int) $loc['inn_cost'], 'inn');
    }

    /**
     * Whether the party may sleep where it stands.
     *
     * Three ways to earn it. A place authored as safe, a bed paid for, or a
     * room whose every door the party has braced shut — see DoorEngine. The
     * third is the only one a delve offers that is not simply given: the floor
     * marks empty rooms camp-safe and nothing else, on the argument that a
     * long rest in the room you just cleared would make the whole descent
     * free. Barricading is what stops it being free.
     */
    public function canCampHere(int $characterId): bool
    {
        $char = $this->getFullCharacter($characterId);
        $loc = $this->getLocation((int) $char['current_location_id']);
        if ((bool) $loc['allow_camp'] || $loc['inn_cost'] !== null) {
            return true;
        }
        $partyId = $this->partyIdForCharacter($characterId);
        return (new DoorEngine($this->db))
            ->barricadeReport($partyId, (int) $char['current_location_id'])['sealed'];
    }

    /**
     * Whether the party may stop for an hour where it stands.
     *
     * Looser than sleeping, deliberately. Anywhere you could camp, plus any
     * ROOM on a delve floor — you can get your back to a wall and pass the
     * waterskin round in a chamber you have just fought through without that
     * being the same claim as bedding down in it. Passages are still no: this
     * is about having a room, not about having a minute.
     *
     * The hit dice are what keep it honest, and they always did — one die per
     * character per rest, capped at their level and only given back by a long
     * one. A party that short rests its way down a floor arrives at the bottom
     * with nothing left to spend.
     */
    public function canShortRestHere(int $characterId): bool
    {
        if ($this->canCampHere($characterId)) {
            return true;
        }
        $char = $this->getFullCharacter($characterId);
        $loc = $this->getLocation((int) $char['current_location_id']);
        return (bool) preg_match('/^_dg_\d+_\d+_r\d+$/', (string) ($loc['location_key'] ?? ''));
    }

    public function healPartial(int $characterId): array
    {
        $char = $this->getFullCharacter($characterId);
        $name = (string) $char['name'];
        $heal = min(8, (int) $char['max_hp'] - (int) $char['current_hp']);
        if ($heal <= 0) {
            return [
                'ok' => true,
                'message' => "{$name} is already at full health.",
                'healed' => 0,
                'character' => $this->getCharacterPosition($characterId),
            ];
        }
        $this->db->prepare('UPDATE characters SET current_hp = current_hp + ? WHERE id = ?')
            ->execute([$heal, $characterId]);
        return [
            'ok' => true,
            'message' => "Brother Aldric tends {$name}'s wounds. {$name} regains {$heal} HP.",
            'healed' => $heal,
            'character' => $this->getCharacterPosition($characterId),
        ];
    }

    // -----------------------------------------------------------------------
    // Routing (quest tracker directions)
    // -----------------------------------------------------------------------

    /**
     * How to get from here to there, as directions the tracker can print.
     *
     * Breadth-first over every exit regardless of lock state — a locked door
     * is still the way, and "through the east gate" is a useful answer even
     * when the gate wants something first.
     *
     * @return array{arrived:bool, hops:int, via:?string, route:string[], reachable:bool, next_location_id:?int}
     */
    public function routeTo(int $fromLocationId, int $toLocationId): array
    {
        if ($fromLocationId === $toLocationId) {
            return [
                'arrived' => true, 'hops' => 0, 'via' => null,
                'route' => [], 'reachable' => true, 'next_location_id' => null,
            ];
        }

        $rows = $this->db->query(
            'SELECT e.from_location_id, e.to_location_id, e.label, l.name AS to_name
               FROM location_exits e
               INNER JOIN locations l ON l.id = e.to_location_id'
        )->fetchAll();

        $out = [];
        foreach ($rows as $e) {
            $out[(int) $e['from_location_id']][] = $e;
        }

        $seen = [$fromLocationId => true];
        $prev = [];
        $queue = [$fromLocationId];
        $found = false;
        while ($queue) {
            $here = array_shift($queue);
            if ($here === $toLocationId) {
                $found = true;
                break;
            }
            foreach ($out[$here] ?? [] as $e) {
                $next = (int) $e['to_location_id'];
                if (isset($seen[$next])) {
                    continue;
                }
                $seen[$next] = true;
                $prev[$next] = $e;
                $queue[] = $next;
            }
        }

        if (!$found) {
            return [
                'arrived' => false, 'hops' => 0, 'via' => null,
                'route' => [], 'reachable' => false, 'next_location_id' => null,
            ];
        }

        $chain = [];
        $cursor = $toLocationId;
        while (isset($prev[$cursor])) {
            $chain[] = $prev[$cursor];
            $cursor = (int) $prev[$cursor]['from_location_id'];
        }
        $chain = array_reverse($chain);
        $first = $chain[0];

        return [
            'arrived'          => false,
            'hops'             => count($chain),
            'via'              => $first['label'] ?: ('the way to ' . $first['to_name']),
            'route'            => array_map(static fn ($e) => (string) $e['to_name'], $chain),
            'reachable'        => true,
            'next_location_id' => (int) $first['to_location_id'],
        ];
    }

    /** The id a location key resolves to, for effects and tools. */
    public function locationIdByKey(string $key): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM locations WHERE location_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int) $v : null;
    }
}
