<?php
/**
 * CharacterLook: the rules that name a look, and the bake that files it.
 *
 *   php tools/test_character_look.php
 *
 * Needs no database. The bake runs against a FIXTURE library made here with
 * GD, in a temp folder, and writes into another temp folder — so it neither
 * depends on the look library being present (it is gitignored, and most
 * checkouts will not have it) nor leaves `lk_` files where the game serves
 * them.
 *
 * The keys are pinned twice over. Six are written out by hand, the same six
 * tools/test_bake_looks.py pins on the Python side, so the PHP and the bake
 * tool are checked against one list of strings rather than against each
 * other. And when a real library is present, every entry in its manifest is
 * round-tripped: its `choices` go through normalise() and key() and must come
 * back as the key it was filed under, and its files must be where paths()
 * says. A spelling rule restated wrongly here fails on the first entry that
 * exercises it.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

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

/** True when $fn throws InvalidArgumentException. */
function refuses(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage() !== '';
    }
    return false;
}

if (!function_exists('imagecreatefromwebp')) {
    echo "FAIL  this PHP's GD has no WebP support; CharacterLook cannot bake without it\n";
    exit(1);
}

$tmp = sys_get_temp_dir() . '/test_character_look_' . getmypid();
$lib = "{$tmp}/lib";
$out = "{$tmp}/out";
@mkdir($out, 0775, true);

function rm_tree(string $dir): void
{
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
        is_dir("{$dir}/{$f}") ? rm_tree("{$dir}/{$f}") : @unlink("{$dir}/{$f}");
    }
    @rmdir($dir);
}
register_shutdown_function(static fn () => rm_tree($tmp));

$look = new CharacterLook($lib, $out);

// ---------------------------------------------------------------- normalise
echo "normalise\n";

$n = $look->normalise(['gender' => 'feminine', 'hair_style' => 'topknot', 'hair_color' => 'purple'], 'Elf', 'Cleric');
check($n === [
    'gender' => 'feminine', 'race' => 'Elf', 'hair_style' => 'topknot', 'hair_color' => 'purple',
    'outfit' => 'plate', 'outfit_color' => 'steel',
], 'a Cleric with no outfit is in steel plate', json_encode($n));

$n = $look->normalise(['gender' => 'masculine', 'hair_style' => 'cropped short', 'hair_color' => 'black'], 'Human', 'Wizard');
check($n['outfit'] === 'robe' && $n['outfit_color'] === 'grey', 'a Wizard is in grey robes', json_encode($n));

$n = $look->normalise(['gender' => 'masculine', 'hair_style' => 'cropped', 'hair_color' => 'black'], 'Human', 'Monk');
check($n['outfit'] === 'tunic' && $n['outfit_color'] === null, 'a Monk is in the tunic', json_encode($n));
check($n['hair_style'] === 'cropped short', 'a style key is stored as its label', (string) $n['hair_style']);

$n = $look->normalise(['gender' => 'masculine', 'hair_style' => 'cropped', 'hair_color' => 'black'], 'Human', null);
check($n['outfit'] === 'tunic', 'no class means the tunic');

$n = $look->normalise(['gender' => 'feminine', 'hair_style' => 'braids', 'hair_color' => 'dark_brown',
    'outfit' => 'leather', 'outfit_color' => 'black'], 'Dwarf', 'Cleric');
check($n['outfit'] === 'leather' && $n['outfit_color'] === 'black', 'an outfit chosen beats the class default');
check($n['hair_color'] === 'dark brown', 'a slugged colour comes back with its space', (string) $n['hair_color']);

$n = $look->normalise(['gender' => 'feminine', 'hair_style' => 'cropped', 'hair_color' => 'red',
    'outfit' => 'tunic', 'outfit_color' => 'default'], 'Gnome');
check($n['outfit_color'] === null, 'the tunic has no colour, whatever was sent');

$n = $look->normalise(['gender' => 'feminine', 'hair_style' => 'bald or shaven', 'hair_color' => 'red'], 'Half-Orc');
check($n['hair_style'] === 'bald or shaven' && $n['hair_color'] === null, 'bald forces the hair colour off');

