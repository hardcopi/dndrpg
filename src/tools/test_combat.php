<?php
/**
 * Test harness for the combat rules and the geometry underneath them.
 *
 * Run with `php src/tools/test_combat.php`. Like test_rules.php it requires the
 * classes directly rather than bootstrap.php, and it exercises only the static
 * half of the engine — targeting, distance, sight, cover, movement, templates,
 * the action economy and the monster AI's preferences. None of it touches a
 * PDO, so a combat rule can be checked without MySQL running.
 *
 * BattleGrid and BattleMapGen are entirely pure, which is what makes it
 * possible to settle an argument about a cone here rather than by starting a
 * fight and squinting at it. The monster AI is testable for the same reason:
 * scoreOption() takes counted facts rather than a board, so its judgement can
 * be checked against invented options with no geometry underneath them.
 *
 * What is deliberately NOT covered: anything that reads or writes the database
 * (casting, slot expenditure against a real character row, XP awards) and the
 * turn loop itself, both of which need a session and a schema. Those are
 * tools/test_grid_combat.sh's job, over real HTTP.
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
require_once $lib . '/BattleGrid.php';
require_once $lib . '/BattleMapGen.php';
require_once $lib . '/CombatEngine.php';

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

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

/** A combatant with only the keys the pure rules read. */
function who(string $cid, string $side, string $rank, array $extra = []): array
{
    return array_merge([
        'cid'        => $cid,
        'name'       => ucfirst($cid),
        'side'       => $side,
        'rank'       => $rank,
        'hp'         => 20,
        'max_hp'     => 20,
        'ac'         => 15,
        'alive'      => true,
        'conditions' => [],
        'is_caster'  => false,
        'is_healer'  => false,
        'has_ranged' => false,
    ], $extra);
}

function cids(array $combatants): array
{
    return array_map(static fn ($c) => $c['cid'], $combatants);
}

/**
 * A board from a picture. Short rows are padded with open ground and the whole
 * thing is trimmed or grown to the real board size, so a test only has to draw
 * the part it cares about.
 *
 * @param string[] $rows
 * @return string[]
 */
function board(array $rows = []): array
{
    $out = [];
    for ($y = 0; $y < BattleGrid::H; $y++) {
        $row = $rows[$y] ?? '';
        $out[] = str_pad(substr($row, 0, BattleGrid::W), BattleGrid::W, '.');
    }
    return $out;
}

/** Put one character on a board. */
function paint(array $rows, int $x, int $y, string $char): array
{
    $rows[$y] = substr_replace($rows[$y], $char, $x, 1);
    return $rows;
}

/** A combatant standing somewhere. */
function at(string $cid, string $side, int $x, int $y, array $extra = []): array
{
    return array_merge(who($cid, $side, 'front'), [
        'x' => $x, 'y' => $y, 'reach_ft' => 5, 'speed' => 30, 'move_left' => 30,
    ], $extra);
}

/** What shotsFrom and friends want in place of a whole session. */
function gridOf(array $terrain): array
{
    return ['w' => BattleGrid::W, 'h' => BattleGrid::H, 'cell_ft' => 5, 'terrain' => $terrain];
}

/** A sword: five feet, no throw. */
const R_MELEE = ['reach' => 5, 'normal' => null, 'long' => null];
/** A halberd. */
const R_REACH = ['reach' => 10, 'normal' => null, 'long' => null];
/** A shortbow. */
const R_BOW = ['reach' => null, 'normal' => 80, 'long' => 320];
/** Something with a short throw, so the long-range band is reachable on a board this size. */
const R_DART = ['reach' => 5, 'normal' => 20, 'long' => 60];

// ---------------------------------------------------------------------------
section('Targeting is a distance now');

$plain = gridOf(board());
$field = [
    at('tank', 'party', 4, 5),
    at('rogue', 'party', 4, 6),
    at('wizard', 'party', 2, 5, ['is_caster' => true]),
    at('brute', 'foe', 5, 5),
    at('archer', 'foe', 11, 5),
];
$hero = $field[0];
$brute = $field[3];

same(
    'a sword reaches the body next to it and nothing else',
    ['brute'],
    cids(CombatEngine::legalTargets($plain, $field, $hero, R_MELEE))
);
same(
    'a bow reaches both, because eighty feet crosses this board',
    ['brute', 'archer'],
    cids(CombatEngine::legalTargets($plain, $field, $hero, R_BOW))
);
ok('canReach agrees with legalTargets', CombatEngine::canReach($plain, $field, $hero, 'brute', R_MELEE));
ok('the far archer is out of sword reach', !CombatEngine::canReach($plain, $field, $hero, 'archer', R_MELEE));
ok('but well inside bow range', CombatEngine::canReach($plain, $field, $hero, 'archer', R_BOW));

// A halberd covers the ring one further out. This is what `reach_ft` buys and
// it is the only thing that made `properties.reach` mean anything.
$polearm = at('polearm', 'party', 3, 5, ['reach_ft' => 10]);
$withPolearm = array_merge($field, [$polearm]);
ok('a reach weapon strikes two cells away', CombatEngine::canReach($plain, $withPolearm, $polearm, 'brute', R_REACH));
ok('and a sword in the same cell does not', !CombatEngine::canReach($plain, $withPolearm, $polearm, 'brute', R_MELEE));

// Range brackets. Past normal range the shot is legal and worse; past long it
// is not a shot at all.
$thrower = at('thrower', 'party', 1, 5);
$near = at('near', 'foe', 4, 5);      // 15 ft
$far = at('far', 'foe', 8, 5);        // 35 ft — past a dart's 20
$gone = at('gone', 'foe', 15, 5);     // 70 ft — past a dart's 60
$darts = [$thrower, $near, $far, $gone];
$shots = CombatEngine::shotsFrom($plain, $darts, $thrower, R_DART);
ok('a close throw is a normal shot', isset($shots['near']) && $shots['near']['long'] === false);
ok('a long throw still lands', isset($shots['far']));
ok('at disadvantage', $shots['far']['long'] === true);
ok('and past long range there is no shot at all', !isset($shots['gone']));
same('distance comes back in feet', 15, $shots['near']['dist']);

// Total cover is not a penalty, it is the absence of a shot — the one place
// legalTargets and losClear have to agree.
$blocking = board();
foreach ([4, 5, 6] as $wy) {
    $blocking = paint($blocking, 8, $wy, '#');
}
$behindWall = [at('shooter', 'party', 5, 5), at('hidden', 'foe', 11, 5)];
same(
    'a target behind a wall cannot be shot at all',
    [],
    cids(CombatEngine::legalTargets(gridOf($blocking), $behindWall, $behindWall[0], R_BOW))
);

// Shooting with somebody at your elbow.
$pressed = [at('archer', 'party', 5, 5), at('thug', 'foe', 6, 5), at('mark', 'foe', 12, 5)];
$pressedShots = CombatEngine::shotsFrom($plain, $pressed, $pressed[0], R_BOW);
ok('a bow shot with an enemy adjacent is crowded', $pressedShots['mark']['crowded'] === true);
ok('and the adjacent enemy is not, being point blank', $pressedShots['thug']['crowded'] === true);
$clear = [at('archer', 'party', 5, 5), at('mark', 'foe', 12, 5)];
ok(
    'with nobody close it is a clean shot',
    CombatEngine::shotsFrom($plain, $clear, $clear[0], R_BOW)['mark']['crowded'] === false
);

