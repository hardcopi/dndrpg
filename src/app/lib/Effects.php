<?php
/**
 * Applies the effect vocabulary that authored content is written in.
 *
 * The other half of Requirements: that class decides whether a line may be
 * shown, this one performs everything a line does. Dialogue choices, dialogue
 * node `on_enter`, quest stage `effects_json` and encounter outcomes all hand
 * their effect list here, which is the only reason "the innkeeper remembers you
 * burned the mill" needs no code — the flag was set by the same three lines of
 * JSON that gave you the gold for it.
 *
 * Effects run in file order, and the caller is handed one merged result
 * describing everything that happened, rather than each system telling the
 * player about itself. A dialogue choice that pays 50 gold, starts a quest and
 * opens a fight is one response with one set of messages.
 *
 * Two kinds of failure, both loud:
 *
 *   - an unknown effect key throws InvalidArgumentException. tools/load_content.py
 *     validates the vocabulary, so one reaching here is a bug in the loader or a
 *     hand-edited row. Failing silently would swallow a whole branch of a quest
 *     and read as writing rather than as a crash.
 *   - an effect naming an item, quest, companion, encounter or map that is not
 *     in the database throws RuntimeException. docs/CONTENT.md is explicit that
 *     a dangling reference is a load error; by the time it is a live playthrough
 *     the only honest thing left to do is say so.
 *
 * Runtime state is different: taking an item the party does not carry, or
 * adjusting the approval of a companion who was never recruited, is a no-op
 * with a note. Those are things content is allowed to attempt speculatively.
 */

declare(strict_types=1);

class Effects
{
    /**
     * How deep effects may cascade before we call it a loop.
     *
     * A quest stage's effects can start another quest, whose first stage has
     * effects of its own. That is legitimate and shallow. A stage that advances
     * the quest it belongs to is a content cycle, and without this it is an
     * infinite one that ends as a fatal error with no clue which quest did it.
     */
    private const MAX_DEPTH = 8;

