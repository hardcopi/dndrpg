<?php
/**
 * Can this PHP composite paperdoll layers?
 *
 * The character creator stacks a base body and up to eight clothing layers into
 * one sprite sheet at 1024x512, and does it server-side so the result is a file
 * the renderer already knows how to draw. That needs alpha-correct compositing
 * and PNG output. GD can do it; Imagick does it better; neither being present
 * means the bake has to be a Python helper instead, which is a different shape
 * of solution and worth knowing before the UI is built.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

printf("php:      %s\n", PHP_VERSION);
printf("gd:       %s\n", extension_loaded('gd') ? 'yes' : 'NO');
printf("imagick:  %s\n", extension_loaded('imagick') ? 'yes' : 'NO');
printf("memory:   %s\n\n", ini_get('memory_limit'));

if (!extension_loaded('gd')) {
    echo "Without GD the bake cannot happen in PHP.\n";
    exit(1);
}

$info = gd_info();
foreach (['PNG Support', 'FreeType Support', 'JPEG Support'] as $k) {
    printf("  %-18s %s\n", $k, !empty($info[$k]) ? 'yes' : 'NO');
}

// The actual operation the creator depends on: stack two transparent PNGs and
// keep the alpha. GD's default is to flatten, which would give every character
// a black box where the layer's transparency was.
$base = imagecreatetruecolor(1024, 512);
imagesavealpha($base, true);
imagealphablending($base, false);
imagefill($base, 0, 0, imagecolorallocatealpha($base, 0, 0, 0, 127));
imagealphablending($base, true);

$layer = imagecreatetruecolor(1024, 512);
imagesavealpha($layer, true);
imagealphablending($layer, false);
imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 200, 40, 40, 40));
imagealphablending($layer, false);

$ok = imagecopy($base, $layer, 0, 0, 0, 0, 1024, 512);

// Read a pixel back: it should be the semi-transparent red, not opaque black.
$rgba = imagecolorat($base, 10, 10);
$alpha = ($rgba >> 24) & 0x7F;
$red = ($rgba >> 16) & 0xFF;

printf("\n  %-18s %s\n", 'alpha composite', $ok ? 'yes' : 'NO');
printf("  %-18s alpha=%d red=%d %s\n", 'transparency kept', $alpha, $red,
    ($alpha > 0 && $alpha < 127 && $red > 150) ? '(correct)' : '(WRONG — layers would flatten)');

$mem = memory_get_peak_usage(true);
printf("  %-18s %.1f MB for two 1024x512 layers\n", 'peak memory', $mem / 1048576);
printf("  %-18s ~%.0f MB for a 9-layer bake\n", 'estimated', ($mem / 1048576) * 4.5);

imagedestroy($base);
imagedestroy($layer);
echo "\nGD can do the bake.\n";
