<?php
/**
 * Levelling up, as something the player does.
 *
 * The split this exists to make
 * -----------------------------
 * XP arrives from two places — CombatEngine::grantXpAndGold when something
 * dies, Effects::grantXp from a quest reward or a line of dialogue — and
 * neither is a place to stop and ask a question. A conversation cannot pause
 * halfway through a node to ask which ability score you would like, and a
 * combat round cannot wait on a die the player has not thrown yet.
 *
 * So granting XP no longer completes a level. It records the level as *owed*:
 * the character's `level` and proficiency bonus move immediately, because those
 * are not choices and everything from spell slots to save DCs reads them, but
 * hit points and choices wait in `pending_level_ups` until the player claims
 * them. Claiming is this class.
 *
 * Everything here is server-authoritative. The client asks to roll and is told
 * what the die showed; it never sends a number. That is the same rule
 * CheckService follows, and for the same reason — a hit point total is worth
 * more to a cheat than a skill check is.
 */

declare(strict_types=1);

class LevelUpService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =======================================================================
    // Recording — called by whatever granted the experience
    // =======================================================================

    /**
     * Note that a character has reached one or more new levels.
     *
     * Writes one row per level crossed, so a single fat XP award that carries
     * somebody from 2 to 4 is two ceremonies and two hit dice, not one lump.
     * The unique key on (character_id, new_level) makes this safe to call
     * twice with the same span — a retried request adds nothing.
     *
     * Does NOT touch hit points. That is the whole point.
     *
     * @return int how many level-ups were newly recorded
     */
    public function record(int $characterId, int $fromLevel, int $toLevel): int
    {
        if ($toLevel <= $fromLevel) {
            return 0;
        }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO pending_level_ups (character_id, new_level) VALUES (?, ?)'
        );
        $recorded = 0;
        for ($level = $fromLevel + 1; $level <= $toLevel; $level++) {
            $stmt->execute([$characterId, $level]);
            $recorded += $stmt->rowCount();
        }
        return $recorded;
    }

    // =======================================================================
    // Reading — what is owed, and what it offers
    // =======================================================================

    /**
     * Unclaimed level-ups across a party, oldest level first.
     *
     * Ordered by level rather than by id so that a character owed two levels
     * works through them in the order they were earned; the level 4 ceremony
     * offering an ability increase must not land before the level 3 one.
     *
     * @return array<int, array>
     */
    public function pendingForParty(int $partyId): array
    {
        $stmt = $this->db->prepare(
            // Membership lives in character_party, not on the character row —
            // a character can be in a party without the party being on them.
            //
            // Art goes through CharacterGenerator's rule rather than reading
            // c.sprite_key, which is NULL for every recruited companion and
            // would hand back no portrait at all.
            'SELECT p.id, p.character_id, p.new_level, p.hp_roll, p.hp_gain, p.choices_json,
                    c.name, c.class, ' . CharacterGenerator::SPRITE_SELECT . '
               FROM pending_level_ups p
               INNER JOIN characters c ON c.id = p.character_id
               INNER JOIN character_party cp ON cp.character_id = c.id
               LEFT JOIN classes cl ON cl.name = c.class
               ' . CharacterGenerator::SPRITE_JOIN . '
              WHERE cp.party_id = ? AND p.resolved_at IS NULL
              ORDER BY p.new_level, p.id'
        );
        $stmt->execute([$partyId]);
        return $stmt->fetchAll();
    }

    /**
     * The full ceremony for one pending row: what has happened, what is left.
     *
     * The client renders this and nothing else — every list it shows, and every
     * question of whether a step is still outstanding, is decided here.
     */
    public function ceremony(int $pendingId): array
    {
        $pending = $this->row($pendingId);
        $character = $this->character((int) $pending['character_id']);
        $class = (string) $character['class'];
        $level = (int) $pending['new_level'];
        $choices = $this->choicesOf($pending);

        $hitDie = Rules::hitDie($class);
        $conMod = Rules::abilityMod((int) $character['constitution']);

        $wantsAsi = Rules::isAsiLevel($level);
        $wantsSpells = Rules::spellsLearnedAt($class, $level);

        return [
            'pending_id' => (int) $pending['id'],
            'character'  => [
                'id'         => (int) $character['id'],
                'name'       => (string) $character['name'],
                'class'      => $class,
                'sprite_key' => $character['sprite_key'] ?? null,
                'level'      => (int) $character['level'],
                'max_hp'     => (int) $character['max_hp'],
                'abilities'  => $this->abilitiesOf($character),
            ],
            'new_level'  => $level,
            // Nothing is outstanding and the row is closed. Reported rather
            // than signalled by refusing to answer, which is what made a
            // finished ceremony indistinguishable from a broken one.
            'settled'    => $pending['resolved_at'] !== null,

            'hp' => [
                'hit_die'  => $hitDie,
                'con_mod'  => $conMod,
                'rolled'   => $pending['hp_roll'] !== null ? (int) $pending['hp_roll'] : null,
                'gained'   => $pending['hp_gain'] !== null ? (int) $pending['hp_gain'] : null,
                'done'     => $pending['hp_roll'] !== null,
            ],

            'asi' => [
                'offered'  => $wantsAsi,
                'done'     => !$wantsAsi || isset($choices['asi']) || isset($choices['feat']),
                'feats'    => $wantsAsi ? Feats::offerTo($character) : [],
                'taken'    => $choices['asi'] ?? null,
                'feat'     => $choices['feat'] ?? null,
                // The name as well as the key, because a feat that has been
                // taken is no longer in the offer the summary would otherwise
                // have to look it up in — and 'resilient_constitution' is not
                // what anybody chose.
                'feat_name' => isset($choices['feat'])
                    ? (Feats::get((string) $choices['feat'])['name'] ?? $choices['feat'])
                    : null,
                'asi_feat'  => self::ASI_FEAT,
            ],

            'spells' => [
                'offered'   => $wantsSpells,
                'done'      => $wantsSpells === 0 || isset($choices['spells']),
                'available' => $wantsSpells > 0 ? $this->spellsOnOffer($character, $level) : [],
                'chosen'    => $choices['spells'] ?? null,
            ],
        ];
    }

    /**
     * Spells this character could learn at this level.
     *
     * Filtered three ways, and each filter is load-bearing:
     *   - the class's own SRD list, via `spells.class_list`;
     *   - a slot level they can actually cast at, so a level-5 Ranger is not
     *     shown level-3 spells HALF_CASTER_SLOTS will never give them;
     *   - nothing they already know.
     *
     * @return array<int, array>
     */
    private function spellsOnOffer(array $character, int $level): array
    {
        $class = (string) $character['class'];
        $maxLevel = Rules::maxCastableSpellLevel($class, $level);

        $stmt = $this->db->prepare(
            'SELECT s.id, s.spell_key, s.name, s.level, s.school, s.description,
                    s.damage_dice, s.damage_type, s.resolution
               FROM spells s
              WHERE FIND_IN_SET(?, s.class_list)
                AND s.level <= ?
                AND s.id NOT IN (
                    SELECT cs.spell_id FROM character_spells cs WHERE cs.character_id = ?
                )
              ORDER BY s.level, s.name'
        );
        $stmt->execute([$class, $maxLevel, (int) $character['id']]);
        return $stmt->fetchAll();
    }

    // =======================================================================
    // Claiming
    // =======================================================================

    /**
     * Roll the hit die for a pending level.
     *
     * Straight d(hit die) plus the CON modifier, with no floor. A 1 is a 1 —
     * the fixed-average path in Rules::hpPerLevel() clamps to a minimum of 1
     * because nothing is watching it; this does not, because the player is, and
     * softening a bad roll after the fact is worse than the bad roll.
     *
     * Idempotent by refusal rather than by re-rolling: asking twice is either a
     * double-click or somebody shopping for a better number, and both should
     * get the die that already landed.
     */
    public function rollHp(int $pendingId): array
    {
        $pending = $this->pending($pendingId);
        if ($pending['hp_roll'] !== null) {
            throw new InvalidArgumentException('That hit die has already been rolled.');
        }

        $character = $this->character((int) $pending['character_id']);
        $hitDie = Rules::hitDie((string) $character['class']);
        $conMod = Rules::abilityMod((int) $character['constitution']);

        $roll = Dice::rollDetailed('1d' . $hitDie);
        $face = (int) $roll['total'];
        // Tough is 2 more hit points for every level, and this is the moment a
        // level arrives. Its retroactive half — the levels already lived — is
        // paid once by grantFeat() when the feat is taken; this is the standing
        // order that keeps paying afterwards.
        $gain = Rules::hpFromRoll($face, $conMod) + Feats::sum($character, 'hp_per_level');
        // Draconic Resilience is Tough's smaller cousin and follows the same
        // standing order. No retroactive half: the Sorcerer's subclass arrives
        // at 1st, so creation already paid the levels before this one.
        if (Rules::subclassFeature($character, 'draconic_resilience')) {
            $gain += 1;
        }

        // Only open a transaction if nobody above us already has. PDO has no
        // nested transactions — beginTransaction() inside one throws — and this
        // is reachable from places that do their own: a quest reward is applied
        // inside QuestService's transaction, and the test harness wraps
        // everything in one so it can roll a scratch character back.
        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare(
                'UPDATE pending_level_ups SET hp_roll = ?, hp_gain = ? WHERE id = ? AND hp_roll IS NULL'
            )->execute([$face, $gain, $pendingId]);

            // Maximum hit points can fall on a bad enough roll with a negative
            // CON, but never below the level count: a level 3 character with 2
            // hit points maximum is a character the rest of the game cannot
            // reason about. Current hit points move by the same amount so that
            // levelling neither heals nor wounds.
            $this->db->prepare(
                'UPDATE characters
                    SET max_hp = GREATEST(level, max_hp + ?),
                        current_hp = LEAST(GREATEST(1, current_hp + ?), GREATEST(level, max_hp + ?))
                  WHERE id = ?'
            )->execute([$gain, $gain, $gain, (int) $character['id']]);

            if ($owned) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($owned) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->settleIfComplete($pendingId);

        return [
            'ok'       => true,
            'hit_die'  => $hitDie,
            'roll'     => $face,
            'con_mod'  => $conMod,
            'gained'   => $gain,
            'ceremony' => $this->ceremony($pendingId),
        ];
    }

    /**
     * The catalogue key of the increase itself.
     *
     * SRD 5.2 makes the Ability Score Improvement a feat like any other, so it
     * is one in Feats::CATALOG and the level-up screen shows one list rather
     * than an increase-or-feat toggle. It is named here because this class has
     * to recognise it and route it to the arithmetic below rather than to
     * grantFeat() — it is the only entry whose effect is a question for the
     * player instead of a number in the catalogue.
     */
    public const ASI_FEAT = 'ability_score_improvement';

    /**
     * Take the increase, or a feat, at a level that offers one.
     *
     * `$asi` is ability key => points, and must total 2 across at most two
     * abilities with nothing pushed past 20 — the SRD shape of the choice, and
     * unchanged. `$featKey` names what was chosen from the list.
     *
     * Three shapes arrive here and all three are honoured:
     *
     *   - `$featKey` naming the Ability Score Improvement, with `$asi` carrying
     *     the points. This is what the client sends now.
     *   - `$featKey` null and `$asi` set, which is what the client sent before
     *     the increase became a feat. Read as the increase, because that is
     *     what it was.
     *   - `$featKey` naming any other feat, with `$asi` ignored.
     *
     * A feat that carries an ability increase of its own — Resilient, Observant,
     * most of the general list — records BOTH a `feat` and an `asi` in the
     * pending row, because both happened and the summary should say so.
     */
    public function chooseAsi(int $pendingId, ?array $asi, ?string $featKey): array
    {
        $pending = $this->pending($pendingId);
        $level = (int) $pending['new_level'];
        if (!Rules::isAsiLevel($level)) {
            throw new InvalidArgumentException('That level does not offer an ability increase.');
        }
        $choices = $this->choicesOf($pending);
        if (isset($choices['asi']) || isset($choices['feat'])) {
            throw new InvalidArgumentException('That choice has already been made.');
        }

        $character = $this->character((int) $pending['character_id']);
        $key = $featKey === null ? '' : Feats::key($featKey);

        if ($key === '' || $key === self::ASI_FEAT) {
            $choices['asi'] = $this->applyAsi($character, $asi ?? []);
        } else {
            $granted = $this->grantFeat((int) $character['id'], $key);
            $choices['feat'] = $key;
            if ($granted['abilities'] !== []) {
                $choices['asi'] = $granted['abilities'];
            }
        }

        $this->db->prepare('UPDATE pending_level_ups SET choices_json = ? WHERE id = ?')
            ->execute([json_encode($choices), $pendingId]);

        $this->settleIfComplete($pendingId);
        return ['ok' => true, 'ceremony' => $this->ceremony($pendingId)];
    }

    /**
     * Put a feat on a character, and pay out everything it is worth.
     *
     * The one door. A feat reaches a character through a level-up, through a
     * companion template at recruitment, or through the backfill tool, and all
     * three come here — because a feat is not only a row in `feats_json`. Half
     * the catalogue carries an ability increase, Tough is worth two hit points
     * for every level already lived, and the Defense fighting style changes
     * armour class the moment it is taken. A second place that wrote the column
     * and skipped the rest would produce characters whose sheet and whose
     * catalogue entry disagree, and nothing would ever say so.
     *
     * Prerequisites are enforced here rather than at the call sites, for the
     * same reason: a companion authored with a general feat at level 1 should
     * be refused loudly at recruitment, exactly as gear naming an item nobody
     * imported is.
     *
     * @return array{ok:bool, feat:string, name:string, abilities:array<string,int>, hp:int}
     */
    public function grantFeat(int $characterId, string $featKey): array
    {
        $character = $this->character($characterId);
        $key = Feats::key($featKey);
        $feat = Feats::get($key);
        if ($feat === null) {
            throw new InvalidArgumentException('No such feat.');
        }
        $check = Feats::qualifies($character, $key);
        if (!$check['ok']) {
            throw new InvalidArgumentException((string) $check['reason']);
        }

        // Written first, so that everything below reads a character who already
        // has it. recalculateAC() in particular asks Feats what the character
        // holds; running it before the write would compute the old armour class
        // and store it as the new one.
        $taken = Feats::taken($character);
        $taken[] = $key;
        $this->db->prepare('UPDATE characters SET feats_json = ? WHERE id = ?')
            ->execute([json_encode(array_values(array_unique($taken))), $characterId]);

        // The increase the feat comes with, if any. `ability_increase_casting`
        // is War Caster's — "your spellcasting ability" is not a column name
        // and cannot be written in the catalogue, so it is resolved here.
        $deltas = [];
        foreach ((array) ($feat['effects']['ability_increase'] ?? []) as $ability => $points) {
            $deltas[(string) $ability] = (int) $points;
        }
        $casting = (int) ($feat['effects']['ability_increase_casting'] ?? 0);
        if ($casting > 0) {
            $short = Rules::spellcastingAbility((string) $character['class']);
            if ($short !== null) {
                $column = self::ABILITY_COLUMNS[$short];
                $deltas[$column] = ($deltas[$column] ?? 0) + $casting;
            }
        }

        $abilities = $deltas === [] ? [] : $this->applyAbilityDeltas($character, $deltas, true);

        // Tough, backdated. The feat reads "for every level you have", so a
        // character who takes it at level 4 is owed eight hit points on the
        // spot; rollHp() pays the two per level from here on.
        $hp = 0;
        $perLevel = (int) ($feat['effects']['hp_per_level'] ?? 0);
        if ($perLevel > 0) {
            $hp = $perLevel * max(1, (int) $character['level']);
            $this->db->prepare(
                'UPDATE characters SET max_hp = max_hp + ?, current_hp = current_hp + ? WHERE id = ?'
            )->execute([$hp, $hp, $characterId]);
        }

        // Defense and Medium Armour Master both move armour class, and nothing
        // else recomputes it. Cheap, and unconditional so that a feat added to
        // the catalogue later cannot be forgotten here.
        (new CharacterGenerator($this->db))->recalculateAC($characterId);

        return [
            'ok'        => true,
            'feat'      => $key,
            'name'      => (string) $feat['name'],
            'abilities' => $abilities,
            'hp'        => $hp,
        ];
    }

    /**
     * Validate and apply a player-directed ability score increase.
     *
     * @param array<string,int> $asi
     * @return array<string,int> what was actually applied
     */
    private function applyAsi(array $character, array $asi): array
    {
        $clean = [];
        foreach ($asi as $ability => $points) {
            $key = strtolower(trim((string) $ability));
            $points = (int) $points;
            if ($points === 0) {
                continue;
            }
            if (!isset(self::ABILITY_COLUMNS[$key])) {
                throw new InvalidArgumentException("No such ability: {$ability}");
            }
            if ($points < 0) {
                throw new InvalidArgumentException('An ability increase cannot be negative.');
            }
            $clean[$key] = ($clean[$key] ?? 0) + $points;
        }

        if (array_sum($clean) !== 2) {
            throw new InvalidArgumentException('An ability score increase is exactly 2 points.');
        }
        if (count($clean) > 2) {
            throw new InvalidArgumentException('Spread across at most two abilities.');
        }
        foreach ($clean as $key => $points) {
            if ($points > 2) {
                throw new InvalidArgumentException('At most 2 points into one ability.');
            }
            $column = self::ABILITY_COLUMNS[$key];
            if ((int) $character[$column] + $points > 20) {
                throw new InvalidArgumentException(ucfirst($column) . ' cannot go above 20.');
            }
        }

        // Refused above rather than trimmed: the player chose these two points
        // and silently spending one of them on nothing is worse than saying so.
        return $this->applyAbilityDeltas($character, $clean, false);
    }

    /**
     * Move ability scores, and settle what moving them costs.
     *
     * Shared by the increase and by every feat that carries one, because the
     * consequences are the same either way and they are the parts that are
     * easy to leave out: a Constitution modifier that crosses a boundary is
     * worth a hit point for every level already lived, and a Dexterity change
     * moves armour class that nothing else recomputes.
     *
     * `$clamp` is the difference between the two callers. A feat's increase is
     * written "to a maximum of 20" and quietly stops there; the player's own
     * two points are refused above rather than trimmed, because those two
     * points were a decision and spending one on nothing is not.
     *
     * @param array<string,int> $deltas ability keys, short or long, to points
     * @return array<string,int> what actually moved, by column name
     */
    private function applyAbilityDeltas(array $character, array $deltas, bool $clamp): array
    {
        $characterId = (int) $character['id'];
        $conBefore = Rules::abilityMod((int) $character['constitution']);

        $applied = [];
        foreach ($deltas as $ability => $points) {
            $key = strtolower(trim((string) $ability));
            if (!isset(self::ABILITY_COLUMNS[$key]) || (int) $points <= 0) {
                continue;
            }
            $column = self::ABILITY_COLUMNS[$key];
            $points = (int) $points;
            if ($clamp) {
                $points = min($points, 20 - (int) $character[$column]);
            }
            if ($points <= 0) {
                continue;
            }
            $this->db->prepare("UPDATE characters SET {$column} = {$column} + ? WHERE id = ?")
                ->execute([$points, $characterId]);
            $applied[$column] = ($applied[$column] ?? 0) + $points;
        }

        if ($applied === []) {
            return [];
        }

        // A CON increase that crosses a modifier boundary is worth a hit point
        // for every level already lived, which is the SRD rule and also the
        // thing a player notices immediately if it is missing.
        $after = $this->character($characterId);
        $conAfter = Rules::abilityMod((int) $after['constitution']);
        if ($conAfter > $conBefore) {
            $retro = ($conAfter - $conBefore) * (int) $after['level'];
            $this->db->prepare(
                'UPDATE characters SET max_hp = max_hp + ?, current_hp = current_hp + ? WHERE id = ?'
            )->execute([$retro, $retro, $characterId]);
        }

        // Dexterity moves armour class, and nothing else recomputes it.
        (new CharacterGenerator($this->db))->recalculateAC($characterId);

        return $applied;
    }

    /**
     * Learn the spells chosen for this level.
     *
     * Every key is re-checked against the same three filters the offer was
     * built from. The client's list is a suggestion; this is the rule.
     *
     * @param string[] $spellKeys
     */
    public function chooseSpells(int $pendingId, array $spellKeys): array
    {
        $pending = $this->pending($pendingId);
        $character = $this->character((int) $pending['character_id']);
        $level = (int) $pending['new_level'];

        $allowed = Rules::spellsLearnedAt((string) $character['class'], $level);
        if ($allowed === 0) {
            throw new InvalidArgumentException('That level does not teach a spell.');
        }
        $choices = $this->choicesOf($pending);
        if (isset($choices['spells'])) {
            throw new InvalidArgumentException('Those spells have already been chosen.');
        }

        $spellKeys = array_values(array_unique(array_filter(array_map('strval', $spellKeys))));
        if (count($spellKeys) !== $allowed) {
            throw new InvalidArgumentException(
                sprintf('Choose exactly %d spell%s.', $allowed, $allowed === 1 ? '' : 's')
            );
        }

        $offered = [];
        foreach ($this->spellsOnOffer($character, $level) as $spell) {
            $offered[(string) $spell['spell_key']] = (int) $spell['id'];
        }

        $ins = $this->db->prepare(
            'INSERT IGNORE INTO character_spells (character_id, spell_id, prepared) VALUES (?, ?, 1)'
        );
        foreach ($spellKeys as $key) {
            if (!isset($offered[$key])) {
                throw new InvalidArgumentException("That character cannot learn {$key}.");
            }
            $ins->execute([(int) $character['id'], $offered[$key]]);
        }

        $choices['spells'] = $spellKeys;
        $this->db->prepare('UPDATE pending_level_ups SET choices_json = ? WHERE id = ?')
            ->execute([json_encode($choices), $pendingId]);

        $this->settleIfComplete($pendingId);
        return ['ok' => true, 'ceremony' => $this->ceremony($pendingId)];
    }

    // =======================================================================
    // Internals
    // =======================================================================

    private const ABILITY_COLUMNS = [
        'str' => 'strength',   'strength'     => 'strength',
        'dex' => 'dexterity',  'dexterity'    => 'dexterity',
        'con' => 'constitution', 'constitution' => 'constitution',
        'int' => 'intelligence', 'intelligence' => 'intelligence',
        'wis' => 'wisdom',     'wisdom'       => 'wisdom',
        'cha' => 'charisma',   'charisma'     => 'charisma',
    ];

    /** Mark the ceremony finished once every step it offered has been taken. */
    private function settleIfComplete(int $pendingId): void
    {
        $c = $this->ceremony($pendingId);
        if ($c['hp']['done'] && $c['asi']['done'] && $c['spells']['done']) {
            $this->db->prepare(
                'UPDATE pending_level_ups SET resolved_at = NOW() WHERE id = ? AND resolved_at IS NULL'
            )->execute([$pendingId]);
            $this->grantSubclassIfDue((int) $this->row($pendingId)['character_id']);
        }
    }

    /**
     * Write the subclass onto a character who has reached the level for it.
     *
     * A grant, not a choice: each class has exactly one subclass in the seed,
     * so nothing needs asking and no ceremony step exists for it. The column
     * was being set only for the three classes whose subclass arrives at 1st
     * (CharacterGenerator) and for recruited companions (CompanionService) —
     * a Fighter played up from 1st never became a Champion, and every
     * SUBCLASS_FEATURES row for them was unreachable.
     *
     * Public because a save from before this fix has levelled characters with
     * a NULL subclass, and anything touching one may settle the debt.
     */
    public function grantSubclassIfDue(int $characterId): void
    {
        $this->db->prepare(
            'UPDATE characters c
              INNER JOIN classes cl ON cl.name = c.class
                SET c.subclass = cl.subclass_name
              WHERE c.id = ? AND c.subclass IS NULL
                AND cl.subclass_name IS NOT NULL
                AND c.level >= cl.subclass_level'
        )->execute([$characterId]);
    }

    /**
     * A pending row, settled or not.
     *
     * Reading one is always allowed. Only the three claiming methods care
     * whether it is finished, and they go through pending() below — which is
     * the distinction that was missing: ceremony() used to take the strict
     * one, so the moment settleIfComplete() marked a row done, the ceremony
     * the same call was about to return became unreadable. Every level-up
     * failed on its last step with "already settled", after that step had
     * been applied — a hit die that granted the hit points and then reported
     * an error instead of the number.
     */
    private function row(int $pendingId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM pending_level_ups WHERE id = ?');
        $stmt->execute([$pendingId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('No such level-up.');
        }
        return $row;
    }

    /** The same row, for the callers that are about to change it. */
    private function pending(int $pendingId): array
    {
        $row = $this->row($pendingId);
        if ($row['resolved_at'] !== null) {
            throw new InvalidArgumentException('That level-up is already settled.');
        }
        return $row;
    }

    /**
     * The character row, with their art resolved the way every other screen
     * resolves it — the raw `sprite_key` column is NULL for a companion.
     */
    private function character(int $characterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*, ' . CharacterGenerator::SPRITE_SELECT . '
               FROM characters c
               LEFT JOIN classes cl ON cl.name = c.class
               ' . CharacterGenerator::SPRITE_JOIN . '
              WHERE c.id = ?'
        );
        $stmt->execute([$characterId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('No such character.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function choicesOf(array $pending): array
    {
        $raw = $pending['choices_json'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $out = json_decode($raw, true);
        return is_array($out) ? $out : [];
    }

    /** @return array<string, array{score:int, mod:int}> */
    private function abilitiesOf(array $character): array
    {
        $out = [];
        foreach (['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'] as $a) {
            $score = (int) $character[$a];
            $out[$a] = ['score' => $score, 'mod' => Rules::abilityMod($score)];
        }
        return $out;
    }
}
