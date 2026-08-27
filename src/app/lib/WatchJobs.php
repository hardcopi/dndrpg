<?php
/**
 * The Proving House watch: six jobs, in order, one at a time, down the Pit.
 *
 * Not a second quest system. Quests, stages, start_quest and quest_stage are
 * still QuestService and DialogEngine. This is the spine those sit on: which
 * contract is being offered, how dangerous the floor is, and whether the hole
 * is open. Jobs are given in talk, not pinned to a wall.
 */
declare(strict_types=1);

final class WatchJobs
{
    public const MOUTH_KEY = '_freeplay_cellar';
    public const INN_KEY = '_freeplay_yard';
    public const SANDBOX_FLAG = 'watch_sandbox';
    public const MODULE_KEY = '_freeplay';

    public const CRATE   = 'fp_watch_crate';
    public const COUSIN  = 'fp_watch_cousin';
    public const SHRINE  = 'fp_watch_shrine';
    public const WINE    = 'fp_watch_wine';
    public const NAME    = 'fp_watch_name';
    public const STAYED  = 'fp_watch_stayed';

    /** Strict order. Completing one unlocks the next giver's mouth. */
    public const ORDER = [
        self::CRATE,
        self::COUSIN,
        self::SHRINE,
        self::WINE,
        self::NAME,
        self::STAYED,
    ];

    /**
     * How DungeonGen should be asked, per job.
     *
     * `danger` is the depth PROFILE handed to generate(), not a second stair.
     * Authored floors ignore it and draw a canned layout.
     *
     * @var array<string, array{giver:string, danger:int, authored:bool}>
     */
    public const JOBS = [
        self::CRATE  => ['giver' => '_fp_odd',    'danger' => 1, 'authored' => true],
        self::COUSIN => ['giver' => '_fp_brenna', 'danger' => 1, 'authored' => false],
        self::SHRINE => ['giver' => '_fp_hessa',  'danger' => 2, 'authored' => false],
        self::WINE   => ['giver' => '_fp_odd',    'danger' => 3, 'authored' => false],
        self::NAME   => ['giver' => '_fp_hessa',  'danger' => 4, 'authored' => false],
        self::STAYED => ['giver' => '_fp_brenna', 'danger' => 1, 'authored' => true],
    ];

    /** Item that means the delve half of a fetch-job is done. */
    private const TURNIN_ITEM = [
        self::CRATE  => 'holy_symbol',
        self::COUSIN => 'fp_cousin_note',
        self::WINE   => 'fp_sour_wine',
    ];

    public static function isPit(string $locationKey): bool
    {
        return $locationKey === self::MOUTH_KEY;
    }

    public static function isWatch(string $questKey): bool
    {
        return isset(self::JOBS[$questKey]);
    }

    public static function nextKey(string $questKey): ?string
    {
        $i = array_search($questKey, self::ORDER, true);
        if ($i === false) {
            return null;
        }
        return self::ORDER[$i + 1] ?? null;
    }

    public static function objFlag(string $questKey): string
    {
        return 'watch_obj:' . $questKey;
    }

    public static function doneFlag(string $questKey): string
    {
        return 'watch_done:' . $questKey;
    }

    public static function sandboxUnlocked(PDO $db, int $partyId): bool
    {
        return (new WorldState($db))->isSet($partyId, self::SANDBOX_FLAG);
    }

    /** The watch contract this party has accepted and not finished, or null. */
    public static function activeKey(PDO $db, int $partyId): ?string
    {
        $stmt = $db->prepare(
            "SELECT q.quest_key
               FROM party_quests pq
               INNER JOIN quests q ON q.id = pq.quest_id
              WHERE pq.party_id = ? AND pq.status = 'active'
                AND q.quest_key IN (" . self::keyList() . ")
              ORDER BY pq.id
              LIMIT 1"
        );
        $stmt->execute([$partyId]);
        $key = $stmt->fetchColumn();
        return $key === false ? null : (string) $key;
    }

    /**
     * The next watch job this party may be offered, or null.
     *
     * Derived from ORDER and party_quests, not from a board. Used to keep the
     * Down button visible and to refuse it with a line that points at the
     * person, rather than hiding the hole.
     */
    public static function postedKey(PDO $db, int $partyId): ?string
    {
        if (self::activeKey($db, $partyId) !== null) {
            return null;
        }
        if (self::sandboxUnlocked($db, $partyId)) {
            return null;
        }
        $stmt = $db->prepare(
            "SELECT q.quest_key
               FROM quests q
               LEFT JOIN party_quests pq ON pq.quest_id = q.id AND pq.party_id = ?
              WHERE q.is_active = 1 AND q.quest_key IN (" . self::keyList() . ")
                AND pq.id IS NULL
              ORDER BY FIELD(q.quest_key, " . self::keyList() . ")
              LIMIT 1"
        );
        $stmt->execute([$partyId]);
        $key = $stmt->fetchColumn();
        return $key === false ? null : (string) $key;
    }

