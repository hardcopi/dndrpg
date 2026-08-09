<?php
/**
 * Racial traits: the RACE_FEATURES table and every hook that reads it.
 *
 * Mirrors tools/test_class_features.php — the table lookups are plain statics,
 * and the save-side hooks are driven through rollSave() by reflection with
 * hand-built combatants, so nothing here writes a row. What this cannot reach
 * without a whole encounter: Relentless Endurance's world-flag spend and the
 * Savage Attacks die (both live inside attack resolution); test_grid_combat.sh
 * is where a whole encounter runs.
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

/** A minimal party-side combatant, the shape startEncounter() stamps. */
function subject(array $over = []): array
{
    return array_merge([
        'name' => 'Subject', 'type' => 'pc', 'class' => 'Fighter', 'level' => 1,
        'str' => 10, 'dex' => 10, 'con' => 10, 'int' => 10, 'wis' => 10, 'cha' => 10,
        'prof' => 2, 'conditions' => [], 'boons' => [], 'race_features' => [],
    ], $over);
}

echo "The table itself\n";

$stout = Rules::raceFeatures('Halfling', 'Stout');
ok('a Stout Halfling is lucky, brave and poison-hardened',
    in_array('halfling_luck', $stout, true)
    && in_array('brave', $stout, true)
    && in_array('stout_resilience', $stout, true));

$lightfoot = Rules::raceFeatures('Halfling', 'Lightfoot');
ok('a Lightfoot has no Stout Resilience',
    !in_array('stout_resilience', $lightfoot, true));

ok('a Human has no table entry at all', Rules::raceFeatures('Human') === []);
ok('an empty race answers nothing', Rules::raceFeatures('', null) === []);

ok('a Dwarf carries resilience and stonecunning regardless of subrace',
    in_array('dwarven_resilience', Rules::raceFeatures('Dwarf', 'Hill Dwarf'), true)
    && in_array('stonecunning', Rules::raceFeatures('Dwarf', 'Mountain Dwarf'), true));

echo "\nBoth actor shapes\n";

ok('a combatant answers from its stamped race_features',
    Rules::raceFeature(subject(['race_features' => ['fey_ancestry']]), 'fey_ancestry'));
ok('a characters row answers from race and subrace columns',
    Rules::raceFeature(['race' => 'Elf', 'subrace' => 'High Elf'], 'fey_ancestry'));
ok('a monster, with neither, answers false',
    !Rules::raceFeature(['name' => 'Goblin', 'traits' => []], 'fey_ancestry'));

echo "\nResistances\n";

ok('dwarven resilience is poison resistance',
    Rules::raceResistances(['dwarven_resilience']) === ['poison']);
ok('tiefling and dragonborn both come to fire, once each',
    Rules::raceResistances(['hellish_resistance', 'draconic_resistance']) === ['fire']);

$dwarf = subject(['race_features' => ['dwarven_resilience'],
                  'resistances' => Rules::raceResistances(['dwarven_resilience'])]);
$after = Rules::damageAfterDefenses(10, 'poison', $dwarf);
ok('a stamped dwarf halves poison through damageAfterDefenses',
    $after['amount'] === 5, "got {$after['amount']}");
$after = Rules::damageAfterDefenses(10, 'slashing', $dwarf);
ok('and takes steel in full', $after['amount'] === 10);

echo "\nKeen Senses\n";

ok('an Elf is proficient in Perception with no other grant',
    Rules::isSkillProficient(['race' => 'Elf', 'subrace' => 'Wood Elf',
        'class' => 'Wizard', 'background' => '', 'level' => 1], 'perception'));
ok('Keen Senses survives an explicit skills list that omits it',
    Rules::isSkillProficient(['race' => 'Elf', 'subrace' => 'High Elf',
        'class' => 'Wizard', 'background' => '', 'level' => 1,
        'skills' => ['athletics']], 'perception'));
ok('a Human wizard with the same list is not',
    !Rules::isSkillProficient(['race' => 'Human', 'class' => 'Wizard',
        'background' => '', 'level' => 1, 'skills' => ['athletics']], 'perception'));

echo "\nThe save-side instincts, through rollSave\n";

$mode = fn (array $s, string $ability, array $tags = []) =>
    $invoke('rollSave', $s, $ability, 10, false, $tags)['mode'];

ok('Brave: advantage against frightened',
    $mode(subject(['race_features' => ['brave']]), 'wis', ['condition' => 'frightened']) === 'advantage');
ok('Brave is about fear, not saves in general',
    $mode(subject(['race_features' => ['brave']]), 'wis', ['condition' => 'poisoned']) === 'normal');

ok('Fey Ancestry: advantage against charmed',
    $mode(subject(['race_features' => ['fey_ancestry']]), 'wis', ['condition' => 'charmed']) === 'advantage');

ok('Dwarven Resilience: advantage against the poisoned condition',
    $mode(subject(['race_features' => ['dwarven_resilience']]), 'con', ['condition' => 'poisoned']) === 'advantage');
ok('Dwarven Resilience: advantage against poison damage',
    $mode(subject(['race_features' => ['dwarven_resilience']]), 'con', ['damage_type' => 'poison']) === 'advantage');
ok('Stout Resilience reads the same',
    $mode(subject(['race_features' => ['stout_resilience']]), 'con', ['condition' => 'poisoned']) === 'advantage');

ok('Gnome Cunning: advantage on a mental save against magic',
    $mode(subject(['race_features' => ['gnome_cunning']]), 'wis', ['magic' => true]) === 'advantage');
ok('Gnome Cunning does not cover a Dexterity save',
    $mode(subject(['race_features' => ['gnome_cunning']]), 'dex', ['magic' => true]) === 'normal');
ok('Gnome Cunning does not cover a mundane save',
    $mode(subject(['race_features' => ['gnome_cunning']]), 'wis', []) === 'normal');

ok('an untagged save with no traits stays normal',
    $mode(subject(), 'con', []) === 'normal');

echo "\nHalfling Luck\n";

// The reroll is stochastic, so drive it statistically: 400 saves at +0 vs
// DC 21 can only ever show natural 20s or rerolls; what a halfling must never
// show is a *kept* natural 1. A plain subject keeps roughly 5% of them.
$kept = 0;
for ($i = 0; $i < 400; $i++) {
    $r = $invoke('rollSave', subject(['race_features' => ['halfling_luck']]), 'con', 21);
    if ($r['natural'] === 1) {
        $kept++;
    }
}
// A rerolled die may itself land on 1 (1-in-400 per throw), so allow a couple.
ok('a halfling keeps (almost) no natural 1s over 400 saves', $kept <= 4, "kept {$kept}");

echo "\n" . ($fail === 0 ? "0 failed" : "{$fail} FAILED") . " ({$pass} passed)\n";
exit($fail === 0 ? 0 : 1);
