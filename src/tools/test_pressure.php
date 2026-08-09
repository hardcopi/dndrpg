<?php
/**
 * The Concern's ledger: one number that only rises, and the flags it crosses.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_pressure.php`.
 *
 * Four things are guarded here, and each of them is a decision from
 * docs/CONCERN_PRESSURE.md rather than an implementation detail:
 *
 *   - **It never comes down.** Every route to the number is checked: a
 *     non-positive delta is refused at ConcernPressure::raise and again through
 *     the `{"pressure": n}` effect, and a threshold flag once set is never
 *     taken off by a later raise. tools/load_content.py holds the other half —
 *     content cannot reach the flag with `set_flag`, `clear_flag` or
 *     `increment_flag` — and this asserts the database is clean of those too,
 *     because the content editors write rows the loader never sees.
 *
 *   - **The thresholds are still payable.** The engine names three bands and
 *     content prices the acts; nothing connects the two, so raising a threshold
 *     past what the corpus charges would leave a whole band of authored
 *     reactions that no playthrough can reach. That fails here rather than in
 *     somebody's game, where it looks like writing that was never wired up.
 *
 *   - **The prices are prices.** Every `{"pressure": n}` in the authored quests
 *     is a small positive integer. A stage that charged fifty would carry a
 *     party through all three bands in one act.
 *
 *   - **The flags land where content reads them**, which is `world_flags`,
 *     party-scoped, like every other fact about a playthrough.
 *
 * Scratch rows inside a transaction it rolls back. Safe against a database
 * somebody is playing on.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$pass = 0;
$fail = 0;

function ok(string $n, bool $c, string $d = ''): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "  ok   $n\n";
    } else {
        $fail++;
        echo "  FAIL $n" . ($d ? "\n         $d" : '') . "\n";
    }
}

/** @return array{0:bool,1:string} whether $fn threw the wanted exception */
function threw(callable $fn, string $class): array
{
    try {
        $fn();
    } catch (Throwable $e) {
        return [$e instanceof $class, get_class($e) . ': ' . $e->getMessage()];
    }
    return [false, 'nothing was thrown'];
}

// ---------------------------------------------------------------------------
echo "\nThe scale itself\n";

$thresholds = ConcernPressure::THRESHOLDS;
$totals = array_keys($thresholds);
$ascending = $totals;
sort($ascending);
ok('there are thresholds at all', $thresholds !== []);
// Declared in order and read in order: flagsAt() walks the array as written,
// and crossings are reported in that order, so a band added out of sequence
// would arrive announced after one it is below.
ok('threshold totals ascend', $totals === $ascending
    && count(array_unique($totals)) === count($totals), json_encode($totals));
ok('every threshold total is positive', !array_filter(
    $totals, static fn ($v) => $v < 1
));
ok('threshold flags are distinct', count(array_unique($thresholds)) === count($thresholds));
foreach ($thresholds as $at => $flag) {
    ok("'{$flag}' is a well-formed flag key", (bool) preg_match('/^[a-z0-9_]+$/', $flag));
    ok("'{$flag}' is not the counter", $flag !== ConcernPressure::FLAG);
    ok("'{$flag}' sets at {$at} and not below",
        in_array($flag, ConcernPressure::flagsAt($at), true)
        && !in_array($flag, ConcernPressure::flagsAt($at - 1), true));
}
ok('ceiling() is the last threshold',
    ConcernPressure::ceiling() === max(array_keys($thresholds)));

// Monotone, which is the whole of "it never comes down" expressed as
// arithmetic: what a party has earned at n is still earned at n + 1.
$monotone = true;
for ($n = 0; $n <= ConcernPressure::ceiling() + 3; $n++) {
    $monotone = $monotone
        && !array_diff(ConcernPressure::flagsAt($n), ConcernPressure::flagsAt($n + 1));
}
ok('flagsAt() never loses a flag as the number rises', $monotone);
ok('flagsAt(0) is empty', ConcernPressure::flagsAt(0) === []);
ok('flagsAt() past the ceiling is every flag',
    ConcernPressure::flagsAt(ConcernPressure::ceiling() + 100)
    === array_values($thresholds));

$engine = ConcernPressure::engineFlags();
ok('engineFlags() names the counter', in_array(ConcernPressure::FLAG, $engine, true));
ok('engineFlags() names every threshold',
    !array_diff(array_values($thresholds), $engine));
ok('engineFlags() repeats nothing', count($engine) === count(array_unique($engine)));

// ---------------------------------------------------------------------------
echo "\nThe ratchet\n";

