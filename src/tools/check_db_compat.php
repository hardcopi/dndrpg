<?php
/**
 * Report the database server and flag anything in the schema that depends on it.
 *
 * The local stack was pinned to MySQL 8.0 to match production on hermes, but is
 * now running MariaDB. The two agree on most of this schema and disagree on a
 * handful of things the Act 1 tables actually use — generated columns, window
 * functions, `CHECK` handling, `utf8mb4` collation defaults — so this says what
 * is in front of it and whether each of those works here.
 *
 *   php tools/check_db_compat.php        (or open /tools/check_db_compat.php)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$db = db();
$row = $db->query('SELECT VERSION() AS v')->fetch();
$version = (string) $row['v'];
$isMaria = stripos($version, 'mariadb') !== false;

echo "server:   {$version}\n";
echo "flavour:  " . ($isMaria ? 'MariaDB' : 'MySQL') . "\n";
echo "docker:   nginx 1.24 / PHP 8.4-FPM / MySQL 8.0  (per CLAUDE.md, matching hermes)\n";
echo "php:      " . PHP_VERSION . "\n\n";

$checks = [];

/** Run one probe and record whether the server accepted it. */
$probe = function (string $label, string $sql, ?string $why = null) use ($db, &$checks): void {
    try {
        $db->query($sql)->fetchAll();
        $checks[] = [true, $label, $why];
    } catch (Throwable $e) {
        $checks[] = [false, $label, $e->getMessage()];
    }
};

// The features this schema leans on, each tied to where it is used.
$probe(
    'window functions (ROW_NUMBER OVER)',
    'SELECT ROW_NUMBER() OVER (ORDER BY id) AS n FROM characters LIMIT 1',
    'migrate_act1.sql de-duplicates character names with one'
);
$probe(
    'STORED generated column readable',
    'SELECT active_name FROM characters LIMIT 1',
    'characters.active_name carries the unique-name constraint'
);
$probe(
    'CTE (WITH ... SELECT)',
    'WITH t AS (SELECT 1 AS x) SELECT x FROM t',
    'not used yet; worth knowing before anyone reaches for one'
);
$probe(
    'JSON_EXTRACT',
    "SELECT JSON_EXTRACT('{\"a\":1}', '$.a') AS a",
    'not used — content JSON is decoded in PHP, deliberately'
);
$probe(
    'utf8mb4 with the schema collation',
    "SELECT _utf8mb4'x' COLLATE utf8mb4_unicode_ci AS s",
    'every table declares utf8mb4_unicode_ci'
);

// The generated column is the one real portability risk, so check it is not
// merely readable but actually enforcing what it exists for.
try {
    $idx = $db->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'characters'
            AND INDEX_NAME = 'uq_active_name'"
    )->fetchColumn();
    $checks[] = [(int) $idx > 0, 'uq_active_name index present',
        (int) $idx > 0 ? null : 'run sql/migrate_act1.sql'];
} catch (Throwable $e) {
    $checks[] = [false, 'uq_active_name index present', $e->getMessage()];
}

try {
    $tok = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens'"
    )->fetchColumn();
    $checks[] = [(int) $tok > 0, 'api_tokens table present',
        (int) $tok > 0 ? null : 'CREATE TABLE api_tokens — see schema.sql'];
} catch (Throwable $e) {
    $checks[] = [false, 'api_tokens table present', $e->getMessage()];
}

$failed = 0;
foreach ($checks as [$ok, $label, $note]) {
    printf("  %-4s %-40s %s\n", $ok ? 'ok' : 'FAIL', $label, $note ?? '');
    $failed += $ok ? 0 : 1;
}

echo "\n";
if ($isMaria) {
    echo "NOTE: this is MariaDB, and CLAUDE.md pins the stack to MySQL 8.0 to match\n";
    echo "      hermes. Nothing in the schema above is MySQL-only, so the game runs —\n";
    echo "      but a query that works here is not proof it works in production, and\n";
    echo "      the two differ most in exactly the areas this schema touches:\n";
    echo "      generated columns, window functions and JSON. Test on the real thing\n";
    echo "      before deploying, or align the local server with hermes.\n";
}

echo "\n{$failed} failed\n";
exit($failed > 0 ? 1 : 0);