    /**
     * What this person should wear over their head, for this party.
     *
     * One mark at a time, and only the current watch giver: `offer` before
     * they take the job, `turnin` when the delve half is done and talk will
     * close it. Null for everyone else, and for the giver while the job is
     * still in the hole.
     *
     * @return 'offer'|'turnin'|null
     */
    public static function questMark(PDO $db, int $partyId, string $npcKey, array $ctx = []): ?string
    {
        $active = self::activeKey($db, $partyId);
        if ($active !== null) {
            if ((self::JOBS[$active]['giver'] ?? '') !== $npcKey) {
                return null;
            }
            return self::canTurnIn($db, $partyId, $active, $ctx) ? 'turnin' : null;
        }
        $posted = self::postedKey($db, $partyId);
        if ($posted !== null && (self::JOBS[$posted]['giver'] ?? '') === $npcKey) {
            return 'offer';
        }
        return null;
    }

    /**
     * May this party go down the Pit right now?
     *
     * @return array{allow:bool, hint:?string}
     */
    public static function pitGate(PDO $db, int $partyId): array
    {
        if (self::sandboxUnlocked($db, $partyId)) {
            return ['allow' => true, 'hint' => null];
        }
        if (self::activeKey($db, $partyId) !== null) {
            return ['allow' => true, 'hint' => null];
        }
        $posted = self::postedKey($db, $partyId);
        if ($posted !== null) {
            $giver = self::JOBS[$posted]['giver'] ?? '_fp_odd';
            $name = self::npcName($db, $giver) ?? 'the person who asked';
            return [
                'allow' => false,
                'hint'  => 'Not without a reason. Talk to ' . $name . '.',
            ];
        }
        return [
            'allow' => false,
            'hint'  => 'Not without a reason.',
        ];
    }

    public static function allowDeeper(PDO $db, int $partyId): bool
    {
        return self::sandboxUnlocked($db, $partyId);
    }

