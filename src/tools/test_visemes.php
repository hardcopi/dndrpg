<?php
/**
 * The mouth tracks agree with the audio they were derived from.
 *
 * `tools/gen_visemes.py` reads every clip `gen_voiceover.py` recorded and
 * writes a mouth track beside it. The two files are joined by clip filename and
 * nothing enforces that join at run time — `Voiceover::clips()` treats a missing
 * track exactly as it treats a missing recording, silently, because a line
 * added since the last generation must not break a conversation.
 *
 * That silence is right for a player and wrong for a build. This is the thing
 * that notices: every clip a manifest points at should have a track, every
 * track should decode to the number of frames it claims, and no track should
 * outlive the clip it describes.
 *
 *   docker compose exec -T php php /var/www/html/tools/test_visemes.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dir = $root . '/assets/audio/vo';

if (!is_dir($dir)) {
    fwrite(STDERR, "no voiceover directory at $dir\n");
    exit(1);
}

$failures = [];
$npcs = 0;
$clips = 0;
$tracked = 0;
$untracked = [];
$frames = 0;

foreach (glob($dir . '/*/lines.json') as $manifest) {
    $npcKey = basename(dirname($manifest));
    $npcs++;

    $lines = json_decode((string) file_get_contents($manifest), true);
    if (!is_array($lines)) {
        $failures[] = "$npcKey: lines.json does not decode";
        continue;
    }

    $mouthPath = dirname($manifest) . '/visemes.json';
    $mouths = is_file($mouthPath)
        ? json_decode((string) file_get_contents($mouthPath), true)
        : [];
    if (!is_array($mouths)) {
        $failures[] = "$npcKey: visemes.json does not decode";
        $mouths = [];
    }

    $referenced = [];
    foreach ($lines as $hash => $cut) {
        if (!is_array($cut)) {
            $failures[] = "$npcKey: $hash is not a list of clips";
            continue;
        }
        foreach ($cut as $clip) {
            $src = (string) ($clip['src'] ?? '');
            if ($src === '') {
                $failures[] = "$npcKey: $hash has a clip with no src";
                continue;
            }
            $referenced[$src] = true;
        }
    }

    foreach (array_keys($referenced) as $src) {
        $clips++;
        $track = $mouths[$src] ?? null;
        if (!is_array($track)) {
            $untracked[] = "$npcKey/$src";
            continue;
        }
        $tracked++;

        $fps = (int) ($track['fps'] ?? 0);
        $claimed = (int) ($track['frames'] ?? 0);
        if ($fps <= 0 || $fps > 120) {
            $failures[] = "$npcKey/$src: fps of $fps is not a frame rate";
        }

        // The two channels are one byte per frame each. A length that
        // disagrees with `frames` means the packer and the writer parted ways,
        // and the client would read a jaw off the end of the array.
        foreach (['open', 'shape'] as $channel) {
            $packed = (string) ($track[$channel] ?? '');
            if ($packed === '') {
                $failures[] = "$npcKey/$src: no $channel channel";
                continue;
            }
            $bytes = base64_decode($packed, true);
            if ($bytes === false) {
                $failures[] = "$npcKey/$src: $channel is not base64";
                continue;
            }
            if (strlen($bytes) !== $claimed) {
                $failures[] = sprintf(
                    '%s/%s: %s has %d frames, header claims %d',
                    $npcKey, $src, $channel, strlen($bytes), $claimed
                );
            }
        }
        $frames += $claimed;

        // A clip is named for a hash of its words and its voice, so audio the
        // manifest still points at must be on disk. A track without it means
        // the mp3 was deleted and the track was not.
        if (!is_file(dirname($manifest) . '/' . $src)) {
            $failures[] = "$npcKey/$src: track exists but the audio does not";
        }
    }

    foreach (array_keys($mouths) as $src) {
        if (!isset($referenced[$src])) {
            $failures[] = "$npcKey/$src: track for a clip no manifest mentions";
        }
    }
}

printf(
    "%d npcs, %d clips, %d with mouth tracks (%.1f%%), %d frames total (%.1f minutes of mouth)\n",
    $npcs, $clips, $tracked,
    $clips > 0 ? $tracked / $clips * 100 : 0.0,
    $frames, $frames / 30 / 60
);

if ($untracked !== []) {
    printf("%d clips have no mouth track. Run: python3 tools/gen_visemes.py --all\n", count($untracked));
    foreach (array_slice($untracked, 0, 10) as $one) {
        echo "  $one\n";
    }
    if (count($untracked) > 10) {
        printf("  ... and %d more\n", count($untracked) - 10);
    }
}

foreach (array_slice($failures, 0, 20) as $failure) {
    echo "FAIL $failure\n";
}
if (count($failures) > 20) {
    printf("... and %d more failures\n", count($failures) - 20);
}

if ($failures !== [] || $untracked !== []) {
    exit(1);
}
echo "ok\n";
exit(0);
