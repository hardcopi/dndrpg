<?php
/**
 * Dice: the seam tests use to make rolls repeatable. No database.
 *   php tools/test_dice.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/lib/Dice.php';

$pass = 0;
$fail = 0;
function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $n\n"; } else { $fail++; echo "  FAIL $n" . ($d ? " — $d" : '') . "\n"; }
}

echo "== random by default ==\n";
Dice::useGenerator(null);
$seen = [];
for ($i = 0; $i < 400; $i++) { $seen[Dice::d20()['natural']] = true; }
ok('every face of a d20 turns up in 400 rolls', count($seen) === 20, count($seen) . ' faces');

echo "\n== seeded ==\n";
Dice::seed(1234);
$a = [Dice::roll('4d6+2'), Dice::d20Mode(3, 'advantage'), Dice::abilityScore()];
Dice::seed(1234);
$b = [Dice::roll('4d6+2'), Dice::d20Mode(3, 'advantage'), Dice::abilityScore()];
ok('the same seed gives the same rolls', $a === $b);
Dice::seed(99);
$c = [Dice::roll('4d6+2'), Dice::d20Mode(3, 'advantage'), Dice::abilityScore()];
ok('a different seed does not', $a !== $c);
$r = Dice::roll('3d8');
ok('seeded rolls stay in range', min($r['rolls']) >= 1 && max($r['rolls']) <= 8);
Dice::useGenerator(null);

echo "\n== scripted ==\n";
Dice::script([20, 1]);
$hi = Dice::d20(5);
$lo = Dice::d20(5);
ok('a scripted 20 is a crit', $hi['natural'] === 20 && $hi['crit'] && $hi['total'] === 25);
ok('a scripted 1 is a fumble', $lo['natural'] === 1 && $lo['fumble']);
$after = [];
for ($i = 0; $i < 200; $i++) { $after[Dice::d20()['natural']] = true; }
ok('and the script runs out back into random', count($after) > 10, count($after) . ' faces');
Dice::script([7]);
try {
    Dice::roll('1d6');
    ok('a scripted value too big for the die is refused', false, 'nothing thrown');
} catch (LogicException $e) {
    ok('a scripted value too big for the die is refused', true);
}
Dice::useGenerator(null);

echo str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $pass, $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
