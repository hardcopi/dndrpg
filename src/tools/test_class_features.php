<?php
/**
 * Class features, and the promise the character sheet makes.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_class_features.php`.
 *
 * `classes.features` is a comma-separated string that CharacterSheet::features()
 * splits and prints. Nothing ever checked it against the engine, and the gap was
 * real: every Rogue sheet in the game promised Expertise and Sneak Attack while
 * the string `sneak` appeared nowhere in any engine file.
 *
 * The last test here is the one that matters. It walks the same column the
 * sheet prints and asserts that everything named in it is something the engine
 * can actually do — so the next feature added to that string has to be
 * implemented, or this fails.
 *
 * Touches no rows.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

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
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

echo "== who has what ==\n";
ok('a 2nd-level barbarian fights recklessly', Rules::hasFeature('Barbarian', 2, 'reckless_attack'));
ok('a 1st-level one does not', !Rules::hasFeature('Barbarian', 1, 'reckless_attack'));
ok('a 5th-level barbarian is fast', Rules::hasFeature('Barbarian', 5, 'fast_movement'));
ok('a 2nd-level fighter surges', Rules::hasFeature('Fighter', 2, 'action_surge'));
ok('a 5th-level rogue dodges uncannily', Rules::hasFeature('Rogue', 5, 'uncanny_dodge'));
ok('a 4th-level rogue does not', !Rules::hasFeature('Rogue', 4, 'uncanny_dodge'));
ok('a wizard has none of it', !Rules::hasFeature('Wizard', 6, 'action_surge'));
ok('and neither does a class nobody has heard of',
    !Rules::hasFeature('Artificer', 6, 'reckless_attack'));

// The map names every feature the CLASS has, with whether this level holds it —
// so a feature added to CLASS_FEATURES appears here as `false` long before
// anybody reaches it. Brutal Critical at 9th is why this row grew.
same('the map the board is sent names the whole class, held or not',
    ['reckless_attack' => true, 'danger_sense' => true, 'fast_movement' => false,
     'brutal_critical' => false],
    Rules::featuresHeld('Barbarian', 2));
same('and at 9th the last of them is held',
    ['reckless_attack' => true, 'danger_sense' => true, 'fast_movement' => true,
     'brutal_critical' => true],
    Rules::featuresHeld('Barbarian', 9));
same('and is empty for a class with none', [], Rules::featuresHeld('Sorcerer', 6));
same('a wizard has exactly the one', ['arcane_recovery' => true],
    Rules::featuresHeld('Wizard', 6));

echo "\n== Fast Movement ==\n";
same('a 5th-level barbarian opens with forty feet', 40,
    CombatEngine::turnSpeed(['class' => 'Barbarian', 'level' => 5, 'speed' => 30]));
same('a 4th-level one opens with thirty', 30,
    CombatEngine::turnSpeed(['class' => 'Barbarian', 'level' => 4, 'speed' => 30]));
same('and so does a fighter', 30,
    CombatEngine::turnSpeed(['class' => 'Fighter', 'level' => 6, 'speed' => 30]));
same('a monster keeps its own speed', 25,
    CombatEngine::turnSpeed(['speed' => 25]));

echo "\n== Danger Sense ==\n";
$rollSave = new ReflectionMethod(CombatEngine::class, 'rollSave');
$rollSave->setAccessible(true);
$barb = ['name' => 'Grash', 'class' => 'Barbarian', 'level' => 3, 'dex' => 14, 'con' => 14,
         'prof' => 2, 'conditions' => [], 'dodging' => false];
$fighter = ['name' => 'Wren', 'class' => 'Fighter', 'level' => 3, 'dex' => 14, 'con' => 14,
            'prof' => 2, 'conditions' => [], 'dodging' => false];
same('a barbarian saves against Dexterity with advantage', 'advantage',
    $rollSave->invoke($engine, $barb, 'dex', 10)['mode']);
same('a fighter does not', 'normal',
    $rollSave->invoke($engine, $fighter, 'dex', 10)['mode']);
same('and it does nothing for Constitution', 'normal',
    $rollSave->invoke($engine, $barb, 'con', 10)['mode']);

echo "\n== Uncanny Dodge ==\n";
function board(array $pc): array
{
    return [
        'round' => 1, 'turn_index' => 0, 'order' => ['pc_1'], 'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16))],
        'log' => [], 'events' => [],
        'combatants' => [array_merge([
            'character_id' => 0, 'cid' => 'pc_1', 'type' => 'pc', 'side' => 'party',
            'name' => 'Sera', 'class' => 'Rogue', 'level' => 5, 'x' => 4, 'y' => 5,
            'hp' => 30, 'max_hp' => 30, 'alive' => true, 'conditions' => [],
            'reaction_used' => false,
        ], $pc)],
    ];
}
$halved = $invoke('uncannyDodge', board([]), 'pc_1', 11);
same('eleven becomes five', 5, $halved['amount']);
ok('and the reaction is spent', !empty($halved['state']['combatants'][0]['reaction_used']));

same('a rogue with no reaction takes it whole', 11,
    $invoke('uncannyDodge', board(['reaction_used' => true]), 'pc_1', 11)['amount']);
same('a 4th-level rogue takes it whole', 11,
    $invoke('uncannyDodge', board(['level' => 4]), 'pc_1', 11)['amount']);
same('a fighter takes it whole', 11,
    $invoke('uncannyDodge', board(['class' => 'Fighter']), 'pc_1', 11)['amount']);
same('and a stunned rogue cannot roll with anything', 11,
    $invoke('uncannyDodge', board(['conditions' => [['key' => 'stunned']]]), 'pc_1', 11)['amount']);

echo "\n== Martial Arts ==\n";
same('a fist is a d4 at first', 4, Rules::martialArtsDie(1));
same('and a d6 at fifth', 6, Rules::martialArtsDie(5));
ok('bare hands are always a monk weapon', Rules::isMonkWeapon(null));
ok('and so is a shortsword',
    Rules::isMonkWeapon(['name' => 'Shortsword', 'properties' => '{"finesse":true,"light":true,"martial":true}']));
ok('a greataxe is not',
    !Rules::isMonkWeapon(['name' => 'Greataxe', 'properties' => '{"heavy":true,"two_handed":true,"martial":true}']));
ok('nor is a bow',
    !Rules::isMonkWeapon(['name' => 'Shortbow', 'properties' => '{"ranged":"80/320"}']));
ok('a simple melee weapon is',
    Rules::isMonkWeapon(['name' => 'Quarterstaff', 'properties' => '{"versatile":"1d8"}']));

// The die and the ability, both read off buildWeapon() rather than restated.
$build = new ReflectionMethod(CombatEngine::class, 'buildWeapon');
$build->setAccessible(true);
$monk = ['type' => 'pc', 'character_id' => 0, 'class' => 'Monk', 'level' => 5,
         'str' => 10, 'dex' => 16, 'prof' => 3, 'conditions' => [], 'feats' => []];
$fist = $build->invoke($engine, $monk, null);
ok('a 5th-level monk punches for a d6', str_starts_with($fist['damage'], '1d6'));
ok('with Dexterity, not Strength', str_contains($fist['damage'], '+3'));
ok('and the swing is tagged as a monk one', in_array('monk', $fist['tags'], true));

$novice = $build->invoke($engine, array_merge($monk, ['level' => 1, 'prof' => 2]), null);
ok('a 1st-level monk punches for a d4', str_starts_with($novice['damage'], '1d4'));

$brawler = ['type' => 'pc', 'character_id' => 0, 'class' => 'Fighter', 'level' => 5,
            'str' => 10, 'dex' => 16, 'prof' => 3, 'conditions' => [], 'feats' => []];
$punch = $build->invoke($engine, $brawler, null);
ok('a fighter still punches for a d4', str_starts_with($punch['damage'], '1d4'));
ok('and with Strength', str_contains($punch['damage'], '+0'));

echo "\n== the off-hand ==\n";
$dagger = ['name' => 'Dagger', 'damage_dice' => '1d4', 'damage_type' => 'piercing',
           'properties' => '{"finesse":true,"light":true}'];
$rogue = ['type' => 'pc', 'character_id' => 0, 'class' => 'Rogue', 'level' => 3,
          'str' => 10, 'dex' => 16, 'prof' => 2, 'conditions' => [], 'feats' => []];
$mainHand = $build->invoke($engine, $rogue, $dagger);
$offHand = $build->invoke($engine, $rogue, $dagger, ['offhand' => true]);
ok('the main hand adds the modifier to damage', str_contains($mainHand['damage'], '+3'));
ok('the off-hand does not', str_contains($offHand['damage'], '+0'));
same('but both roll the same die and hit the same', $mainHand['bonus'], $offHand['bonus']);

echo "\n== Arcane Recovery ==\n";
same('a 1st-level wizard recovers one level of slots', 1, Rules::arcaneRecoveryBudget(1));
same('a 5th-level wizard recovers three', 3, Rules::arcaneRecoveryBudget(5));
same('and a 6th-level one also three', 3, Rules::arcaneRecoveryBudget(6));

// The pool itself: highest first, because a 2nd-level slot costs the budget two
// and is worth far more than two 1st-level ones in play.
$book = new Spellbook(db());
$recover = new ReflectionMethod(Spellbook::class, 'recover');
$recover->setAccessible(true);

// Driven through a scratch character so nothing real is touched.
db()->exec("INSERT INTO characters (name, race, class, level, strength, dexterity,
    constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
    speed, proficiency_bonus, spell_slots_json)
    VALUES ('__slot_probe', 'Human', 'Wizard', 5, 10,10,10,16,10,10, 20,20, 12, 30, 3,
            '{\"1\":3,\"2\":2}')");
$probe = (int) db()->lastInsertId();

$given = $book->recover($probe, 3, Rules::MAX_SLOT_LEVEL);
same('three levels of budget buys back a 2nd and a 1st', [2 => 1, 1 => 1], $given);
same('and the expended counts come down', ['1' => 2, '2' => 1], $book->expended($probe));

db()->prepare("UPDATE characters SET spell_slots_json = '{\"1\":1}' WHERE id = ?")->execute([$probe]);
same('a budget larger than what was spent takes what there is', [1 => 1],
    $book->recover($probe, 9, Rules::MAX_SLOT_LEVEL));
db()->prepare("UPDATE characters SET spell_slots_json = '{}' WHERE id = ?")->execute([$probe]);
same('and nothing expended recovers nothing', [], $book->recover($probe, 3, Rules::MAX_SLOT_LEVEL));
db()->prepare('DELETE FROM characters WHERE id = ?')->execute([$probe]);

echo "\n== Lay on Hands and Divine Sense ==\n";
same('a 1st-level paladin holds five points', 5, Rules::layOnHandsPool(1));
same('a 6th-level one holds thirty', 30, Rules::layOnHandsPool(6));
same('curing a poisoning costs five', 5, Rules::LAY_ON_HANDS_CURE);
same('divine sense with average charisma is one use', 1, Rules::divineSenseUses(10));
same('with 16 charisma, four', 4, Rules::divineSenseUses(16));
same('and never fewer than one', 1, Rules::divineSenseUses(6));
ok('a paladin has both from first level',
    Rules::hasFeature('Paladin', 1, 'lay_on_hands') && Rules::hasFeature('Paladin', 1, 'divine_sense'));

// The thing Divine Sense looks for has to exist on a combatant. `monsters.type`
// sat unread until this feature asked what a creature IS.
$undead = (int) db()->query("SELECT COUNT(*) FROM monsters WHERE LOWER(type) = 'undead'")->fetchColumn();
ok('the bestiary holds something for it to find', $undead > 0, "{$undead} undead");
same('and the types it looks for are the SRD three',
    ['undead', 'fiend', 'celestial'], Rules::DIVINE_SENSE_TYPES);

echo "\n== the sheet's promise ==\n";
// Every name in `classes.features` must be something the engine can do. The
// map is from the words a player reads to where the rule lives; a feature with
// no entry here is a feature the sheet promises and the engine has not got.
$implemented = [
    // CombatEngine::BONUS_ACTIONS
    'Rage' => true, 'Second Wind' => true,
    // Rules::CLASS_FEATURES
    'Reckless Attack' => true, 'Danger Sense' => true, 'Fast Movement' => true,
    'Action Surge' => true, 'Uncanny Dodge' => true, 'Martial Arts' => true,
    'Lay on Hands' => true, 'Divine Sense' => true, 'Arcane Recovery' => true,
    'Divine Smite' => true, 'Channel Divinity' => true,
    // Their own functions
    'Sneak Attack' => true, 'Expertise' => true,
    // Spellcasting is the spell list, slots and the Cast action.
    'Spellcasting' => true, 'Pact Magic' => true,
    // CheckService: a d20 boost the whole party can be offered, counted per
    // rest in world_flags.
    'Bardic Inspiration' => true,
    // Flavour rather than mechanics: these grant a language or a subclass
    // choice, both of which the sheet shows and neither of which is a rule the
    // combat engine has to hold up.
    'Thieves\' Cant' => true, 'Druidic' => true, 'Divine Domain' => true,
    'Sorcerous Origin' => true, 'Otherworldly Patron' => true,
    'Fighting Style' => true, 'Unarmored Defense' => true,
];

/**
 * Promised on a sheet, and genuinely not implemented.
 *
 * Written out rather than passed over, because the point of the assertion
 * below is that this list can only be changed on purpose. Adding a feature to
 * `classes.features` without implementing it fails the first test; implementing
 * one of these without taking it off this list fails the second.
 *
 * Every one is a first- or second-level feature, so every one of these classes
 * is missing something a character has from the moment they are rolled.
 */