// Flanking comes back on the shot, so resolveAttack never has to ask twice.
$pincer = [at('a', 'party', 4, 5), at('b', 'party', 6, 5), at('mark', 'foe', 5, 5)];
ok(
    'a pincer flanks',
    CombatEngine::shotsFrom($plain, $pincer, $pincer[0], R_MELEE)['mark']['flank'] === true
);
$alone = [at('a', 'party', 4, 5), at('mark', 'foe', 5, 5)];
ok(
    'one attacker does not',
    CombatEngine::shotsFrom($plain, $alone, $alone[0], R_MELEE)['mark']['flank'] === false
);

// A downed character is not a target and not an obstacle, exactly as before.
$downed = $field;
$downed[3]['conditions'] = [['key' => 'unconscious']];
same(
    'an unconscious enemy is no longer a target',
    ['archer'],
    cids(CombatEngine::legalTargets($plain, $downed, $hero, R_BOW))
);
ok('but keeps alive => true, being dying rather than dead', $downed[3]['alive'] === true);
ok('and is not targetable', !CombatEngine::isTargetable($downed[3]));
same('the far side is still the far side', 'foe', CombatEngine::enemySide($hero));
same('and it works the other way round', 'party', CombatEngine::enemySide($brute));

// ---------------------------------------------------------------------------
section('Saving throws for half, and concentration');

same('a failed save takes it all', 13, CombatEngine::saveDamage(13, false, 'half'));
same('a made save halves, rounding down', 6, CombatEngine::saveDamage(13, true, 'half'));
same('a made save against negate takes nothing', 0, CombatEngine::saveDamage(13, true, 'negate'));
same('a failed save against negate still takes it all', 13, CombatEngine::saveDamage(13, false, 'negate'));
same('halving an odd 1 gives 0', 0, CombatEngine::saveDamage(1, true, 'half'));

same('small hits still call for DC 10', 10, CombatEngine::concentrationDc(1));
same('DC 10 is the floor', 10, CombatEngine::concentrationDc(20));
same('21 damage is DC 10', 10, CombatEngine::concentrationDc(21));
same('22 damage is DC 11', 11, CombatEngine::concentrationDc(22));
same('50 damage is DC 25', 25, CombatEngine::concentrationDc(50));

// ---------------------------------------------------------------------------
section('Spell slots');

same('a cantrip is always free', 1, CombatEngine::slotsAvailable([], 'Wizard', 1, 0));
same('a level 1 wizard has two first-level slots', 2, CombatEngine::slotsAvailable([], 'Wizard', 1, 1));
same('spending one leaves one', 1, CombatEngine::slotsAvailable(['1' => 1], 'Wizard', 1, 1));
same('spending both leaves none', 0, CombatEngine::slotsAvailable(['1' => 2], 'Wizard', 1, 1));
same('an integer-keyed record reads the same', 0, CombatEngine::slotsAvailable([1 => 2], 'Wizard', 1, 1));
same('over-spending cannot go negative', 0, CombatEngine::slotsAvailable(['1' => 9], 'Wizard', 1, 1));
same('a level 1 wizard has no second-level slots', 0, CombatEngine::slotsAvailable([], 'Wizard', 1, 2));
same('a fighter has none at all', 0, CombatEngine::slotsAvailable([], 'Fighter', 5, 1));
same('a level 1 paladin has none either', 0, CombatEngine::slotsAvailable([], 'Paladin', 1, 1));
same('a level 2 paladin has two', 2, CombatEngine::slotsAvailable([], 'Paladin', 2, 1));

// ---------------------------------------------------------------------------
section('Advantage bookkeeping');

$blind = ['conditions' => [['key' => 'blinded']], 'attack_kind' => 'melee'];
$plain = ['conditions' => []];

same('a clean roll is normal', 'normal', CombatEngine::rollMode(Conditions::attackContext($plain, $plain)));
same(
    'a blinded attacker has disadvantage',
    'disadvantage',
    CombatEngine::rollMode(Conditions::attackContext($blind, $plain))
);
same(
    'Help gives advantage',
    'advantage',
    CombatEngine::rollMode(Conditions::attackContext($plain, $plain), true, false)
);
same(
    'Dodge and Help cancel',
    'normal',
    CombatEngine::rollMode(Conditions::attackContext($plain, $plain), true, true)
);
// The context has already cancelled itself here; folding Help in must not
// revive the advantage it threw away.
$selfCancelling = Conditions::attackContext($blind, ['conditions' => [['key' => 'restrained']]]);
same('a self-cancelling context reports normal', false, $selfCancelling['advantage']);
same(
    'and stays normal when Help is added',
    'normal',
    CombatEngine::rollMode($selfCancelling, true, false)
);

// ---------------------------------------------------------------------------
section('Expected damage');

same('2d6+3 averages 10', 10.0, CombatEngine::expectedDamage('2d6+3'));
same('1d8 averages 4.5', 4.5, CombatEngine::expectedDamage('1d8'));
same('a bare number is itself', 4.0, CombatEngine::expectedDamage('4'));
same('nonsense is worth nothing', 0.0, CombatEngine::expectedDamage('a sword'));

// ---------------------------------------------------------------------------
section('Flee DC');

same('a CR 1/4 rabble is DC 10', 10, CombatEngine::fleeDc(0.25));
same('a CR 3 ogre is DC 13', 13, CombatEngine::fleeDc(3.0));
same('the DC never drops below 10', 10, CombatEngine::fleeDc(0.0));

// ---------------------------------------------------------------------------
section('Monster AI: it goes for the wounded caster');

$monster = who('goblin', 'foe', 'front', ['hp' => 7, 'max_hp' => 7, 'has_ranged' => true]);

$brutePc = who('fighter', 'party', 'front', ['hp' => 30, 'max_hp' => 30, 'ac' => 18]);
$casterPc = who('wizard', 'party', 'back', [
    'hp' => 4, 'max_hp' => 14, 'ac' => 12, 'is_caster' => true, 'is_healer' => false,
]);
$healerPc = who('cleric', 'party', 'back', [
    'hp' => 12, 'max_hp' => 14, 'ac' => 16, 'is_caster' => true, 'is_healer' => true,
]);

$shootBrute = ['kind' => 'attack', 'target' => $brutePc, 'expected_damage' => 5.5];
$shootCaster = ['kind' => 'attack', 'target' => $casterPc, 'expected_damage' => 5.5];
$shootHealer = ['kind' => 'attack', 'target' => $healerPc, 'expected_damage' => 5.5];

$sBrute = CombatEngine::scoreOption($monster, $shootBrute);
$sCaster = CombatEngine::scoreOption($monster, $shootCaster);
$sHealer = CombatEngine::scoreOption($monster, $shootHealer);

ok('the wounded caster beats the healthy brute', $sCaster > $sBrute, "{$sCaster} vs {$sBrute}");
ok('the wounded caster beats the healthy healer', $sCaster > $sHealer, "{$sCaster} vs {$sHealer}");
ok('the healer beats the plain brute', $sHealer > $sBrute, "{$sHealer} vs {$sBrute}");
ok('every attack beats doing nothing', $sBrute > CombatEngine::scoreOption($monster, ['kind' => 'dodge']));

// Pack Tactics is worth having, but not worth switching to a worse target for.
$packed = $shootBrute;
$packed['pack'] = true;
ok('pack support makes the same target better', CombatEngine::scoreOption($monster, $packed) > $sBrute);
ok(
    'but not better than the wounded caster',
    CombatEngine::scoreOption($monster, $packed) < $sCaster
);

