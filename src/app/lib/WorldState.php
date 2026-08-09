<?php
/**
 * Party-scoped flags, and the snapshot of a playthrough that content is
 * evaluated against.
 *
 * This is the whole of the game's memory. Dialogue variants, quest stages,
 * recruitment gates and encounter outcomes all read and write here, and there
 * is no second place a fact about the playthrough can live — which is the only
 * reason an NPC can be made to remember something without a bespoke table per
 * thing worth remembering.
 *
 * Scoped to the party rather than a character on purpose. Companions join and
 * leave and the leader can change, but the party is the save; anything keyed to
 * one character would be lost or duplicated the moment the roster does.
 */

declare(strict_types=1);

class WorldState
{
    private PDO $db;

    /**
     * Flags for one party, read once per request.
     *
     * A single dialogue node can evaluate dozens of conditions across its
     * variants, its choices and its interjections, and every one of them would
     * otherwise be a round trip. Writes go through set() so this never goes
     * stale within a request.
     */
    private array $cache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Every flag for a party, keyed by name. */
    public function all(int $partyId): array
    {
        if (!isset($this->cache[$partyId])) {
            $stmt = $this->db->prepare(
                'SELECT flag_key, flag_value FROM world_flags WHERE party_id = ?'
            );
            $stmt->execute([$partyId]);
            $this->cache[$partyId] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        }
        return $this->cache[$partyId];
    }

    public function get(int $partyId, string $key): ?string
    {
        return $this->all($partyId)[$key] ?? null;
    }

    /**
     * Whether a flag counts as set.
     *
     * "0" and "" are false so that a counter at zero and a flag deliberately
     * cleared to a falsey value both read as absent. Otherwise
     * {"flag": "goblins_spared"} would pass for a party that spared none.
     */
    public function isSet(int $partyId, string $key): bool
    {
        $v = $this->get($partyId, $key);
        return $v !== null && $v !== '' && $v !== '0';
    }

    public function number(int $partyId, string $key): int
    {
        return (int) ($this->get($partyId, $key) ?? '0');
    }

    public function set(int $partyId, string $key, string $value = '1'): void
    {
        $this->db->prepare(
            'INSERT INTO world_flags (party_id, flag_key, flag_value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE flag_value = VALUES(flag_value)'
        )->execute([$partyId, $key, $value]);
        $this->all($partyId);
        $this->cache[$partyId][$key] = $value;
    }

    public function clear(int $partyId, string $key): void
    {
        $this->db->prepare('DELETE FROM world_flags WHERE party_id = ? AND flag_key = ?')
            ->execute([$partyId, $key]);
        $this->all($partyId);
        unset($this->cache[$partyId][$key]);
    }

    /**
     * Drop the cached flags for a party so the next read goes to the database.
     *
     * The cache is per instance, and more than one instance is alive in a
     * single request: DialogEngine holds one, the Effects it applies through
     * holds another. A flag written through Effects is therefore invisible to
     * DialogEngine's copy until something says so — which is why a camp topic
     * could set its told-flag and still be sitting on the menu when the same
     * request drew it again. Between requests the caches are new and the bug
     * is invisible, which is exactly why it wants naming rather than fixing
     * by accident.
     */
    public function forget(int $partyId): void
    {
        unset($this->cache[$partyId]);
    }

    /**
     * Add to a counter flag and return the new value.
     *
     * Done as one statement rather than read-modify-write because a single
     * dialogue choice can increment the same counter from an effect and a
     * quest stage in the same request.
     */
    public function increment(int $partyId, string $key, int $by = 1): int
    {
        $this->db->prepare(
            'INSERT INTO world_flags (party_id, flag_key, flag_value) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE flag_value = CAST(CAST(flag_value AS SIGNED) + ? AS CHAR)'
        )->execute([$partyId, $key, (string) $by, $by]);
        unset($this->cache[$partyId]);
        return $this->number($partyId, $key);
    }