$n = $look->normalise(['gender' => 'feminine', 'hair_style' => 'hood'], 'Human');
check($n['hair_style'] === 'hidden under a hood' && $n['hair_color'] === null, 'a hood needs no colour and has none');

$n = $look->normalise(['gender' => 'masculine', 'hair_style' => 'topknot', 'hair_color' => 'red'], 'Dragonborn', 'Fighter');
check($n['hair_style'] === null && $n['hair_color'] === null, 'a Dragonborn has no hair, whatever was sent', json_encode($n));

$n = $look->normalise(['gender' => 'feminine', 'race' => 'Elf', 'hair_style' => 'cropped', 'hair_color' => 'red'], 'Dwarf');
check($n['race'] === 'Dwarf', 'race comes from the character, not the picks', $n['race']);

$n = $look->normalise(['gender' => 'Feminine', 'hair_style' => 'cropped', 'hair_color' => 'red'], 'elf');
check($n['gender'] === 'feminine' && $n['race'] === 'Elf', 'gender and race are matched without case');

$base = ['gender' => 'feminine', 'hair_style' => 'cropped', 'hair_color' => 'red'];
check(refuses(fn () => $look->normalise(['hair_style' => 'cropped', 'hair_color' => 'red'], 'Elf')), 'no gender is refused');
check(refuses(fn () => $look->normalise(['gender' => 'neither'] + $base, 'Elf')), 'an unknown gender is refused');
check(refuses(fn () => $look->normalise(['hair_style' => 'mohawk'] + $base, 'Elf')), 'an unknown hair style is refused');
check(refuses(fn () => $look->normalise(['hair_style' => ''] + $base, 'Elf')), 'no hair style is refused');
check(refuses(fn () => $look->normalise(['hair_color' => 'green'] + $base, 'Elf')), 'an unknown hair colour is refused');
check(refuses(fn () => $look->normalise(['hair_color' => ''] + $base, 'Elf')), 'a coloured style with no colour is refused');
check(refuses(fn () => $look->normalise(['outfit' => 'chainmail'] + $base, 'Elf')), 'an unknown outfit is refused');
check(refuses(fn () => $look->normalise(['outfit' => 'plate', 'outfit_color' => 'pink'] + $base, 'Elf')), 'plate in pink is refused');
check(refuses(fn () => $look->normalise(['outfit' => 'plate', 'outfit_color' => 'blue'] + $base, 'Elf')), "robes' blue on plate is refused");
check(refuses(fn () => $look->normalise($base, 'Orc')), 'a race with no looks is refused in words');

// --------------------------------------------------------------------- keys
echo "key\n";

// The same six strings tools/test_bake_looks.py pins, written out by hand.
$keys = [
    [['gender' => 'feminine', 'hair_style' => 'bald or shaven'], 'Dwarf', 'tunic', null,
        'p2:look:feminine_Dwarf/bald'],
    [['gender' => 'masculine', 'hair_style' => 'wild and shaggy', 'hair_color' => 'black'], 'Human', 'plate', 'blackened',
        'p2:look:masculine_Human/shaggy__black|plate__blackened'],
    [['gender' => 'feminine', 'hair_style' => 'long and loose', 'hair_color' => 'dark brown'], 'Elf', 'leather', 'grey-brown',
        'p2:look:feminine_Elf/long_loose__dark_brown|leather__grey-brown'],
    [['gender' => 'masculine'], 'Dragonborn', 'tunic', null,
        'p2:base:masculine_Dragonborn'],
    [['gender' => 'feminine'], 'Dragonborn', 'robe', 'crimson',
        'p2:base:feminine_Dragonborn|robe__crimson'],
    [['gender' => 'masculine', 'hair_style' => 'hidden under a hood'], 'Tiefling', 'robe', 'green',
        'p2:look:masculine_Tiefling/hood|robe__green'],
    [['gender' => 'feminine', 'hair_style' => 'topknot', 'hair_color' => 'purple'], 'Elf', 'plate', 'bronze',
        'p2:look:feminine_Elf/topknot__purple|plate__bronze'],
];
foreach ($keys as [$picks, $race, $outfit, $colour, $want]) {
    $n = $look->normalise($picks + ['outfit' => $outfit, 'outfit_color' => $colour], $race);
    $got = $look->key($n);
    check($got === $want, "key {$want}", $got);
}

