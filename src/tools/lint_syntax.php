<?php
/**
 * Does every PHP file in this tree actually parse?
 *
 * WHY THIS EXISTS
 *
 * It did not, and verify.sh said 41 passed. Two files — sheet_print.php and
 * adventure_print.php — carried
 *
 *     . '<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">'
 *
 * where a search-and-replace had rewritten a plain HTML link tag that happened
 * to be sitting INSIDE a single-quoted PHP string. The string ends at
 * `href="<?= asset('`, the parser then meets a bare `assets`, and the file is
 * dead. It shipped, and the first anyone knew was a 500 from the live site
 * when somebody tried to print a character sheet.
 *
 * Nothing in the suite would ever have caught it. lint_php.py is a
 * cross-reference checker and says so in its own docstring — "It is not a
 * parser" — and every other PHP harness runs one file and exercises the
 * engine, never the pages. So the forty PHP files a player actually requests
 * were the only ones nothing looked at, and a syntax error in any of them was
 * invisible until it was a 500.
 *
 * HOW
 *
 * `token_get_all($src, TOKEN_PARSE)` runs the real parser and throws
 * ParseError on anything it cannot read — the same answer as `php -l` without
 * spawning a process per file, which matters when the interpreter is on the
 * far side of `docker compose exec`. One process for the whole tree.
 *
 * What it does NOT do is decide whether the code is sensible. A file that
 * parses can still be wrong in every way; this only asserts that PHP can read
 * it, which is the floor and was missing.
 *
 *     php tools/lint_syntax.php          # every file, quiet unless broken
 *     php tools/lint_syntax.php --list   # name each file as it passes
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$verbose = in_array('--list', $argv, true);

/* Everything a request can reach, plus the tools themselves. Vendor
   directories are excluded because a dependency that does not parse is its
   author's bug and not ours to report on every run. */
$dirs = ['', 'app', 'app/lib', 'app/config', 'app/inc', 'api', 'tools'];
$files = [];
foreach ($dirs as $d) {
    $path = $root . ($d === '' ? '' : '/' . $d);
    foreach (glob($path . '/*.php') ?: [] as $f) {
        $files[realpath($f)] = true;
    }
}
$files = array_keys($files);
sort($files);

$bad = [];
foreach ($files as $f) {
    $src = file_get_contents($f);
    if ($src === false) {
        $bad[] = [$f, 0, 'unreadable'];
        continue;
    }
    try {
        token_get_all($src, TOKEN_PARSE);
        if ($verbose) {
            printf("  ok   %s\n", substr($f, strlen($root) + 1));
        }
    } catch (ParseError $e) {
        $bad[] = [$f, $e->getLine(), $e->getMessage()];
    }
}

printf("%d PHP file(s) parsed\n", count($files));

if ($bad) {
    printf("\n%d FILE(S) DO NOT PARSE:\n", count($bad));
    foreach ($bad as [$f, $line, $msg]) {
        printf("  %s:%d\n      %s\n", substr($f, strlen($root) + 1), $line, $msg);
    }
    exit(1);
}

echo "all parse\n";
exit(0);