// ---------------------------------------------------------------------------
section('Monster AI: the shot it prefers');

// Cover and a long shot both make a hit less likely, so a monster with a choice
// takes the clean one — even though the target is identical.
$clean = ['kind' => 'attack', 'target' => $brutePc, 'expected_damage' => 5.5,
          'shot' => ['cover' => 0, 'long' => false, 'crowded' => false, 'flank' => false]];
$throughCover = $clean;
$throughCover['shot']['cover'] = 5;
$atRange = $clean;
$atRange['shot']['long'] = true;
$flanked = $clean;
$flanked['shot']['flank'] = true;

ok('cover on the target puts a monster off', CombatEngine::scoreOption($monster, $clean) > CombatEngine::scoreOption($monster, $throughCover));
ok('so does a long shot', CombatEngine::scoreOption($monster, $clean) > CombatEngine::scoreOption($monster, $atRange));
ok('and flanking draws it in', CombatEngine::scoreOption($monster, $flanked) > CombatEngine::scoreOption($monster, $clean));

$exposed = $clean;
$safe = $clean;
$safe['safe'] = true;
ok('shooting from somewhere nothing threatens is worth a little', CombatEngine::scoreOption($monster, $safe) > CombatEngine::scoreOption($monster, $exposed));

// ---------------------------------------------------------------------------
section('Monster AI: whether the walk is worth it');

$walkOver = ['kind' => 'move_attack', 'target' => $brutePc, 'expected_damage' => 5.5,
             'move_cost' => 25, 'dest' => [5, 5], 'provokes' => 0,
             'dest_cover' => 0, 'dest_threat' => 0,
             'shot' => ['cover' => 0, 'long' => false, 'crowded' => false, 'flank' => false]];

$throughTeeth = $walkOver;
$throughTeeth['provokes'] = 2;
ok(
    'a walk that eats two free swings is much worse than one that eats none',
    CombatEngine::scoreOption($monster, $walkOver) - CombatEngine::scoreOption($monster, $throughTeeth) > 100.0
);
ok(
    'and worse than simply shooting from here',
    CombatEngine::scoreOption($monster, $throughTeeth) < CombatEngine::scoreOption($monster, $clean)
);

$intoTheOpen = $walkOver;
$intoTheOpen['dest_threat'] = 3;
ok('walking into three enemies\' reach is worse than not', CombatEngine::scoreOption($monster, $walkOver) > CombatEngine::scoreOption($monster, $intoTheOpen));

$behindAPillar = $walkOver;
$behindAPillar['dest_cover'] = 5;
ok('ending up behind cover is worth having', CombatEngine::scoreOption($monster, $behindAPillar) > CombatEngine::scoreOption($monster, $walkOver));

$stroll = $walkOver;
$stroll['move_cost'] = 5;
ok('all else equal it walks less far', CombatEngine::scoreOption($monster, $stroll) > CombatEngine::scoreOption($monster, $walkOver));

// ---------------------------------------------------------------------------
section('Monster AI: repositioning');

// The old rank retreat, reworked. A badly hurt archer that can get out of
// everybody's reach and keep shooting should do so; a melee brute has nothing
// to gain by backing off, exactly as when this meant falling back a rank.
$hurtArcher = who('archer', 'foe', 'front', ['hp' => 2, 'max_hp' => 12, 'has_ranged' => true]);
$scratched = who('archer', 'foe', 'front', ['hp' => 4, 'max_hp' => 12, 'has_ranged' => true]);
$hurtBrute = who('ogre', 'foe', 'front', ['hp' => 2, 'max_hp' => 12, 'has_ranged' => false]);
$healthy = who('ogre', 'foe', 'front', ['hp' => 12, 'max_hp' => 12, 'has_ranged' => true]);

$toSafety = ['kind' => 'reposition', 'dest' => [1, 1], 'provokes' => 0,
             'dest_cover' => 0, 'dest_threat' => 0, 'closes_ft' => 0, 'engages_soft' => false];

ok('a badly hurt archer breaks off', CombatEngine::scoreOption($hurtArcher, $toSafety) > 0.0);
ok(
    'the worse hurt it is, the keener it is to go',
    CombatEngine::scoreOption($hurtArcher, $toSafety) > CombatEngine::scoreOption($scratched, $toSafety)
);
same('a melee-only brute has no reason to run', 0.0, CombatEngine::scoreOption($hurtBrute, $toSafety));
same('and a healthy monster holds its ground', 0.0, CombatEngine::scoreOption($healthy, $toSafety));

$stillInReach = $toSafety;
$stillInReach['dest_threat'] = 1;
same(
    'retreating to somewhere still threatened is not a retreat',
    0.0,
    CombatEngine::scoreOption($hurtArcher, $stillInReach)
);

$closing = ['kind' => 'reposition', 'dest' => [8, 5], 'provokes' => 0,
            'dest_cover' => 0, 'dest_threat' => 0, 'closes_ft' => 30, 'engages_soft' => false];
$dawdling = $closing;
$dawdling['closes_ft'] = 5;
ok('with nothing in reach it walks toward somebody', CombatEngine::scoreOption($healthy, $closing) > 0.0);
ok('and prefers to close further', CombatEngine::scoreOption($healthy, $closing) > CombatEngine::scoreOption($healthy, $dawdling));

$ontoTheCaster = $closing;
$ontoTheCaster['engages_soft'] = true;
ok('reaching the caster is worth more than merely closing', CombatEngine::scoreOption($healthy, $ontoTheCaster) > CombatEngine::scoreOption($healthy, $closing));

$costly = $closing;
$costly['provokes'] = 1;
same('but not through a free swing it cannot afford', 0.0, CombatEngine::scoreOption($healthy, $costly));
ok('doing nothing at all still scores something', CombatEngine::scoreOption($healthy, ['kind' => 'dodge']) > 0.0);

// ---------------------------------------------------------------------------
section('Traits');

$wolf = ['traits' => ['Keen Hearing and Smell' => '...', 'Pack Tactics' => '...']];
ok('Pack Tactics is found case-insensitively', CombatEngine::hasTrait($wolf, 'pack tactics'));
ok('an absent trait is absent', !CombatEngine::hasTrait($wolf, 'undead fortitude'));
ok('a JSON string of traits is read too', CombatEngine::hasTrait(['traits' => '{"Pack Tactics":"x"}'], 'pack tactics'));
ok('no traits at all is safe', !CombatEngine::hasTrait([], 'pack tactics'));

// ---------------------------------------------------------------------------
section('Boons');

/*
 * Every buff spell used to be inert: the `buff` resolution read only
 * `effect.condition`, and not one of the six buffs declared one, so Bless,
 * Shield of Faith, Hunter's Mark, Guidance and Lesser Restoration all spent a
 * slot and a turn to do nothing. These check the arithmetic half of the fix.
 * The private helpers are reached by reflection rather than being widened to
 * public purely for a test.
 */
$effectiveAc = new ReflectionMethod(CombatEngine::class, 'effectiveAc');
$effectiveAc->setAccessible(true);
$acOf = static fn (array $c) => $effectiveAc->invoke(null, $c);

$boonBonus = new ReflectionMethod(CombatEngine::class, 'boonBonus');
$boonBonus->setAccessible(true);
$engine = (new ReflectionClass(CombatEngine::class))->newInstanceWithoutConstructor();
$bonusOf = static fn (array $c, string $f) => $boonBonus->invoke($engine, $c, $f);

