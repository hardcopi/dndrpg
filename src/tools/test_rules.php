<?php
/**
 * Test harness for the rules layer: Dice, Rules and Conditions.
 *
 * Run with `php src/tools/test_rules.php`. It requires its classes
 * directly instead of bootstrap.php, because bootstrap opens a session and
 * defines db(), and a rules test that needs MySQL running is a rules test
 * nobody will run.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

$lib = dirname(__DIR__) . '/app/lib';
require_once $lib . '/Dice.php';
// Rules and Conditions both ask Feats what a character has taken, so it comes
// along even in a harness that deliberately avoids bootstrap.php. It is pure
// static arithmetic over a constant array and needs nothing behind it.
require_once $lib . '/Feats.php';
require_once $lib . '/Rules.php';
require_once $lib . '/Conditions.php';

$RESULTS = ['pass' => 0, 'fail' => 0];

function ok(string $name, bool $condition, string $detail = ''): void
{
    global $RESULTS;
    if ($condition) {
        $RESULTS['pass']++;
        return;
    }
    $RESULTS['fail']++;
    echo 'FAIL  ' . $name . ($detail === '' ? '' : '  (' . $detail . ')') . PHP_EOL;
}

function same(string $name, $expected, $actual): void
{
    ok(
        $name,
        $expected === $actual,
        'expected ' . json_encode($expected) . ', got ' . json_encode($actual)
    );
}

function threw(string $name, callable $fn): void
{
    try {
        $fn();
        ok($name, false, 'no exception raised');
    } catch (InvalidArgumentException $e) {
        ok($name, true);
    }
}

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

// ---------------------------------------------------------------------------
section('Ability modifiers and proficiency');

same('mod 1 is -5', -5, Rules::abilityMod(1));
same('mod 3 is -4', -4, Rules::abilityMod(3));
same('mod 8 is -1', -1, Rules::abilityMod(8));
same('mod 9 rounds down to -1', -1, Rules::abilityMod(9));
same('mod 10 is 0', 0, Rules::abilityMod(10));
same('mod 11 is 0', 0, Rules::abilityMod(11));
same('mod 12 is 1', 1, Rules::abilityMod(12));
same('mod 20 is 5', 5, Rules::abilityMod(20));
same('mod 30 is 10', 10, Rules::abilityMod(30));
same('mod 0 is -5', -5, Rules::abilityMod(0));

foreach ([1 => 2, 4 => 2, 5 => 3, 8 => 3, 9 => 4, 12 => 4, 13 => 5, 16 => 5, 17 => 6, 20 => 6] as $level => $bonus) {
    same("proficiency at level {$level}", $bonus, Rules::proficiencyBonus($level));
}
same('proficiency clamps below level 1', 2, Rules::proficiencyBonus(0));
same('proficiency clamps above level 20', 6, Rules::proficiencyBonus(99));

same('there are 18 SRD skills', 18, count(Rules::SKILLS));
same('stealth is a dexterity skill', 'dex', Rules::skillAbility('stealth'));
same('spelled-out skill names resolve', 'dex', Rules::skillAbility('Sleight of Hand'));
threw('unknown skill throws', static fn() => Rules::skillAbility('haggling'));

// ---------------------------------------------------------------------------
section('Dice: advantage and disadvantage');

$advOk = true;
$disOk = true;
$normOk = true;
$critOk = true;
for ($i = 0; $i < 400; $i++) {
    $a = Dice::d20Mode(2, 'advantage');
    if (count($a['rolls']) !== 2 || $a['natural'] !== max($a['rolls'])) {
        $advOk = false;
    }
    if ($a['total'] !== $a['natural'] + 2 || $a['mode'] !== 'advantage') {
        $advOk = false;
    }

    $d = Dice::d20Mode(-1, 'disadvantage');
    if (count($d['rolls']) !== 2 || $d['natural'] !== min($d['rolls'])) {
        $disOk = false;
    }
    if ($d['total'] !== $d['natural'] - 1 || $d['mode'] !== 'disadvantage') {
        $disOk = false;
    }

    $n = Dice::d20Mode(3);
    if (count($n['rolls']) !== 1 || $n['natural'] !== $n['rolls'][0] || $n['total'] !== $n['natural'] + 3) {
        $normOk = false;
    }

    // crit and fumble are judged on the die that counted, never the discard
    foreach ([$a, $d, $n] as $r) {
        if ($r['crit'] !== ($r['natural'] === 20) || $r['fumble'] !== ($r['natural'] === 1)) {
            $critOk = false;
        }
    }
}
ok('advantage keeps the higher die', $advOk);
ok('disadvantage keeps the lower die', $disOk);
ok('normal rolls a single die', $normOk);
ok('crit and fumble follow the counted die', $critOk);
same('an unknown mode falls back to normal', 'normal', Dice::d20Mode(0, 'lucky')['mode']);
same('an unknown mode rolls one die', 1, count(Dice::d20Mode(0, 'lucky')['rolls']));

// ---------------------------------------------------------------------------
section('Dice: critical damage doubles dice only');

$boundsOk = true;
$countOk = true;
$modOk = true;
for ($i = 0; $i < 200; $i++) {
    $crit = Dice::rollDetailed('2d6+3', true);
    if (count($crit['rolls']) !== 4) {
        $countOk = false;
    }
    if ($crit['modifier'] !== 3) {
        $modOk = false;
    }
    if ($crit['total'] !== array_sum($crit['rolls']) + 3 || $crit['total'] < 7 || $crit['total'] > 27) {
        $boundsOk = false;
    }
    if ($crit['dice_total'] !== array_sum($crit['rolls'])) {
        $boundsOk = false;
    }
}
ok('a critical doubles the dice', $countOk);
ok('a critical leaves the modifier alone', $modOk);
ok('critical total is dice plus one modifier', $boundsOk);

same('a normal hit rolls the stated dice', 2, count(Dice::rollDetailed('2d6+3')['rolls']));
same('a critical with a negative modifier keeps it negative', -1, Dice::rollDetailed('1d8-1', true)['modifier']);
same('a critical with a negative modifier doubles the dice', 2, count(Dice::rollDetailed('1d8-1', true)['rolls']));
same('a modifier-only expression has nothing to double', 4, Dice::rollDetailed('+4', true)['total']);
same('a modifier-only expression rolls nothing', 0, count(Dice::rollDetailed('+4', true)['rolls']));

// the pre-existing API must keep working: CombatEngine and CharacterGenerator call it
$legacy = Dice::roll('2d6+3');
ok('roll() still returns two dice', count($legacy['rolls']) === 2);
ok('roll() total is in range', $legacy['total'] >= 5 && $legacy['total'] <= 15);
same('roll() of an empty expression is zero', 0, Dice::roll('')['total']);
same('roll() of a bare modifier', 2, Dice::roll('+2')['total']);
threw('roll() rejects nonsense', static fn() => Dice::roll('goblin'));
$score = Dice::abilityScore();
ok('abilityScore keeps three dice', count($score['rolls']) === 3);
ok('abilityScore is between 3 and 18', $score['total'] >= 3 && $score['total'] <= 18);
$d20 = Dice::d20(2);
same('d20() natural matches its roll', $d20['roll'], $d20['natural']);
same('d20() total is roll plus modifier', $d20['roll'] + 2, $d20['total']);

// ---------------------------------------------------------------------------
section('Saves, skills and both actor shapes');

$fighterRow = [
    'class' => 'Fighter', 'level' => 1, 'background' => 'Folk Hero',
    'strength' => 16, 'dexterity' => 12, 'constitution' => 14,
    'intelligence' => 10, 'wisdom' => 8, 'charisma' => 10,
    'proficiency_bonus' => 2,
];
$fighterCombatant = [
    'class' => 'Fighter', 'name' => 'Ser Test',
    'str' => 16, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 8, 'cha' => 10,
    'prof' => 2,
];

$strSave = Rules::savingThrow($fighterRow, 'str');
same('fighter is proficient in STR saves', 5, $strSave['total']);
same('a proficient save shows two parts', 2, count($strSave['parts']));
same('the first part names the ability', 'Strength', $strSave['parts'][0]['label']);
same('the second part is proficiency', 2, $strSave['parts'][1]['value']);

$dexSave = Rules::savingThrow($fighterRow, 'dexterity');
same('fighter is not proficient in DEX saves', 1, $dexSave['total']);
same('a non-proficient save shows one part', 1, count($dexSave['parts']));

same(
    'both actor shapes agree on saves',
    Rules::savingThrow($fighterRow, 'str')['total'],
    Rules::savingThrow($fighterCombatant, 'str')['total']
);
same(
    'both actor shapes agree on skills',
    Rules::skillCheck($fighterRow, 'athletics')['total'],
    Rules::skillCheck($fighterCombatant, 'athletics')['total']
);

$noProf = ['class' => 'Fighter', 'str' => 16, 'level' => 5];
same('proficiency is derived from level when absent', 6, Rules::savingThrow($noProf, 'str')['total']);

foreach (Rules::CLASS_SAVES as $class => $saves) {
    same("{$class} has two save proficiencies", 2, count(Rules::classSaveProficiencies($class)));
}
same('cleric saves are WIS and CHA', ['wis', 'cha'], Rules::classSaveProficiencies('Cleric'));
same('class lookup is case-insensitive', ['wis', 'cha'], Rules::classSaveProficiencies('cleric'));
same('an unknown class has no save proficiencies', [], Rules::classSaveProficiencies('Artificer'));

$rogue = [
    'class' => 'Rogue', 'level' => 1, 'background' => 'Criminal',
    'strength' => 10, 'dexterity' => 16, 'constitution' => 12,
    'intelligence' => 12, 'wisdom' => 12, 'charisma' => 14,
];
// Stealth and Perception are the two the Rogue's Expertise doubles, so both of
// these carry the proficiency bonus twice. That is the whole of the difference
// from the numbers this file asserted before Expertise was implemented.
same('rogue is proficient in stealth', 7, Rules::skillCheck($rogue, 'stealth')['total']);
same('rogue stealth shows all three parts', 3, count(Rules::skillCheck($rogue, 'stealth')['parts']));
same('rogue is not proficient in arcana', 1, Rules::skillCheck($rogue, 'arcana')['total']);
same('passive perception is 10 plus the modifier', 15, Rules::passiveScore($rogue, 'perception'));

// --- Expertise -------------------------------------------------------------
//
// Two skills at 1st and two more at 6th, taken class-signature-first, and the
// bonus counted twice rather than the proficiency held twice.
same('a rogue doubles two skills at 1st', 2, Rules::expertiseCount('Rogue', 1));
same('and four at 6th', 4, Rules::expertiseCount('Rogue', 6));
same('nobody else doubles anything', 0, Rules::expertiseCount('Bard', 6));
same('expertise takes the signature skills first',
    ['stealth', 'perception'], Rules::expertiseSkills($rogue));
ok('the mark agrees with the number', Rules::hasExpertise($rogue, 'stealth'));
ok('and is not set on an ordinary proficiency', !Rules::hasExpertise($rogue, 'deception'));
ok('a doubled skill is still only proficient once',
    Rules::isSkillProficient($rogue, 'stealth'));

$rogue6 = ['class' => 'Rogue', 'level' => 6, 'background' => 'Criminal', 'dexterity' => 16];
same('a 6th-level rogue doubles four', 4, count(Rules::expertiseSkills($rogue6)));

// The class list is a preference, not the source. A rogue whose proficiencies
// came entirely from an explicit list still gets Expertise in something.
$oddRogue = ['class' => 'Rogue', 'level' => 1, 'skills' => ['Arcana', 'Medicine'], 'intelligence' => 16];
same('expertise falls back to whatever they are proficient in',
    ['arcana', 'medicine'], Rules::expertiseSkills($oddRogue));

// A Bard may choose from all eighteen skills; it must not end up proficient in
// all eighteen.
$bard = ['class' => 'Bard', 'level' => 1, 'charisma' => 16, 'intelligence' => 10];
same('bard class list is all 18 skills', 18, count(Rules::classSkillProficiencies('Bard')));
same('bard is proficient in performance', 5, Rules::skillCheck($bard, 'performance')['total']);
same('bard is not proficient in arcana', 0, Rules::skillCheck($bard, 'arcana')['total']);

same('acolyte grants insight and religion', ['insight', 'religion'], Rules::backgroundSkills('Acolyte'));
same('background lookup is case-insensitive', ['arcana', 'history'], Rules::backgroundSkills('sage'));
same('a background nobody has heard of grants nothing', [], Rules::backgroundSkills('Ratcatcher'));

// The three names this game shipped before the lists were revised. Each one was
// silently granting nothing, which is why these are asserted by name rather than
// as a group: 'Folk Hero' was create.php's DEFAULT, so the commonest character in
// the game was the one missing two skills.
same('Folk Hero is honoured as Farmer', ['animal_handling', 'nature'], Rules::backgroundSkills('Folk Hero'));
same('Guild Artisan is honoured as Artisan', ['investigation', 'persuasion'], Rules::backgroundSkills('Guild Artisan'));
same('Outlander is honoured as Guide', ['stealth', 'survival'], Rules::backgroundSkills('Outlander'));
same('a legacy background is case-insensitive too', ['animal_handling', 'nature'], Rules::backgroundSkills('folk hero'));

$sage = ['class' => 'Wizard', 'level' => 1, 'background' => 'Sage', 'intelligence' => 16, 'wisdom' => 10];
same('background skills are granted', 5, Rules::skillCheck($sage, 'history')['total']);

$explicit = ['class' => 'Rogue', 'level' => 1, 'dexterity' => 16, 'skills' => ['Arcana'], 'intelligence' => 10];
// Asserted as the MARK rather than the modifier: this rogue is proficient in
// nothing but Arcana, so Expertise has nowhere else to go and the total carries
// the proficiency bonus twice. The mark is what "grants what it names" means.
ok('an explicit skill list grants what it names', Rules::isSkillProficient($explicit, 'arcana'));
same('and Expertise doubles it, having nothing else to double',
    4, Rules::skillCheck($explicit, 'arcana')['total']);
same('an explicit skill list overrides the class default', 3, Rules::skillCheck($explicit, 'stealth')['total']);

// The proficiency MARK rather than the modifier. The printed character sheet
// fills eighteen bubbles and six more from these, and the thing worth asserting
// is that they agree with the totals above rather than being a second opinion:
// a filled bubble beside a bonus that does not include proficiency is worse
// than no bubble at all, because the player believes it.
ok('rogue is marked proficient in stealth', Rules::isSkillProficient($rogue, 'stealth'));
ok('rogue is not marked proficient in arcana', !Rules::isSkillProficient($rogue, 'arcana'));
ok('the mark honours an explicit skill list', Rules::isSkillProficient($explicit, 'Arcana'));
ok('the mark honours the list it does not name', !Rules::isSkillProficient($explicit, 'stealth'));
ok('a background skill is marked too', Rules::isSkillProficient($sage, 'history'));
same('skill names are read loosely', true, Rules::isSkillProficient($rogue, 'Sleight of Hand'));
threw('an unknown skill throws', static fn() => Rules::isSkillProficient($rogue, 'juggling'));

ok('fighter is marked proficient in STR saves', Rules::isSaveProficient($fighterRow, 'str'));
ok('fighter is not marked proficient in DEX saves', !Rules::isSaveProficient($fighterRow, 'dexterity'));
ok('the mark works on a combatant too', Rules::isSaveProficient($fighterCombatant, 'con'));

// Every mark must agree with its own modifier, for every class and every skill.
// Written as a sweep because the two are computed by different code paths and
// the failure is silent: eighteen rows that each look plausible on their own.
foreach (array_keys(Rules::CLASS_SKILLS) as $className) {
    $subject = ['class' => $className, 'level' => 3, 'background' => 'Guard'] + array_fill_keys(
        ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'],
        14
    );
    $mismatched = [];
    foreach (Rules::SKILLS as $skill => $ability) {
        $marked = Rules::isSkillProficient($subject, $skill);
        $withProf = Rules::skillCheck($subject, $skill)['total'] > 2;
        if ($marked !== $withProf) {
            $mismatched[] = $skill;
        }
    }
    same("{$className}: every skill mark matches its bonus", [], $mismatched);

    $badSaves = [];
    foreach (Rules::ABILITIES as $ability) {
        $marked = Rules::isSaveProficient($subject, $ability);
        if ($marked !== (Rules::savingThrow($subject, $ability)['total'] > 2)) {
            $badSaves[] = $ability;
        }
    }
    same("{$className}: every save mark matches its bonus", [], $badSaves);
}

$goblin = [
    'name' => 'Goblin', 'dexterity' => 14, 'constitution' => 10,
    'saves_json' => '{"dex":4}',
];
same('a stat block save bonus is used whole', 4, Rules::savingThrow($goblin, 'dex')['total']);
same('a stat block save is a single part', 1, count(Rules::savingThrow($goblin, 'dex')['parts']));
same('an unstated save falls back to the ability', 0, Rules::savingThrow($goblin, 'con')['total']);
threw('unknown ability throws', static fn() => Rules::savingThrow($goblin, 'luck'));

// ---------------------------------------------------------------------------
section('Spellcasting');

$wizard = ['class' => 'Wizard', 'level' => 5, 'intelligence' => 18, 'wisdom' => 10];
same('wizard casts on intelligence', 'int', Rules::spellcastingAbility('Wizard'));
same('wizard spell save DC', 15, Rules::spellSaveDc($wizard));
same('wizard spell attack bonus', 7, Rules::spellAttackBonus($wizard));

$cleric = ['class' => 'Cleric', 'level' => 1, 'wisdom' => 16, 'intelligence' => 8];
same('cleric casts on wisdom', 'wis', Rules::spellcastingAbility('Cleric'));
same('cleric spell save DC', 13, Rules::spellSaveDc($cleric));

same('a fighter has no spellcasting ability', null, Rules::spellcastingAbility('Fighter'));
same('a non-caster DC falls back to 8 plus proficiency', 10, Rules::spellSaveDc($fighterRow));

// ---------------------------------------------------------------------------
section('Weapon attacks');

// $fighterRow is STR 16 (+3), DEX 12 (+1), proficiency 2.
$fists = Rules::weaponAttack($fighterRow, null);
same('no weapon is an unarmed strike', 'Unarmed strike', $fists['name']);
same('unarmed hits with strength and proficiency', 5, $fists['bonus']);
same('unarmed damage carries the modifier', '1d4+3', $fists['damage']);
same('unarmed damage is bludgeoning', 'bludgeoning', $fists['damage_type']);
same('unarmed reach is melee', 'melee', $fists['reach']);

$longsword = ['name' => 'Longsword', 'damage_dice' => '1d8', 'damage_type' => 'slashing',
    'properties' => '{"versatile":"1d10","martial":true}'];
$sword = Rules::weaponAttack($fighterRow, $longsword);
same('a plain weapon uses strength', 5, $sword['bonus']);
same('a plain weapon damage expression', '1d8+3', $sword['damage']);
same('the weapon names itself', 'Longsword', $sword['name']);

// Finesse takes whichever is better, which means it does NOT always take DEX.
$dagger = ['name' => 'Dagger', 'damage_dice' => '1d4', 'damage_type' => 'piercing',
    'properties' => '{"finesse":true}'];
same('finesse keeps strength when strength is better', 5, Rules::weaponAttack($fighterRow, $dagger)['bonus']);
same('finesse takes dexterity when dexterity is better', 5, Rules::weaponAttack($rogue, $dagger)['bonus']);
same('finesse damage follows the same ability', '1d4+3', Rules::weaponAttack($rogue, $dagger)['damage']);

// The content writes `ranged`, not `ammunition`; both have to mean ranged or a
// Ranger's bow is swung with Strength. See the note on Rules::weaponAttack.
$bow = ['name' => 'Shortbow', 'damage_dice' => '1d6', 'damage_type' => 'piercing',
    'properties' => '{"ranged":"80/320","two_handed":true}'];
// A weapon's own enchantment, which the engine has always added when it swings
// and the sheet did not add when it printed. The two have to agree: a player
// adding +5 to a die the game resolves at +6 has no way to notice.
$magicSword = [
    'name' => 'Longsword +1', 'damage_dice' => '1d8', 'damage_type' => 'slashing',
    'properties' => '{"versatile":"1d10","attack_bonus":1,"damage_bonus":1}',
];
same('a +1 weapon adds its plus to the attack', 6, Rules::weaponAttack($fighterRow, $magicSword)['bonus']);
same('and to the damage', '1d8+4', Rules::weaponAttack($fighterRow, $magicSword)['damage']);
same('a plain one is unchanged', 5, Rules::weaponAttack($fighterRow, $longsword)['bonus']);

same('a ranged weapon uses dexterity', 3, Rules::weaponAttack($fighterRow, $bow)['bonus']);
same('a ranged weapon reaches', 'ranged', Rules::weaponAttack($fighterRow, $bow)['reach']);
$sling = ['name' => 'Sling', 'damage_dice' => '1d4', 'properties' => '{"ammunition":true}'];
same('ammunition also means ranged', 'ranged', Rules::weaponAttack($fighterRow, $sling)['reach']);

// A negative modifier still prints with its sign, because the damage string is
// read by a person as well as rolled by Dice.
$weakling = ['class' => 'Wizard', 'level' => 1, 'strength' => 6, 'dexterity' => 10];
same('a negative modifier signs itself', '1d8-2', Rules::weaponAttack($weakling, $longsword)['damage']);

// A weapon row with nothing on it is a club, not a crash.
$bare = Rules::weaponAttack($fighterRow, ['name' => 'Cudgel']);
same('a weapon with no dice falls back', '1d6+3', $bare['damage']);
same('a weapon with no type falls back', 'slashing', $bare['damage_type']);

same('properties decode from JSON', ['finesse' => true], Rules::weaponProperties($dagger));
same('properties pass through when decoded', ['finesse' => true], Rules::weaponProperties(['properties' => ['finesse' => true]]));
same('absent properties are empty', [], Rules::weaponProperties(['name' => 'Rock']));
same('unparseable properties are empty', [], Rules::weaponProperties(['properties' => 'not json']));

// ---------------------------------------------------------------------------
section('Spell slots, levels 1-20');

// Transcribed from the SRD's own tables rather than read out of Rules, which
// is the whole use of this file: two independent copies of 180 numbers is what
// catches a finger slip in one of them. Nine columns for a full caster, five
// for the two half casters and for pact magic — neither of those ever reaches
// a 6th-level slot however long its owner lives.
$fullCaster = [
     1 => [1 => 2, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     2 => [1 => 3, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     3 => [1 => 4, 2 => 2, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     4 => [1 => 4, 2 => 3, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     5 => [1 => 4, 2 => 3, 3 => 2, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     6 => [1 => 4, 2 => 3, 3 => 3, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     7 => [1 => 4, 2 => 3, 3 => 3, 4 => 1, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     8 => [1 => 4, 2 => 3, 3 => 3, 4 => 2, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
     9 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 1, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
    10 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 0, 7 => 0, 8 => 0, 9 => 0],
    11 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 0, 8 => 0, 9 => 0],
    12 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 0, 8 => 0, 9 => 0],
    13 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 0, 9 => 0],
    14 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 0, 9 => 0],
    15 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1, 9 => 0],
    16 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1, 9 => 0],
    17 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1, 9 => 1],
    18 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 1, 7 => 1, 8 => 1, 9 => 1],
    19 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 1, 8 => 1, 9 => 1],
    20 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1],
];
foreach ($fullCaster as $level => $slots) {
    same("wizard slots at level {$level}", $slots, Rules::slotTable('Wizard', $level));
    same("cleric slots at level {$level}", $slots, Rules::slotTable('Cleric', $level));
}

$warlock = [
     1 => [1 => 1, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
     2 => [1 => 2, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
     3 => [1 => 0, 2 => 2, 3 => 0, 4 => 0, 5 => 0],
     4 => [1 => 0, 2 => 2, 3 => 0, 4 => 0, 5 => 0],
     5 => [1 => 0, 2 => 0, 3 => 2, 4 => 0, 5 => 0],
     6 => [1 => 0, 2 => 0, 3 => 2, 4 => 0, 5 => 0],
     7 => [1 => 0, 2 => 0, 3 => 0, 4 => 2, 5 => 0],
     8 => [1 => 0, 2 => 0, 3 => 0, 4 => 2, 5 => 0],
     9 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 2],
    10 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 2],
    11 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 3],
    12 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 3],
    13 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 3],
    14 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 3],
    15 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 3],
    16 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 3],
    17 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 4],
    18 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 4],
    19 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 4],
    20 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 4],
];
foreach ($warlock as $level => $slots) {
    same("warlock slots at level {$level}", $slots, Rules::slotTable('Warlock', $level));
}

$paladin = [
     1 => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
     2 => [1 => 2, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
     3 => [1 => 3, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
     4 => [1 => 3, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
     5 => [1 => 4, 2 => 2, 3 => 0, 4 => 0, 5 => 0],
     6 => [1 => 4, 2 => 2, 3 => 0, 4 => 0, 5 => 0],
     7 => [1 => 4, 2 => 3, 3 => 0, 4 => 0, 5 => 0],
     8 => [1 => 4, 2 => 3, 3 => 0, 4 => 0, 5 => 0],
     9 => [1 => 4, 2 => 3, 3 => 2, 4 => 0, 5 => 0],
    10 => [1 => 4, 2 => 3, 3 => 2, 4 => 0, 5 => 0],
    11 => [1 => 4, 2 => 3, 3 => 3, 4 => 0, 5 => 0],
    12 => [1 => 4, 2 => 3, 3 => 3, 4 => 0, 5 => 0],
    13 => [1 => 4, 2 => 3, 3 => 3, 4 => 1, 5 => 0],
    14 => [1 => 4, 2 => 3, 3 => 3, 4 => 1, 5 => 0],
    15 => [1 => 4, 2 => 3, 3 => 3, 4 => 2, 5 => 0],
    16 => [1 => 4, 2 => 3, 3 => 3, 4 => 2, 5 => 0],
    17 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 1],
    18 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 1],
    19 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2],
    20 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2],
];
foreach ($paladin as $level => $slots) {
    same("paladin slots at level {$level}", $slots, Rules::slotTable('Paladin', $level));
    same("ranger slots at level {$level}", $slots, Rules::slotTable('Ranger', $level));
}

same('a fighter has no slots', array_fill_keys(range(1, Rules::MAX_SLOT_LEVEL), 0),
     Rules::slotTable('Fighter', 20));
same('slot levels clamp low', Rules::slotTable('Wizard', 1), Rules::slotTable('Wizard', 0));
same('slot levels clamp high', Rules::slotTable('Wizard', Rules::MAX_LEVEL),
     Rules::slotTable('Wizard', Rules::MAX_LEVEL + 6));

// slotState is the table crossed with what has been spent. The column records
// only expenditure, so an empty one is a FULL set — the reading anybody writing
// this a second time is most likely to get backwards, which is why there is now
// only one place it is written.
same(
    'a rested cleric has every slot',
    [['level' => 1, 'have' => 4, 'used' => 0, 'left' => 4],
     ['level' => 2, 'have' => 2, 'used' => 0, 'left' => 2]],
    Rules::slotState(['class' => 'Cleric', 'level' => 3])
);
same(
    'spent slots are subtracted',
    [['level' => 1, 'have' => 4, 'used' => 3, 'left' => 1],
     ['level' => 2, 'have' => 2, 'used' => 1, 'left' => 1]],
    Rules::slotState(['class' => 'Cleric', 'level' => 3, 'spell_slots_json' => '{"1":3,"2":1}'])
);
same(
    'integer keys are read as well as string ones',
    1,
    Rules::slotState(['class' => 'Cleric', 'level' => 3, 'spell_slots_json' => '{"2":1}'])[1]['used']
);
same(
    'more spent than held is clamped to held',
    4,
    Rules::slotState(['class' => 'Cleric', 'level' => 3, 'spell_slots_json' => '{"1":99}'])[0]['used']
);
same(
    'a negative expenditure is clamped to none',
    0,
    Rules::slotState(['class' => 'Cleric', 'level' => 3, 'spell_slots_json' => '{"1":-5}'])[0]['used']
);
same('a level with no slots is left out', 2, count(Rules::slotState(['class' => 'Cleric', 'level' => 3])));
same('a fighter has no slot rows at all', [], Rules::slotState(['class' => 'Fighter', 'level' => 6]));
same('unreadable slot JSON means nothing spent', 0, Rules::slotState(['class' => 'Cleric', 'level' => 3, 'spell_slots_json' => 'oops'])[0]['used']);
same(
    'a warlock reports only the pact row',
    [['level' => 3, 'have' => 2, 'used' => 0, 'left' => 2]],
    Rules::slotState(['class' => 'Warlock', 'level' => 5])
);

// ---------------------------------------------------------------------------
section('Damage after defences');

$skeleton = [
    'resistances_json'    => '["fire","cold"]',
    'vulnerabilities_json' => '["bludgeoning"]',
    'immunities_json'     => '["poison"]',
];
same('resistance halves', 4, Rules::damageAfterDefenses(9, 'fire', $skeleton)['amount']);
same('halving rounds down', 3, Rules::damageAfterDefenses(7, 'cold', $skeleton)['amount']);
same('vulnerability doubles', 18, Rules::damageAfterDefenses(9, 'bludgeoning', $skeleton)['amount']);
same('immunity zeroes', 0, Rules::damageAfterDefenses(9, 'poison', $skeleton)['amount']);
same('an untouched type passes through', 9, Rules::damageAfterDefenses(9, 'slashing', $skeleton)['amount']);
same('an untouched type has no note', null, Rules::damageAfterDefenses(9, 'slashing', $skeleton)['note']);
ok(
    'resistance is explained',
    Rules::damageAfterDefenses(9, 'fire', $skeleton)['note'] === 'resistant to fire',
    (string) Rules::damageAfterDefenses(9, 'fire', $skeleton)['note']
);
same('damage type matching ignores case', 4, Rules::damageAfterDefenses(9, 'Fire', $skeleton)['amount']);

$both = ['resistances' => ['fire'], 'vulnerabilities' => ['fire']];
same('resistance applies before vulnerability', 8, Rules::damageAfterDefenses(9, 'fire', $both)['amount']);
ok(
    'both defences are explained',
    Rules::damageAfterDefenses(9, 'fire', $both)['note'] === 'resistant to fire, vulnerable to fire'
);

$immuneAndVulnerable = ['immunities' => ['fire'], 'vulnerabilities' => ['fire']];
same('immunity wins outright', 0, Rules::damageAfterDefenses(9, 'fire', $immuneAndVulnerable)['amount']);

same('decoded arrays work too', 4, Rules::damageAfterDefenses(9, 'fire', ['resistances' => ['fire']])['amount']);
same('a defender with no defences takes it all', 9, Rules::damageAfterDefenses(9, 'fire', [])['amount']);
same('negative damage is clamped to zero', 0, Rules::damageAfterDefenses(-4, 'fire', $skeleton)['amount']);
same('zero damage stays zero under vulnerability', 0, Rules::damageAfterDefenses(0, 'bludgeoning', $skeleton)['amount']);

// Petrified halves everything, which is the only place in the SRD where a
// condition changes what a blow is worth rather than the die that threw it.
$stone = ['conditions' => [['key' => 'petrified']]];
same('stone halves a blow', 4, Rules::damageAfterDefenses(9, 'fire', $stone)['amount']);
same('and says so', 'stone', Rules::damageAfterDefenses(9, 'fire', $stone)['note']);
same('whatever the damage type', 5, Rules::damageAfterDefenses(10, 'psychic', $stone)['amount']);
// Not halved twice. A petrified creature that also resists the type takes half,
// not a quarter — the two are one resistance, and stone is the one that shows.
$stoneAndResistant = ['conditions' => [['key' => 'petrified']], 'resistances' => ['fire']];
same('stone does not stack with a type resistance', 4,
    Rules::damageAfterDefenses(9, 'fire', $stoneAndResistant)['amount']);
// Immunity still wins outright, because it is checked first and always was.
same('and immunity still beats it', 0,
    Rules::damageAfterDefenses(9, 'fire',
        ['conditions' => [['key' => 'petrified']], 'immunities' => ['fire']])['amount']);
same('an ordinary defender is unaffected by any of this', 9,
    Rules::damageAfterDefenses(9, 'fire', ['conditions' => []])['amount']);

ok('the mark agrees with the arithmetic', Conditions::resistsAllDamage($stone));
ok('and nothing else sets it', !Conditions::resistsAllDamage(['conditions' => [['key' => 'prone']]]));

// The five that pin a creature in place. `speed_zero` sat in this catalogue
// unread for the whole life of the rank board.
$rooting = ['grappled', 'paralyzed', 'petrified', 'restrained', 'stunned', 'unconscious'];
foreach (array_keys(Conditions::CATALOG) as $key) {
    same(
        "rooted by {$key}",
        in_array($key, $rooting, true),
        Conditions::rooted(['conditions' => [['key' => $key]]])
    );
}
same('fear names its source', ['mon_1'],
    Conditions::fearedBy(['conditions' => [['key' => 'frightened', 'source_cid' => 'mon_1']]]));
same('a fear with no source names nobody', [],
    Conditions::fearedBy(['conditions' => [['key' => 'frightened']]]));
same('and being afraid of two things names both', ['mon_1', 'mon_2'],
    Conditions::fearedBy(['conditions' => [
        ['key' => 'frightened', 'source_cid' => 'mon_1'],
        ['key' => 'frightened', 'source_cid' => 'mon_2'],
    ]]));

// ---------------------------------------------------------------------------
section('Conditions: catalog and bookkeeping');

$expectedKeys = [
    'blinded', 'charmed', 'frightened', 'grappled', 'incapacitated', 'invisible',
    'paralyzed', 'petrified', 'poisoned', 'prone', 'restrained', 'stunned', 'unconscious',
];
// Thirteen of the SRD's fifteen. Deafened and exhaustion are argued for in the
// header of Conditions.php; petrified was the one missing without a reason.
same('the catalog carries thirteen conditions', $expectedKeys, array_keys(Conditions::CATALOG));

$catalogShapeOk = true;
foreach (Conditions::CATALOG as $key => $meta) {
    foreach (['label', 'description', 'blocks_actions', 'attacks_against_advantage',
              'attacks_against_disadvantage', 'melee_against_advantage',
              'ranged_against_disadvantage', 'melee_auto_crit', 'attacks_by_advantage',
              'attacks_by_disadvantage', 'checks_disadvantage', 'auto_fail_saves',
              'saves_disadvantage', 'speed_zero'] as $field) {
        if (!array_key_exists($field, $meta)) {
            $catalogShapeOk = false;
            echo "      {$key} is missing {$field}" . PHP_EOL;
        }
    }
}
ok('every catalog entry declares every flag', $catalogShapeOk);

$c = ['cid' => 'pc_1', 'conditions' => []];
ok('a clean combatant has nothing', !Conditions::has($c, 'prone'));
$c = Conditions::add($c, ['key' => 'prone']);
ok('add applies a condition', Conditions::has($c, 'prone'));
same('add does not duplicate the list', 1, count($c['conditions']));

$c = Conditions::add($c, ['key' => 'frightened', 'rounds' => 2, 'source_cid' => 'mon_1']);
same('a second condition is kept alongside', 2, count($c['conditions']));

// A weaker re-application must not cut the first one short.
$c = Conditions::add($c, ['key' => 'frightened', 'rounds' => 1, 'source_cid' => 'mon_2']);
same('re-applying keeps the longer duration', 2, $c['conditions'][1]['rounds']);
same('re-applying does not stack', 2, count($c['conditions']));

$c = Conditions::add($c, ['key' => 'frightened']);
same('an open-ended re-application wins', null, $c['conditions'][1]['rounds']);

$c = Conditions::remove($c, 'frightened');
ok('remove clears the condition', !Conditions::has($c, 'frightened'));
ok('remove leaves the others', Conditions::has($c, 'prone'));
same('remove reindexes the list', [0], array_keys($c['conditions']));

threw('an unknown condition throws', static fn() => Conditions::add($c, ['key' => 'bored']));

$immune = ['cid' => 'mon_1', 'condition_immunities_json' => '["poisoned","charmed"]', 'conditions' => []];
$immune = Conditions::add($immune, ['key' => 'poisoned', 'rounds' => 3]);
ok('immunity refuses the condition', !Conditions::has($immune, 'poisoned'));
$immune = Conditions::add($immune, ['key' => 'prone']);
ok('immunity does not refuse everything', Conditions::has($immune, 'prone'));

ok('a bare string condition is understood', Conditions::has(['conditions' => ['prone']], 'prone'));
ok('an unknown stored condition is ignored', !Conditions::has(['conditions' => ['deafened']], 'deafened'));
ok('a missing conditions key is fine', Conditions::canAct(['cid' => 'pc_1']));

// ---------------------------------------------------------------------------
section('Conditions: tickStart');

$ticked = Conditions::tickStart([
    'cid' => 'pc_1',
    'conditions' => [
        ['key' => 'prone', 'rounds' => 1],
        ['key' => 'frightened', 'rounds' => 3, 'source_cid' => 'mon_1', 'save_ability' => 'wis', 'save_dc' => 13],
        ['key' => 'blinded'],
    ],
]);
same('a condition on its last round expires', ['prone'], $ticked['expired']);
same('surviving conditions are kept', 2, count($ticked['combatant']['conditions']));
same('a timer counts down', 2, $ticked['combatant']['conditions'][0]['rounds']);
same('an open-ended condition is not counted down', null, $ticked['combatant']['conditions'][1]['rounds'] ?? null);
same('a repeat save is reported', 1, count($ticked['saves_due']));
same('the repeat save names its ability', 'wis', $ticked['saves_due'][0]['ability']);
same('the repeat save carries its DC', 13, $ticked['saves_due'][0]['dc']);
same('the repeat save names its source', 'mon_1', $ticked['saves_due'][0]['source_cid']);

$expiring = Conditions::tickStart([
    'conditions' => [
        ['key' => 'stunned', 'rounds' => 1, 'save_ability' => 'con', 'save_dc' => 15],
    ],
]);
same('an expiring condition does not also demand a save', 0, count($expiring['saves_due']));
same('an expiring condition is reported', ['stunned'], $expiring['expired']);

// ---------------------------------------------------------------------------
section('Conditions: acting');

$blocksActions = ['incapacitated', 'paralyzed', 'petrified', 'stunned', 'unconscious'];
foreach (array_keys(Conditions::CATALOG) as $key) {
    $subject = ['cid' => 'pc_1', 'conditions' => [['key' => $key]]];
    same(
        "canAct with {$key}",
        !in_array($key, $blocksActions, true),
        Conditions::canAct($subject)
    );
}

// ---------------------------------------------------------------------------
section('Class features above 6th');

// Diamond Soul: proficient in EVERY save from 14th, and the sheet's tick and
// the roll's bonus must agree — they are one function now, and this is what
// says so.
$monk6  = ['class' => 'Monk', 'level' => 6,  'wisdom' => 10, 'charisma' => 10];
$monk14 = ['class' => 'Monk', 'level' => 14, 'wisdom' => 10, 'charisma' => 10];
ok('a monk at 6 is not proficient in Charisma saves',
   !Rules::isSaveProficient($monk6, 'cha'));
ok('a monk at 14 is',
   Rules::isSaveProficient($monk14, 'cha'));
// Charisma 10 is a +0 modifier, so the whole of a 14th-level monk's Charisma
// save is the proficiency Diamond Soul granted — and a 6th-level one's is zero.
same('a monk at 6 rolls Charisma saves flat', 0,
     Rules::savingThrow($monk6, 'cha')['total']);
same('and at 14 the roll adds the bonus the sheet ticked',
     Rules::proficiencyBonus(14),
     Rules::savingThrow($monk14, 'cha')['total']);
ok('Strength and Dexterity were already proficient and are not doubled',
   Rules::savingThrow($monk14, 'str')['total']
     === Rules::abilityMod(10) + Rules::proficiencyBonus(14));

// The levels each new feature arrives at, read from the engine's own table.
foreach ([
    ['Barbarian', 'brutal_critical', 9],
    ['Monk', 'evasion', 7],
    ['Monk', 'diamond_soul', 14],
    ['Rogue', 'evasion', 7],
    ['Paladin', 'improved_divine_smite', 11],
] as [$class, $feature, $at]) {
    ok("{$class} has no {$feature} at " . ($at - 1),
       !Rules::hasFeature($class, $at - 1, $feature));
    ok("{$class} has {$feature} at {$at}",
       Rules::hasFeature($class, $at, $feature));
    ok("and still at " . Rules::MAX_LEVEL,
       Rules::hasFeature($class, Rules::MAX_LEVEL, $feature));
}

section('Conditions: attack context');

$clean = ['cid' => 'mon_9', 'conditions' => []];

$attackerAdvantage = ['invisible'];
$attackerDisadvantage = ['blinded', 'frightened', 'poisoned', 'prone', 'restrained'];

foreach (array_keys(Conditions::CATALOG) as $key) {
    $attacker = ['cid' => 'pc_1', 'attack_kind' => 'melee', 'conditions' => [['key' => $key]]];
    $ctx = Conditions::attackContext($attacker, $clean);
    same(
        "attacker {$key}: advantage",
        in_array($key, $attackerAdvantage, true),
        $ctx['advantage']
    );
    same(
        "attacker {$key}: disadvantage",
        in_array($key, $attackerDisadvantage, true),
        $ctx['disadvantage']
    );
    same("attacker {$key}: no auto-crit from its own state", false, $ctx['auto_crit']);
}

$targetAdvantageMelee = ['blinded', 'paralyzed', 'petrified', 'prone', 'restrained', 'stunned', 'unconscious'];
$targetDisadvantageMelee = ['invisible'];
$targetAutoCrit = ['paralyzed', 'unconscious'];

foreach (array_keys(Conditions::CATALOG) as $key) {
    $attacker = ['cid' => 'pc_1', 'attack_kind' => 'melee', 'conditions' => []];
    $target = ['cid' => 'mon_1', 'conditions' => [['key' => $key]]];
    $ctx = Conditions::attackContext($attacker, $target);
    same(
        "melee vs {$key}: advantage",
        in_array($key, $targetAdvantageMelee, true),
        $ctx['advantage']
    );
    same(
        "melee vs {$key}: disadvantage",
        in_array($key, $targetDisadvantageMelee, true),
        $ctx['disadvantage']
    );
    same(
        "melee vs {$key}: auto-crit",
        in_array($key, $targetAutoCrit, true),
        $ctx['auto_crit']
    );
    same("melee vs {$key}: never auto-hits", false, $ctx['auto_hit']);
}

$targetAdvantageRanged = ['blinded', 'paralyzed', 'petrified', 'restrained', 'stunned', 'unconscious'];
$targetDisadvantageRanged = ['invisible', 'prone'];

foreach (array_keys(Conditions::CATALOG) as $key) {
    $attacker = ['cid' => 'pc_1', 'attack_kind' => 'ranged', 'conditions' => []];
    $target = ['cid' => 'mon_1', 'conditions' => [['key' => $key]]];
    $ctx = Conditions::attackContext($attacker, $target);
    same(
        "ranged vs {$key}: advantage",
        in_array($key, $targetAdvantageRanged, true),
        $ctx['advantage']
    );
    same(
        "ranged vs {$key}: disadvantage",
        in_array($key, $targetDisadvantageRanged, true),
        $ctx['disadvantage']
    );
    same("ranged vs {$key}: no auto-crit at range", false, $ctx['auto_crit']);
}

$cancelling = Conditions::attackContext(
    ['cid' => 'pc_1', 'attack_kind' => 'melee', 'conditions' => [['key' => 'blinded']]],
    ['cid' => 'mon_1', 'conditions' => [['key' => 'restrained']]]
);
same('advantage and disadvantage cancel: no advantage', false, $cancelling['advantage']);
same('advantage and disadvantage cancel: no disadvantage', false, $cancelling['disadvantage']);
ok('the cancellation is logged', in_array('advantage and disadvantage cancel', $cancelling['reasons'], true));
ok('both sources are still logged', count($cancelling['reasons']) === 3);

$reasoned = Conditions::attackContext(
    ['cid' => 'pc_1', 'attack_kind' => 'melee', 'conditions' => []],
    ['cid' => 'mon_1', 'conditions' => [['key' => 'restrained']]]
);
ok('a reason names the target condition', in_array('target is restrained', $reasoned['reasons'], true));

$charmed = Conditions::attackContext(
    ['cid' => 'pc_1', 'conditions' => [['key' => 'charmed', 'source_cid' => 'mon_1']]],
    ['cid' => 'mon_1', 'conditions' => []]
);
same('a charmed creature cannot attack its charmer', true, $charmed['forbidden']);

$charmedElsewhere = Conditions::attackContext(
    ['cid' => 'pc_1', 'conditions' => [['key' => 'charmed', 'source_cid' => 'mon_1']]],
    ['cid' => 'mon_2', 'conditions' => []]
);
same('a charmed creature may attack anyone else', false, $charmedElsewhere['forbidden']);

same(
    'melee is the default attack kind',
    true,
    Conditions::attackContext(
        ['cid' => 'pc_1', 'conditions' => []],
        ['cid' => 'mon_1', 'conditions' => [['key' => 'prone']]]
    )['advantage']
);

// ---------------------------------------------------------------------------
section('Conditions: save context');

$autoFailStrDex = ['paralyzed', 'petrified', 'stunned', 'unconscious'];
foreach (array_keys(Conditions::CATALOG) as $key) {
    $subject = ['cid' => 'pc_1', 'conditions' => [['key' => $key]]];
    foreach (['str', 'dex'] as $ability) {
        same(
            "{$key}: {$ability} save auto-fails",
            in_array($key, $autoFailStrDex, true),
            Conditions::saveContext($subject, $ability)['auto_fail']
        );
    }
    same(
        "{$key}: con save does not auto-fail",
        false,
        Conditions::saveContext($subject, 'con')['auto_fail']
    );
    same(
        "{$key}: dex save disadvantage",
        $key === 'restrained',
        Conditions::saveContext($subject, 'dex')['disadvantage']
    );
    same(
        "{$key}: wis save is untouched",
        false,
        Conditions::saveContext($subject, 'wis')['disadvantage']
    );
}

same(
    'spelled-out abilities resolve in save context',
    true,
    Conditions::saveContext(['conditions' => [['key' => 'restrained']]], 'dexterity')['disadvantage']
);
ok(
    'an auto-fail is explained',
    Conditions::saveContext(['conditions' => [['key' => 'paralyzed']]], 'str')['reasons'] !== []
);

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
