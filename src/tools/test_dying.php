<?php
/**
 * Death saving throws, and the ways a character can skip them.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_dying.php`.
 *
 * Before this, a player character at zero gained Unconscious and stayed there:
 * `alive => false` was unreachable for anything but a monster, there were no
 * saves, no stabilising and no instant death. Falling had exactly one
 * consequence — out of the fight until somebody healed you — and it cost
 * nothing to leave them lying there.
 *
 * The die is forced where a rule turns on a particular number. A death save is
 * a flat d20 with no modifier, so a natural 20 and a natural 1 are the two
 * cases most worth asserting and the two least likely to turn up on their own.
 *
 * Touches no rows: every character here has `character_id` 0, which no row has.
 */
declare(strict_types=1);
require_once '/var/www/html/app/bootstrap.php';

$engine = new CombatEngine(db());
$call = function (string $name, array ...$args) use ($engine) {
    $m = new ReflectionMethod(CombatEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke($engine, ...$args);
};
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

/** One character, upright or otherwise, and one monster to hit them with. */
function board(array $pc = []): array
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
                'class' => 'Fighter', 'level' => 3, 'x' => 4, 'y' => 5,
                'str' => 14, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 10, 'cha' => 10,
                'prof' => 2, 'speed' => 30, 'move_left' => 30,
                'hp' => 20, 'max_hp' => 20, 'ac' => 15, 'alive' => true,
                'conditions' => [], 'boons' => [], 'feats' => [], 'concentrating' => null,
                'stable' => false, 'death_saves' => ['success' => 0, 'fail' => 0],
            ], $pc),
            ['cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
             'x' => 5, 'y' => 5, 'hp' => 11, 'max_hp' => 11, 'ac' => 12, 'alive' => true,
             'conditions' => [], 'boons' => [], 'concentrating' => null],
        ],
    ];
}

/** Down and dying: at zero, unconscious, not yet stable. */
function dying(array $extra = []): array
{
    return board(array_merge([
        'hp' => 0,
        'conditions' => [['key' => 'unconscious']],
    ], $extra));
}

$who = fn(array $s) => $s['combatants'][0];

echo "== who is dying ==\n";
ok('somebody at zero and unconscious is', CombatEngine::isDying($who(dying())));
ok('somebody on their feet is not', !CombatEngine::isDying($who(board())));
ok('somebody stable is not', !CombatEngine::isDying($who(dying(['stable' => true]))));
ok('somebody already dead is not', !CombatEngine::isDying($who(dying(['alive' => false]))));
ok('and a monster never is',
    !CombatEngine::isDying(['type' => 'monster', 'alive' => true, 'hp' => 0,
                            'conditions' => [['key' => 'unconscious']]]));

echo "\n== falling over ==\n";
$hit = $invoke('takeDamage', board(), 'pc_1', 20);
$down = $who($hit);
ok('twenty damage to twenty hit points puts them down', (int) $down['hp'] === 0);
ok('and leaves them alive', !empty($down['alive']));
ok('and unconscious', Conditions::has($down, 'unconscious'));
same('with a clean slate', ['success' => 0, 'fail' => 0], $down['death_saves']);

// Instant death: leftover damage at least equal to the hit point maximum.
$obliterated = $who($invoke('takeDamage', board(), 'pc_1', 40));
ok('forty damage to a twenty hit point maximum kills outright', empty($obliterated['alive']));
$survives = $who($invoke('takeDamage', board(), 'pc_1', 39));
ok('thirty-nine does not — one short of the maximum in leftover', !empty($survives['alive']));
// The leftover is what counts, not the blow. Already at 1 hp, a 20 point hit
// leaves 19 over and that is short of the maximum.
$atOne = $who($invoke('takeDamage', board(['hp' => 1]), 'pc_1', 20));
ok('it is the leftover that kills, not the total', !empty($atOne['alive']));

echo "\n== the saves ==\n";
// A flat d20 has no modifier to fix, so the outcome is sampled instead: over
// many rolls all three outcomes must occur and nothing else may.
$outcomes = [];
for ($i = 0; $i < 200; $i++) {
    $after = $who($invoke('deathSave', dying(), 'pc_1'));
    $s = (int) $after['death_saves']['success'];
    $f = (int) $after['death_saves']['fail'];
    if (!empty($after['alive']) && (int) $after['hp'] === 1) {
        $outcomes['revive'] = true;          // natural 20
    } elseif ($f === 2 && $s === 0) {
        $outcomes['double'] = true;          // natural 1
    } elseif ($s === 1) {
        $outcomes['success'] = true;
    } elseif ($f === 1) {
        $outcomes['fail'] = true;
    } else {
        $outcomes['??'] = true;
    }
}
ok('a save can be held', isset($outcomes['success']));
ok('a save can be missed', isset($outcomes['fail']));
ok('a natural 20 puts them back up at one hit point', isset($outcomes['revive']));
ok('a natural 1 costs two', isset($outcomes['double']));
ok('and nothing else happens', !isset($outcomes['??']));