same('no boons leaves AC alone', 15, $acOf(who('a', 'party', 'front')));
same(
    'Shield of Faith raises it',
    17,
    $acOf(who('a', 'party', 'front', ['boons' => [['key' => 'shield of faith', 'ac' => 2]]]))
);
same(
    'two sources stack',
    18,
    $acOf(who('a', 'party', 'front', ['boons' => [
        ['key' => 'shield of faith', 'ac' => 2],
        ['key' => 'other', 'ac' => 1],
    ]]))
);
same('a combatant predating boons is safe', 15, $acOf(['ac' => 15]));
same('AC can never be argued below 1', 1, $acOf(['ac' => 2, 'boons' => [['key' => 'x', 'ac' => -50]]]));

same('no boons is no bonus', 0, $bonusOf(who('a', 'party', 'front'), 'attack_bonus_dice'));
$blessed = who('a', 'party', 'front', ['boons' => [
    ['key' => 'bless', 'attack_bonus_dice' => '1d4', 'save_bonus_dice' => '1d4'],
]]);
$atk = $bonusOf($blessed, 'attack_bonus_dice');
ok('Bless adds 1d4 to attacks', $atk >= 1 && $atk <= 4, "got {$atk}");
$sav = $bonusOf($blessed, 'save_bonus_dice');
ok('and 1d4 to saves', $sav >= 1 && $sav <= 4, "got {$sav}");
same('but nothing to damage, which it does not touch', 0, $bonusOf($blessed, 'damage_bonus_dice'));

$marked = who('a', 'party', 'front', ['boons' => [['key' => "hunter's mark", 'damage_bonus_dice' => '1d6']]]);
$dmg = $bonusOf($marked, 'damage_bonus_dice');
ok("Hunter's Mark adds 1d6 to damage", $dmg >= 1 && $dmg <= 6, "got {$dmg}");

// A boon is rolled fresh each time it is read — Bless is not one number held
// for the whole fight. Twenty reads of 1d4 landing on a single value would be
// a 1-in-4^19 coincidence, so this is a safe thing to assert.
$seen = [];
for ($i = 0; $i < 20; $i++) {
    $seen[$bonusOf($blessed, 'attack_bonus_dice')] = true;
}
ok('and it is re-rolled on every attack, not fixed at cast', count($seen) > 1);

// ===========================================================================
// The grid
// ===========================================================================

$open = board();

// ---------------------------------------------------------------------------
section('Distance is Chebyshev — every square is five feet');

same('a step east is one cell', 1, BattleGrid::cells(3, 3, 4, 3));
same('a step diagonally is also one cell', 1, BattleGrid::cells(3, 3, 4, 4));
same('and so costs the same five feet', 5, BattleGrid::feet(3, 3, 4, 4));
same('a knight move is two cells', 2, BattleGrid::cells(3, 3, 5, 4));
same('standing still is nothing', 0, BattleGrid::cells(3, 3, 3, 3));
same('thirty feet is six cells', 6, BattleGrid::ftToCells(30));
same('a ten-foot reach is two cells', 2, BattleGrid::ftToCells(10));
same('and five feet is one', 1, BattleGrid::ftToCells(5));

same('the board reads its own cells', '.', BattleGrid::at($open, 0, 0));
ok('off the board is solid rock', !BattleGrid::passable($open, -1, 5));
ok('and off the board blocks sight', BattleGrid::info($open, 99, 99)['opaque']);

// ---------------------------------------------------------------------------
section('Line of sight');

// A three-cell wall directly between two combatants standing level with it.
$wall = board();
foreach ([4, 5, 6] as $wy) {
    $wall = paint($wall, 5, $wy, '#');
}

ok('a wall blocks sight', !BattleGrid::losClear($wall, 2, 5, 8, 5));
ok('and it blocks from the other side too', !BattleGrid::losClear($wall, 8, 5, 2, 5));
ok('open ground does not', BattleGrid::losClear($open, 2, 5, 8, 5));
ok('going around the wall is clear', BattleGrid::losClear($wall, 2, 1, 8, 1));

// A low obstacle is cover, not a blindfold. This is the whole reason `o` and
// `n` are different characters.
$crates = board();
foreach ([4, 5, 6] as $wy) {
    $crates = paint($crates, 5, $wy, 'o');
}
ok('a crate does not block sight', BattleGrid::losClear($crates, 2, 5, 8, 5));

// A pit is the mirror image: you cannot walk it, you can shoot over it.
$chasm = board();
foreach ([4, 5, 6] as $wy) {
    $chasm = paint($chasm, 5, $wy, 'v');
}
ok('a pit does not block sight', BattleGrid::losClear($chasm, 2, 5, 8, 5));
ok('but nothing walks across it', !BattleGrid::passable($chasm, 5, 5));

// The bug this rule exists to stop: a ray sliding down the seam between two
// wall cells is not a clear shot, however thin the gap looks on paper.
$seam = board();
foreach ([3, 4, 5, 6, 7] as $wy) {
    $seam = paint($seam, 5, $wy, '#');
}
ok('nothing threads the join between two walls', !BattleGrid::losClear($seam, 2, 5, 8, 5));

// But shooting along the outside face of a wall is fine, which is the other
// half of the same rule.
$face = board();
for ($wx = 3; $wx <= 9; $wx++) {
    $face = paint($face, $wx, 6, '#');
}
ok('shooting along a wall face is clear', BattleGrid::losClear($face, 2, 5, 10, 5));

// Symmetry, swept. If this ever fails, the monster AI and the player have
// started disagreeing about what is shootable and every targeting bug reported
// for a month will really be this one.
$speckled = board();
$rngSeed = 20260805;
foreach ([[3, 2], [4, 7], [6, 4], [7, 9], [9, 3], [10, 6], [11, 2], [12, 8], [5, 5], [8, 5]] as [$px, $py]) {
    $speckled = paint($speckled, $px, $py, $py % 2 === 0 ? '#' : 'n');
}
$sample = [];
for ($y = 0; $y < BattleGrid::H; $y += 2) {
    for ($x = 0; $x < BattleGrid::W; $x += 2) {
        $sample[] = [$x, $y];
    }
}
$asym = 0;
foreach ($sample as [$ax, $ay]) {
    foreach ($sample as [$bx, $by]) {
        if (BattleGrid::losClear($speckled, $ax, $ay, $bx, $by)
            !== BattleGrid::losClear($speckled, $bx, $by, $ax, $ay)) {
            $asym++;
        }
    }
}
same('sight is symmetric across every sampled pair', 0, $asym);

// ---------------------------------------------------------------------------
section('Cover');

$shooter = at('shooter', 'party', 2, 5);
$hider = at('hider', 'foe', 8, 5);

same('open ground is no cover', BattleGrid::COVER_NONE, BattleGrid::coverBetween($open, [], $shooter, $hider));
same(
    'a wall of crates is half cover, however many rays it clips',
    BattleGrid::COVER_HALF,
    BattleGrid::coverBetween($crates, [], $shooter, $hider)
);

// A single pillar mid-field: the attacker leans round it, so it is half cover
// rather than three-quarters. That is the SRD corner rule doing its job.
$pillar = paint(board(), 5, 5, 'n');
same(
    'one pillar between you is half cover',
    BattleGrid::COVER_HALF,
    BattleGrid::coverBetween($pillar, [], $shooter, $hider)
);