$knownMissing = [
    // Empty, and it is meant to stay that way. The Ranger's Favored Enemy and
    // Natural Explorer were the last two on this list and have been taken out
    // of `classes.features` rather than implemented — see the comment on that
    // row in sql/seed_data.sql for why. Nothing a character sheet prints is now
    // a promise the engine cannot keep.
];
$rows = db()->query('SELECT name, features FROM classes')->fetchAll(PDO::FETCH_ASSOC);
$promised = [];
foreach ($rows as $row) {
    foreach (array_filter(array_map('trim', explode(',', (string) $row['features'])), 'strlen') as $f) {
        if (!isset($implemented[$f])) {
            $promised[] = "{$row['name']}: {$f}";
        }
    }
}
sort($promised);
$expected = $knownMissing;
sort($expected);
same('no NEW promise has appeared on a character sheet', $expected, $promised);
ok('and the ones outstanding are the ones we know about',
    count($knownMissing) === 0, count($knownMissing) . ' listed');

if ($knownMissing) {
    echo "\n  " . count($knownMissing) . " feature(s) still promised by classes.features"
        . " and not implemented:\n";
    foreach ($knownMissing as $m) {
        echo "    - {$m}\n";
    }
} else {
    echo "\n  Every feature named on a character sheet is one the engine has.\n";
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