    /**
     * Everything a content condition may ask about, gathered once.
     *
     * Requirements::pass() takes this rather than a PDO handle so that
     * evaluating fifty conditions against one dialogue node is fifty array
     * lookups, and so the evaluator itself has no database to be tested
     * against.
     */
    public function context(int $partyId, ?int $leaderCharacterId = null): array
    {
        $leader = null;
        $party = [];
        $stmt = $this->db->prepare(
            // Art resolution is CharacterGenerator's rule, borrowed rather than
            // restated. The copy that used to live here omitted the companions
            // leg, which stopped mattering only in the sense that it silently
            // gave every recruited companion their class's generic portrait.
            'SELECT c.*, ' . CharacterGenerator::SPRITE_SELECT . '
             FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             LEFT JOIN classes cl ON cl.name = c.class
             ' . CharacterGenerator::SPRITE_JOIN . '
             WHERE cp.party_id = ? AND c.is_active = 1
             ORDER BY cp.slot'
        );
        $stmt->execute([$partyId]);
        foreach ($stmt->fetchAll() as $row) {
            $party[] = $row;
            if ($leaderCharacterId !== null && (int) $row['id'] === $leaderCharacterId) {
                $leader = $row;
            }
        }
        $leader ??= $party[0] ?? null;

        // Origin tags are what {"origin": "..."} matches. Derived at creation
        // and stored, but recomputed here when absent so a character made
        // before the column existed still answers correctly.
        $tags = [];
        if ($leader) {
            $tags = self::originTags($leader);
        }

        $quests = $this->db->prepare(
            'SELECT q.quest_key, pq.status, pq.resolution, s.stage_key
             FROM party_quests pq
             INNER JOIN quests q ON q.id = pq.quest_id
             LEFT JOIN quest_stages s ON s.id = pq.current_stage_id
             WHERE pq.party_id = ?'
        );
        $quests->execute([$partyId]);
        $questState = [];
        foreach ($quests->fetchAll() as $row) {
            $questState[$row['quest_key']] = [
                'status'     => $row['status'],
                'stage'      => $row['stage_key'],
                'resolution' => $row['resolution'],
            ];
        }

        $comps = $this->db->prepare(
            'SELECT companion_key, status, approval, romance_stage, romance_locked, character_id
             FROM party_companions WHERE party_id = ?'
        );
        $comps->execute([$partyId]);
        $companionState = [];
        foreach ($comps->fetchAll() as $row) {
            $companionState[$row['companion_key']] = [
                'status'         => $row['status'],
                'approval'       => (int) $row['approval'],
                'romance_stage'  => (int) $row['romance_stage'],
                'romance_locked' => (bool) $row['romance_locked'],
                'character_id'   => $row['character_id'] ? (int) $row['character_id'] : null,
            ];
        }

        // Inventory is pooled across the party for {"has_item": ...}. A key in
        // the rogue's pack is still a key the party has, and asking the player
        // to remember who is carrying what is not a decision worth making them
        // make in a conversation.
        $items = [];
        if ($party) {
            $ids = implode(',', array_map(static fn ($p) => (int) $p['id'], $party));
            $inv = $this->db->query(
                "SELECT DISTINCT i.item_key FROM character_inventory ci
                 INNER JOIN items i ON i.id = ci.item_id
                 WHERE ci.character_id IN ({$ids}) AND i.item_key IS NOT NULL"
            );
            $items = array_flip($inv->fetchAll(PDO::FETCH_COLUMN));
        }

        return [
            'party_id'   => $partyId,
            'leader'     => $leader,
            'party'      => $party,
            'flags'      => $this->all($partyId),
            'quests'     => $questState,
            'companions' => $companionState,
            'items'      => $items,
            'origins'    => $tags,
            'gold'       => $leader ? (int) $leader['gold'] : 0,
            'level'      => $leader ? (int) $leader['level'] : 1,
        ];
    }

    /**
     * The tags {"origin": "..."} matches, from a characters row.
     *
     * Lowercased with spaces and hyphens as underscores, so "Half-Orc" and
     * "Folk Hero" become `half_orc` and `folk_hero` — the same shape an author
     * writes in a JSON file, and the same shape the loader validates.
     */
    public static function originTags(array $character): array
    {
        $out = [];
        foreach (['race', 'subrace', 'class', 'background'] as $field) {
            $v = trim((string) ($character[$field] ?? ''));
            if ($v !== '') {
                $out[] = self::tag($v);
            }
        }
        // Stored tags are additive: a quest can grant one the character sheet
        // has no column for, such as `oathbreaker` or `guild_member`.
        foreach (explode(',', (string) ($character['origin_tags'] ?? '')) as $extra) {
            $extra = trim($extra);
            if ($extra !== '') {
                $out[] = self::tag($extra);
            }
        }
        return array_values(array_unique($out));
    }

    public static function tag(string $value): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    /** The party a character belongs to, or null if they are unattached. */
    public static function partyIdFor(PDO $db, int $characterId): ?int
    {
        $stmt = $db->prepare('SELECT party_id FROM character_party WHERE character_id = ? LIMIT 1');
        $stmt->execute([$characterId]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int) $v : null;
    }
}
