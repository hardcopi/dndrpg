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
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The whole book for one module key, or null if there is no such module.
     *
     * @return array<string, mixed>|null
     */
    public function build(string $moduleKey): ?array
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

        return [
            'module'     => $module,
            'regions'    => $regions,
            'cast'       => $this->cast($locationIds),
            'quests'     => $this->quests($locationIds),
            'encounters' => $encounters,
            'monsters'   => $this->monsters($encounters),
            'counts'     => [
                'regions'    => count($regions),
                'locations'  => count($locationIds),
                'encounters' => count($encounters),
            ],
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
    private function regions(int $moduleId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM regions WHERE module_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$moduleId]);
        $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($regions as $i => $region) {
            $regions[$i]['locations'] = $this->locations((int) $region['id']);
        }
        return $regions;
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
    private function monsters(array $encounters): array
    {
        $keys = [];
        foreach ($encounters as $e) {
            foreach ($e['roster'] as $m) {
                $keys[$m['monster_key']] = true;
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