$db->beginTransaction();
try {
    $moduleId = (int) $db->query('SELECT id FROM modules ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, 'pressure probe')")
        ->execute([$moduleId]);
    $partyId = (int) $db->lastInsertId();

    $world = new WorldState($db);

    ok('a new party owes nothing', $world->number($partyId, ConcernPressure::FLAG) === 0);
    ok('and carries none of the threshold flags', !array_filter(
        array_values($thresholds),
        static fn (string $f) => $world->isSet($partyId, $f)
    ));

    [$refused, $why] = threw(
        static fn () => ConcernPressure::raise($world, $partyId, 0),
        InvalidArgumentException::class
    );
    ok('raise(0) is refused', $refused, $why);
    [$refused, $why] = threw(
        static fn () => ConcernPressure::raise($world, $partyId, -4),
        InvalidArgumentException::class
    );
    ok('raise(-4) is refused', $refused, $why);
    ok('a refused raise moved nothing',
        $world->number($partyId, ConcernPressure::FLAG) === 0);

    // Walk the whole scale one point at a time and check each flag arrives on
    // the step it is declared for, is reported exactly once, and never leaves.
    $reported = [];
    $everSeen = [];
    $stillSet = true;
    for ($n = 1; $n <= ConcernPressure::ceiling() + 2; $n++) {
        $moved = ConcernPressure::raise($world, $partyId, 1);
        if ($moved['total'] !== $n) {
            ok("total after {$n} raises of 1", false, 'got ' . $moved['total']);
            break;
        }
        foreach ($moved['crossed'] as $flag) {
            $reported[$flag] = ($reported[$flag] ?? 0) + 1;
            $everSeen[] = $flag;
        }
        // Everything crossed so far is on the party, now and at every later step.
        foreach ($everSeen as $flag) {
            $stillSet = $stillSet && $world->isSet($partyId, $flag);
        }
    }
    ok('the total is the sum of the raises',
        $world->number($partyId, ConcernPressure::FLAG) === ConcernPressure::ceiling() + 2);
    ok('every threshold was crossed exactly once',
        array_keys($reported) === array_values($thresholds)
        && !array_filter($reported, static fn ($c) => $c !== 1),
        json_encode($reported));
    ok('a crossed flag is still set at every later total', $stillSet);

    // A crossing is reported by the raise that causes it and by no other, so a
    // caller cannot act on the same threshold twice.
    $moved = ConcernPressure::raise($world, $partyId, 20);
    ok('a raise past the top crosses nothing new', $moved['crossed'] === []);

    // One big raise skips no flag: a stage worth 5 to a party at 0 must still
    // set the band it passes through.
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, 'pressure probe 2')")
        ->execute([$moduleId]);
    $secondId = (int) $db->lastInsertId();
    $moved = ConcernPressure::raise($world, $secondId, ConcernPressure::ceiling());
    ok('one raise to the ceiling crosses every band at once',
        $moved['crossed'] === array_values($thresholds), json_encode($moved));

    // ------------------------------------------------------------------
    echo "\nThe effect content writes\n";

    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, 'pressure probe 3')")
        ->execute([$moduleId]);
    $thirdId = (int) $db->lastInsertId();
    $effects = new Effects($db);

    $first = array_key_first($thresholds);
    $r = $effects->apply($thirdId, [['pressure' => $first]]);
    ok('{"pressure": n} reports the new total',
        ($r['flags_set'][ConcernPressure::FLAG] ?? null) === (string) $first, json_encode($r['flags_set']));
    ok('and reports the flag it crossed',
        ($r['flags_set'][$thresholds[$first]] ?? null) === '1', json_encode($r['flags_set']));
    ok('and says nothing to the player about it', $r['messages'] === [],
        json_encode($r['messages']));

    // Effects holds its own WorldState; a second one must see the write, or a
    // condition evaluated later in the same request would miss the threshold.
    ok('the flag is really in world_flags',
        (new WorldState($db))->isSet($thirdId, $thresholds[$first]));

    $r = $effects->apply($thirdId, [['pressure' => 1]]);
    ok('a second charge adds to the first',
        ($r['flags_set'][ConcernPressure::FLAG] ?? null) === (string) ($first + 1));

    [$refused, $why] = threw(
        static fn () => $effects->apply($thirdId, [['pressure' => -2]]),
        InvalidArgumentException::class
    );
    ok('a negative price is refused through the effect too', $refused, $why);

    // ------------------------------------------------------------------
    echo "\nWhat the database is allowed to write\n";

    // The loader refuses content that writes an engine flag, but the content
    // editors write rows it never sees. Walk everything that carries effects.
    $owned = [];
    foreach (array_merge([ConcernPressure::FLAG], array_values($thresholds),
                         [DelveEngine::DEPTH_FLAG]) as $f) {
        $owned[$f] = true;
    }
    $offenders = [];
    $walkEffects = function ($node, string $where) use (&$walkEffects, $owned, &$offenders): void {
        if (!is_array($node)) {
            return;
        }
        foreach (['set_flag', 'clear_flag', 'increment_flag'] as $verb) {
            if (isset($node[$verb]) && is_string($node[$verb]) && isset($owned[$node[$verb]])) {
                $offenders[] = "{$where}: {$verb} {$node[$verb]}";
            }
        }
        foreach ($node as $child) {
            $walkEffects($child, $where);
        }
    };
    foreach ($db->query(
        'SELECT q.quest_key, s.stage_key, s.effects_json FROM quest_stages s
         INNER JOIN quests q ON q.id = s.quest_id WHERE s.effects_json IS NOT NULL'
    ) as $row) {
        $walkEffects(json_decode((string) $row['effects_json'], true),
              "quest {$row['quest_key']}/{$row['stage_key']}");
    }
    foreach ($db->query(
        'SELECT npc_key, dialogue_json FROM npcs WHERE dialogue_json IS NOT NULL'
    ) as $row) {
        $walkEffects(json_decode((string) $row['dialogue_json'], true), "dialogue {$row['npc_key']}");
    }
    ok('no loaded content writes a flag the engine owns', $offenders === [],
        implode('; ', array_slice($offenders, 0, 5)));
} finally {
    $db->rollBack();
}