$n = $look->normalise(['gender' => 'feminine', 'hair_style' => 'long and loose', 'hair_color' => 'dark brown',
    'outfit' => 'leather', 'outfit_color' => 'grey-brown'], 'Elf');
check($look->paths($n) === [
    'full' => 'feminine_Elf/long_loose__dark_brown/leather__grey-brown.full.webp',
    'bust' => 'feminine_Elf/long_loose__dark_brown/leather__grey-brown.bust.webp',
    'fig'  => 'feminine_Elf/long_loose__dark_brown/leather__grey-brown.fig.webp',
    'face' => 'feminine_Elf/long_loose__dark_brown/leather__grey-brown.face.webp',
], 'paths follow path_template', json_encode($look->paths($n)));
$n = $look->normalise(['gender' => 'masculine', 'outfit' => 'tunic'], 'Dragonborn');
check($look->paths($n)['fig'] === 'masculine_Dragonborn/base/tunic.fig.webp', 'a hairless race is filed under base');

// The real library, when this checkout has one.
$real = dirname(__DIR__) . '/assets/looks/v1';
if (is_file("{$real}/manifest.json")) {
    $man = json_decode((string) file_get_contents("{$real}/manifest.json"), true);
    $shipped = new CharacterLook($real, $out);
    $entries = $man['entries'] ?? [];
    $bad = [];
    foreach ($entries as $e) {
        $c = $e['choices'];
        try {
            $n = $shipped->normalise($c, $c['race']);
            if ($shipped->key($n) !== $e['key']) {
                $bad[] = "{$e['key']} -> " . $shipped->key($n);
            } elseif ($shipped->paths($n) !== $e['files'] || !$shipped->exists($n)) {
                $bad[] = "{$e['key']}: paths disagree with the manifest";
            }
        } catch (Throwable $ex) {
            $bad[] = "{$e['key']}: " . $ex->getMessage();
        }
    }
    check($bad === [], count($entries) . ' manifest entries round-trip choices -> key -> files',
        implode('; ', array_slice($bad, 0, 5)));
    echo '  (' . count($entries) . " entries in the shipped library)\n";
} else {
    echo "  (no look library in this checkout; manifest round-trip skipped)\n";
}

// --------------------------------------------------------------------- bake
echo "bake\n";

/** One fixture picture, a flat colour with a transparent corner on the figure. */
function fixture(string $path, int $w, int $h, array $rgb, bool $alpha): void
{
    @mkdir(dirname($path), 0775, true);
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, ...[...$rgb, 0]));
    if ($alpha) {
        imagefilledrectangle($im, 0, 0, 20, 20, imagecolorallocatealpha($im, 0, 0, 0, 127));
    }
    imagewebp($im, $path, 101);   // 101 = lossless, so the alpha survives exactly
    imagedestroy($im);
}

$looks = [
    'a' => $look->normalise(['gender' => 'feminine', 'hair_style' => 'topknot', 'hair_color' => 'purple'], 'Elf', 'Cleric'),
    'b' => $look->normalise(['gender' => 'feminine', 'hair_style' => 'bald'], 'Elf', 'Wizard'),
];
foreach ($looks as $i => $n) {
    $rgb = $i === 'a' ? [200, 40, 40] : [40, 40, 200];
    foreach ($look->paths($n) as $kind => $rel) {
        [$w, $h] = ['full' => [384, 1024], 'bust' => [384, 512], 'fig' => [192, 320], 'face' => [144, 144]][$kind];
        fixture("{$lib}/{$rel}", $w, $h, $rgb, $kind === 'fig');
    }
}
check($look->exists($looks['a']), 'exists() finds all four fixture pictures');
$missing = $look->normalise(['gender' => 'masculine', 'hair_style' => 'topknot', 'hair_color' => 'red'], 'Gnome');
check(!$look->exists($missing), 'exists() is false for a look the library lacks');
check(refuses(fn () => $look->bake(1, $missing, 'cleric')), 'baking a look the library lacks is refused in words');

