<?php
/**
 * Subclasses: the grant is tested in test_levelup.php territory (it needs a
 * real character); what lives here is everything the SUBCLASS_FEATURES table
 * promises, driven through hand-built combatants the way test_dying.php does
 * it — character_id 0, so the engine's own UPDATE statements touch no row.
 *
 * Deliberately not here: Fast Hands' economy (doUseItem reads real
 * inventory rows) and Dark One's Blessing on a save-kill (the hook is on the
 * attack, a narrowing Rules::SUBCLASS_FEATURES documents).
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$engine = new CombatEngine(db());
$invoke = function (string $name, ...$args) use ($engine) {
    $m = new ReflectionMethod(CombatEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke($engine, ...$args);
};
$invokeStatic = function (string $name, ...$args) {
    $m = new ReflectionMethod(CombatEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
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

/** One party member and one bandit, adjacent, nothing in the way. */
function board(array $pc = [], array $mon = []): array
{
    return [
        'round' => 1,
        'turn_index' => 0,
        'order' => ['pc_1', 'mon_1'],
        'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16)), 'w' => 16, 'h' => 12],
        'log' => [],
        'events' => [],
        'combatants' => [
            array_merge([
                'character_id' => 0,
                'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party', 'name' => 'Wren',
                'class' => 'Fighter', 'subclass' => null, 'level' => 3, 'x' => 4, 'y' => 5,
                'str' => 14, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 10, 'cha' => 10,
                'prof' => 2, 'speed' => 30, 'move_left' => 30,
                'hp' => 20, 'max_hp' => 20, 'ac' => 15, 'alive' => true, 'temp_hp' => 0,
                'conditions' => [], 'boons' => [], 'feats' => [], 'feat_turn' => [],
                'race_features' => [], 'concentrating' => null,
                'stable' => false, 'death_saves' => ['success' => 0, 'fail' => 0],
            ], $pc),
            array_merge([
                'cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
                'x' => 5, 'y' => 5, 'hp' => 11, 'max_hp' => 11, 'ac' => 0, 'alive' => true,
                'temp_hp' => 0, 'conditions' => [], 'boons' => [], 'concentrating' => null,
            ], $mon),
        ],
    ];
}

/** A sure-thing melee poke for exactly 1 damage, so riders stand out. */
function poke(): array
{
    return ['name' => 'Pin', 'bonus' => 100, 'damage' => '1d1',
            'damage_type' => 'piercing', 'reach' => 'melee', 'tags' => ['melee']];
}

/**
 * Resolve the poke on a fresh copy of $board until the d20 is not a natural 1.
 * +100 hits everything except a fumble, and a test that fails one run in
 * twenty on a fumble is a test nobody trusts.
 */
function sureHit(callable $invoke, array $board): array
{
    for ($i = 0; $i < 40; $i++) {
        $state = $invoke('resolveAttack', $board, 'pc_1', 'mon_1', poke());
        foreach ($state['events'] as $ev) {
            if (($ev['type'] ?? '') === 'attack'
                && (int) ($ev['natural'] ?? 0) !== 1
                && empty($ev['crit'])) {
                return $state;
            }
        }
    }
    fwrite(STDERR, "forty fumbles and crits in a row — check Dice\n");
    exit(1);
}

$pc = fn(array $s) => $s['combatants'][0];
$mon = fn(array $s) => $s['combatants'][1];

echo "The table and its lookups\n";

ok('a 3rd-level Champion has Improved Critical',
    Rules::subclassFeature(['subclass' => 'Champion', 'level' => 3], 'improved_critical'));
ok('a 2nd-level Fighter on the way there does not',
    !Rules::subclassFeature(['subclass' => 'Champion', 'level' => 2], 'improved_critical'));
ok('a NULL subclass answers false',
    !Rules::subclassFeature(['subclass' => null, 'level' => 6], 'improved_critical'));
ok('a monster answers false',
    !Rules::subclassFeature(['name' => 'Goblin'], 'improved_critical'));

$held = Rules::featuresHeld('Fighter', 3, 'Champion');
ok('featuresHeld folds the subclass in beside Action Surge',
    !empty($held['action_surge']) && !empty($held['improved_critical']));
$held = Rules::featuresHeld('Fighter', 3, null);
ok('and leaves it out for a Fighter without one',
    !array_key_exists('improved_critical', $held));

echo "\nImproved Critical\n";

same('a Champion crits from 19', 19,
    $invokeStatic('critFloor', ['subclass' => 'Champion', 'level' => 3]));
same('everyone else from 20', 20,
    $invokeStatic('critFloor', ['subclass' => null, 'level' => 3]));

echo "\nDraconic Resilience\n";

same('+1 hit point per level, from creation',
    CharacterGenerator::hitPointsAtLevel(6, 0, 3) + 3,
    CharacterGenerator::hitPointsAtLevel(6, 0, 3, null, 'Draconic Bloodline'));
same('scaled skin is 13 + DEX unarmoured', 15,
    CharacterGenerator::unarmoredAc('Sorcerer', ['dexterity' => 14], 'Draconic Bloodline'));
same('and does not reach another class through the same door', 12,
    CharacterGenerator::unarmoredAc('Wizard', ['dexterity' => 14], null));

echo "\nDisciple of Life\n";

$spell = ['name' => 'Cure Wounds', 'level' => 1, 'damage_dice' => '1d1',
          'damage_type' => '', 'effect_json' => null, 'duration_rounds' => 0];
