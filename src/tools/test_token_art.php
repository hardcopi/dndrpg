<?php
/**
 * TokenArt: distinct faces for copies of the same monster.
 *
 * Needs the imported pack (assets/images/tokens/index.json). Without it the
 * picker must return null rather than invent a path — a missing pack is a
 * fallback to `_face.png`, not a 404 in the fight.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/TokenArt.php';

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  $name\n";
    } else {
        $fail++;
        echo "  FAIL $name\n";
    }
}

echo "== TokenArt ==\n";

ok('an unknown monster has no token', TokenArt::pick('not_a_beast', 0, 1) === null);
ok('an empty key has no token', TokenArt::pick('', 0, 1) === null);

$idx = TokenArt::index();
if (!$idx) {
    echo "  (no tokens/index.json — pack not imported; skip the rest)\n";
    echo "$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

ok('goblins are in the pack', !empty($idx['goblin']));
$a = TokenArt::pick('goblin', 0, 100);
$b = TokenArt::pick('goblin', 1, 100);
$c = TokenArt::pick('goblin', 2, 100);
ok('three goblins in one fight get three paths', $a && $b && $c && count(array_unique([$a, $b, $c])) === 3);
ok('the path is under tokens/', is_string($a) && str_starts_with($a, 'tokens/goblin/'));
$root = dirname(__DIR__) . '/assets/images/';
ok('and the file is on disk', is_string($a) && is_file($root . $a));

ok('the same fight is stable', TokenArt::pick('goblin', 0, 100) === $a);
$starts = [];
foreach ([1, 2, 3, 5, 8, 13, 21, 34, 55, 89, 144, 233] as $s) {
    $starts[] = TokenArt::pick('goblin', 0, $s);
}
ok('different fights do not all start on the same goblin', count(array_unique($starts)) > 1);

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
