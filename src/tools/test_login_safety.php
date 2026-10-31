<?php
/**
 * Test harness for the sign-in throttle and the CSRF gate's decisions.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_login_safety.php`.
 *
 * Needs no database. The throttle runs against a scratch directory under the
 * temp dir with a clock this file moves by hand, so a fifteen-minute lock is
 * tested in milliseconds and no real store is touched. The HTTP side — the
 * 403s, the 429, the cookie flags — is a curl transcript, not this file:
 * headers and session ids do not exist under CLI.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$RESULTS = ['pass' => 0, 'fail' => 0];

function ok(string $name, bool $condition, string $detail = ''): void
{
    global $RESULTS;
    if ($condition) {
        $RESULTS['pass']++;
        return;
    }
    $RESULTS['fail']++;
    echo 'FAIL  ' . $name . ($detail === '' ? '' : '  (' . $detail . ')') . PHP_EOL;
}

function same(string $name, $expected, $actual): void
{
    ok($name, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

function rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        @chmod($f, 0600);
        @unlink($f);
    }
    @chmod($dir, 0700);
    @rmdir($dir);
}

$base = sys_get_temp_dir() . '/rpg-throttle-test-' . bin2hex(random_bytes(4));
$now = 1_000_000;
$clock = static function () use (&$now): int {
    return $now;
};

// A wrong password, as the route sees it: the attempt is reserved (counted)
// and then simply not forgiven. Returns what reserveLogin() said.
$miss = static fn (LoginThrottle $t, string $name, string $ip): int => $t->reserveLogin($name, $ip);

// ---------------------------------------------------------------------------
section('Five misses lock the name');

$dir = $base . '/a';
$t = new LoginThrottle($dir, $clock);
$ip = '203.0.113.7';

same('nothing locked to begin with', 0, $t->loginRetryAfter('owner', $ip));
ok('looking does not create the directory', !is_dir($dir));
for ($i = 1; $i <= 4; $i++) {
    same("miss $i is allowed through", 0, $miss($t, 'owner', $ip));
    $now += 10;
}
same('four misses are not a lock', 0, $t->loginRetryAfter('owner', $ip));
same('the fifth attempt is still let through', 0, $miss($t, 'owner', $ip));
same('and locks the ones after it', LoginThrottle::LOCK_SECONDS, $t->loginRetryAfter('owner', $ip));
same('the sixth is refused, whatever its password', LoginThrottle::LOCK_SECONDS, $t->reserveLogin('owner', $ip));
same('case and padding are one counter', LoginThrottle::LOCK_SECONDS, $t->reserveLogin('  OWNER ', $ip));
same('another name from the same address is not locked', 0, $t->loginRetryAfter('someone', $ip));
same('the store directory is private', '0700', substr(sprintf('%o', fileperms($dir)), -4));

$now += 60;
same('retry_after counts down', LoginThrottle::LOCK_SECONDS - 60, $t->reserveLogin('owner', $ip));
same('refusals do not extend the lock', LoginThrottle::LOCK_SECONDS - 60, $t->loginRetryAfter('owner', $ip));

// ---------------------------------------------------------------------------
section('The lock lifts');

$now += LoginThrottle::LOCK_SECONDS;
same('unlocked after the window', 0, $t->loginRetryAfter('owner', $ip));
same('the next attempt is allowed', 0, $miss($t, 'owner', $ip));
same('and is the first of five again, not a lock', 0, $t->loginRetryAfter('owner', $ip));

// ---------------------------------------------------------------------------
section('Misses spread out do not add up');

$dir = $base . '/b';
$t = new LoginThrottle($dir, $clock);
for ($i = 0; $i < 4; $i++) {
    $miss($t, 'slow', $ip);
}
$now += LoginThrottle::WINDOW_SECONDS;
$miss($t, 'slow', $ip);
same('four, then one a window later, is not five', 0, $t->loginRetryAfter('slow', $ip));

// ---------------------------------------------------------------------------
section('Success clears');

$dir = $base . '/c';
$t = new LoginThrottle($dir, $clock);
for ($i = 0; $i < 4; $i++) {
    $miss($t, 'owner', $ip);
}
same('a fifth attempt is reserved', 0, $t->reserveLogin('owner', $ip));
same('and, at the limit, locks', LoginThrottle::LOCK_SECONDS, $t->loginRetryAfter('owner', $ip));
$t->loginSucceeded('owner', $ip);
same('but it was the right password: the lock and the count go', 0, $t->loginRetryAfter('owner', $ip));
for ($i = 0; $i < 4; $i++) {
    $miss($t, 'owner', $ip);
}
same('four misses after the success: not locked', 0, $t->loginRetryAfter('owner', $ip));
$miss($t, 'owner', $ip);
same('five since the success do lock', LoginThrottle::LOCK_SECONDS, $t->loginRetryAfter('owner', $ip));

// ---------------------------------------------------------------------------
section('Names that do not exist are counted the same');

// The throttle never sees the users table, so this is true by construction;
// the assertion is that nothing about the key depends on the name being real.
$dir = $base . '/d';
$t = new LoginThrottle($dir, $clock);
for ($i = 0; $i < LoginThrottle::USER_LIMIT; $i++) {
    $miss($t, 'no_such_user_x', $ip);
}
same('a made-up name is refused on the sixth', LoginThrottle::LOCK_SECONDS, $t->reserveLogin('no_such_user_x', $ip));

// ---------------------------------------------------------------------------
section('Thirty misses lock the address');

$dir = $base . '/e';
$t = new LoginThrottle($dir, $clock);
for ($i = 0; $i < LoginThrottle::IP_LIMIT - 1; $i++) {
    $miss($t, 'spray' . $i, $ip);
}
same('twenty-nine names, one each: address not locked', 0, $t->loginRetryAfter('fresh', $ip));
$miss($t, 'spray-last', $ip);
same('the thirtieth locks the address for every name', LoginThrottle::LOCK_SECONDS, $t->reserveLogin('fresh', $ip));
same('another address is unaffected', 0, $t->loginRetryAfter('fresh', '198.51.100.1'));

// ---------------------------------------------------------------------------
section('Registration has its own allowance');

$dir = $base . '/f';
$t = new LoginThrottle($dir, $clock);
for ($i = 0; $i < LoginThrottle::IP_LIMIT; $i++) {
    same('sign-up ' . ($i + 1) . ' is allowed', 0, $t->reserveRegister($ip));
}
ok('the thirty-first sign-up is refused', $t->reserveRegister($ip) > 0);
same('but sign-in is not', 0, $t->loginRetryAfter('owner', $ip));

// ---------------------------------------------------------------------------
section('Overlapping attempts cannot both slip through');

// Interleaved by hand: two instances — two PHP-FPM workers — against one
// store. Before the reservation, both could read "four misses" and both go on
// to verify a password.
$dir = $base . '/h';
$w1 = new LoginThrottle($dir, $clock);
$w2 = new LoginThrottle($dir, $clock);
same('worker 1, attempt 1', 0, $w1->reserveLogin('owner', $ip));
same('worker 2, attempt 2', 0, $w2->reserveLogin('owner', $ip));
same('worker 1, attempt 3', 0, $w1->reserveLogin('owner', $ip));
same('worker 2, attempt 4', 0, $w2->reserveLogin('owner', $ip));
same('worker 1, attempt 5', 0, $w1->reserveLogin('owner', $ip));
same('worker 2, attempt 6: refused, it saw worker 1\'s fifth', LoginThrottle::LOCK_SECONDS, $w2->reserveLogin('owner', $ip));
same('worker 1, attempt 7: refused', LoginThrottle::LOCK_SECONDS, $w1->reserveLogin('owner', $ip));

// And for real: twelve processes released at the same instant, each making
// one attempt. Exactly five may reach the password check, every time.
$dir = $base . '/race';
@mkdir($dir, 0700, true);
$go = $base . '/race-go';
$child = '$go = $argv[1]; require $argv[3];'
    . 'while (!is_file($go)) { usleep(200); }'
    . '$t = new LoginThrottle($argv[2]);'
    . 'echo $t->reserveLogin("racer", "192.0.2.1") === 0 ? "allowed" : "refused";';
$procs = [];
for ($i = 0; $i < 12; $i++) {
    $procs[] = proc_open(
        [PHP_BINARY, '-r', $child, $go, $dir, dirname(__DIR__) . '/app/lib/LoginThrottle.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes[$i]
    );
}
usleep(300000);
touch($go);
$allowed = 0;
$outcomes = [];
foreach ($procs as $i => $proc) {
    $out = trim((string) stream_get_contents($pipes[$i][1]));
    $err = trim((string) stream_get_contents($pipes[$i][2]));
    proc_close($proc);
    $outcomes[] = $out . ($err !== '' ? ' [' . $err . ']' : '');
    $allowed += $out === 'allowed' ? 1 : 0;
}
same('twelve simultaneous attempts: exactly five allowed', LoginThrottle::USER_LIMIT, $allowed);
ok('every process answered', count(array_filter($outcomes, fn ($o) => in_array($o, ['allowed', 'refused'], true))) === 12, implode(', ', $outcomes));
@unlink($go);

// ---------------------------------------------------------------------------
section('An unusable store fails open');

// A file where the directory should be: mkdir cannot succeed, and running as
// root (the container does) would sail through a chmod-based test.
@mkdir($base, 0700, true);
$blocked = $base . '/not-a-dir';
file_put_contents($blocked, 'x');
$t = new LoginThrottle($blocked, $clock);
$logged = ini_set('error_log', $base . '/errors.log');
$refusedAny = false;
for ($i = 0; $i < 10; $i++) {
    $refusedAny = $refusedAny || $t->reserveLogin('owner', $ip) !== 0;
}
ok('ten attempts against a broken store: none refused', !$refusedAny);
same('and still allowed', 0, $t->loginRetryAfter('owner', $ip));
$t->loginSucceeded('owner', $ip);
ini_set('error_log', (string) $logged);
$log = (string) @file_get_contents($base . '/errors.log');
ok('and it said so in the error log', str_contains($log, 'failing open'), $log);

$garbled = $base . '/g';
$t = new LoginThrottle($garbled, $clock);
$miss($t, 'owner', $ip);
foreach (glob($garbled . '/*.json') ?: [] as $f) {
    file_put_contents($f, '{not json');
}
same('a corrupt record reads as no record', 0, $t->loginRetryAfter('owner', $ip));
same('and can be written over', 0, $t->reserveLogin('owner', $ip));

// ---------------------------------------------------------------------------
section('Who the CSRF gate asks');

$saved = $_SERVER;
$cookies = $_COOKIE;
$case = static function (array $server, array $cookie = []) use ($saved): bool {
    $_SERVER = array_merge(array_diff_key($saved, array_flip([
        'HTTP_AUTHORIZATION', 'HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE', 'HTTP_SEC_FETCH_MODE',
    ])), $server);
    $_COOKIE = $cookie;
    return csrf_required();
};
$sid = [session_name() => 'abc'];

same('GET is never asked', false, $case(['REQUEST_METHOD' => 'GET'], $sid));
same('HEAD is never asked', false, $case(['REQUEST_METHOD' => 'HEAD'], $sid));
same('OPTIONS is never asked', false, $case(['REQUEST_METHOD' => 'OPTIONS'], $sid));
same('POST with the session cookie is', true, $case(['REQUEST_METHOD' => 'POST'], $sid));
same('PUT and DELETE are too', true, $case(['REQUEST_METHOD' => 'DELETE'], $sid));
same('a Bearer POST is not', false, $case(['REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer rpg_x'], $sid));
same('a malformed Authorization does not exempt', true, $case(['REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Basic eA=='], $sid));
same('cookieless and not a browser (Unity, curl) is not', false, $case(['REQUEST_METHOD' => 'POST']));
same('cookieless with an Origin (a forged login) is', true, $case(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://evil.example']));
same('cookieless with Sec-Fetch-Site is', true, $case(['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'cross-site']));
$_SERVER = $saved;
$_COOKIE = $cookies;

// ---------------------------------------------------------------------------
section('The cookie is Secure only over TLS, and only on nginx\'s word');

$saved = $_SERVER;
$_SERVER = ['HTTPS' => 'on', 'SERVER_PORT' => '443'];
same('HTTPS=on is Secure', true, session_cookie_params()['secure']);
$_SERVER = ['SERVER_PORT' => '443'];
same('port 443 alone is Secure', true, session_cookie_params()['secure']);
$_SERVER = ['HTTPS' => 'off', 'SERVER_PORT' => '80'];
same('HTTPS=off on 80 is not', false, session_cookie_params()['secure']);
// X-Forwarded-Proto is the client's to write here — there is no proxy — so it
// must move the answer in neither direction: TLS stays Secure whatever it
// says, and plain http is not made Secure by it.
$_SERVER = ['HTTPS' => 'on', 'SERVER_PORT' => '443', 'HTTP_X_FORWARDED_PROTO' => 'http'];
same('HTTPS=on is Secure even when X-Forwarded-Proto says http', true, session_cookie_params()['secure']);
$_SERVER = ['SERVER_PORT' => '443', 'HTTP_X_FORWARDED_PROTO' => 'http'];
same('port 443 is Secure even when X-Forwarded-Proto says http', true, session_cookie_params()['secure']);
$_SERVER = ['SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https'];
same('plain http is not made Secure by X-Forwarded-Proto: https', false, session_cookie_params()['secure']);
$params = session_cookie_params();
same('HttpOnly', true, $params['httponly']);
same('SameSite=Lax', 'Lax', $params['samesite']);
same('path /', '/', $params['path']);
$_SERVER = $saved;

// ---------------------------------------------------------------------------
section('The token');

$_SESSION = [];
$first = csrf_token();
same('64 hex characters', 1, preg_match('/^[0-9a-f]{64}$/', $first));
same('stable within a session', $first, csrf_token());
$_SESSION = ['auth_user_id' => 1];
ok('a session from before tokens existed is given one, not refused', strlen(csrf_token()) === 64);
ok('and keeps what it had', ($_SESSION['auth_user_id'] ?? null) === 1);
ok('the meta tag carries it', str_contains(csrf_meta(), $_SESSION[CSRF_SESSION_KEY]));
$_SESSION = [];

rmtree($base . '/a');
rmtree($base . '/b');
rmtree($base . '/c');
rmtree($base . '/d');
rmtree($base . '/e');
rmtree($base . '/f');
rmtree($base . '/g');
rmtree($base . '/h');
rmtree($base . '/race');
@unlink($blocked);
@unlink($base . '/errors.log');
@rmdir($base);

echo PHP_EOL . sprintf('%d passed, %d failed', $RESULTS['pass'], $RESULTS['fail']) . PHP_EOL;
exit($RESULTS['fail'] > 0 ? 1 : 0);
