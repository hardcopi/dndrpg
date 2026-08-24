<?php
/**
 * The recorded reading of an authored line, if there is one.
 *
 * A dialogue node is a paragraph of prose with speech quoted inside it, and it
 * is read by two or more voices: a narrator for the prose, the character for
 * what is in the quotes. Working out where those cuts fall is a small grammar —
 * whose name precedes a quote, whether the words after it are a speech tag —
 * and it is NOT implemented here. `tools/gen_voiceover.py` does that cut once,
 * when it generates the audio, and writes the answer down. This class looks the
 * answer up.
 *
 * That division is the whole design. A PHP reimplementation of the quote
 * grammar would agree with the Python one exactly until the day one of the two
 * was fixed, and then the game would be playing Aldric's line in Finch's voice
 * with no test able to see it. The same rule the combat engine follows for its
 * board: derive once, ship the result, and let the other end paint it.
 *
 * The lookup key is a sha1 of the EXACT authored text. Nothing normalises it on
 * either side — no trimming, no case folding — because the two ends have to
 * agree on the key byte for byte and the simplest agreement is "the string
 * itself". So editing a line orphans its audio, which is the correct outcome:
 * the recording is of the words that were there before. It fails to silence
 * rather than to a stale reading, and regenerating fixes it.
 *
 * Audio is optional everywhere. An NPC nobody has recorded, a line added since
 * the last generation, an install that never ran the tool at all — each returns
 * an empty list, and the client simply has nothing to play.
 */

declare(strict_types=1);

class Voiceover
{
    /** Where the generator writes, relative to the document root. */
    private const DIR = 'assets/audio/vo';

    /** One decoded lines.json per NPC per request; most requests touch one. */
    private static array $cache = [];

    /** One decoded visemes.json per NPC per request. Same shape of access. */
    private static array $mouths = [];

    /** The cast, decoded once. Narrator plus every character with a voice. */
    private static ?array $cast = null;

    /**
     * The clips one authored string reads as, in the order they are spoken.
     *
     * Each clip may carry a `visemes` track: how the mouth moves while it
     * plays, derived from the recording by `tools/gen_visemes.py` and looked up
     * here exactly as the cut is. Same division as the quote grammar — the
     * analysis happens once, offline, and this only reads the answer.
     *
     * The track is optional in precisely the way the audio is. An NPC recorded
     * before the mouth tool existed, a clip added since the last run, an install
     * that never ran it: the key is simply absent and the client plays the line
     * with a still face, which is what it did before any of this.
     *
     * @return list<array{speaker:string,voice:string,src:string,visemes?:array}>
     *         empty if nothing has been recorded for this text
     */
    public static function clips(string $npcKey, string $text): array
    {
        if ($text === '' || $npcKey === '') {
            return [];
        }
        $lines = self::lines($npcKey);
        $found = $lines[sha1($text)] ?? null;
        if (!is_array($found)) {
            return [];
        }

        $mouths = self::mouths($npcKey);

        $out = [];
        foreach ($found as $clip) {
            $src = (string) ($clip['src'] ?? '');
            if ($src === '') {
                continue;
            }
            $entry = [
                'speaker' => (string) ($clip['speaker'] ?? ''),
                'voice'   => (string) ($clip['voice'] ?? ''),
                // Built here rather than in the manifest so the audio can be
                // moved without regenerating every file that points at it.
                'src'     => self::DIR . '/' . rawurlencode($npcKey) . '/' . rawurlencode($src),
            ];
            // Keyed on the bare filename, before the line above turns it into a
            // path: the clip name is already a hash of the words and the voice,
            // so it identifies the audio wherever the audio is served from.
            $mouth = $mouths[$src] ?? null;
            if (is_array($mouth) && isset($mouth['open'])) {
                $entry['visemes'] = $mouth;
            }
            $out[] = $entry;
        }
        return $out;
    }

    /** Whether this NPC has been recorded at all — one file test, cached. */
    public static function has(string $npcKey): bool
    {
        return self::lines($npcKey) !== [];
    }

    /**
     * Who reads for whom.
     *
     * Not used by the engine, which is handed the voice on each clip. It is here
     * for the tools and the content screen, which want to say what somebody
     * sounds like without opening a conversation.
     */
    public static function cast(): array
    {
        if (self::$cast === null) {
            self::$cast = self::read(self::root() . '/' . self::DIR . '/voices.json');
        }
        return self::$cast;
    }

    /** @return array<string,list<array<string,string>>> */
    private static function lines(string $npcKey): array
    {
        if (!array_key_exists($npcKey, self::$cache)) {
            // The key reaches this from a request body by way of the engine, and
            // it names a path. Anything that is not a plain content key is not a
            // missing recording, it is somebody walking out of the directory.
            self::$cache[$npcKey] = preg_match('/^[a-z0-9_]+$/', $npcKey) !== 1
                ? []
                : self::read(self::root() . '/' . self::DIR . '/' . $npcKey . '/lines.json');
        }
        return self::$cache[$npcKey];
    }

    /**
     * The mouth tracks for one NPC, keyed by clip filename.
     *
     * Guarded by the same key pattern as `lines()` and for the same reason: the
     * key comes off a request and names a directory.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function mouths(string $npcKey): array
    {
        if (!array_key_exists($npcKey, self::$mouths)) {
            self::$mouths[$npcKey] = preg_match('/^[a-z0-9_]+$/', $npcKey) !== 1
                ? []
                : self::read(self::root() . '/' . self::DIR . '/' . $npcKey . '/visemes.json');
        }
        return self::$mouths[$npcKey];
    }

    private static function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** The document root: this file is `<root>/app/lib/Voiceover.php`. */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
