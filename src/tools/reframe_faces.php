<?php
/**
 * Re-cut player faces from their busts, through CharacterLook::FACE_WINDOW.
 *
 * Two kinds of player face were cut too tight to survive a small thumbnail:
 * the creator's looks (`lk_<id>_<hex>`), which copied the look library's
 * forehead-to-mouth crop, and the older chosen tokens (`token_*`), which were
 * cut the same way. In the 32px circles of the people list and the initiative
 * bar they showed hair and a brow. CharacterLook::bake now cuts the face from
 * the bust itself; this does the same for faces already on disk — the shipped
 * token art, and every look baked before the change, which live only on the
 * server that baked them.
 *
 * Only those two prefixes, and only where the bust is the 384x512 both are
 * drawn at: NPC faces were cut by tools/cut_face.py to their own framing and
 * are not this tool's business. Files only — no database.
 *
 *     php tools/reframe_faces.php            # report
 *     php tools/reframe_faces.php --apply    # rewrite the faces
 *     php tools/reframe_faces.php --dir=/some/npcs --apply
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/lib/CharacterLook.php';

$apply = in_array('--apply', $argv, true);
$dir = dirname(__DIR__) . '/assets/images/npcs';
foreach ($argv as $a) {
    if (str_starts_with($a, '--dir=')) {
        $dir = rtrim(substr($a, 6), '/');
    }
}

$done = $skipped = 0;
foreach (scandir($dir) ?: [] as $file) {
    if (!preg_match('/^((?:lk_[1-9][0-9]*_[0-9a-f]{6})|(?:token_[a-z0-9_]+?))_bust\.png$/', $file, $m)) {
        continue;
    }
    $key = $m[1];
    $bust = @imagecreatefrompng("{$dir}/{$file}");
    if (!$bust || imagesx($bust) !== 384 || imagesy($bust) !== 512) {
        printf("  SKIP  %-28s bust is not a 384x512 PNG\n", $key);
        $skipped++;
        continue;
    }
    if ($apply) {
        $face = CharacterLook::faceFromBust($bust);
        if (!imagepng($face, "{$dir}/{$key}_face.png", 9)) {
            printf("  FAIL  %-28s could not write the face\n", $key);
            $skipped++;
            continue;
        }
        imagedestroy($face);
    }
    imagedestroy($bust);
    printf("  %s  %s_face.png\n", $apply ? 'cut ' : 'would cut', $key);
    $done++;
}
printf("\n%d face(s) %s, %d skipped.%s\n", $done, $apply ? 'cut' : 'to cut', $skipped,
    $apply ? '' : ' Nothing written; pass --apply.');