// The class sprite a look inherits for the map, and a stranger's look that a
// careless glob for character 1 would delete.
fixture("{$tmp}/x.webp", 4, 4, [1, 2, 3], false);
foreach (['cleric.png', 'cleric_sheet.png'] as $f) {
    imagepng(imagecreatefromwebp("{$tmp}/x.webp"), "{$out}/{$f}");
}
$strangers = ['lk_12_abcdef_bust.png', 'lk_12_abcdef_face.png', 'lk_12_abcdef_fig.png', 'lk_12_abcdef.png'];
foreach ($strangers as $f) {
    copy("{$out}/cleric.png", "{$out}/{$f}");
}
$bustsBefore = json_encode(['cleric' => 8]);
file_put_contents("{$out}/busts.json", $bustsBefore);

$keyA = $look->bake(1, $looks['a'], 'cleric');
check($keyA === CharacterLook::keyFor(1, 'p2:look:feminine_Elf/topknot__purple|plate__steel'), 'bake returns the key for this id and look', $keyA);
check((bool) preg_match('/^lk_1_[0-9a-f]{6}$/', $keyA), 'the key is lk_<id>_<6 hex>', $keyA);
check(CharacterLook::ownerOf($keyA) === 1, 'ownerOf reads the id back out');
check(CharacterLook::ownerOf('lk_1_abc') === null && CharacterLook::ownerOf('pc_1') === null
    && CharacterLook::ownerOf('lk_01_abcdef') === null && CharacterLook::ownerOf('lk_1_ABCDEF') === null,
    'ownerOf refuses anything not shaped exactly like keyFor()');

foreach (['bust' => [384, 512], 'face' => [144, 144], 'fig' => [192, 320]] as $kind => [$w, $h]) {
    $p = "{$out}/{$keyA}_{$kind}.png";
    $size = is_file($p) ? getimagesize($p) : false;
    check($size !== false && $size[2] === IMAGETYPE_PNG && $size[0] === $w && $size[1] === $h,
        "{$kind} is a {$w}x{$h} PNG", $size ? "{$size[0]}x{$size[1]} type {$size[2]}" : 'missing');
}
$fig = @imagecreatefrompng("{$out}/{$keyA}_fig.png");
check($fig !== false
    && ((imagecolorat($fig, 5, 5) >> 24) & 0x7F) === 127
    && ((imagecolorat($fig, 100, 200) >> 24) & 0x7F) === 0,
    'the figure keeps its alpha: clear corner, opaque body');
check(is_file("{$out}/{$keyA}.png") && is_file("{$out}/{$keyA}_sheet.png"), 'the map sprite is inherited from the class');
check(file_get_contents("{$out}/busts.json") === $bustsBefore, 'busts.json is not touched by a bake');

// Re-dress: a new key, the old files gone, the stranger's untouched.
$keyB = $look->bake(1, $looks['b'], 'cleric');
check($keyB !== $keyA && CharacterLook::ownerOf($keyB) === 1, 're-dressing gives a new key', "{$keyA} -> {$keyB}");
$left = array_values(array_filter(scandir($out), fn ($f) => str_starts_with($f, "{$keyA}")));
check($left === [], 'the previous look\'s files are deleted', implode(', ', $left));
foreach ($strangers as $f) {
    check(is_file("{$out}/{$f}"), "character 12's {$f} survives character 1 re-dressing");
}
check(file_get_contents("{$out}/busts.json") === $bustsBefore, 'busts.json is not touched by a re-dress either');
check(is_file("{$out}/{$keyB}_fig.png") && is_file("{$out}/{$keyB}_sheet.png"), 'the new look is complete');

// Same look again: same key, still all there (delete-then-write of one name).
check($look->bake(1, $looks['b'], 'cleric') === $keyB && is_file("{$out}/{$keyB}_bust.png"),
    'baking the same look twice keeps the same key and its files');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
