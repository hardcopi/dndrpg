<?php
/**
 * Re-composite every created character's art from the choices already on record.
 *
 * `characters.appearance_json` exists precisely so a character's look survives a
 * change to the art beneath it: it stores the layer keys the player picked, not
 * the pixels. When the paperdoll library is re-cut — as it was when the bust cap
 * moved from 320 to 512 and the face cap from 96 to 144 — the baked `pc_<id>`
 * sprites are the one thing that does not follow, because they were flattened at
 * the old sizes. This re-flattens them.
 *
 * Without it a created character keeps a 320px bust while every authored NPC
 * moves to 512, which shows up as one blurry portrait in an otherwise sharp
 * conversation.
 *
 * A character with no `appearance_json` was never built from layers (the class
 * premades use authored art) and is skipped rather than guessed at.
 *
 * Reports and changes nothing unless asked:
 *
 *     /tools/rebake_appearances.php              # what would be re-baked
 *     /tools/rebake_appearances.php?apply=1      # do it
 *
 * or, since the php container has a CLI:
 *
 *     docker compose exec -T php php /var/www/html/tools/rebake_appearances.php --apply
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$argvFlags = [];
foreach (($_SERVER['argv'] ?? []) as $a) {
    $argvFlags[ltrim($a, '-')] = true;
}
$want = static fn (string $flag): bool =>
    !empty($_GET[$flag]) || isset($argvFlags[$flag]);

$apply = $want('apply');
$db = db();

$rows = $db->query(
    'SELECT id, name, sprite_key, appearance_json
       FROM characters
      WHERE appearance_json IS NOT NULL
      ORDER BY id'
)->fetchAll();

printf("%d character(s) carry a stored appearance.\n\n", count($rows));

$done = $failed = 0;
foreach ($rows as $r) {
    $id = (int) $r['id'];
    $picks = json_decode((string) $r['appearance_json'], true);
    if (!is_array($picks)) {
        printf("  %-4d %-22s SKIP  appearance_json is not an object\n", $id, $r['name']);
        $failed++;
        continue;
    }

    // The API stores it as ['sex' => ...] + layer picks, so the sex comes back
    // out of the same array it went in with.
    $sex = (string) ($picks['sex'] ?? 'male');
    unset($picks['sex']);

    $expect = Paperdoll::keyFor($id);
    if (!$apply) {
        printf("  %-4d %-22s would re-bake %-10s (%s, %d layers)\n",
            $id, $r['name'], $expect, $sex, count($picks));
        continue;
    }

    try {
        $key = Paperdoll::bake($sex, $picks, $expect);
        // sprite_key is rewritten too: it should already be this, but a row that
        // drifted is exactly the sort of thing this is here to put right.
        if ($key !== $r['sprite_key']) {
            $db->prepare('UPDATE characters SET sprite_key = ? WHERE id = ?')
                ->execute([$key, $id]);
            printf("  %-4d %-22s re-baked %s (sprite_key was %s)\n",
                $id, $r['name'], $key, $r['sprite_key']);
        } else {
            printf("  %-4d %-22s re-baked %s\n", $id, $r['name'], $key);
        }
        $done++;
    } catch (Throwable $e) {
        // A mixed-scale layer library throws from Paperdoll::stack(). That is the
        // failure this whole exercise is guarding against, so it is reported
        // loudly rather than swallowed.
        printf("  %-4d %-22s FAILED  %s\n", $id, $r['name'], $e->getMessage());
        $failed++;
    }
}

echo "\n";
if (!$apply) {
    echo "Nothing was written. Add ?apply=1 (or --apply) to re-bake.\n";
} else {
    printf("%d re-baked, %d failed.\n", $done, $failed);
    if ($done) {
        echo "Hard-reload the game (Ctrl+Shift+R) to pick the new art up.\n";
    }
}