// Hugging the pillar is what earns three-quarters.
$snug = at('snug', 'foe', 6, 5);
ok(
    'a target pressed against the pillar has more cover',
    BattleGrid::coverBetween($pillar, [], at('far', 'party', 1, 8), $snug) >= BattleGrid::COVER_HALF
);

// A body in the way is half cover and never more — this is what stops your own
// front rank from being a wall.
$screen = [at('screen', 'party', 5, 5)];
same(
    'an ally in the line of fire is half cover',
    BattleGrid::COVER_HALF,
    BattleGrid::coverBetween($open, $screen, $shooter, $hider)
);
same(
    'a dead body is not cover at all',
    BattleGrid::COVER_NONE,
    BattleGrid::coverBetween($open, [at('corpse', 'party', 5, 5, ['alive' => false])], $shooter, $hider)
);
same(
    'and neither the shooter nor the target covers the target',
    BattleGrid::COVER_NONE,
    BattleGrid::coverBetween($open, [$shooter, $hider], $shooter, $hider)
);

/*
 * A crowd is not cover.
 *
 * Six creatures packed around one target used to report half cover from every
 * one of them, because the corner was chosen on the terrain alone — every
 * corner tied at zero, the first one won, and it happened to look diagonally
 * through somebody. The attacker gets to pick the corner that gives the
 * clearest shot, and next to a body there is always one.
 */
$mobbed = at('mark', 'foe', 5, 5);
$mob = [$mobbed];
foreach ([[4, 4], [5, 4], [6, 4], [4, 5], [6, 5], [4, 6], [5, 6], [6, 6]] as $i => [$mx, $my]) {
    $mob[] = at('thug' . $i, 'party', $mx, $my);
}
$crowdedCover = [];
foreach ($mob as $c) {
    if ($c['side'] !== 'party') {
        continue;
    }
    $crowdedCover[$c['cid']] = BattleGrid::coverBetween($open, $mob, $c, $mobbed);
}
same('nobody in a scrum gets cover from the scrum', [0, 0, 0, 0, 0, 0, 0, 0], array_values($crowdedCover));

// The rule still fires when somebody really is in the way, at a distance where
// leaning round them is not an option.
$screened = [at('bow', 'party', 1, 5), at('shield', 'party', 5, 5), at('mark', 'foe', 9, 5)];
same(
    'but a body directly in the line at range still is',
    BattleGrid::COVER_HALF,
    BattleGrid::coverBetween($open, $screened, $screened[0], $screened[2])
);

// Total cover is not a bonus, it is the absence of a shot. legalTargets asks
// losClear, so this pairing has to hold.
ok('a wall means no shot rather than a big bonus', !BattleGrid::losClear($wall, 2, 5, 8, 5));

// ---------------------------------------------------------------------------
section('Movement');

$mover = at('mover', 'party', 1, 5);

$reach = BattleGrid::reachable($open, [$mover], $mover, 30);
same('the origin costs nothing', 0, $reach['1,5']['cost']);
ok('six cells east is reachable', isset($reach['7,5']));
same('and costs thirty feet', 30, $reach['7,5']['cost']);
ok('seven cells east is not', !isset($reach['8,5']));
ok('six cells diagonally is reachable too, because diagonals are cheap', isset($reach['7,11']) || isset($reach['7,0']));

// Rough ground doubles the price, so half the distance for the same budget.
$mud = board();
for ($mx = 2; $mx <= 12; $mx++) {
    for ($my = 0; $my < BattleGrid::H; $my++) {
        $mud = paint($mud, $mx, $my, ',');
    }
}
$muddy = BattleGrid::reachable($mud, [$mover], $mover, 30);
same('rough ground costs ten feet a cell', 30, $muddy['4,5']['cost']);
ok('so thirty feet only carries three cells', !isset($muddy['5,5']));

// The predecessor chain is the client's path preview. If the summed steps ever
// disagree with the recorded cost, the board is drawing a route the server
// would charge differently for.
$path = BattleGrid::pathTo($reach, '7,5');
same('the path starts where the mover is', '1,5', $path[0]);
same('and ends where they are going', '7,5', $path[count($path) - 1]);
same('with a step for every cell', 7, count($path));
$summed = 0;
for ($i = 1; $i < count($path); $i++) {
    [$px, $py] = BattleGrid::parseKey($path[$i]);
    $summed += BattleGrid::enterCost($open, $px, $py);
}
same('and its steps add up to what the search charged', $reach['7,5']['cost'], $summed);
same('a destination that was never reached has no path', [], BattleGrid::pathTo($reach, '15,0'));

/*
 * A path has to LOOK like somebody walking.
 *
 * Under Chebyshev a diagonal costs what a step costs, so nearly every
 * destination has many routes of identical price and the one recorded is
 * decided entirely by tie-break. Left to the enumeration order, ten cells due
 * east came back as ten diagonals zigzagging either side of the straight line —
 * the right fifty feet, and the board animates the walk faithfully, so the
 * party looked unable to walk in a straight line.
 *
 * The assertion above this one is why it went unnoticed for so long: a zigzag
 * has exactly the same number of CELLS as the straight line, so counting steps
 * passes either way. Shape is what has to be checked.
 */
$steps = static function (array $reach, string $dest): array {
    $path = BattleGrid::pathTo($reach, $dest);
    $out = [];
    for ($i = 1; $i < count($path); $i++) {
        [$ax, $ay] = BattleGrid::parseKey($path[$i - 1]);
        [$bx, $by] = BattleGrid::parseKey($path[$i]);
        $out[] = ($bx - $ax) . ',' . ($by - $ay);
    }
    return $out;
};
/** How many times the path changes heading. */
$turns = static function (array $steps): int {
    $n = 0;
    for ($i = 1; $i < count($steps); $i++) {
        if ($steps[$i] !== $steps[$i - 1]) {
            $n++;
        }
    }
    return $n;
};

$long = BattleGrid::reachable($open, [$mover], $mover, 60);
same(
    'ten cells due east is walked due east, not as ten diagonals',
    array_fill(0, 10, '1,0'),
    $steps($long, '11,5')
);
same('a pure diagonal is still walked diagonally', array_fill(0, 4, '1,1'), $steps($long, '5,9'));

// Eight east and two south: six straight then two diagonal is one turn and two
// diagonals; five diagonals down then three back up is also one turn, and is a
// V for no reason. Turns alone do not separate them — leaning less is what does.
$vshape = $steps($long, '9,7');
same('a mostly-straight move bends once', 1, $turns($vshape));
same(
    'and spends diagonals only where they buy ground',
    2,
    count(array_filter($vshape, static fn ($s) => $s === '1,1' || $s === '1,-1'))
);

// The invariant that matters most: whatever shape it picks, the path must still
// cost exactly what the search charged for the destination.
foreach (['11,5', '5,9', '9,7', '7,8'] as $dest) {
    $sum = 0;
    foreach (BattleGrid::pathTo($long, $dest) as $i => $cell) {
        if ($i === 0) {
            continue;
        }
        [$px, $py] = BattleGrid::parseKey($cell);
        $sum += BattleGrid::enterCost($open, $px, $py);
    }
    same("the straightened path to {$dest} still costs what it is priced at", $long[$dest]['cost'], $sum);
}

// Occupancy, which is where most of the feel lives.
$ally = at('ally', 'party', 3, 5);
$foe = at('foe', 'foe', 3, 5);