    private PDO $db;
    private WorldState $world;
    private ?QuestService $quests = null;
    private ?CompanionService $companions = null;
    private int $depth = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->world = new WorldState($db);
    }

    /**
     * Every key a caller may read, always present.
     *
     * Returned whole even when nothing happened so the UI can destructure it
     * without guarding each field, and so a new effect that populates an
     * existing key needs no change at any call site.
     */
    public static function blank(): array
    {
        return [
            'messages'        => [],   // player-facing, in the order they happened
            'notes'           => [],   // things content asked for that did not apply
            'flags_set'       => [],   // key => new value, or null when cleared
            'approval'        => [],   // companion_key => delta actually applied
            'quests_started'  => [],
            'quests_advanced' => [],   // ['quest' => key, 'stage' => key]
            'combat'          => null, // encounter_key the caller should open
            'shop'            => null, // npc_key
            'travel'          => null, // ['location_id' =>]
            'end_dialog'      => false,
            'recruited'       => [],
            'left'            => [],
            'hostile'         => [],   // companions who turned; the caller starts the fight
            'level_ups'       => [],
        ];
    }

    /**
     * Fold one result into another.
     *
     * Lists concatenate and approval deltas sum, because two effects adjusting
     * the same companion in one choice is ordinary authoring. The scalars —
     * combat, shop, travel — keep the first value set: two of them in one list
     * is a content bug either way, and taking the first at least means the
     * fight the author wrote before the shop is the one that opens.
     *
     * Pure, so it is testable without a database and cannot be the thing that
     * loses a message.
     */
    public static function merge(array $into, array $from): array
    {
        foreach (['messages', 'notes', 'quests_started', 'quests_advanced',
                  'recruited', 'left', 'hostile', 'level_ups'] as $list) {
            if (!empty($from[$list])) {
                $into[$list] = array_merge($into[$list] ?? [], (array) $from[$list]);
            }
        }
        foreach (($from['flags_set'] ?? []) as $key => $value) {
            $into['flags_set'][$key] = $value;
        }
        foreach (($from['approval'] ?? []) as $key => $delta) {
            $into['approval'][$key] = ($into['approval'][$key] ?? 0) + (int) $delta;
        }
        foreach (['combat', 'shop', 'travel'] as $scalar) {
            if (($into[$scalar] ?? null) === null && ($from[$scalar] ?? null) !== null) {
                $into[$scalar] = $from[$scalar];
            }
        }
        $into['end_dialog'] = !empty($into['end_dialog']) || !empty($from['end_dialog']);
        return $into;
    }

    /**
     * Apply a list of effects to a party.
     *
     * `$opts` carries who is acting: `character_id` receives items and pays or
     * pockets gold, defaulting to the party leader. Everything else — flags,
     * quests, companions, XP — belongs to the party as a whole, because the
     * roster changes and the save does not.
     */
    public function apply(int $partyId, array $effects, array $opts = []): array
    {
        $result = self::blank();
        if (!$effects) {
            return $result;
        }
        if ($this->depth >= self::MAX_DEPTH) {
            throw new RuntimeException(
                'Effects nested more than ' . self::MAX_DEPTH . ' deep; content has a cycle.'
            );
        }

        $this->depth++;
        try {
            foreach ($effects as $effect) {
                if (!is_array($effect)) {
                    throw new InvalidArgumentException(
                        'An effect must be an object: ' . json_encode($effect, JSON_UNESCAPED_SLASHES)
                    );
                }
                $result = self::merge($result, $this->one($partyId, $effect, $opts));
            }
        } finally {
            $this->depth--;
        }
        return $result;
    }

    // -----------------------------------------------------------------------

    private function one(int $partyId, array $e, array $opts): array
    {
        $r = self::blank();

        if (isset($e['set_flag'])) {
            $key = (string) $e['set_flag'];
            $value = (string) ($e['value'] ?? '1');
            $this->world->set($partyId, $key, $value);
            $r['flags_set'][$key] = $value;
            return $r;
        }
        if (isset($e['clear_flag'])) {
            $key = (string) $e['clear_flag'];
            $this->world->clear($partyId, $key);
            $r['flags_set'][$key] = null;
            return $r;
        }
        if (isset($e['increment_flag'])) {
            $key = (string) $e['increment_flag'];
            $now = $this->world->increment($partyId, $key, (int) ($e['by'] ?? 1));
            $r['flags_set'][$key] = (string) $now;
            return $r;
        }
        if (isset($e['approval'])) {
            return $this->approval($partyId, (array) $e['approval']);
        }
        if (isset($e['give_item'])) {
            return $this->giveItem($partyId, (string) $e['give_item'], (int) ($e['quantity'] ?? 1), $opts);
        }
        if (isset($e['take_item'])) {
            return $this->takeItem($partyId, (string) $e['take_item'], (int) ($e['quantity'] ?? 1));
        }
        if (isset($e['give_gold'])) {
            return $this->gold($partyId, (int) $e['give_gold'], $opts);
        }
        if (isset($e['take_gold'])) {
            return $this->gold($partyId, -(int) $e['take_gold'], $opts);
        }
        if (isset($e['xp'])) {
            return $this->xp($partyId, (int) $e['xp']);
        }
        if (isset($e['start_quest'])) {
            return $this->startQuest($partyId, (string) $e['start_quest']);
        }
        if (isset($e['quest_stage'])) {
            $spec = (array) $e['quest_stage'];
            return $this->quests()->advance($partyId, (string) $spec['quest'], (string) $spec['stage']);
        }
        if (isset($e['fail_quest'])) {
            return $this->quests()->fail($partyId, (string) $e['fail_quest']);
        }
        if (isset($e['recruit'])) {
            return $this->companions()->recruit($partyId, (string) $e['recruit']);
        }
        if (isset($e['companion_leaves'])) {
            return $this->companions()->leave($partyId, (string) $e['companion_leaves']);
        }
        if (isset($e['romance'])) {
            $spec = (array) $e['romance'];
            return $this->companions()->setRomanceStage(
                $partyId,
                (string) $spec['companion'],
                (int) ($spec['stage'] ?? 1)
            );
        }
        if (isset($e['start_combat'])) {
            return $this->startCombat((string) $e['start_combat']);
        }
        if (isset($e['open_shop'])) {
            return $this->openShop((string) $e['open_shop']);
        }
        if (isset($e['rest'])) {
            return $this->rest($partyId, (string) $e['rest']);
        }
        if (isset($e['heal'])) {
            return $this->heal($partyId, (int) $e['heal']);
        }
        if (isset($e['travel'])) {
            return $this->travel($partyId, (array) $e['travel']);
        }

        throw new InvalidArgumentException(
            'Unknown effect: ' . json_encode($e, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Adjust how companions feel, and act on it if one has had enough.
     *
     * Only recruited companions have an opinion to move. Content is written
     * against the whole cast — the same choice is authored to please Kessa and
     * appal Aldric whether or not either is travelling with you — so a delta
     * aimed at someone not in the party is a note, not a failure.
     *
     * Crossing `hostile_at` is resolved here and now, because docs/CONTENT.md
     * says a companion who turns does it on the spot. Crossing `leave_at` is
     * not: they walk out at the next camp, which is where checkThresholds runs.
     */
    private function approval(int $partyId, array $deltas): array
    {
        $r = self::blank();
        foreach ($deltas as $key => $delta) {
            $adjusted = $this->companions()->adjustApproval($partyId, (string) $key, (int) $delta);
            if (!$adjusted['applied']) {
                $r['notes'][] = $adjusted['note'];
                continue;
            }
            $r['approval'][(string) $key] = (int) $delta;
            if ($adjusted['crossed'] === 'hostile') {
                $r = self::merge($r, $this->companions()->turnHostile($partyId, (string) $key));
            }
            // The two crossings that used to pass in silence. Romance opening
            // is the one a player reported never finding; the leave warning
            // is its dark twin — the walk-out itself still waits for camp
            // (checkThresholds), but the player deserves the look they would
            // get across a real fire.
            if ($adjusted['crossed'] === 'romance') {
                $r['messages'][] = $adjusted['name']
                    . ' has started looking at you differently.';
            }
            if ($adjusted['crossed'] === 'leave') {
                $r['messages'][] = $adjusted['name']
                    . ' has gone very quiet. This will come to words at the next camp.';
            }
        }
        return $r;
    }

    private function giveItem(int $partyId, string $itemKey, int $quantity, array $opts): array
    {
        $r = self::blank();
        $quantity = max(1, $quantity);
        $item = $this->itemByKey($itemKey);
        $holder = $this->actor($partyId, $opts);
        if ($holder === null) {
            $r['notes'][] = "No one is here to carry {$item['name']}.";
            return $r;
        }

        // Stack onto an unequipped pile of the same thing rather than adding a
        // second row. Equipped rows are left alone because equipment is tracked
        // per row, and merging a spare longsword into the one being swung would
        // equip both or neither.
        $stack = $this->db->prepare(
            'UPDATE character_inventory SET quantity = quantity + ?
             WHERE character_id = ? AND item_id = ? AND is_equipped = 0
             LIMIT 1'
        );
        $stack->execute([$quantity, $holder, (int) $item['id']]);
        if ($stack->rowCount() === 0) {
            $this->db->prepare(
                'INSERT INTO character_inventory (character_id, item_id, quantity, is_equipped)
                 VALUES (?,?,?,0)'
            )->execute([$holder, (int) $item['id'], $quantity]);
        }

        $r['messages'][] = $quantity > 1
            ? "Received {$quantity} × {$item['name']}."
            : "Received {$item['name']}.";
        return $r;
    }

    /**
     * Remove an item from the party's packs, wherever it is.
     *
     * Inventory is pooled for `{"has_item": ...}`, so it has to be pooled here
     * too: a condition that passes because the rogue has the key, followed by a
     * take that only searches the leader, is a door that opens and a key that
     * is still in your pocket.
     */
    private function takeItem(int $partyId, string $itemKey, int $quantity): array
    {
        $r = self::blank();
        $quantity = max(1, $quantity);
        $item = $this->itemByKey($itemKey);
        $ids = $this->partyCharacterIds($partyId);
        if (!$ids) {
            $r['notes'][] = "No party to take {$item['name']} from.";
            return $r;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->prepare(
            "SELECT id, quantity FROM character_inventory
             WHERE item_id = ? AND character_id IN ({$placeholders})
             ORDER BY is_equipped, id"
        );
        $rows->execute(array_merge([(int) $item['id']], $ids));

        $remaining = $quantity;
        foreach ($rows->fetchAll() as $row) {
            if ($remaining <= 0) {
                break;
            }
            $held = (int) $row['quantity'];
            if ($held <= $remaining) {
                $this->db->prepare('DELETE FROM character_inventory WHERE id = ?')->execute([(int) $row['id']]);
                $remaining -= $held;
            } else {
                $this->db->prepare('UPDATE character_inventory SET quantity = quantity - ? WHERE id = ?')
                    ->execute([$remaining, (int) $row['id']]);
                $remaining = 0;
            }
        }

        $taken = $quantity - $remaining;
        if ($taken === 0) {
            $r['notes'][] = "The party has no {$item['name']} to give up.";
            return $r;
        }
        $r['messages'][] = $taken > 1
            ? "Handed over {$taken} × {$item['name']}."
            : "Handed over {$item['name']}.";
        return $r;
    }

    /**
     * Gold moves through one purse.
     *
     * The party's money sits on the acting character — combat already pays its
     * loot to the leader alone — so there is one number to check against a
     * `{"gold_at_least": 50}` condition. Spending is clamped at zero rather
     * than refused, because an effect list has already half-run by the time it
     * reaches the price and a negative balance is worse than a discount.
     */
    private function gold(int $partyId, int $delta, array $opts): array
    {
        $r = self::blank();
        $who = $this->actor($partyId, $opts);
        if ($who === null) {
            $r['notes'][] = 'No one is here to hold the money.';
            return $r;
        }
        if ($delta >= 0) {
            $this->db->prepare('UPDATE characters SET gold = gold + ? WHERE id = ?')
                ->execute([$delta, $who]);
            $r['messages'][] = "Gained {$delta} gp.";
        } else {
            $this->db->prepare('UPDATE characters SET gold = GREATEST(0, CAST(gold AS SIGNED) - ?) WHERE id = ?')
                ->execute([-$delta, $who]);
            $r['messages'][] = 'Paid ' . (-$delta) . ' gp.';
        }
        return $r;
    }

    /**
     * Experience, split the way CombatEngine::finalizeCombat() splits it.
     *
     * Everyone present gets an equal share of the award rather than the whole
     * of it, so that talking your way past the mill guards and killing them are
     * worth the same to the party and the remainder is dropped either way.
     */
    private function xp(int $partyId, int $amount): array
    {
        $r = self::blank();
        if ($amount <= 0) {
            return $r;
        }
        $ids = $this->partyCharacterIds($partyId);
        if (!$ids) {
            $r['notes'][] = 'No party to award experience to.';
            return $r;
        }
        $each = intdiv($amount, count($ids));
        if ($each <= 0) {
            $r['notes'][] = "{$amount} XP does not divide across the party.";
            return $r;
        }
        $r['messages'][] = "Each of you gains {$each} experience.";
        foreach ($ids as $id) {
            $levelUp = $this->grantXp($id, $each);
            if ($levelUp !== null) {
                $r['level_ups'][] = $levelUp;
                $r['messages'][] = "{$levelUp['name']} reaches level {$levelUp['level']}!";
            }
        }
        return $r;
    }

    /**
     * Add XP to one character and level them if it is enough.
     *
     * The level is recomputed from their total rather than stepped, so a large
     * award carries them as far as it should. HP is granted per level gained
     * for the same reason.
     */
    private function grantXp(int $characterId, int $xp): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT name, class, level, experience, constitution FROM characters WHERE id = ?'
        );
        $stmt->execute([$characterId]);
        $c = $stmt->fetch();
        if (!$c) {
            return null;
        }

        $total = (int) $c['experience'] + $xp;
        $was = (int) $c['level'];
        $now = max($was, Rules::levelForXp($total));

        if ($now === $was) {
            $this->db->prepare('UPDATE characters SET experience = ? WHERE id = ?')
                ->execute([$total, $characterId]);
            return null;
        }

        // Level and proficiency move now; hit points and choices do not.
        //
        // XP is granted from inside a dialogue node as readily as from a fight,
        // and a conversation cannot stop halfway through to ask which ability
        // score somebody would like to raise. So the level itself is applied —
        // spell slots, save DCs and the proficiency bonus all read it, and
        // leaving it behind would be a character who is owed a level rather
        // than one who has it — while the hit die and the choices are recorded
        // as owed for the player to claim. See LevelUpService.
        $this->db->prepare(
            'UPDATE characters SET experience = ?, level = ?, proficiency_bonus = ? WHERE id = ?'
        )->execute([$total, $now, Rules::proficiencyBonus($now), $characterId]);

        (new LevelUpService($this->db))->record($characterId, $was, $now);

        return [
            'character_id' => $characterId,
            'name'         => (string) $c['name'],
            'level'        => $now,
            'hit_die'      => Rules::hitDie((string) $c['class']),
            // No 'hp_gained': nothing has been gained yet. The player rolls for
            // it. A key that used to carry a number and now always carried zero
            // would be read by something as "levelled for no hit points".
            'awaiting_roll' => true,
        ];
    }

    /**
     * Take a job, unless the party is too green for it.
     *
     * `QuestService::start` throws on a `required_level` it cannot meet, which
     * is right where the caller can act on the refusal — the job board simply
     * leaves the row out of the list. It is wrong here. An effect runs in the
     * middle of something already happening: a reply already given, or a
     * dialogue node already being entered. Throwing turns "you are not ready
     * for this yet" into a 400 that kills the conversation, and worse, kills it
     * halfway through the effect list — Sera's recruit_offer recruits her and
     * THEN starts the quest, so the throw left her recruited with no quest.
     *
     * So an ungated start still starts; a gated one is reported as a note and
     * the conversation carries on. Nothing is lost: these on_enter effects run
     * again on the next entry, so the job starts by itself once the party has
     * the level for it. The dialogue engine greys the replies that offer work
     * this would decline, so the player is told rather than left guessing.
     */
    private function startQuest(int $partyId, string $questKey): array
    {
        $need = $this->quests()->levelGate($partyId, $questKey);
        if ($need !== null) {
            $quest = $this->quests()->questByKeyOrNull($questKey);
            $r = self::blank();
            $r['notes'][] = sprintf(
                'Not started (needs level %d): %s',
                $need,
                $quest['title'] ?? $questKey
            );
            return $r;
        }
        return $this->quests()->start($partyId, $questKey);
    }

    private function startCombat(string $encounterKey): array
    {
        $stmt = $this->db->prepare('SELECT name FROM encounters WHERE encounter_key = ?');
        $stmt->execute([$encounterKey]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new RuntimeException("start_combat names no imported encounter: {$encounterKey}");
        }
        $r = self::blank();
        $r['combat'] = $encounterKey;
        // A fight cannot open behind a conversation still on screen.
        $r['end_dialog'] = true;
        $r['messages'][] = (string) $name . '!';
        return $r;
    }

    /**
     * Open a shop, named by the merchant who keeps it.
     *
     * The only reference here that is not resolved to a row and thrown on:
     * tools/load_content.py deliberately checks nothing but the shape of this
     * string, because no table holds a shop yet. Throwing on it would make the
     * engine stricter than the loader, which means the first anyone hears of a
     * typo is a 500 in the middle of a conversation.
     */
    private function openShop(string $npcKey): array
    {
        $stmt = $this->db->prepare('SELECT is_merchant FROM npcs WHERE npc_key = ?');
        $stmt->execute([$npcKey]);
        $row = $stmt->fetch();

        $r = self::blank();
        $r['shop'] = $npcKey;
        if (!$row) {
            $r['notes'][] = "open_shop names no imported NPC: {$npcKey}";
        } elseif (!$row['is_merchant']) {
            $r['notes'][] = "{$npcKey} is not flagged as a merchant.";
        }
        return $r;
    }

    /**
     * A rest heals the party and settles up with the companions.
     *
     * No charge, unlike LocationEngine::restAtInn — an authored `{"rest": "inn"}`
     * is the innkeeper having already agreed, and billing the player for a
     * scene they were given would read as a bug.
     *
     * This is also the "next camp" that docs/CONTENT.md defers a departure to.
     * Anyone whose approval fell through the floor during the day leaves while
     * the fire burns down, which is a scene, rather than mid-sentence in the
     * conversation that offended them, which is a glitch.
     */
    private function rest(int $partyId, string $where): array
    {
        $r = self::blank();
        $this->db->prepare(
            'UPDATE characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             SET c.current_hp = c.max_hp, c.spell_slots_json = NULL
             WHERE cp.party_id = ?'
        )->execute([$partyId]);

        $r['messages'][] = $where === 'inn'
            ? 'You take a room for the night. The party wakes rested.'
            : 'The party makes camp. Wounds close and spells return.';

        return self::merge($r, $this->companions()->checkThresholds($partyId));
    }

    private function heal(int $partyId, int $amount): array
    {
        $r = self::blank();
        if ($amount <= 0) {
            return $r;
        }
        $this->db->prepare(
            'UPDATE characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             SET c.current_hp = LEAST(c.max_hp, c.current_hp + ?)
             WHERE cp.party_id = ?'
        )->execute([$amount, $partyId]);
        $r['messages'][] = "The party recovers up to {$amount} hit points.";
        return $r;
    }

    /**
     * Move the whole party at once.
     *
     * Locations are keyed in content and numbered in the database; the key is
     * resolved here rather than at import so that a travel effect reads as a
     * place. The party moves together because there is one party and it is in
     * one place.
     */
    private function travel(int $partyId, array $spec): array
    {
        $key = (string) ($spec['location'] ?? '');
        $stmt = $this->db->prepare('SELECT id, name FROM locations WHERE location_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $loc = $stmt->fetch();
        if (!$loc) {
            throw new RuntimeException("travel names no location: {$key}");
        }
        $locationId = (int) $loc['id'];

        // Deliberately does NOT record the visit: LocationEngine::getState()
        // does that when the client next asks where it is, and its return
        // value is what decides whether the first-visit paragraph is shown.
        // Marking it here would mean a place a conversation sent you to never
        // read as somewhere new.
        $this->db->prepare(
            'UPDATE characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             SET c.current_location_id = ?
             WHERE cp.party_id = ?'
        )->execute([$locationId, $partyId]);

        $r = self::blank();
        $r['travel'] = ['location_id' => $locationId];
        $r['messages'][] = "You travel to {$loc['name']}.";
        $r['end_dialog'] = true;
        return $r;
    }

    // -----------------------------------------------------------------------

    private function itemByKey(string $itemKey): array
    {
        $stmt = $this->db->prepare('SELECT id, name FROM items WHERE item_key = ?');
        $stmt->execute([$itemKey]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("No imported item with key: {$itemKey}");
        }
        return $row;
    }

    /** @return int[] */
    private function partyCharacterIds(int $partyId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             WHERE cp.party_id = ? AND c.is_active = 1
             ORDER BY cp.slot'
        );
        $stmt->execute([$partyId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Who is holding the purse: whoever the caller says is acting, else the
     * leader, else whoever is standing in the first slot. Checked against the
     * party rather than trusted, because `character_id` arrives from a request.
     */
    private function actor(int $partyId, array $opts): ?int
    {
        $ids = $this->partyCharacterIds($partyId);
        if (!$ids) {
            return null;
        }
        $wanted = (int) ($opts['character_id'] ?? 0);
        if ($wanted > 0 && in_array($wanted, $ids, true)) {
            return $wanted;
        }
        $stmt = $this->db->prepare('SELECT leader_character_id FROM parties WHERE id = ?');
        $stmt->execute([$partyId]);
        $leader = (int) $stmt->fetchColumn();
        return in_array($leader, $ids, true) ? $leader : $ids[0];
    }

    /**
     * Quests and companions are built lazily and kept, so that a cascade of
     * effects shares one instance of each — and, with it, one depth counter and
     * one flag cache.
     */
    private function quests(): QuestService
    {
        return $this->quests ??= new QuestService($this->db, $this);
    }

    private function companions(): CompanionService
    {
        return $this->companions ??= new CompanionService($this->db);
    }
}