// ---------------------------------------------------------------------------
echo "\nThe authored ledger\n";

// Read from content/, not from the database: this is a claim about what ships,
// and the database may be mid-playthrough with an older import in it.
$questDir = dirname(__DIR__) . '/content/quests';
$prices = [];       // quest_key => [stage_key => price]
$badPrices = [];
foreach (glob($questDir . '/*.json') ?: [] as $path) {
    $doc = json_decode((string) file_get_contents($path), true);
    if (!is_array($doc)) {
        ok('parsed ' . basename($path), false);
        continue;
    }
    foreach (($doc['stages'] ?? []) as $stageKey => $stage) {
        foreach (($stage['effects'] ?? []) as $effect) {
            if (!isset($effect['pressure'])) {
                continue;
            }
            $n = $effect['pressure'];
            if (!is_int($n) || $n < 1 || $n > 5) {
                $badPrices[] = "{$doc['quest_key']}/{$stageKey} = " . json_encode($n);
            }
            $prices[(string) $doc['quest_key']][(string) $stageKey] =
                ($prices[(string) $doc['quest_key']][(string) $stageKey] ?? 0) + (int) $n;
        }
    }
}

ok('some quest charges the Concern for something', $prices !== []);
ok('every price is a small positive integer', $badPrices === [], implode('; ', $badPrices));

// A quest resolves once, so the most a single playthrough can be charged is the
// dearest ending of each quest that has one. If that will not reach the top
// band, the top band is writing nobody can get to.
$dearest = 0;
foreach ($prices as $stages) {
    $dearest += max($stages);
}
ok("the dearest playthrough ({$dearest}) reaches the top band ("
   . ConcernPressure::ceiling() . ')',
   $dearest >= ConcernPressure::ceiling(),
   'lower a threshold, or price more of the acts that cost the Concern something');

// And it must not be so close that only a completionist ever crosses. Half the
// reachable total is an arbitrary line, but a top band that needs 90% of every
// hostile ending in the game is a band nobody will see.
ok('the top band is not the whole ledger',
   ConcernPressure::ceiling() <= intdiv($dearest, 2),
   "dearest {$dearest}, ceiling " . ConcernPressure::ceiling());

// ---------------------------------------------------------------------------
echo "\nWhat reads the bands\n";

// A threshold nobody wrote a reaction for is a number that changes nothing.
// tools/trace_content.py reports this too, and reports it alongside a hundred
// and fifty findings of older standing, which is a signal easy to lose; here it
// is a failure.
$readers = [];
$walkConditions = function ($node) use (&$walkConditions, &$readers): void {
    if (!is_array($node)) {
        return;
    }
    if (isset($node['flag']) && is_string($node['flag'])) {
        $readers[$node['flag']] = ($readers[$node['flag']] ?? 0) + 1;
    }
    foreach ($node as $child) {
        $walkConditions($child);
    }
};
$contentDir = dirname(__DIR__) . '/content';
foreach (glob($contentDir . '/*/*.json') ?: [] as $path) {
    $walkConditions(json_decode((string) file_get_contents($path), true));
}
foreach ($thresholds as $at => $flag) {
    ok("something in content/ reacts to '{$flag}'", ($readers[$flag] ?? 0) > 0,
        'the band sets and the world does not notice');
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
