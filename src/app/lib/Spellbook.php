<?php
/**
 * Spell slots and casting outside a fight.
 *
 * The slot ledger lived as two private methods on CombatEngine, which was
 * right for as long as combat was the only place magic happened. The moment a
 * Cure Wounds can be cast over a campfire there are two spenders, and two
 * copies of "which slot does a Warlock actually burn" is the drift this file
 * exists to prevent. CombatEngine::doCast delegates here for the ledger;
 * castOutOfCombat() is the campfire door.
 *
 * OUT OF COMBAT MEANS HEALING, deliberately. Buffs are boons, boons live only
 * inside `combat_sessions.state_json`, and a Bless "cast" at noon that had
 * evaporated by the evening's fight would read as a bug, not a rule. The named
 * extension, when somebody wants it, is a `characters.active_boons_json` store
 * — until that exists this class refuses anything that is not a mending, and
 * says why.
 */

declare(strict_types=1);

class Spellbook
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Expended slots for a character, keyed by slot level. */
    public function expended(int $characterId): array
    {
        $stmt = $this->db->prepare('SELECT spell_slots_json FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $raw = $stmt->fetchColumn();
        $out = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($out) ? $out : [];
    }

    /**
     * Burn one slot of one level. The caller has already resolved which level
     * actually burns — for a Warlock the pact slot, via Rules::slotLevelSpent.
     */
    public function spend(int $characterId, int $level): void
    {
        $expended = $this->expended($characterId);
        $key = (string) $level;
        $expended[$key] = (int) ($expended[$key] ?? 0) + 1;
        $this->db->prepare('UPDATE characters SET spell_slots_json = ? WHERE id = ?')
            ->execute([json_encode($expended), $characterId]);
    }

    /**
     * Give back expended slots worth a combined level of `$budget`.
     *
     * Highest first, which is the choice any player would make and the reason
     * nobody is asked: a 2nd-level slot is worth two 1st-level ones to the
     * budget and far more than two 1st-level ones in play.
     *
     * Returns what was actually recovered, by level, so the caller can say so.
     * A budget larger than anything expended recovers everything and stops.
     *
     * @return array<int, int> slot level => how many came back
     */
    public function recover(int $characterId, int $budget, int $maxLevel = 5): array
    {
        $expended = $this->expended($characterId);
        $levels = array_map('intval', array_keys($expended));
        rsort($levels);

        $given = [];
        foreach ($levels as $level) {
            if ($level < 1 || $level > $maxLevel) {
                continue;
            }
            $held = (int) ($expended[(string) $level] ?? 0);
            while ($held > 0 && $budget >= $level) {
                $held--;
                $budget -= $level;
                $given[$level] = ($given[$level] ?? 0) + 1;
            }
            $expended[(string) $level] = $held;
        }
        if ($given !== []) {
            $this->db->prepare('UPDATE characters SET spell_slots_json = ? WHERE id = ?')
                ->execute([json_encode($expended), $characterId]);
        }
        return $given;
    }

    /** The spells row, but only if this character actually knows it. */
    public function knownSpell(int $characterId, int $spellId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.* FROM spells s
             INNER JOIN character_spells cs ON cs.spell_id = s.id
             WHERE cs.character_id = ? AND s.id = ?'
        );
        $stmt->execute([$characterId, $spellId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cast a healing spell at the campfire, on the road, anywhere but a fight.
     *
     * The same rules the combat path applies, minus the board: slot refused
     * before anything is rolled, the Healer feat rerolls 1s, Disciple of Life
     * pays its bonus, and the total clamps to the target's maximum.
     *
     * @return array{ok:bool, message:string, healed:int, spell:string}
     */
    public function castOutOfCombat(array $caster, int $spellId, array $target): array
    {
        $casterId = (int) $caster['id'];
        $spell = $this->knownSpell($casterId, $spellId);
        if (!$spell) {
            throw new InvalidArgumentException('Spell not known.');
        }

        $resolution = (string) ($spell['resolution'] ?? 'attack');
        if (($spell['damage_type'] ?? '') === 'healing' && $resolution === 'attack') {
            $resolution = 'heal';
        }
        $effect = json_decode($spell['effect_json'] ?? '{}', true) ?: [];
        if ($resolution !== 'heal' || !empty($effect['temp_hp']) || !empty($effect['stabilise'])) {
            throw new InvalidArgumentException(
                'Only healing magic holds outside a fight — anything else fades before it matters.'
            );
        }

        $class = (string) $caster['class'];
        $casterLevel = max(1, (int) ($caster['level'] ?? 1));
        $spellLevel = (int) $spell['level'];
        if ($spellLevel > 0) {
            $left = CombatEngine::slotsAvailable(
                $this->expended($casterId),
                $class,
                $casterLevel,
                $spellLevel
            );
            if ($left < 1) {
                throw new InvalidArgumentException(
                    "No level {$spellLevel} spell slots left — rest first."
                );
            }
        }

        $current = (int) $target['current_hp'];
        $max = (int) $target['max_hp'];
        if ($current >= $max) {
            // Refused rather than cast into nothing, so a mis-click cannot
            // burn the day's last slot on somebody already hale.
            throw new InvalidArgumentException("{$target['name']} is unhurt.");
        }

        $ability = Rules::spellcastingAbility($class) ?? 'wis';
        $column = ['str' => 'strength', 'dex' => 'dexterity', 'con' => 'constitution',
                   'int' => 'intelligence', 'wis' => 'wisdom', 'cha' => 'charisma'][$ability] ?? 'wisdom';
        $mod = max(0, Rules::abilityMod((int) ($caster[$column] ?? 10)));
        $expr = (string) ($spell['damage_dice'] ?? '') ?: '1d8';
        if (!preg_match('/[+-]/', $expr)) {
            $expr .= sprintf('%+d', $mod);
        }

        $roll = Dice::rollDetailed($expr);
        // The Healer feat: every healing die's 1 is thrown once more. Same
        // reading as CombatEngine::healingRoll, which cannot be reached from
        // here without dragging a whole fight along.
        if (Feats::flag($caster, 'heal_reroll_ones')) {
            $sides = 0;
            if (preg_match('/\d+d(\d+)/', $expr, $m)) {
                $sides = (int) $m[1];
            }
            $roll = CombatEngine::rerollUnder($roll, $sides, 1);
        }
        $healed = max(0, (int) $roll['total']);
        if ($spellLevel >= 1 && Rules::subclassFeature($caster, 'disciple_of_life')) {
            $healed += 2 + $spellLevel;
        }
        $healed = min($healed, $max - $current);

        if ($spellLevel > 0) {
            $this->spend($casterId, Rules::slotLevelSpent($class, $casterLevel, $spellLevel));
        }
        $this->db->prepare('UPDATE characters SET current_hp = current_hp + ? WHERE id = ?')
            ->execute([$healed, (int) $target['id']]);

        $who = ((int) $target['id'] === $casterId) ? 'themselves' : $target['name'];
        return [
            'ok'      => true,
            'message' => "{$caster['name']} casts {$spell['name']} on {$who} — {$healed} HP.",
            'healed'  => $healed,
            'spell'   => (string) $spell['name'],
        ];
    }
}