$past = BattleGrid::reachable($open, [$mover, $ally], $mover, 30);
ok('you may walk through a friend', isset($past['5,5']));
ok('but not stop on them', isset($past['3,5']) && $past['3,5']['end'] === false);

$barred = BattleGrid::reachable($open, [$mover, $foe], $mover, 30);
ok('an enemy is a wall', !isset($barred['3,5']));
ok('though you can go round them', isset($barred['5,5']));

// A downed body is furniture: step over it, do not stand on it.
$downedFoe = at('downed', 'foe', 3, 5, ['conditions' => [['key' => 'unconscious']]]);
$overBody = BattleGrid::reachable($open, [$mover, $downedFoe], $mover, 30);
ok('an unconscious enemy can be stepped over', isset($overBody['5,5']));
ok('but still cannot be stood on', isset($overBody['3,5']) && $overBody['3,5']['end'] === false);

// Nobody squeezes between the corners of two walls.
$pinch = paint(paint(board(), 5, 5, '#'), 6, 6, '#');
$corner = BattleGrid::reachable($pinch, [at('m', 'party', 5, 6)], at('m', 'party', 5, 6), 5);
ok('a diagonal between two wall corners is refused', !isset($corner['6,5']));

// Speed zero is a real answer, not a crash.
$rooted = at('growth', 'foe', 8, 5, ['speed' => 0]);
$stuck = BattleGrid::reachable($open, [$rooted], $rooted, 0);
same('something with no speed reaches only its own cell', ['8,5'], array_keys($stuck));

// ---------------------------------------------------------------------------
section('Threat, opportunity and flanking');

$guard = at('guard', 'foe', 5, 5);
$threat = BattleGrid::threatenedCells($open, [$guard]);
same('a sword threatens the eight cells around it', 8, count($threat));
ok('including diagonally', isset($threat['6,6']));
ok('but not its own square', !isset($threat['5,5']));
ok('and not two cells away', !isset($threat['7,5']));

$halberdier = at('halberdier', 'foe', 5, 5, ['reach_ft' => 10]);
$long = BattleGrid::threatenedCells($open, [$halberdier]);
same('a reach weapon threatens the whole five-by-five', 24, count($long));
ok('including two cells out', isset($long['7,5']));

// A ten-foot reach still needs to see what it is hitting. The wall goes at x=4,
// between the halberdier at (5,5) and the cell at (3,5) they would otherwise
// threaten — stand them *on* it and the wall is their own square, which blocks
// nothing.
$walledOff = board();
foreach ([4, 5, 6] as $wy) {
    $walledOff = paint($walledOff, 4, $wy, '#');
}
same(
    'a cell one side of a wall is still threatened',
    ['halberdier'],
    BattleGrid::threatenersOf($walledOff, [$halberdier], 6, 5)
);
same(
    'but reach does not go through the wall',
    [],
    BattleGrid::threatenersOf($walledOff, [$halberdier], 3, 5)
);

// The opportunity-attack rule in one comparison: leaving reach provokes,
// circling inside it does not.
$leaving = BattleGrid::threatenersOf($open, [$guard], 4, 5);
$arriving = BattleGrid::threatenersOf($open, [$guard], 3, 5);
$sidestep = BattleGrid::threatenersOf($open, [$guard], 4, 6);
ok('a cell beside the guard is threatened', $leaving === ['guard']);
ok('a cell two away is not', $arriving === []);
ok('stepping out of reach provokes', array_diff($leaving, $arriving) === ['guard']);
ok('circling within reach does not', array_diff($leaving, $sidestep) === []);

// Flanking: exactly opposite, through the target's centre.
$target = at('target', 'foe', 5, 5);
$west = at('west', 'party', 4, 5);
$east = at('east', 'party', 6, 5);
$northEast = at('ne', 'party', 6, 4);

ok('two allies on opposite sides flank', BattleGrid::flanking($open, $west, [$east], $target));
ok('and it reads the same from the other one', BattleGrid::flanking($open, $east, [$west], $target));
ok('an ally at an angle does not flank', !BattleGrid::flanking($open, $west, [$northEast], $target));
ok('one attacker alone does not flank', !BattleGrid::flanking($open, $west, [], $target));
ok('and neither does an ally who cannot reach', !BattleGrid::flanking($open, $west, [at('far', 'party', 9, 5)], $target));

$diagA = at('a', 'party', 4, 4);
$diagB = at('b', 'party', 6, 6);
ok('a diagonal pair flanks too', BattleGrid::flanking($open, $diagA, [$diagB], $target));

// ---------------------------------------------------------------------------
section('Templates');

// The cone rule, written down. A cone of length n is as wide as it is long: a
// cell at distance d is inside when its offset from the axis is at most d/2.
// These are the answers; arguing with them means arguing with the test.
$coneEast = BattleGrid::template($open, 'cone', 15, 5, 5, 9, 5);
sort($coneEast);
$wantEast = ['6,5', '7,4', '7,5', '7,6', '8,4', '8,5', '8,6'];
sort($wantEast);
same('a fifteen-foot cone east covers seven cells', $wantEast, $coneEast);

$coneDiag = BattleGrid::template($open, 'cone', 15, 5, 5, 9, 9);
sort($coneDiag);
$wantDiag = ['6,6', '6,7', '7,6', '7,7', '7,8', '8,7', '8,8'];
sort($wantDiag);
same('and a diagonal cone covers seven as well', $wantDiag, $coneDiag);

same('a cone never includes the caster', false, in_array('5,5', $coneEast, true));

$radius = BattleGrid::template($open, 'radius', 15, 5, 5, 5, 5);
same('a fifteen-foot radius is seven by seven', 49, count($radius));
ok('and it does include its own centre', in_array('5,5', $radius, true));

$cube = BattleGrid::template($open, 'cube', 15, 5, 5, 8, 5);
sort($cube);
same('a fifteen-foot cube is three by three', 9, count($cube));
ok('centred on where it was aimed', in_array('8,5', $cube, true));

$line = BattleGrid::template($open, 'line', 15, 5, 5, 9, 5);
same('a fifteen-foot line is three cells', ['6,5', '7,5', '8,5'], $line);

// Nothing turns a corner. A fireball behind a wall is a fireball you cannot
// place there.
$blocked = BattleGrid::template($wall, 'radius', 25, 2, 5, 2, 5);
ok('a template does not reach past a wall', !in_array('8,5', $blocked, true));
ok('though it fills the side it was cast on', in_array('3,5', $blocked, true));

$clipped = BattleGrid::template($open, 'line', 100, 12, 5, 15, 5);
ok('a hundred-foot line stops at the edge of the board', count($clipped) <= 3);

same('an unknown shape draws nothing', [], BattleGrid::template($open, 'trapezoid', 15, 5, 5, 8, 5));

same('east is east', [1, 0], BattleGrid::direction(5, 5, 9, 5));
same('north-east is north-east', [1, -1], BattleGrid::direction(5, 5, 9, 1));
same('aiming at yourself still has an answer', [1, 0], BattleGrid::direction(5, 5, 5, 5));

// ---------------------------------------------------------------------------
section('Ranges, read from the values the content actually holds');

