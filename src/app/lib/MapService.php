<?php
/**
 * The game's side of the map service.
 *
 * The dungeon generator is TypeScript and lives in its own container; this asks
 * it for a floor. See docker/mapgen for what is on the other end.
 *
 * EVERY FAILURE IS SILENT AND RETURNS NULL, on purpose. The caller — see
 * DelveEngine::descend — falls back to DungeonGen, which has generated every
 * floor in this game so far and will carry on doing it. A prettier dungeon is
 * worth a container; it is not worth a stair that refuses to go down because a
 * service is restarting. The only thing a failure does is get logged.
 *
 * The timeout is short for the same reason. A player pressing "down" is waiting
 * on this, and two seconds of nothing is worse than a plainer floor drawn now.
 */

declare(strict_types=1);

final class MapService
{
    /** Long enough for a big floor, short enough that a hung service is not a hung stair. */
    private const TIMEOUT_SECONDS = 2;

    /** Kinds the delve asks for. The service makes more; these are the two that read as a dungeon. */
    public const KIND_KEEP = 'keep';
    public const KIND_CAVES = 'caves';

    /** Where the service is, or null when this install has none. */
    public static function url(): ?string
    {
        $url = getenv('RPG_MAPGEN_URL');
        $url = is_string($url) ? trim($url) : '';
        return $url === '' ? null : rtrim($url, '/');
    }

    public static function available(): bool
    {
        return self::url() !== null;
    }

    /**
     * One floor, or null if the service could not draw one.
     *
     * The seed is the caller's and travels unchanged: the same seed must give
     * the same floor for ever, because `dungeon_delves` stores two integers and
     * calls that a dungeon. Depth is folded into the seed here rather than sent,
     * so that one delve's floors differ from each other while a delve replayed
     * from its seed is identical — the same trick, and the same constant, that
     * DungeonGen::generate uses.
     *
     * @return array|null the `ground` level's dungeon, decoded
     */
    public static function floor(int $seed, int $depth, string $kind, array $options = []): ?array
    {
        $url = self::url();
        if ($url === null) {
            return null;
        }

        $body = json_encode(array_merge([
            'kind' => $kind,
            'seed' => self::seedFor($seed, $depth),
        ], $options), JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url . '/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status !== 200) {
            error_log('MapService: ' . ($error !== '' ? $error : "HTTP {$status}") . ' — falling back to DungeonGen.');
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded['ok']) || empty($decoded['levels'])) {
            error_log('MapService: unreadable answer — falling back to DungeonGen.');
            return null;
        }

        // The ground floor only. A keep comes back with a `below` and sometimes
        // an `above`, which is the generator describing one building on several
        // storeys; a delve's floors are separate places joined by a stair, and
        // each is asked for on its own with its own seed.
        foreach ($decoded['levels'] as $level) {
            if (($level['id'] ?? null) === 'ground' && isset($level['dungeon'])) {
                return $level['dungeon'];
            }
        }
        return $decoded['levels'][0]['dungeon'] ?? null;
    }

    /**
     * The seed for one floor of one delve.
     *
     * Lifted from DungeonGen::generate so that both generators walk the same
     * ladder: a delve is one seed, and each depth is a fixed shuffle of it.
     * 0x9E3779B1 is the golden-ratio constant every hash in this codebase uses
     * for the same purpose — spreading nearby inputs apart.
     */
    public static function seedFor(int $seed, int $depth): int
    {
        return (($seed ^ ($depth * 0x9E3779B1)) & 0x7FFFFFFF) ?: 1;
    }
}