    public static function npcName(PDO $db, string $npcKey): ?string
    {
        $stmt = $db->prepare('SELECT name FROM npcs WHERE npc_key = ?');
        $stmt->execute([$npcKey]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    /**
     * Completing a watch job unlocks the next giver. Nothing is posted.
     */
    public static function onComplete(PDO $db, string $questKey): void
    {
        // Next offer is whoever JOBS names for the first watch quest this
        // party has not taken. questMark() and pitGate() derive that from
        // party_quests; a board flag would be a second copy of the fact.
        unset($db, $questKey);
    }

    /**
     * Standing in the stamped room completes the delve half of a watch job.
     */
    public static function onArrive(PDO $db, int $partyId, int $locationId): void
    {
        $job = self::activeKey($db, $partyId);
        if ($job === null) {
            return;
        }
        $stmt = $db->prepare('SELECT location_key FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);
        $here = $stmt->fetchColumn();
        if (!is_string($here) || $here === '') {
            return;
        }
        $world = new WorldState($db);
        $want = $world->get($partyId, self::objFlag($job));
        if ($want !== null && $want === $here) {
            $world->set($partyId, self::doneFlag($job));
        }
    }

    /**
     * Stamp a generated floor so this job's objective is actually on it.
     *
     * Mutates $level in place. Authored floors are built whole and skip this.
     */
    public static function stamp(array &$level, string $questKey): void
    {
        $level['watch_job'] = $questKey;
        $room = self::pickStampRoom($level);
        if ($room < 0) {
            return;
        }
        switch ($questKey) {
            case self::COUSIN:
                self::stampItem($level, $room, 'fp_cousin_note',
                    'A scrap of paper is wedged under a stone, the hand hurried: went further. do not follow. — C.');
                break;
            case self::SHRINE:
                self::stampNamed($level, $room, 'The Shrine', 'altar',
                    'A low altar stands against the far wall. Something in the stone is keeping a watch nobody asked it to keep.');
                $level['watch_objective_room'] = $room;
                break;
            case self::WINE:
                self::stampWine($level, $room);
                break;
            case self::NAME:
                self::stampNamed($level, $room, 'The Marked Wall', 'altar',
                    'A name is cut into the far wall, twice, in two different hands: Calder. The second hand is older.');
                $level['watch_objective_room'] = $room;
                break;
        }
    }

    /**
     * The delve half is done: the party has the thing, or stood in the room.
     */
    private static function canTurnIn(PDO $db, int $partyId, string $questKey, array $ctx): bool
    {
        $item = self::TURNIN_ITEM[$questKey] ?? null;
        if ($item !== null) {
            $items = $ctx['items'] ?? null;
            if (is_array($items) && isset($items[$item])) {
                return true;
            }
            if (!is_array($items)) {
                $stmt = $db->prepare(
                    "SELECT 1
                       FROM character_inventory ci
                       INNER JOIN character_party cp ON cp.character_id = ci.character_id
                       INNER JOIN items i ON i.id = ci.item_id
                      WHERE cp.party_id = ? AND i.item_key = ?
                      LIMIT 1"
                );
                $stmt->execute([$partyId, $item]);
                if ($stmt->fetchColumn() !== false) {
                    return true;
                }
            }
        }
        $flags = $ctx['flags'] ?? null;
        $done = self::doneFlag($questKey);
        if (is_array($flags)) {
            $raw = $flags[$done] ?? null;
            return $raw !== null && $raw !== '' && $raw !== '0';
        }
        return (new WorldState($db))->isSet($partyId, $done);
    }

    private static function pickStampRoom(array $level): int
    {
        $entrance = (int) ($level['entrance'] ?? 0);
        $stair = (int) ($level['stair'] ?? $entrance);
        foreach ($level['rooms'] ?? [] as $r) {
            $id = (int) ($r['id'] ?? -1);
            if ($id !== $entrance && $id !== $stair) {
                return $id;
            }
        }
        foreach ($level['rooms'] ?? [] as $r) {
            $id = (int) ($r['id'] ?? -1);
            if ($id !== $entrance) {
                return $id;
            }
        }
        return $entrance;
    }

    private static function roomIndex(array $level, int $id): ?int
    {
        foreach ($level['rooms'] as $i => $r) {
            if ((int) ($r['id'] ?? -1) === $id) {
                return $i;
            }
        }
        return null;
    }

    private static function stampItem(array &$level, int $room, string $itemKey, string $clause): void
    {
        $i = self::roomIndex($level, $room);
        if ($i === null) {
            return;
        }
        $level['rooms'][$i]['kind'] = 'treasure';
        $level['rooms'][$i]['place_items'] = [$itemKey];
        if (empty($level['rooms'][$i]['furnishing'])) {
            $level['rooms'][$i]['furnishing'] = [
                'kind'    => 'crate',
                'clause'  => 'A crate sits where somebody left it.',
                'locked'  => false,
                'dc'      => 10,
                'trapped' => false,
                'trap'    => null,
            ];
        }
        $level['rooms'][$i]['description'] = rtrim((string) $level['rooms'][$i]['description'])
            . ' ' . $clause;
    }

    private static function stampNamed(array &$level, int $room, string $name, string $dress, string $clause): void
    {
        $i = self::roomIndex($level, $room);
        if ($i === null) {
            return;
        }
        $level['rooms'][$i]['name'] = $name;
        $spec = DungeonGen::DRESSING[$dress] ?? ['clause' => $clause];
        $level['rooms'][$i]['dressing'] = ['kind' => $dress, 'clause' => $spec['clause']];
        $level['rooms'][$i]['description'] = rtrim((string) $level['rooms'][$i]['description'])
            . ' ' . $clause;
    }

    private static function stampWine(array &$level, int $room): void
    {
        $i = self::roomIndex($level, $room);
        if ($i === null) {
            return;
        }
        $trap = DungeonGen::TRAPS['middle'][2];
        $level['rooms'][$i]['kind'] = 'treasure';
        $level['rooms'][$i]['place_items'] = ['fp_sour_wine'];
        $level['rooms'][$i]['furnishing'] = [
            'kind'    => 'cabinet',
            'clause'  => 'A wine-rack is bolted to the damp wall, bottles still in it, the glass filmed green.',
            'locked'  => true,
            'dc'      => 13,
            'trapped' => true,
            'trap'    => $trap,
        ];
        $level['rooms'][$i]['description'] = rtrim((string) $level['rooms'][$i]['description'])
            . ' A wine-rack is bolted to the damp wall, bottles still in it, the glass filmed green.';
    }

    private static function keyList(): string
    {
        return implode(',', array_map(
            static fn (string $k) => "'" . str_replace("'", "''", $k) . "'",
            self::ORDER
        ));
    }
}
