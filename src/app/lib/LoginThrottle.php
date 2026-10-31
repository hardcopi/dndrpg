<?php
/**
 * How many times you may get the password wrong.
 *
 * Two counters, because two different people are guessing. Five misses on one
 * username in fifteen minutes locks that username for fifteen: that stops a
 * patient guesser working through one account. Thirty misses from one address
 * locks the address: that stops one machine spraying a common password across
 * every username, which the per-name counter never sees.
 *
 * A username that does not exist is counted exactly like one that does. A
 * lockout that only ever happened to real accounts would be a way of asking
 * which names are real — the thing Auth::login's dummy hash exists to prevent.
 *
 * AN ATTEMPT IS COUNTED BEFORE IT IS JUDGED. "Is this locked?" and "count
 * this miss" used to be two steps with the password check between them, so ten
 * attempts sent at once all read an unlocked record before any of them wrote a
 * miss, and all ten reached password_verify(). reserveLogin() now asks and
 * counts in one step, holding the username's and the address's files locked
 * together, so the sixth of six simultaneous attempts sees the fifth's count.
 * A success then clears the counters, as it always did — the cost is that an
 * attempt which turns out right was briefly counted as if it might not be.
 *
 * FILES, NOT A TABLE. The live database has no migration tracking, and a new
 * table is a deploy step that can be forgotten; a directory is not. One small
 * JSON file per key, named by the key's hash so a username never becomes a
 * path, each read and written under flock().
 *
 * IT FAILS OPEN. If the directory cannot be made or written, this logs and
 * allows the attempt. The site has one player, and the worst outcome of a full
 * disk must not be that he cannot sign in to his own game; an unthrottled form
 * for the duration of a disk fault is the cheaper failure.
 */

declare(strict_types=1);

final class LoginThrottle
{
    public const USER_LIMIT = 5;
    public const IP_LIMIT   = 30;

    /** Failures older than this no longer count towards a lock. */
    public const WINDOW_SECONDS = 15 * 60;

    /** How long a lock lasts once earned. */
    public const LOCK_SECONDS = 15 * 60;

    private string $dir;

    /** @var callable(): int */
    private $clock;

    /**
     * @param string|null   $dir   null = RPG_LOGIN_THROTTLE_DIR, else the temp dir
     * @param callable|null $clock returns a unix time; injectable so tests need not sleep
     */
    public function __construct(?string $dir = null, ?callable $clock = null)
    {
        if ($dir === null) {
            $env = getenv('RPG_LOGIN_THROTTLE_DIR');
            $dir = ($env !== false && $env !== '')
                ? $env
                : sys_get_temp_dir() . '/rpg-login-throttle';
        }
        $this->dir = rtrim($dir, '/');
        $this->clock = $clock ?? static fn (): int => time();
    }

    // =======================================================================
    // Signing in
    // =======================================================================

    /**
     * Refuse, or count this attempt — atomically.
     *
     * Returns the seconds until this username and address may try again, in
     * which case nothing was counted and the password must not be checked; or
     * 0, in which case the attempt has been counted against both and the
     * caller may go on to verify it. The attempt that reaches a limit is let
     * through and locks the ones after it, so "five misses, then refused" is
     * exactly the policy it was.
     */
    public function reserveLogin(string $username, string $ip): int
    {
        return $this->reserve([
            self::userKey($username) => self::USER_LIMIT,
            self::ipKey($ip)         => self::IP_LIMIT,
        ]);
    }

    /** A right password: the attempt counted by reserveLogin() is forgiven, and so are the misses before it. */
    public function loginSucceeded(string $username, string $ip): void
    {
        $this->clear(self::userKey($username));
        $this->clear(self::ipKey($ip));
    }

    /**
     * Seconds left on a lock, without counting anything.
     *
     * Not what the route asks — it reserves — but what a test or a diagnosis
     * wants: looking must not itself be an attempt.
     */
    public function loginRetryAfter(string $username, string $ip): int
    {
        $left = 0;
        $keys = array_values(array_filter(
            [self::userKey($username), self::ipKey($ip)],
            // Asking must not create a file: otherwise every name anybody
            // types leaves one behind.
            fn (string $k): bool => is_file($this->path($k))
        ));
        $this->withRecords($keys, function (array $recs) use (&$left): ?array {
            $now = ($this->clock)();
            foreach ($recs as $rec) {
                $left = max($left, (int) $rec['locked_until'] - $now);
            }
            return null;
        });
        return $left;
    }