// Every distinct `range` string in content/monsters, every `range_text` in
// content/spells, and every `speed`. Driving the table from what exists is what
// makes this a regression test rather than a demonstration.
foreach ([
    '80/320'  => ['normal' => 80,  'long' => 320],
    '30/120'  => ['normal' => 30,  'long' => 120],
    '150/600' => ['normal' => 150, 'long' => 600],
    '30/60'   => ['normal' => 30,  'long' => 60],
    '20/60'   => ['normal' => 20,  'long' => 60],
    '40 ft.'  => ['normal' => 40,  'long' => null],
    '5 ft.'   => ['normal' => 5,   'long' => null],
    ''        => ['normal' => null, 'long' => null],
] as $text => $want) {
    same("range '{$text}'", $want, Rules::parseRangeText($text));
}

$shortbow = ['name' => 'Shortbow', 'properties' => '{"ranged":"80/320"}'];
same('a shortbow carries eighty and three hundred and twenty', ['reach' => null, 'normal' => 80, 'long' => 320], Rules::weaponRange($shortbow));
same('fists reach five feet', ['reach' => 5, 'normal' => null, 'long' => null], Rules::weaponRange(null));
same(
    'a plain sword reaches five feet',
    ['reach' => 5, 'normal' => null, 'long' => null],
    Rules::weaponRange(['properties' => '{"finesse":true}'])
);
same(
    'a reach weapon reaches ten',
    ['reach' => 10, 'normal' => null, 'long' => null],
    Rules::weaponRange(['properties' => ['versatile' => '1d8', 'reach' => true]])
);
same(
    'a thrown weapon does both',
    ['reach' => 5, 'normal' => 20, 'long' => 60],
    Rules::weaponRange(['properties' => ['thrown' => '20/60']])
);
same(
    'a bare thrown:true still gets numbers',
    ['reach' => 5, 'normal' => 20, 'long' => 60],
    Rules::weaponRange(['properties' => ['thrown' => true]])
);
same(
    'ammunition is spelled the other way and means the same',
    ['reach' => null, 'normal' => 30, 'long' => 120],
    Rules::weaponRange(['properties' => ['ammunition' => true]])
);

foreach ([
    '60 feet'               => ['kind' => 'ranged', 'ft' => 60,  'aoe' => null],
    '120 feet'              => ['kind' => 'ranged', 'ft' => 120, 'aoe' => null],
    '150 feet'              => ['kind' => 'ranged', 'ft' => 150, 'aoe' => null],
    '90 feet'               => ['kind' => 'ranged', 'ft' => 90,  'aoe' => null],
    '30 feet'               => ['kind' => 'ranged', 'ft' => 30,  'aoe' => null],
    '10 feet'               => ['kind' => 'ranged', 'ft' => 10,  'aoe' => null],
    'Touch'                 => ['kind' => 'touch',  'ft' => 5,   'aoe' => null],
    'Self'                  => ['kind' => 'self',   'ft' => 0,   'aoe' => null],
    'Self (15-foot cone)'   => ['kind' => 'self', 'ft' => 0, 'aoe' => ['shape' => 'cone',   'ft' => 15]],
    'Self (30-foot cone)'   => ['kind' => 'self', 'ft' => 0, 'aoe' => ['shape' => 'cone',   'ft' => 30]],
    'Self (15-foot radius)' => ['kind' => 'self', 'ft' => 0, 'aoe' => ['shape' => 'radius', 'ft' => 15]],
    'Self (15-foot cube)'   => ['kind' => 'self', 'ft' => 0, 'aoe' => ['shape' => 'cube',   'ft' => 15]],
    'Self (100-foot line)'  => ['kind' => 'self', 'ft' => 0, 'aoe' => ['shape' => 'line',   'ft' => 100]],
] as $text => $want) {
    same("spell range '{$text}'", $want, Rules::spellRange($text));
}

// The old one-bit range model, kept as the fallback for a range nobody wrote.
same(
    'an unparseable melee spell falls back to touch',
    ['kind' => 'touch', 'ft' => 5, 'aoe' => null],
    Rules::spellRange('somewhere over there', 0)
);
same(
    'and an unparseable ranged one to sixty feet',
    ['kind' => 'ranged', 'ft' => 60, 'aoe' => null],
    Rules::spellRange('somewhere over there', 1)
);

foreach (['30 ft.' => 30, '20 ft.' => 20, '40 ft.' => 40, '50 ft.' => 50, '10 ft.' => 10, '0 ft.' => 0] as $text => $want) {
    same("speed '{$text}'", $want, Rules::parseSpeedFt($text));
}
same('a missing speed is the SRD default', 30, Rules::parseSpeedFt(null));
same('and so is a nonsensical one', 30, Rules::parseSpeedFt('quick'));

/*
 * Every weapon in the content, read off disk.
 *
 * The synthetic cases above prove the parser; this proves the *content* — that
 * no authored weapon comes back unable to attack at all, which is what a
 * mistyped property would produce and what nothing else would catch. It also
 * fails loudly the day somebody adds a weapon with a range nobody anticipated.
 */
$weaponFiles = glob(dirname(__DIR__) . '/content/items/*.json') ?: [];
$mute = [];
$armed = 0;
foreach ($weaponFiles as $file) {
    $item = json_decode((string) file_get_contents($file), true);
    if (!is_array($item) || ($item['item_type'] ?? '') !== 'weapon') {
        continue;
    }
    $armed++;
    $range = Rules::weaponRange($item);
    if ($range['reach'] === null && $range['normal'] === null) {
        $mute[] = $item['item_key'] ?? basename($file);
    }
}
ok('there are weapons to check', $armed >= 8, "found {$armed}");
same('every authored weapon can reach something', [], $mute);

// The three the grid exists for. A halberd that does not reach ten feet, or a
// longbow that does not outrange a sling, would be a silent content fault.
$byKey = static function (string $key) {
    $path = dirname(__DIR__) . "/content/items/{$key}.json";
    return is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
};
same('a halberd reaches ten feet', 10, Rules::weaponRange($byKey('halberd'))['reach']);
same('a longbow carries a hundred and fifty', 150, Rules::weaponRange($byKey('longbow'))['normal']);
same('and six hundred at the outside', 600, Rules::weaponRange($byKey('longbow'))['long']);
same('a spear stabs at five feet', 5, Rules::weaponRange($byKey('spear'))['reach']);
same('and throws twenty', 20, Rules::weaponRange($byKey('spear'))['normal']);
same('a sling outranges nothing much', 30, Rules::weaponRange($byKey('sling'))['normal']);
same('and cannot be swung at all', null, Rules::weaponRange($byKey('sling'))['reach']);

// ---------------------------------------------------------------------------
section('Generated battlefields');

$first = BattleMapGen::generate(12345, 'cavern');
$again = BattleMapGen::generate(12345, 'cavern');
same('the same seed builds the same map', $first['terrain'], $again['terrain']);
ok('a different seed builds a different one', BattleMapGen::generate(999, 'cavern')['terrain'] !== $first['terrain']);
same('the board is twelve rows', BattleGrid::H, count($first['terrain']));
same('of sixteen characters', BattleGrid::W, strlen($first['terrain'][0]));
ok('an unknown palette still builds something', count(BattleMapGen::generate(1, 'swamp')['terrain']) === BattleGrid::H);

same('a tavern is an interior', 'interior', BattleMapGen::paletteFor('building', 'town'));
same('a room in a dungeon is a cavern', 'cavern', BattleMapGen::paletteFor('room', 'dungeon'));
same('a dungeon passage is a tunnel', 'tunnel', BattleMapGen::paletteFor('site', 'dungeon'));
same('a camp is a camp wherever it is pitched', 'camp', BattleMapGen::paletteFor('camp', 'wilderness'));
same('open country is forest', 'forest', BattleMapGen::paletteFor('site', 'wilderness'));
same('a town street is a street', 'street', BattleMapGen::paletteFor('street', 'town'));
same('and the pit is always the arena', 'arena', BattleMapGen::paletteFor('arena', 'dungeon'));