$cleric = ['class' => 'Cleric', 'subclass' => 'Life Domain', 'level' => 1];
$state = board($cleric, ['hp' => 1, 'max_hp' => 20]);
$state = $invoke('applySpellTo', $state, 'pc_1', 'mon_1', $spell, 'heal');
// 1 (the die) + 0 (WIS) + 2 + 1 (the discipline) onto 1 hit point.
same('a 1st-level cure carries +3 through a Life cleric', 5, (int) $mon($state)['hp']);

$state = board(['class' => 'Cleric', 'subclass' => null, 'level' => 1], ['hp' => 1, 'max_hp' => 20]);
$state = $invoke('applySpellTo', $state, 'pc_1', 'mon_1', $spell, 'heal');
same('and +0 through anyone else', 2, (int) $mon($state)['hp']);

echo "\nSculpt Spells\n";

$blast = ['name' => 'Fireball', 'level' => 3, 'damage_dice' => '8d6', 'damage_type' => 'fire',
          'save_ability' => 'dex', 'save_effect' => 'half', 'effect_json' => null,
          'duration_rounds' => 0, 'school' => 'Evocation'];
$evoker = ['class' => 'Wizard', 'subclass' => 'School of Evocation', 'level' => 3,
           'cid' => 'pc_1'];
// The ally shares the caster's side; the blast must pass over them whole.
$state = board($evoker, ['side' => 'party', 'hp' => 11]);
$state = $invoke('applySpellTo', $state, 'pc_1', 'mon_1', $blast, 'save', 0, true);
same('an ally inside an evoker\'s blast takes nothing', 11, (int) $mon($state)['hp']);

$state = board(['class' => 'Wizard', 'subclass' => null, 'level' => 3], ['side' => 'party', 'hp' => 110, 'max_hp' => 110]);
$state = $invoke('applySpellTo', $state, 'pc_1', 'mon_1', $blast, 'save', 0, true);
ok('without the school, friendly fire burns', (int) $mon($state)['hp'] < 110);

echo "\nColossus Slayer\n";

$hunter = ['class' => 'Ranger', 'subclass' => 'Hunter', 'level' => 3];
$state = sureHit($invoke, board($hunter, ['hp' => 10, 'max_hp' => 11]));   // already wounded
$loss = 10 - (int) $mon($state)['hp'];
ok('a wounded target takes the extra d8', $loss >= 2 && $loss <= 9, "lost {$loss}");
ok('and it is spent for the turn', in_array('colossus_slayer', $pc($state)['feat_turn'], true));

$state = sureHit($invoke, board($hunter, ['hp' => 11, 'max_hp' => 11]));   // untouched
same('an unwounded target takes only the blade', 10, (int) $mon($state)['hp']);

echo "\nDark One's Blessing\n";

$fiend = ['class' => 'Warlock', 'subclass' => 'The Fiend', 'level' => 3, 'cha' => 16];
$state = sureHit($invoke, board($fiend, ['hp' => 1]));
ok('the kill lands', (int) $mon($state)['hp'] <= 0);
same('and the patron pays CHA + level in ward', 6, (int) $pc($state)['temp_hp']);

$state = sureHit($invoke, board($fiend, ['hp' => 5]));
same('a wound that does not kill pays nothing', 0, (int) $pc($state)['temp_hp']);

echo "\nRelentless Endurance, while the board is out\n";

$orc = ['race_features' => ['relentless_endurance'], 'relentless_left' => 1];
$state = $invoke('takeDamage', board($orc), 'pc_1', 20);
same('dropped to zero, a half-orc stands on 1', 1, (int) $pc($state)['hp']);
same('and the trait is spent', 0, (int) $pc($state)['relentless_left']);

$state = $invoke('takeDamage', board($orc), 'pc_1', 40);
ok('killed outright, the trait stands aside', (int) $pc($state)['hp'] === 0);

$state = $invoke('takeDamage', board(['race_features' => ['relentless_endurance'],
                                      'relentless_left' => 0]), 'pc_1', 20);
ok('and spent is spent', (int) $pc($state)['hp'] === 0);

echo "\nThe grant itself\n";

// The one DB-backed claim: a scratch character inside a transaction that is
// rolled back, the test_levelup.php pattern.
$db = db();
$db->beginTransaction();
try {
    $mk = function (string $class, int $level) use ($db): int {
        $db->prepare(
            'INSERT INTO characters
                (name, race, class, level, experience, strength, dexterity, constitution,
                 intelligence, wisdom, charisma, max_hp, current_hp, armor_class, proficiency_bonus)
             VALUES (?, ?, ?, ?, 0, 10, 10, 10, 10, 10, 10, 10, 10, 10, 2)'
        )->execute(['_test_' . bin2hex(random_bytes(4)), 'Human', $class, $level]);
        return (int) $db->lastInsertId();
    };
    $subclassOf = function (int $id) use ($db) {
        $s = $db->prepare('SELECT subclass FROM characters WHERE id = ?');
        $s->execute([$id]);
        return $s->fetchColumn();
    };
    $lus = new LevelUpService($db);

    $fighter = $mk('Fighter', 3);
    $lus->grantSubclassIfDue($fighter);
    same('a 3rd-level Fighter becomes a Champion', 'Champion', $subclassOf($fighter));

    $green = $mk('Fighter', 2);
    $lus->grantSubclassIfDue($green);
    same('a 2nd-level Fighter stays undeclared', null, $subclassOf($green) ?: null);

    $ranger = $mk('Ranger', 4);
    $lus->grantSubclassIfDue($ranger);
    same('a levelled save from before the fix settles its debt', 'Hunter', $subclassOf($ranger));
} finally {
    $db->rollBack();
}

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
