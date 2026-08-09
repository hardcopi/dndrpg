<?php
/**
 * Test harness for the two-phase check: CheckService.
 *
 * Run with `php src/tools/test_checks.php`. Like test_rules.php it requires the
 * classes directly rather than bootstrap.php, and it never constructs a
 * CheckService — everything that decides an outcome is static and takes what it
 * needs as an argument, so the arithmetic is exercised with no MySQL running and
 * no party in existence.
 *
 * $_SESSION is declared below because the pending-check store writes to it and
 * nothing here starts a session. That is the whole of the stubbing: the store is
 * an array either way.
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
require_once $lib . '/WorldState.php';
require_once $lib . '/CheckService.php';

$_SESSION = [];

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

/** A stored check, as request() would have left it in the session. */
function pending(array $over = []): array
{
    return array_merge([
        'check_id'  => 'harness',
        'party_id'  => 1,
        'kind'      => 'skill',
        'label'     => 'Persuasion',
        'dc'        => 14,
        'character' => ['id' => 1, 'name' => 'Harness', 'sprite_key' => null],
        'modifier'  => 5,
        'parts'     => [['label' => 'Charisma', 'value' => 3], ['label' => 'Proficiency', 'value' => 2]],
        'die_mode'  => 'normal',
        'boosts'    => [],
    ], $over);
}

function boost(array $over = []): array
{
    return array_merge([
        'key'                 => 'guidance',
        'label'               => 'Guidance',
        'detail'              => 'Brother Aldric, cantrip',
        'bonus_dice'          => '1d4',
        'bonus_flat'          => 0,
        'die_mode'            => null,
        'reroll_ones'         => false,
        'source_character_id' => 7,
        'cost'                => 'concentration',
    ], $over);
}

// ---------------------------------------------------------------------------
section('Outcome classification');

same('a natural 20 is a critical however low the total', 'critical', CheckService::outcomeOf(20, 5, 30));
same('a natural 1 is a fumble however high the total', 'fumble', CheckService::outcomeOf(1, 40, 5));
same('meeting the DC exactly succeeds', 'success', CheckService::outcomeOf(9, 14, 14));
same('one short of the DC fails', 'failure', CheckService::outcomeOf(9, 13, 14));
same('a high roll under the DC still fails', 'failure', CheckService::outcomeOf(19, 20, 25));

// ---------------------------------------------------------------------------
section('Success chance');

same('half the die beats DC 11 with no modifier', 50, CheckService::successChance(0, 11));
same('a modifier moves the threshold', 75, CheckService::successChance(5, 11));
same('an impossible DC still leaves the natural 20', 5, CheckService::successChance(0, 30));
same('a trivial DC still leaves the natural 1', 95, CheckService::successChance(20, 2));
same('advantage on a coin flip', 75, CheckService::successChance(0, 11, 'advantage'));
same('disadvantage on a coin flip', 25, CheckService::successChance(0, 11, 'disadvantage'));
same('disadvantage squares a near-certainty', 90, CheckService::successChance(20, 2, 'disadvantage'));
same('an unknown mode is treated as normal', 50, CheckService::successChance(0, 11, 'sideways'));

// ---------------------------------------------------------------------------
section('Boost arithmetic');

$arithmeticOk = true;
$diceInRange = true;
$partsAgree = true;
$marginOk = true;
for ($i = 0; $i < 400; $i++) {
    $result = CheckService::resolvePending(
        pending(['boosts' => [
            boost(),
            boost(['key' => 'bardic_inspiration', 'label' => 'Bardic Inspiration', 'bonus_dice' => '1d6', 'cost' => 'use']),
            boost(['key' => 'trinket', 'label' => 'Trinket', 'bonus_dice' => '', 'bonus_flat' => 2, 'cost' => 'free']),
        ]]),
        ['guidance', 'bardic_inspiration', 'trinket']
    );

    $boostTotal = 0;
    foreach ($result['boost_rolls'] as $roll) {
        $boostTotal += $roll['value'];
    }
    if ($result['total'] !== $result['natural'] + $result['modifier'] + $boostTotal) {
        $arithmeticOk = false;
    }

    $byLabel = [];
    foreach ($result['boost_rolls'] as $roll) {
        $byLabel[$roll['label']] = $roll['value'];
    }
    if (($byLabel['Guidance'] ?? 0) < 1 || ($byLabel['Guidance'] ?? 0) > 4) {
        $diceInRange = false;
    }
    if (($byLabel['Bardic Inspiration'] ?? 0) < 1 || ($byLabel['Bardic Inspiration'] ?? 0) > 6) {
        $diceInRange = false;
    }
    if (($byLabel['Trinket'] ?? 0) !== 2) {
        $diceInRange = false;
    }

    // Every part shown to the player has to add up to the modifier the roll
    // actually used, boosts included, or the breakdown contradicts the total.
    $partsTotal = 0;
    foreach ($result['parts'] as $part) {
        $partsTotal += $part['value'];
    }
    if ($partsTotal !== $result['modifier'] + $boostTotal) {
        $partsAgree = false;
    }

    if ($result['margin'] !== $result['total'] - $result['dc']) {
        $marginOk = false;
    }
}
ok('the total is die plus modifier plus boosts', $arithmeticOk);
ok('each boost rolls inside its own die', $diceInRange);
ok('the itemised parts add up to what was applied', $partsAgree);
ok('the margin is the total against the DC', $marginOk);

