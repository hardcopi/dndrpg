<?php
/**
 * Test harness for accounts, roles and the gates.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_auth.php`.
 *
 * Needs the database, and works on scratch accounts inside a transaction it
 * rolls back, like test_levelup.php — so it is safe against a database somebody
 * is playing on.
 *
 * $_SESSION is an ordinary array under CLI, which is all Auth needs; the one
 * thing that cannot be exercised here is session_regenerate_id(), which does
 * nothing without a real session. What that protects against — session fixation
 * — is not something a unit test can observe anyway.
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

function threw(string $name, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($name, false, 'no exception raised');
    } catch (Throwable $e) {
        ok($name, $needle === '' || stripos($e->getMessage(), $needle) !== false, 'message was: ' . $e->getMessage());
    }
}

$db = db();
$auth = new Auth($db);
$_SESSION = [];

$suffix = bin2hex(random_bytes(4));
$db->beginTransaction();

try {
    // -----------------------------------------------------------------------
    section('Creating accounts');

    $player = $auth->register("player_{$suffix}", 'correct horse battery', 'p@example.com');
    same('a new account is an ordinary user', 'user', $player['role']);
    ok('and is active', (int) $player['is_active'] === 1);

    threw('a duplicate username is refused', function () use ($auth, $suffix) {
        $auth->register("player_{$suffix}", 'another password');
    }, 'taken');

    threw('a short password is refused', fn () => $auth->register("short_{$suffix}", 'abc'), 'at least 8');
    threw('a username with spaces is refused', fn () => $auth->register("bad name {$suffix}", 'a password here'), '3 to 50');
    threw('a two-character username is refused', fn () => $auth->register('ab', 'a password here'), '3 to 50');
    threw('a bad email is refused', fn () => $auth->register("mail_{$suffix}", 'a password here', 'not-an-email'), 'email');
    threw('an unknown role is refused', fn () => $auth->register("role_{$suffix}", 'a password here', '', 'wizard'), 'role');

    // -----------------------------------------------------------------------
    section('The password is hashed, not stored');

    $row = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $row->execute([(int) $player['id']]);
    $hash = (string) $row->fetchColumn();
    ok('the password does not appear in the column', !str_contains($hash, 'correct horse battery'));
    ok('and the hash verifies', password_verify('correct horse battery', $hash));
    ok('a wrong password does not', !password_verify('incorrect horse battery', $hash));

    // -----------------------------------------------------------------------
    section('Signing in');

    $_SESSION = [];
    Auth::forgetCache();
    ok('nobody is signed in to begin with', !$auth->isSignedIn());

    threw('the wrong password is refused', function () use ($auth, $suffix) {
        $auth->login("player_{$suffix}", 'wrong');
    }, 'do not match');
    threw('an account that does not exist is refused', function () use ($auth) {
        $auth->login('nobody_at_all', 'any password');
    }, 'do not match');

    // Both failures must read the same, or the form answers "does this username
    // exist" to anyone who asks.
    $m1 = $m2 = '';
    try { $auth->login("player_{$suffix}", 'wrong'); } catch (Throwable $e) { $m1 = $e->getMessage(); }
    try { $auth->login('nobody_at_all', 'wrong'); } catch (Throwable $e) { $m2 = $e->getMessage(); }
    same('a wrong username and a wrong password are indistinguishable', $m1, $m2);

    Auth::forgetCache();
    $signedIn = $auth->login("player_{$suffix}", 'correct horse battery');
    same('the right password signs you in', "player_{$suffix}", $signedIn['username']);
    ok('and isSignedIn agrees', $auth->isSignedIn());
    same('userId is the account', (int) $player['id'], $auth->userId());
    ok('an ordinary user is not an admin', !$auth->isAdmin());
    ok('last_login_at was stamped', $auth->currentUser()['last_login_at'] !== null);

    // -----------------------------------------------------------------------
    section('An account with no password cannot be used');

    // This is the state migrate_users.sql deliberately leaves the promoted
    // administrator in, so it had better hold.
    $db->prepare('INSERT INTO users (username, role, password_hash) VALUES (?, ?, NULL)')
        ->execute(["nopass_{$suffix}", 'admin']);
    threw('a NULL password_hash cannot sign in', function () use ($auth, $suffix) {
        $auth->login("nopass_{$suffix}", '');
    }, 'do not match');
    threw('and not with any password either', function () use ($auth, $suffix) {
        $auth->login("nopass_{$suffix}", 'anything at all');
    }, 'do not match');

    // -----------------------------------------------------------------------
    section('Roles and gates');

    $admin = $auth->register("admin_{$suffix}", 'a good long password', '', Auth::ROLE_ADMIN);
    same('an admin account has the admin role', 'admin', $admin['role']);

    Auth::forgetCache();
    $auth->login("admin_{$suffix}", 'a good long password');
    ok('the admin is an admin', $auth->isAdmin());
    ok('requireAdmin lets them through', is_array($auth->requireAdmin()));

    Auth::forgetCache();
    $auth->login("player_{$suffix}", 'correct horse battery');
    ok('requireUser lets an ordinary user through', is_array($auth->requireUser()));
    threw('requireAdmin refuses them', fn () => $auth->requireAdmin(), 'administrators');

    // The distinction the two exceptions exist for.
    try {
        $auth->requireAdmin();
    } catch (Throwable $e) {
        ok('a signed-in user is forbidden, not asked to sign in', $e instanceof AuthForbiddenException, get_class($e));
    }
    $auth->logout();
    try {
        $auth->requireUser();
    } catch (Throwable $e) {
        ok('a signed-out visitor is asked to sign in', $e instanceof AuthRequiredException, get_class($e));
    }

    // -----------------------------------------------------------------------
    section('Signing out, and being switched off');

    ok('logout clears the session', !$auth->isSignedIn());

    Auth::forgetCache();
    $auth->login("player_{$suffix}", 'correct horse battery');
    $_SESSION['character_id'] = 999;
    $_SESSION['party_id'] = 888;
    $auth->logout();
    ok('logout also drops the active character', !isset($_SESSION['character_id']));
    ok('and the party', !isset($_SESSION['party_id']));

    // Signing in must not leave the previous player inside somebody else's save.
    $_SESSION['character_id'] = 777;
    Auth::forgetCache();
    $auth->login("admin_{$suffix}", 'a good long password');
    ok('signing in drops any character left by the last session', !isset($_SESSION['character_id']));

    // A deactivated account stops being signed in on its next request.
    Auth::forgetCache();
    $auth->login("player_{$suffix}", 'correct horse battery');
    ok('signed in before deactivation', $auth->isSignedIn());
    $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int) $player['id']]);
    Auth::forgetCache();
    ok('deactivating signs them out', !$auth->isSignedIn());
    threw('and a deactivated account cannot sign back in', function () use ($auth, $suffix) {
        Auth::forgetCache();
        $auth->login("player_{$suffix}", 'correct horse battery');
    }, 'do not match');

    // -----------------------------------------------------------------------
    section('Registration switch');

    $auth->setRegistrationOpen(true);
    ok('registration can be opened', $auth->registrationOpen());
    $auth->setRegistrationOpen(false);
    ok('and closed', !$auth->registrationOpen());
    $auth->setRegistrationOpen(true);

    ok(
        'the environment is not overriding it in this test run',
        !$auth->registrationLockedByEnv() || getenv('RPG_REGISTRATION') !== false
    );

    // -----------------------------------------------------------------------
    section('Bearer tokens');

    $tokenUser = $auth->register("token_{$suffix}", 'a token password here');
    $issued = $auth->issueToken((int) $tokenUser['id'], 'unity');
    ok('a token is issued as a secret', str_starts_with($issued['token'], 'rpg_') && strlen($issued['token']) > 20);
    $stored = $db->prepare('SELECT token_hash FROM api_tokens WHERE user_id = ?');
    $stored->execute([(int) $tokenUser['id']]);
    $hash = (string) $stored->fetchColumn();
    ok('the secret is not stored', $hash !== '' && !str_contains($hash, $issued['token']));
    ok('and the hash matches', $hash === hash('sha256', $issued['token']));

    $_SESSION = [];
    Auth::forgetCache();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['token'];
    $viaToken = new Auth($db);
    same('a Bearer token signs you in', "token_{$suffix}", $viaToken->currentUser()['username'] ?? null);
    ok('usingToken agrees', $viaToken->usingToken());
    same('and userId is the account', (int) $tokenUser['id'], $viaToken->userId());

    $_SESSION['character_id'] = 4242;
    $_SESSION['party_id'] = 77;
    $viaToken->persistTokenState();
    $_SESSION = [];
    Auth::forgetCache();
    $viaToken2 = new Auth($db);
    $viaToken2->currentUser();
    same('token state restores the active character', 4242, $_SESSION['character_id'] ?? null);
    same('and the party', 77, $_SESSION['party_id'] ?? null);

    Auth::forgetCache();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer rpg_' . str_repeat('ab', 32);
    $bogus = new Auth($db);
    ok('a garbage token is not signed in', !$bogus->isSignedIn());

    unset($_SERVER['HTTP_AUTHORIZATION']);
    Auth::forgetCache();
    $_SESSION = [];
    $auth->login("token_{$suffix}", 'a token password here');
    ok('a cookie session still signs in without a token', $auth->isSignedIn());
    ok('and is not usingToken', !$auth->usingToken());

    $viaToken->logout();
    Auth::forgetCache();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $issued['token'];
    $afterLogout = new Auth($db);
    ok('logout revokes the token', !$afterLogout->isSignedIn());

    $unresolved = $auth->issueToken((int) $tokenUser['id'], 'unity');
    Auth::forgetCache();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $unresolved['token'];
    $neverAsked = new Auth($db);
    $neverAsked->logout();
    Auth::forgetCache();
    $afterUnresolved = new Auth($db);
    ok('logout revokes even if currentUser was never asked', !$afterUnresolved->isSignedIn());
    unset($_SERVER['HTTP_AUTHORIZATION']);
    Auth::forgetCache();

    $second = $auth->issueToken((int) $tokenUser['id'], 'unity');
    $auth->setPassword((int) $tokenUser['id'], 'a changed password here');
    Auth::forgetCache();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $second['token'];
    $afterPw = new Auth($db);
    ok('changing the password revokes tokens', !$afterPw->isSignedIn());
    unset($_SERVER['HTTP_AUTHORIZATION']);
    Auth::forgetCache();

    $live = $auth->issueToken((int) $tokenUser['id'], 'unity');
    $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int) $tokenUser['id']]);
    Auth::forgetCache();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $live['token'];
    $afterOff = new Auth($db);
    ok('a deactivated account cannot use its token', !$afterOff->isSignedIn());
    unset($_SERVER['HTTP_AUTHORIZATION']);
    Auth::forgetCache();
    $db->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([(int) $tokenUser['id']]);

    $expired = $auth->issueToken((int) $tokenUser['id'], 'unity');
    $db->prepare(
        'UPDATE api_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE token_hash = ?'
    )->execute([hash('sha256', $expired['token'])]);
    Auth::forgetCache();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired['token'];
    $afterExp = new Auth($db);
    ok('an expired token is refused', !$afterExp->isSignedIn());
    unset($_SERVER['HTTP_AUTHORIZATION']);
    Auth::forgetCache();

    for ($i = 0; $i < Auth::TOKEN_MAX_LIVE + 1; $i++) {
        $auth->issueToken((int) $tokenUser['id'], 'cap');
    }
    $liveCount = $db->prepare(
        'SELECT COUNT(*) FROM api_tokens
          WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()'
    );
    $liveCount->execute([(int) $tokenUser['id']]);
    same('issuing past the cap revokes the oldest', Auth::TOKEN_MAX_LIVE, (int) $liveCount->fetchColumn());
} finally {
    $db->rollBack();
    $_SESSION = [];
    Auth::forgetCache();
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
