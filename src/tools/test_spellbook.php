<?php
/**
 * Magic outside the fight, and the scaling inside it: Spellbook's campfire
 * casting, the upcast/cantrip dice arithmetic, and Shield's reaction.
 *
 * NEEDS the database — knowing a spell is a JOIN — and works on scratch
 * characters inside a transaction it rolls back. Safe against a database
 * somebody is playing on.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$book = new Spellbook($db);
$engine = new CombatEngine($db);
$invoke = function (string $name, ...$args) use ($engine) {
    $m = new ReflectionMethod(CombatEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke($engine, ...$args);
};

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "  ok   $n\n";
    } else {
        $fail++;
        echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
    }
}
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

echo "Scaled dice, which need no database\n";

$cure = ['level' => 1, 'damage_dice' => '1d8',
         'higher_level_json' => '{"dice_per_level":"1d8"}'];
same('a spell at its own level rolls its own dice', '1d8',
    CombatEngine::scaledSpellDice($cure, 3));
same('a bigger slot adds its dice', '3d8',
    CombatEngine::scaledSpellDice($cure + ['cast_level' => 3], 3));
same('a mismatched die is refused rather than mangled', '1d8',
    CombatEngine::scaledSpellDice(['level' => 1, 'damage_dice' => '1d8',
        'higher_level_json' => '{"dice_per_level":"1d6"}', 'cast_level' => 2], 3));
same('a modifier survives the scaling', '5d4+3',
    CombatEngine::scaledSpellDice(['level' => 1, 'damage_dice' => '3d4+3',
        'higher_level_json' => '{"dice_per_level":"1d4"}', 'cast_level' => 3], 3));
same('a cantrip doubles at caster level 5', '2d10',
    CombatEngine::scaledSpellDice(['level' => 0, 'damage_dice' => '1d10'], 5));
same('and not a moment sooner', '1d10',
    CombatEngine::scaledSpellDice(['level' => 0, 'damage_dice' => '1d10'], 4));
same('a spell with no dice passes through untouched', '',
    CombatEngine::scaledSpellDice(['level' => 1, 'damage_dice' => ''], 5));

$db->beginTransaction();
try {
    $mk = function (string $class, int $level, array $over = []) use ($db): int {
        $db->prepare(
            'INSERT INTO characters
                (name, race, class, subclass, level, experience, strength, dexterity,
                 constitution, intelligence, wisdom, charisma, max_hp, current_hp,
                 armor_class, proficiency_bonus)
             VALUES (?, ?, ?, ?, ?, 0, 10, 10, 10, 10, ?, 10, ?, ?, 12, 2)'
        )->execute([
            '_test_' . bin2hex(random_bytes(4)), 'Human', $class,
            $over['subclass'] ?? null, $level,
            $over['wisdom'] ?? 10, $over['max_hp'] ?? 20, $over['current_hp'] ?? 20,
        ]);
        return (int) $db->lastInsertId();
    };
    $teach = function (int $characterId, string $spellKey) use ($db): void {
        $db->prepare(
            'INSERT INTO character_spells (character_id, spell_id, prepared)
             SELECT ?, id, 1 FROM spells WHERE spell_key = ?'
        )->execute([$characterId, $spellKey]);
    };
    $row = function (int $id) use ($db): array {
        $s = $db->prepare('SELECT * FROM characters WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch();
    };
    $spellId = function (string $key) use ($db): int {
        $s = $db->prepare('SELECT id FROM spells WHERE spell_key = ?');
        $s->execute([$key]);
        return (int) $s->fetchColumn();
    };

    echo "\nThe campfire cast\n";

    $cleric = $mk('Cleric', 3);
    $teach($cleric, 'cure_wounds');
    $teach($cleric, 'bless');
    $hurt = $mk('Fighter', 3, ['current_hp' => 5]);

    $r = $book->castOutOfCombat($row($cleric), $spellId('cure_wounds'), $row($hurt));
    ok('a cure lands and says how much', $r['healed'] >= 1 && $r['healed'] <= 8, "healed {$r['healed']}");
    ok('the wounded man has it', (int) $row($hurt)['current_hp'] === 5 + $r['healed']);
    same('and a first-level slot is spent', ['1' => 1],
        json_decode((string) $row($cleric)['spell_slots_json'], true));

    $refuse = function (callable $fn): bool {
        try {
            $fn();
            return false;
        } catch (InvalidArgumentException $e) {
            return true;
        }
    };

    ok('a buff is refused with its reason', $refuse(
        fn () => $book->castOutOfCombat($row($cleric), $spellId('bless'), $row($hurt))
    ));
    ok('an unknown spell is refused', $refuse(
        fn () => $book->castOutOfCombat($row($cleric), $spellId('fireball'), $row($hurt))
    ));
    $db->prepare('UPDATE characters SET current_hp = max_hp WHERE id = ?')->execute([$hurt]);
    ok('the unhurt are refused before the slot burns', $refuse(
        fn () => $book->castOutOfCombat($row($cleric), $spellId('cure_wounds'), $row($hurt))
    ));
    same('— and it did not burn', ['1' => 1],
        json_decode((string) $row($cleric)['spell_slots_json'], true));

    $db->prepare("UPDATE characters SET spell_slots_json = '{\"1\":9}' WHERE id = ?")
        ->execute([$cleric]);
    $db->prepare('UPDATE characters SET current_hp = 5 WHERE id = ?')->execute([$hurt]);
    ok('an empty book of slots is refused', $refuse(
        fn () => $book->castOutOfCombat($row($cleric), $spellId('cure_wounds'), $row($hurt))
    ));

    $life = $mk('Cleric', 3, ['subclass' => 'Life Domain']);
    $teach($life, 'cure_wounds');
    $r = $book->castOutOfCombat($row($life), $spellId('cure_wounds'), $row($hurt));
    ok('Disciple of Life pays its bonus at the campfire too',
        $r['healed'] >= 4, "healed {$r['healed']}");

    echo "\nShield, when its moment comes\n";

    $wizard = $mk('Wizard', 3);
    $teach($wizard, 'shield');
    $combatant = [
        'cid' => 'pc_1', 'type' => 'pc', 'character_id' => $wizard, 'name' => 'Ward',
        'class' => 'Wizard', 'level' => 3, 'reaction_used' => false, 'boons' => [],
        'conditions' => [], 'hp' => 10, 'max_hp' => 10, 'ac' => 12, 'alive' => true,
    ];
    $state = ['combatants' => [$combatant], 'log' => [], 'events' => []];

    $after = $invoke('autoShield', $state, $combatant);
    ok('a wizard with the spell and a slot takes the ward', $after !== null);
    $ward = $after['combatants'][0];
    ok('the reaction is spent', !empty($ward['reaction_used']));
    ok('and the +5 hangs as a boon',
        !empty($ward['boons']) && (int) $ward['boons'][0]['ac'] === 5);
    same('and the slot burned', ['1' => 1],
        json_decode((string) $row($wizard)['spell_slots_json'], true));

    $db->prepare("UPDATE characters SET spell_slots_json = '{\"1\":9}' WHERE id = ?")
        ->execute([$wizard]);
    same('out of slots, the ward stays down', null, $invoke('autoShield', $state, $combatant));

    $fighter = $mk('Fighter', 3);
    $noBook = $combatant;
    $noBook['character_id'] = $fighter;
    same('no spellbook, no ward', null, $invoke('autoShield', $state, $noBook));

    echo "\nWhat the party cards show is left\n";

    // The pips on a party card are drawn from this and compute nothing of
    // their own, so what is asserted is the whole of what the board knows.
    $caster = $mk('Cleric', 3);
    $pc = static fn (int $id, string $class) => [
        'cid' => 'pc_' . $id, 'type' => 'pc', 'character_id' => $id,
        'class' => $class, 'level' => 3,
    ];
    $slotState = $invoke('deriveSlots', [
        'combatants' => [
            $pc($caster, 'Cleric'),
            $pc($fighter, 'Fighter'),
            ['cid' => 'mon_1', 'type' => 'monster', 'name' => 'Rat'],
        ],
    ]);
    $rows = $slotState['combatants'][0]['slots'];

    same('a cleric of three carries two slot levels', 2, count($rows));
    same('four first-level, all unspent', ['level' => 1, 'max' => 4, 'left' => 4], $rows[0]);
    same('two second-level', ['level' => 2, 'max' => 2, 'left' => 2], $rows[1]);
    same('a fighter carries an empty list, not a missing key',
        [], $slotState['combatants'][1]['slots']);
    ok('and a monster is not asked at all',
        !array_key_exists('slots', $slotState['combatants'][2]));

    // The counts are read from the character row rather than tallied inside
    // the fight, which is the point: spend one anywhere and the card knows.
    $db->prepare("UPDATE characters SET spell_slots_json = '{\"1\":3}' WHERE id = ?")
        ->execute([$caster]);
    $after = $invoke('deriveSlots', ['combatants' => [$pc($caster, 'Cleric')]]);
    same('three spent leaves one', ['level' => 1, 'max' => 4, 'left' => 1],
        $after['combatants'][0]['slots'][0]);

    // Overspending is not a negative number of slots on somebody's card.
    $db->prepare("UPDATE characters SET spell_slots_json = '{\"1\":9}' WHERE id = ?")
        ->execute([$caster]);
    $empty = $invoke('deriveSlots', ['combatants' => [$pc($caster, 'Cleric')]]);
    same('and a book spent past empty floors at zero',
        0, $empty['combatants'][0]['slots'][0]['left']);
} finally {
    $db->rollBack();
}

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