/*
 * The guarantee that a player never spawns walled in, checked by brute force
 * across every palette. It costs milliseconds and it is the only thing standing
 * between a bad seed and a fight nobody can reach the other side of.
 */
$palettes = ['arena', 'interior', 'street', 'cavern', 'tunnel', 'camp', 'forest'];
$bad = [];
foreach ($palettes as $palette) {
    for ($seed = 1; $seed <= 200; $seed++) {
        $map = BattleMapGen::generate($seed * 7919, $palette);
        $terrain = $map['terrain'];

        $partySpots = 0;
        for ($y = $map['zones']['party'][1]; $y <= $map['zones']['party'][3]; $y++) {
            for ($x = $map['zones']['party'][0]; $x <= $map['zones']['party'][2]; $x++) {
                if (BattleGrid::passable($terrain, $x, $y)) {
                    $partySpots++;
                }
            }
        }
        $foeSpots = 0;
        for ($y = $map['zones']['foe'][1]; $y <= $map['zones']['foe'][3]; $y++) {
            for ($x = $map['zones']['foe'][0]; $x <= $map['zones']['foe'][2]; $x++) {
                if (BattleGrid::passable($terrain, $x, $y)) {
                    $foeSpots++;
                }
            }
        }
        if ($partySpots < 8 || $foeSpots < 8) {
            $bad[] = "{$palette}/{$seed}: room for {$partySpots} and {$foeSpots}";
            continue;
        }

        // Can the party actually walk to the enemy?
        $walker = ['cid' => 'w', 'side' => 'party', 'alive' => true, 'x' => 2, 'y' => 5];
        $reachAll = BattleGrid::reachable($terrain, [$walker], $walker, 9999);
        $touched = false;
        for ($y = $map['zones']['foe'][1]; $y <= $map['zones']['foe'][3] && !$touched; $y++) {
            for ($x = $map['zones']['foe'][0]; $x <= $map['zones']['foe'][2]; $x++) {
                if (isset($reachAll[BattleGrid::keyOf($x, $y)])) {
                    $touched = true;
                    break;
                }
            }
        }
        if (!$touched) {
            $bad[] = "{$palette}/{$seed}: zones not connected";
        }
    }
}
same('1400 generated maps are all playable', [], array_slice($bad, 0, 5));

// Deployment turns the old rank into a starting column and nothing else.
$map = BattleMapGen::generate(4242, 'street');
$spots = BattleMapGen::deploy(
    $map['terrain'],
    $map['zones']['party'],
    ['front', 'front', 'back', 'back'],
    'right'
);
same('everybody gets a cell', 4, count($spots));
$seenSpots = [];
foreach ($spots as $s) {
    $seenSpots[BattleGrid::keyOf($s['x'], $s['y'])] = true;
}
same('and no two share one', 4, count($seenSpots));
ok(
    'the front rank starts nearer the enemy than the back',
    min($spots[0]['x'], $spots[1]['x']) > max($spots[2]['x'], $spots[3]['x'])
);
foreach ($spots as $i => $s) {
    ok("deployed combatant {$i} stands on open ground", BattleGrid::passable($map['terrain'], $s['x'], $s['y']));
    ok("deployed combatant {$i} stands inside the zone", BattleMapGen::inZone($s['x'], $s['y'], $map['zones']['party']));
}

$foeSpots = BattleMapGen::deploy($map['terrain'], $map['zones']['foe'], ['front', 'back'], 'left');
ok('and the enemy front rank faces the other way', $foeSpots[0]['x'] < $foeSpots[1]['x']);

// A crowd larger than the zone still gets placed rather than stacking.
$crowd = BattleMapGen::deploy($map['terrain'], $map['zones']['party'], array_fill(0, 12, 'front'), 'right');
$crowdKeys = [];
foreach ($crowd as $s) {
    $crowdKeys[BattleGrid::keyOf($s['x'], $s['y'])] = true;
}
same('twelve combatants get twelve distinct cells', 12, count($crowdKeys));

// ---------------------------------------------------------------------------
echo PHP_EOL . '== Dash: what a monster thinks of running ==' . PHP_EOL;

$runner = ['cid' => 'mon_1', 'hp' => 20, 'max_hp' => 20, 'speed' => 30];

ok('a dash that closes ground beats taking cover',
    CombatEngine::scoreOption($runner, ['kind' => 'dash', 'closes_ft' => 25])
    > CombatEngine::scoreOption($runner, ['kind' => 'dodge']));
same('a dash that closes nothing is worth nothing', 0.0,
    CombatEngine::scoreOption($runner, ['kind' => 'dash', 'closes_ft' => 0]));
same('and so is one that would lose ground', 0.0,
    CombatEngine::scoreOption($runner, ['kind' => 'dash', 'closes_ft' => -10]));
ok('further is better',
    CombatEngine::scoreOption($runner, ['kind' => 'dash', 'closes_ft' => 40])
    > CombatEngine::scoreOption($runner, ['kind' => 'dash', 'closes_ft' => 10]));
ok('but not through two opportunity attacks',
    CombatEngine::scoreOption($runner, ['kind' => 'dash', 'closes_ft' => 25, 'provokes' => 2])
    <= CombatEngine::scoreOption($runner, ['kind' => 'dodge']));
ok('an unknown option is still never chosen',
    CombatEngine::scoreOption($runner, ['kind' => 'sprint', 'closes_ft' => 99]) === 0.0);

// ---------------------------------------------------------------------------
echo PHP_EOL . '== Cunning Action offers a choice ==' . PHP_EOL;

$cunning = CombatEngine::BONUS_ACTIONS['Rogue'];
same('the rogue is offered two', ['dash', 'disengage'], array_column($cunning['options'], 'key'));
ok('the barbarian is offered none', !isset(CombatEngine::BONUS_ACTIONS['Barbarian']['options']));
ok('and so is the fighter', !isset(CombatEngine::BONUS_ACTIONS['Fighter']['options']));

// ---------------------------------------------------------------------------
echo PHP_EOL . '== Sneak Attack ==' . PHP_EOL;

// The dice only. Whether the opening is there reads the board and the actor's
// turn stamp through a live engine, so it belongs to test_grid_combat.sh over
// real HTTP rather than here — this harness holds no PDO by design.
same('1d6 at 1st', 1, CombatEngine::sneakAttackDice('Rogue', 1));
same('still 1d6 at 2nd', 1, CombatEngine::sneakAttackDice('Rogue', 2));
same('2d6 at 3rd', 2, CombatEngine::sneakAttackDice('Rogue', 3));
same('still 2d6 at 4th', 2, CombatEngine::sneakAttackDice('Rogue', 4));
same('3d6 at 5th', 3, CombatEngine::sneakAttackDice('Rogue', 5));
same('and 3d6 at 6th, where the game stops', 3, CombatEngine::sneakAttackDice('Rogue', 6));
same('a barbarian sneaks up on nobody', 0, CombatEngine::sneakAttackDice('Barbarian', 6));
same('nor does a class that does not exist', 0, CombatEngine::sneakAttackDice('Artificer', 6));
same('a level below 1 is still a rogue', 1, CombatEngine::sneakAttackDice('Rogue', 0));

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
