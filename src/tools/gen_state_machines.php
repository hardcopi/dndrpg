<?php
/**
 * Write docs/STATE_MACHINES.md and assets/js/state-machines.js from the
 * machine tables in app/lib (StateMachines::all()).
 *
 *   php tools/gen_state_machines.php           write both files
 *   php tools/gen_state_machines.php --check   exit 1 if either is stale (verify.sh)
 *   php tools/gen_state_machines.php --json    print every machine as JSON (for agents)
 *
 * Needs no database: the tables are plain PHP.
 */

declare(strict_types=1);

$src = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($src): void {
    $f = $src . '/app/lib/' . $class . '.php';
    if (is_file($f)) {
        require_once $f;
    }
});

$mode = $argv[1] ?? '';
if ($mode === '--json') {
    echo json_encode(StateMachines::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

$targets = [
    dirname($src) . '/docs/STATE_MACHINES.md' => StateMachines::markdown(),
    $src . '/assets/js/state-machines.js'     => StateMachines::javascript(),
];

$stale = 0;
foreach ($targets as $path => $body) {
    $have = is_file($path) ? file_get_contents($path) : null;
    if ($mode === '--check') {
        if ($have !== $body) {
            echo "STALE  {$path} — run: php src/tools/gen_state_machines.php\n";
            $stale++;
        } else {
            echo "ok     {$path}\n";
        }
        continue;
    }
    if ($have !== $body) {
        file_put_contents($path, $body);
        echo "wrote  {$path}\n";
    } else {
        echo "same   {$path}\n";
    }
}
exit($stale ? 1 : 0);