    // =======================================================================
    // Signing up
    // =======================================================================

    /**
     * Registration counts every attempt, not only failures: a run of
     * successful sign-ups is the thing being limited. Its own key, so somebody
     * filling in the form cannot use up the address's sign-in allowance.
     * Same contract as reserveLogin().
     */
    public function reserveRegister(string $ip): int
    {
        return $this->reserve(['register-ip:' . $ip => self::IP_LIMIT]);
    }

    // =======================================================================
    // The store
    // =======================================================================

    private static function userKey(string $username): string
    {
        // Trimmed as Auth::login trims it, and lowercased because the users
        // table's collation matches names case-insensitively — "Owner" and
        // "owner" are one account and must be one counter.
        return 'user:' . strtolower(trim($username));
    }

    private static function ipKey(string $ip): string
    {
        return 'ip:' . $ip;
    }

    /**
     * @param array<string,int> $limits key => attempts allowed in a window
     * @return int seconds of the longest lock among them; 0 = counted and allowed
     */
    private function reserve(array $limits): int
    {
        $refused = 0;
        $this->withRecords(array_keys($limits), function (array $recs) use ($limits, &$refused): ?array {
            $now = ($this->clock)();
            foreach ($recs as $rec) {
                $refused = max($refused, (int) $rec['locked_until'] - $now);
            }
            if ($refused > 0) {
                // Refused attempts are not counted: a guesser hammering a
                // locked name must not be able to keep it locked for ever by
                // extending the lock with every refusal.
                return null;
            }
            foreach ($recs as $key => $rec) {
                if ($now - (int) $rec['first'] >= self::WINDOW_SECONDS) {
                    $rec['count'] = 0;
                    $rec['first'] = $now;
                }
                $rec['count'] = (int) $rec['count'] + 1;
                if ($rec['count'] >= $limits[$key]) {
                    // The count starts again from the lock, so when it lifts
                    // the next guess is the first of five, not the sixth.
                    $rec['locked_until'] = $now + self::LOCK_SECONDS;
                    $rec['count'] = 0;
                    $rec['first'] = $now;
                }
                $recs[$key] = $rec;
            }
            return $recs;
        });
        return $refused;
    }

    private function clear(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && !@unlink($path)) {
            error_log('LoginThrottle: could not clear ' . $path);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    /**
     * Lock several records, read them, and write back what $fn returns
     * (null = leave them all). $fn gets and returns records keyed by key.
     *
     * Every file is locked before any is read, and always in the order of
     * its file name, so two requests wanting the same pair can never each hold
     * one and wait for the other. Any failure is logged and swallowed, and $fn
     * is then not run at all — see the class header on failing open.
     *
     * @param list<string> $keys
     */
    private function withRecords(array $keys, callable $fn): void
    {
        if ($keys === []) {
            return;
        }
        $blank = ['count' => 0, 'first' => 0, 'locked_until' => 0];
        $paths = [];
        foreach ($keys as $key) {
            $paths[$this->path($key)] = $key;
        }
        ksort($paths, SORT_STRING);

        $handles = [];
        try {
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                throw new RuntimeException('cannot create ' . $this->dir);
            }
            foreach ($paths as $path => $key) {
                $fh = @fopen($path, 'c+');
                if ($fh === false) {
                    throw new RuntimeException('cannot open a record in ' . $this->dir);
                }
                $handles[$key] = $fh;
                if (!flock($fh, LOCK_EX)) {
                    throw new RuntimeException('cannot lock a record in ' . $this->dir);
                }
            }

            $recs = [];
            foreach ($handles as $key => $fh) {
                $raw = stream_get_contents($fh);
                $rec = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                $recs[$key] = is_array($rec) ? array_merge($blank, $rec) : $blank;
            }

            $out = $fn($recs);
            if ($out !== null) {
                foreach ($out as $key => $rec) {
                    $fh = $handles[$key];
                    if (!ftruncate($fh, 0) || !rewind($fh) || fwrite($fh, (string) json_encode($rec)) === false) {
                        throw new RuntimeException('cannot write a record in ' . $this->dir);
                    }
                    fflush($fh);
                }
            }
        } catch (Throwable $e) {
            error_log('LoginThrottle: ' . $e->getMessage() . ' — failing open, sign-in is not being limited');
        } finally {
            foreach (array_reverse($handles, true) as $fh) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }
}