// Three of either settles it. Sampled rather than asserted once: from two
// failures a *successful* save changes nothing about being alive, so a single
// roll proves nothing either way. What must never happen is a third failure
// that leaves somebody breathing.
$breathingOnThree = 0;
$died = 0;
for ($i = 0; $i < 200; $i++) {
    $after = $who($invoke('deathSave', dying(['death_saves' => ['success' => 0, 'fail' => 2]]), 'pc_1'));
    if ((int) $after['death_saves']['fail'] >= 3 && !empty($after['alive'])) {
        $breathingOnThree++;
    }
    if (empty($after['alive'])) {
        $died++;
    }
}
same('a third failure is never survived', 0, $breathingOnThree);
ok('and from two failures, most rolls are fatal', $died > 60, "died {$died} of 200");

// Sampled for the same reason as the failures above, and it caught a real
// hole in the first version of this test: from two successes a *missed* save
// leaves somebody at two-and-one, neither stable nor dead, so asserting on one
// roll passed or failed depending on the die.
$notStableOnThree = 0;
$stabilised = 0;
for ($i = 0; $i < 200; $i++) {
    $after = $who($invoke('deathSave', dying(['death_saves' => ['success' => 2, 'fail' => 0]]), 'pc_1'));
    if ((int) $after['death_saves']['success'] >= 3 && empty($after['stable'])) {
        $notStableOnThree++;
    }
    if (!empty($after['stable'])) {
        $stabilised++;
    }
}
same('a third success always stabilises', 0, $notStableOnThree);
ok('and from two successes, about half of rolls do', $stabilised > 60, "stabilised {$stabilised} of 200");

echo "\n== struck while down ==\n";
$struck = $who($invoke('takeDamage', dying(), 'pc_1', 7));
same('a blow on somebody down is one failure', 1, (int) $struck['death_saves']['fail']);
same('and takes no hit points, because there are none', 0, (int) $struck['hp']);
$critted = $who($invoke('takeDamage', dying(), 'pc_1', 7, true));
same('a critical is two', 2, (int) $critted['death_saves']['fail']);
$finished = $who($invoke('takeDamage', dying(['death_saves' => ['success' => 1, 'fail' => 2]]), 'pc_1', 3));
ok('and a third finishes them', empty($finished['alive']));

echo "\n== getting back up ==\n";
$healed = $who($invoke('heal', dying(['death_saves' => ['success' => 1, 'fail' => 2]]), 'pc_1', 5));
same('healing puts hit points back', 5, (int) $healed['hp']);
ok('and stands them up', !Conditions::has($healed, 'unconscious'));
same('and wipes the slate — failures do not follow you', ['success' => 0, 'fail' => 0],
    $healed['death_saves']);

$stableThenHit = $who($invoke('takeDamage', dying(['stable' => true]), 'pc_1', 4));
ok('a stable character struck again is dying once more',
    empty($stableThenHit['stable']) || (int) $stableThenHit['death_saves']['fail'] > 0);

echo "\n== the turn ==\n";
// beginTurn is where the save is taken. A dying character's turn is the save
// and nothing else, and used to be skipped in silence as "Unconscious".
$turn = $invoke('beginTurn', dying());
$afterTurn = $who($turn);
$rolled = ($afterTurn['death_saves']['success'] ?? 0) + ($afterTurn['death_saves']['fail'] ?? 0);
ok('a dying character rolls at the start of their turn',
    $rolled > 0 || (int) $afterTurn['hp'] === 1 || empty($afterTurn['alive']));
ok('and an event is emitted for the board',
    count(array_filter($turn['events'] ?? [], fn($e) => $e['type'] === 'death_save')) > 0);

$stableTurn = $invoke('beginTurn', dying(['stable' => true]));
same('a stable character rolls nothing', 0,
    count(array_filter($stableTurn['events'] ?? [], fn($e) => $e['type'] === 'death_save')));

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
