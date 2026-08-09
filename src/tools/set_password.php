<?php
/**
 * Set an account's password from the command line.
 *
 * The migration that introduced roles promoted the existing owner of every
 * character to administrator and left their password_hash NULL on purpose: a
 * default password shipped in a migration is a published password, and that row
 * would otherwise be a live administrator with a known credential from the
 * moment the file ran. Auth::login() refuses an account with no hash, so the
 * account does not work until somebody runs this.
 *
 *     docker compose exec -T php php /var/www/html/tools/set_password.php admin
 *     docker compose exec -T php php /var/www/html/tools/set_password.php admin --show
 *
 * With no password argument it reads one from the terminal with echo turned
 * off, so it does not end up in shell history or in `ps`. A password may be
 * passed as a second argument for scripting, which is faster and less private.
 *
 * Also: --role=admin|user to change a role, and --create to make the account if
 * it does not exist.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This is a command line tool.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, static fn ($a) => str_starts_with($a, '--')));
$rest  = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));

$username = $rest[0] ?? '';
$password = $rest[1] ?? null;

$create = in_array('--create', $flags, true);
$show   = in_array('--show', $flags, true);
$role   = null;
foreach ($flags as $f) {
    if (str_starts_with($f, '--role=')) {
        $role = substr($f, 7);
    }
}

if ($username === '') {
    fwrite(STDERR, "usage: set_password.php <username> [password] [--create] [--role=admin|user] [--show]\n\n");
    $rows = db()->query('SELECT username, role, is_active, password_hash IS NOT NULL AS has_password FROM users ORDER BY id')
        ->fetchAll();
    fwrite(STDERR, "accounts:\n");
    foreach ($rows as $r) {
        fwrite(STDERR, sprintf(
            "  %-20s %-6s %s%s\n",
            $r['username'],
            $r['role'],
            $r['has_password'] ? 'has a password' : 'NO PASSWORD — cannot sign in',
            (int) $r['is_active'] === 1 ? '' : '  (deactivated)'
        ));
    }
    exit(1);
}

/** Read a password without echoing it. */
function prompt_secret(string $label): string
{
    fwrite(STDOUT, $label);
    // `stty -echo` is the portable-enough answer here; the fallback is a visible
    // password, which is better than refusing to run.
    $usedStty = false;
    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'win') === false) {
        $before = shell_exec('stty -g 2>/dev/null');
        if ($before !== null && trim((string) $before) !== '') {
            shell_exec('stty -echo 2>/dev/null');
            $usedStty = true;
        }
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($usedStty) {
        shell_exec('stty ' . trim((string) $before) . ' 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return $value;
}

$db = db();
$auth = new Auth($db);

$stmt = $db->prepare('SELECT id, username, role FROM users WHERE username = ?');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user && !$create) {
    fwrite(STDERR, "No account named '{$username}'. Pass --create to make one.\n");
    exit(1);
}

if ($password === null) {
    $password = prompt_secret("New password for {$username}: ");
    $again = prompt_secret('Again: ');
    if ($password !== $again) {
        fwrite(STDERR, "Those did not match.\n");
        exit(1);
    }
}

try {
    if (!$user) {
        $created = $auth->register($username, $password, '', $role ?: Auth::ROLE_USER);
        printf("Created %s (%s).\n", $created['username'], $created['role']);
    } else {
        $auth->setPassword((int) $user['id'], $password);
        printf("Password set for %s.\n", $user['username']);
        if ($role !== null) {
            if (!in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_USER], true)) {
                fwrite(STDERR, "Unknown role '{$role}'.\n");
                exit(1);
            }
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, (int) $user['id']]);
            printf("Role is now %s.\n", $role);
        }
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($show) {
    printf("(password was: %s)\n", $password);
}
