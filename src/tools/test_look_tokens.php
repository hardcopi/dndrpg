<?php
/**
 * Painted look tokens: every race on offer has a bust on disk.
 *
 * Installed at src/tools/test_look_tokens.php. dirname(__DIR__) is src/.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$pass = 0;
$fail = 0;

function ok(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "ok   $msg\n";
    } else {
        $fail++;
        echo "FAIL $msg\n";
    }
}

function same($got, $want, string $msg): void
{
    ok($got === $want, $msg);
}

same(RACES_WITHHELD, [], 'RACES_WITHHELD is empty');

$tokensPath = $root . '/assets/images/npcs/tokens.json';
ok(is_file($tokensPath), 'tokens.json exists');

$raw = is_file($tokensPath) ? file_get_contents($tokensPath) : false;
$tokens = is_string($raw) ? json_decode($raw, true) : null;
ok(is_array($tokens), 'tokens.json decodes to an object');

$expected = [
    'Dragonborn', 'Tiefling', 'Human', 'Elf', 'Dwarf',
    'Gnome', 'Halfling', 'Half-Elf', 'Half-Orc', 'Sarsen',
];
foreach ($expected as $race) {
    ok(isset($tokens[$race]), $race . ' is present');
}

ok(isset($tokens['Dragonborn']), 'Dragonborn is present');
ok(isset($tokens['Tiefling']), 'Tiefling is present');

if (is_array($tokens)) {
    foreach ($tokens as $race => $rows) {
        ok(is_array($rows) && $rows !== [], $race . ' has at least one token');
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $t) {
            $key = is_array($t) ? (string) ($t['key'] ?? '') : '';
            ok($key !== '', $race . ' token has a key');
            if ($key === '') {
                continue;
            }
            $bust = $root . '/assets/images/npcs/' . $key . '_bust.png';
            $face = $root . '/assets/images/npcs/' . $key . '_face.png';
            ok(is_file($bust), $key . '_bust.png exists');
            ok(is_file($face), $key . '_face.png exists');
        }
    }
}

echo ($fail ? 'FAIL' : 'PASS') . "  $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
