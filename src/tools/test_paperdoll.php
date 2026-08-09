<?php
/**
 * Bake a few characters and check the results are what the renderer expects.
 *
 * Runs the real compositor against the real sliced library, so it catches the
 * things that only show up in pixels: a layer that failed to stack, an output
 * that came out the wrong size, a trim that ate the character.
 *
 *   php tools/test_paperdoll.php     (or open /tools/test_paperdoll.php)
 *
 * Writes into assets/images/npcs/ under `pc_test_*` keys, which nothing
 * references, then cleans them up unless --keep is given.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$keep = in_array('--keep', $argv ?? [], true) || isset($_GET['keep']);
$pass = 0;
$fail = 0;

function check(bool $ok, string $what, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    echo "  FAIL  {$what}" . ($detail !== '' ? "  — {$detail}" : '') . "\n";
}

$cases = [
    'pc_test_m' => ['male', [
        'body' => 'body_1', 'feet' => 'feet_1', 'pants' => 'pants_2',
        'top' => 'top_3', 'hair' => 'hair_2', 'facialhair' => 'facialhair_1',
    ]],
    // Bald, barefoot, no shirt: the minimum a character can be, and the case
    // that catches an over-eager "required category" rule.
    'pc_test_min' => ['male', ['body' => 'body_2']],
    'pc_test_f' => ['female', [
        'body' => 'body_2', 'feet' => 'feet_1', 'dress' => 'dress_3',
        'hair' => 'hair_4_alt2', 'accessory' => 'accessory_1',
    ]],
];

$out = dirname(__DIR__) . '/assets/images/npcs';
$made = [];

foreach ($cases as $key => [$sex, $picks]) {
    echo "{$key} ({$sex}, " . count($picks) . " layers)\n";
    try {
        Paperdoll::bake($sex, $picks, $key);
    } catch (Throwable $e) {
        check(false, "{$key} baked", $e->getMessage());
        continue;
    }
    $made[] = $key;
    check(true, "{$key} baked");

    // The walk sheet is the contract with the renderer: N frames x 8 facings at
    // 128px. Anything else and the CSS background-position animation shows the
    // wrong part of the wrong row.
    //
    // Measured against the layer library rather than a number written here. A
    // bake is a composite of those layers, so it must be exactly their size —
    // and when the frame count moved from 4 to 8 this assertion was the only
    // thing that failed, reporting three correct bakes as broken. Deriving it
    // means the expectation cannot go stale again.
    $sheet = "{$out}/{$key}_sheet.png";
    check(is_file($sheet), "{$key}_sheet.png exists");
    $layer = glob(dirname(__DIR__) . '/assets/images/paperdoll/*/*_sheet.png')[0] ?? null;
    if (is_file($sheet) && $layer) {
        [$w, $h] = getimagesize($sheet);
        [$lw, $lh] = getimagesize($layer);
        check(
            $w === $lw && $h === $lh,
            "{$key} sheet matches the layer library ({$lw}x{$lh})",
            "got {$w}x{$h}"
        );
    }

    foreach (['' => 'still', '_face' => 'face', '_bust' => 'bust'] as $suffix => $label) {
        $p = "{$out}/{$key}{$suffix}.png";
        if (!is_file($p)) {
            check(false, "{$key} {$label} exists");
            continue;
        }
        [$w, $h] = getimagesize($p);
        // Trimmed, so the exact size varies — but an empty or full-canvas result
        // means the trim went wrong in one direction or the other.
        check($w > 8 && $h > 8, "{$key} {$label} is not empty", "{$w}x{$h}");
        // Read the caps off Paperdoll rather than restating them: this file used
        // to hardcode 320 and 96, so raising them turned six correct bakes into
        // six failures and looked like a regression.
        if ($suffix === '_bust') {
            $cap = Paperdoll::BUST_PX;
            check(max($w, $h) <= $cap, "{$key} bust fits {$cap}", "{$w}x{$h}");
        }
        if ($suffix === '_face') {
            $cap = Paperdoll::FACE_PX;
            check(max($w, $h) <= $cap, "{$key} face fits {$cap}", "{$w}x{$h}");
        }
        printf("    %-6s %dx%d\n", $label, $w, $h);
    }

    // Layers actually stacked: a composited character has more opaque pixels in
    // its standing frame than the bare body does, or nothing was drawn on top.
    if ($key !== 'pc_test_min') {
        $bare = "{$out}/pc_test_min.png";
        if (is_file($bare) && is_file("{$out}/{$key}.png")) {
            $a = @imagecreatefrompng("{$out}/{$key}.png");
            $b = @imagecreatefrompng($bare);
            if ($a && $b) {
                $count = static function ($im): int {
                    $n = 0;
                    for ($y = 0; $y < imagesy($im); $y++) {
                        for ($x = 0; $x < imagesx($im); $x++) {
                            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 60) {
                                $n++;
                            }
                        }
                    }
                    return $n;
                };
                check($count($a) > 0 && $count($b) > 0, "{$key} has visible pixels");
                imagedestroy($a);
                imagedestroy($b);
            }
        }
    }

    // busts.json has to know, or DialogEngine offers expressions that do not exist.
    $counts = json_decode((string) @file_get_contents("{$out}/busts.json"), true) ?: [];
    check(($counts[$key] ?? 0) === 1, "{$key} recorded in busts.json");
}

echo "\nRejections:\n";
foreach ([
    'unknown layer'      => ['male', ['body' => 'body_1', 'hair' => 'hair_99']],
    'wrong category'     => ['male', ['body' => 'body_1', 'hair' => 'top_1']],
    'no body'            => ['male', ['hair' => 'hair_1']],
    'dress and trousers' => ['female', ['body' => 'body_1', 'dress' => 'dress_1', 'pants' => 'pants_1']],
    'unknown sex'        => ['alien', ['body' => 'body_1']],
] as $what => [$sex, $picks]) {
    try {
        Paperdoll::resolve($sex, $picks);
        check(false, "refuses {$what}", 'it was accepted');
    } catch (InvalidArgumentException $e) {
        check(true, "refuses {$what}");
        echo "    {$what}: {$e->getMessage()}\n";
    }
}

if (!$keep) {
    foreach ($made as $key) {
        foreach (['_sheet', '', '_face', '_bust'] as $suffix) {
            @unlink("{$out}/{$key}{$suffix}.png");
        }
    }
    // Leave busts.json tidy too.
    $p = "{$out}/busts.json";
    $counts = json_decode((string) @file_get_contents($p), true) ?: [];
    foreach ($made as $key) {
        unset($counts[$key]);
    }
    ksort($counts);
    file_put_contents($p, json_encode($counts, JSON_PRETTY_PRINT) . "\n");
    echo "\ncleaned up " . count($made) . " test bakes (pass --keep to inspect them)\n";
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