$flat = CheckService::resolvePending(
    pending(['boosts' => [boost(['key' => 'trinket', 'label' => 'Trinket', 'bonus_dice' => '', 'bonus_flat' => 2])]]),
    ['trinket']
);
same('a flat boost names itself with a sign', '+2', $flat['boost_rolls'][0]['expr']);

$none = CheckService::resolvePending(pending(['boosts' => [boost()]]), []);
same('an unspent boost rolls nothing', [], $none['boost_rolls']);
same('an unspent boost is not reported as spent', [], $none['spent']);
same('the parts are untouched when nothing is spent', pending()['parts'], $none['parts']);

$twice = CheckService::resolvePending(pending(['boosts' => [boost()]]), ['guidance', 'guidance']);
same('choosing one boost twice adds one die', 1, count($twice['boost_rolls']));

same('the token comes back on the result', 'harness', $none['check_id']);

// ---------------------------------------------------------------------------
section('Die mode and Halfling Lucky');

$adv = CheckService::resolvePending(pending(['die_mode' => 'advantage']), []);
same('advantage throws two dice', 2, count($adv['rolls']));
same('advantage keeps the higher', max($adv['rolls']), $adv['natural']);
same('the mode is reported back', 'advantage', $adv['die_mode']);

$granted = CheckService::resolvePending(
    pending(['boosts' => [boost(['key' => 'blessing', 'label' => 'Blessing', 'bonus_dice' => '', 'die_mode' => 'advantage'])]]),
    ['blessing']
);
same('a boost can grant advantage', 'advantage', $granted['die_mode']);

$cancelled = CheckService::resolvePending(
    pending([
        'die_mode' => 'disadvantage',
        'boosts'   => [boost(['key' => 'blessing', 'label' => 'Blessing', 'bonus_dice' => '', 'die_mode' => 'advantage'])],
    ]),
    ['blessing']
);
same('granted advantage cancels disadvantage', 'normal', $cancelled['die_mode']);

$lucky = boost([
    'key'         => 'halfling_lucky',
    'label'       => 'Lucky',
    'bonus_dice'  => '',
    'reroll_ones' => true,
    'cost'        => 'free',
]);

$luckyRerolled = true;
$luckySpentOnlyWhenFired = true;
$luckyAddsNothing = true;
$fired = 0;
for ($i = 0; $i < 600; $i++) {
    $result = CheckService::resolvePending(pending(['boosts' => [$lucky]]), ['halfling_lucky']);
    $rerolled = count($result['rolls']) > 1;
    if ($rerolled) {
        $fired++;
        if ($result['rolls'][0] !== 1 || $result['natural'] !== end($result['rolls'])) {
            $luckyRerolled = false;
        }
    } elseif ($result['natural'] === 1) {
        $luckyRerolled = false;
    }
    if ($rerolled !== ($result['spent'] !== [])) {
        $luckySpentOnlyWhenFired = false;
    }
    if ($result['boost_rolls'] !== [] || $result['total'] !== $result['natural'] + $result['modifier']) {
        $luckyAddsNothing = false;
    }
}
ok('Lucky rerolls a natural 1 and keeps the new die', $luckyRerolled);
ok('Lucky is only spent when a 1 turns up', $luckySpentOnlyWhenFired);
ok('Lucky adds nothing to the total', $luckyAddsNothing);
ok('a 1 came up at least once in 600 rolls', $fired > 0, 'the reroll path was never exercised');

// ---------------------------------------------------------------------------
section('Rejecting a boost that was never offered');

threw(
    'an unoffered key is refused',
    static fn() => CheckService::resolvePending(pending(['boosts' => [boost()]]), ['bless'])
);
threw(
    'a boost is refused when none were offered',
    static fn() => CheckService::resolvePending(pending(), ['guidance'])
);
threw(
    'a boost invented by the client is refused',
    static fn() => CheckService::resolvePending(pending(['boosts' => [boost()]]), ['divine_intervention'])
);

// ---------------------------------------------------------------------------
section('The pending-check token');

$id = CheckService::stash(pending());
ok('the token is opaque hex', (bool) preg_match('/^[0-9a-f]{32}$/', $id));
$second = CheckService::stash(pending());
ok('two checks never share a token', $id !== $second);
CheckService::claim($second);

$claimed = CheckService::claim($id);
same('the claimed check is the one stored', 14, $claimed['dc']);
same('the token is written into what it names', $id, $claimed['check_id']);
threw('a token cannot be claimed twice', static fn() => CheckService::claim($id));
threw('an unknown token is refused', static fn() => CheckService::claim('deadbeef'));
threw('an empty token is refused', static fn() => CheckService::claim(''));

$stale = CheckService::stash(pending());
$_SESSION['pending_checks'][$stale]['created_at'] = time() - CheckService::TTL_SECONDS - 1;
threw('a token older than the TTL is refused', static fn() => CheckService::claim($stale));
ok('an expired token is dropped rather than left to retry', !isset($_SESSION['pending_checks'][$stale]));

$fresh = CheckService::stash(pending());
$_SESSION['pending_checks'][$fresh]['created_at'] = time() - CheckService::TTL_SECONDS + 5;
ok('a token just inside the TTL still works', CheckService::claim($fresh)['dc'] === 14);

same('nothing is left behind in the store', [], $_SESSION['pending_checks']);

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
