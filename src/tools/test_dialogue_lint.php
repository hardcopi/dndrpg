<?php
/**
 * Test harness for DialogueLint.
 *
 * Served over HTTP like the other harnesses — there is no PHP CLI on this host:
 *
 *     curl -s http://localhost:8081/tools/test_dialogue_lint.php | tail -5
 *
 * Two halves, and the second is the one that matters.
 *
 * The first builds small broken documents by hand and asserts the linter says
 * the right thing about each. That proves the rules fire.
 *
 * The second runs the linter across EVERY conversation in the live database and
 * asserts it finds nothing. That proves the rules do not fire when they should
 * not — which is the failure mode a linter actually dies of. `load_content.py`
 * already passes on this corpus, so any error here is either a real fault the
 * loader misses or, far more likely, a rule transcribed wrongly into PHP. Both
 * are worth knowing before anybody trusts the markers on a graph.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

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
    ok($name, $expected === $actual,
        'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

/** Does any finding's message contain this fragment? */
function has(array $findings, string $fragment): bool
{
    foreach ($findings as $f) {
        if (strpos($f['message'], $fragment) !== false) {
            return true;
        }
    }
    return false;
}

function levels(array $findings, string $level): array
{
    return array_values(array_filter($findings, static fn ($f) => $f['level'] === $level));
}

$lint = new DialogueLint();

// A minimal document that must lint clean. Everything below is this, bent.
$good = [
    'start' => 'greeting',
    'nodes' => [
        'greeting' => [
            'text'    => 'Hello.',
            'choices' => [
                ['label' => 'Tell me more.', 'next' => 'more'],
                ['label' => 'Goodbye.', 'end' => true],
            ],
        ],
        'more' => [
            'text'    => 'There is more.',
            'choices' => [['label' => 'Back.', 'next' => 'greeting']],
        ],
    ],
];

// ---------------------------------------------------------------------------
section('A sound conversation is left alone');

same('the good document is clean', [], $lint->check($good));

// ---------------------------------------------------------------------------
section('Dangling links');

$d = $good;
$d['nodes']['greeting']['choices'][0]['next'] = 'nowhere';
ok('a next naming no node is an error', has($lint->check($d), "no node 'nowhere' in this file"));

$d = $good;
$d['nodes']['greeting']['choices'][0] = [
    'label' => 'Try it.',
    'check' => ['skill' => 'persuasion', 'dc' => 12,
                'on_success' => 'more', 'on_failure' => 'missing'],
];
ok('a check branch naming no node is an error', has($lint->check($d), "no node 'missing' in this file"));

$d = $good;
$d['start'] = 'absent';
ok('a start that is not a node is an error', has($lint->check($d), "start node 'absent' is not in `nodes`"));

// ---------------------------------------------------------------------------
section('Reachability');

$d = $good;
$d['nodes']['stranded'] = ['text' => 'Nobody comes here.', 'choices' => [['label' => 'Oh.', 'end' => true]]];
ok('a node nothing jumps to is unreachable', has($lint->check($d), "unreachable from 'greeting'"));

// The camp subtree is opened directly by the camp screen and is named by no
// `next` anywhere. Walking only from `start` would condemn all of it.
$d = $good;
$d['nodes']['camp'] = ['text' => 'The fire is low.', 'choices' => [['label' => 'Sit.', 'end' => true]]];
same('camp is a root, not an orphan', [], $lint->check($d));

$d = $good;
$d['nodes']['parley_here'] = ['text' => 'Hold.', 'choices' => [['label' => 'Fine.', 'end' => true]]];
$withParley = new DialogueLint(['parley_here']);
same('a parley node is a root', [], $withParley->check($d));
ok('...and is an orphan without that root', has($lint->check($d), 'unreachable'));

// ---------------------------------------------------------------------------
section('Variant order and the fallback');

$d = $good;
$d['nodes']['greeting'] = [
    ['conditions' => [['flag' => 'a']], 'text' => 'A.', 'choices' => [['label' => 'x', 'next' => 'more']]],
    ['text' => 'Fallback.', 'choices' => [['label' => 'x', 'next' => 'more']]],
];
same('conditional then fallback is fine', [], $lint->check($d));

$d['nodes']['greeting'] = [
    ['text' => 'Fallback first.', 'choices' => [['label' => 'x', 'end' => true]]],
    ['conditions' => [['flag' => 'a']], 'text' => 'Never seen.', 'choices' => [['label' => 'x', 'end' => true]]],
];
ok('an unconditional variant before the end is an error',
   has($lint->check($d), 'an unconditional variant must come last'));

$d['nodes']['greeting'] = [
    ['conditions' => [['flag' => 'a']], 'text' => 'A.', 'choices' => [['label' => 'x', 'end' => true]]],
    ['conditions' => [['flag' => 'b']], 'text' => 'B.', 'choices' => [['label' => 'x', 'end' => true]]],
];
ok('every variant conditional is an error', has($lint->check($d), 'every variant is conditional'));

// A spent `once` variant steps aside, so it does not count as the fallback.
$d['nodes']['greeting'] = [
    ['once' => true, 'text' => 'One time.', 'choices' => [['label' => 'x', 'next' => 'more']]],
    ['text' => 'Ever after.', 'choices' => [['label' => 'x', 'next' => 'more']]],
];
same('a once variant does not swallow the rest', [], $lint->check($d));

// ---------------------------------------------------------------------------
section('Shadowing');

$shadow = static function (array $variants) use ($good): array {
    $d = $good;
    $d['nodes']['greeting'] = $variants;
    return $d;
};
$go = [['label' => 'x', 'next' => 'more']];
$tail = ['text' => 'Fallback.', 'choices' => $go];

// The whole rule in one document: {a} is satisfied wherever {a,b} is, so the
// second variant is written and never spoken.
$d = $shadow([
    ['conditions' => [['flag' => 'a']], 'text' => 'Broad.', 'choices' => $go],
    ['conditions' => [['flag' => 'a'], ['flag' => 'b']], 'text' => 'Narrow.', 'choices' => $go],
    $tail,
]);
ok('a broader variant above a narrower one is an error',
   has($lint->check($d), 'variant 0 above it is satisfied wherever this one is'));

// The same two, the right way round. Specific first is how the shipped files
// are authored and it must stay silent.
same('narrow above broad is fine', [], $lint->check($shadow([
    ['conditions' => [['flag' => 'a'], ['flag' => 'b']], 'text' => 'Narrow.', 'choices' => $go],
    ['conditions' => [['flag' => 'a']], 'text' => 'Broad.', 'choices' => $go],
    $tail,
])));

same('unrelated conditions do not shadow', [], $lint->check($shadow([
    ['conditions' => [['flag' => 'a']], 'text' => 'A.', 'choices' => $go],
    ['conditions' => [['flag' => 'b']], 'text' => 'B.', 'choices' => $go],
    $tail,
])));

// A lower threshold is met wherever a higher one is, so this shadows even
// though the two predicates are not identical.
ok('a lower *_at_least above a higher one is an error',
   has($lint->check($shadow([
       ['conditions' => [['approval_at_least' => 20]], 'text' => 'Warm.', 'choices' => $go],
       ['conditions' => [['approval_at_least' => 40]], 'text' => 'Warmer.', 'choices' => $go],
       $tail,
   ])), 'variant 0 above it is satisfied wherever this one is'));

same('a higher *_at_least above a lower one is fine', [], $lint->check($shadow([
    ['conditions' => [['approval_at_least' => 40]], 'text' => 'Warmer.', 'choices' => $go],
    ['conditions' => [['approval_at_least' => 20]], 'text' => 'Warm.', 'choices' => $go],
    $tail,
])));

// A spent `once` steps aside and a pooled variant rotates, so neither is ever
// the permanent winner — the same reasoning the fallback rule applies.
same('a once variant does not shadow', [], $lint->check($shadow([
    ['once' => true, 'conditions' => [['flag' => 'a']], 'text' => 'First time.', 'choices' => $go],
    ['conditions' => [['flag' => 'a'], ['flag' => 'b']], 'text' => 'After.', 'choices' => $go],
    $tail,
])));

same('a pooled variant does not shadow', [], $lint->check($shadow([
    ['pool' => 'idle', 'conditions' => [['flag' => 'a']], 'text' => 'Idle.', 'choices' => $go],
    ['pool' => 'idle', 'conditions' => [['flag' => 'a']], 'text' => 'Idle two.', 'choices' => $go],
    ['conditions' => [['flag' => 'a'], ['flag' => 'b']], 'text' => 'After.', 'choices' => $go],
    $tail,
])));

// The unconditional case belongs to checkVariantOrder. It must not be reported
// twice under two different names.
$both = levels($lint->check($shadow([
    ['text' => 'Fallback first.', 'choices' => $go],
    ['conditions' => [['flag' => 'a']], 'text' => 'Never.', 'choices' => $go],
])), DialogueLint::ERROR);
same('an unconditional variant is one finding, not two', 1, count($both));

// Predicate order within a condition list is not significance — the engine ANDs
// them — so the same two conditions written the other way round still shadow.
ok('condition order does not hide a shadow',
   has($lint->check($shadow([
       ['conditions' => [['flag' => 'a']], 'text' => 'Broad.', 'choices' => $go],
       ['conditions' => [['flag' => 'b'], ['flag' => 'a']], 'text' => 'Narrow.', 'choices' => $go],
       $tail,
   ])), 'can never be shown'));

// A different value on the same key is a different gate, and proving they
// exclude each other needs the condition vocabulary this linter deliberately
// does not carry. Conservative: say nothing.
same('same key, different value is not treated as a shadow', [], $lint->check($shadow([
    ['conditions' => [['flag' => 'fate', 'equals' => 'ash']], 'text' => 'Ash.', 'choices' => $go],
    ['conditions' => [['flag' => 'fate', 'equals' => 'water']], 'text' => 'Water.', 'choices' => $go],
    $tail,
])));

// ---------------------------------------------------------------------------
section('Flag wiring');

// Given no world index the check stands down entirely: an empty write set and
// "nobody told me" are the same value and only one is a finding.
$d = $good;
$d['nodes']['greeting']['conditions'] = [['flag' => 'nothing_sets_this']];
$d['nodes']['greeting'] = [$d['nodes']['greeting'], $tail];
same('no world index means no flag findings', [],
     levels($lint->check($d), DialogueLint::WARNING));

$wired = new DialogueLint([], [], ['known_flag'], ['known_flag']);

ok('a condition on a flag nothing sets warns',
   has($wired->check($d), "no effect anywhere sets the flag 'nothing_sets_this'"));
same('...and only warns', 0, count(levels($wired->check($d), DialogueLint::ERROR)));

$d = $good;
$d['nodes']['greeting']['conditions'] = [['flag' => 'known_flag']];
$d['nodes']['greeting'] = [$d['nodes']['greeting'], $tail];
same('a condition on a flag the world sets is fine', [], $wired->check($d));

// Negated and `any`-nested reads count: a gate that cannot pass is worth the
// same finding whichever way round it is written.
$d = $good;
$d['nodes']['greeting']['conditions'] = [['not' => ['flag' => 'never_set']]];
$d['nodes']['greeting'] = [$d['nodes']['greeting'], $tail];
ok('a negated read is still a read', has($wired->check($d), "sets the flag 'never_set'"));

$d = $good;
$d['nodes']['greeting']['conditions'] = [['any' => [['flag' => 'never_set'], ['flag' => 'known_flag']]]];
$d['nodes']['greeting'] = [$d['nodes']['greeting'], $tail];
ok('an `any` read is still a read', has($wired->check($d), "sets the flag 'never_set'"));

// The other direction.
$d = $good;
$d['nodes']['greeting']['on_enter'] = [['set_flag' => 'nobody_reads_this']];
ok('an effect nothing reads warns',
   has($wired->check($d), "the flag 'nobody_reads_this' is set here and no condition anywhere reads it"));

$d = $good;
$d['nodes']['greeting']['on_enter'] = [['set_flag' => 'known_flag']];
same('an effect the world reads is fine', [], $wired->check($d));

// A gate and the effect that opens it, added in one edit. Neither is in the
// database yet, so both must be read out of the document itself or a legal
// save reports two faults.
$d = $good;
$d['nodes']['greeting']['on_enter'] = [['set_flag' => 'brand_new']];
$d['nodes']['more']['conditions'] = [['flag' => 'brand_new']];
$d['nodes']['more'] = [$d['nodes']['more'], ['text' => 'Or not.', 'choices' => [['label' => 'x', 'next' => 'greeting']]]];
same('a flag written and read in the same unsaved document is fine', [], $wired->check($d));

// ---------------------------------------------------------------------------
section('Pools');

$mk = static function (array $variants) use ($good): array {
    $d = $good;
    $d['nodes']['greeting'] = $variants;
    return $d;
};
$cond = [['flag' => 'active']];
$pooled = static fn (string $t) => ['pool' => 'idle', 'conditions' => $cond, 'text' => $t,
                                    'choices' => [['label' => 'x', 'next' => 'more']]];
$fallback = ['text' => 'Fallback.', 'choices' => [['label' => 'x', 'next' => 'more']]];

same('a contiguous pool is fine', [],
     $lint->check($mk([$pooled('one'), $pooled('two'), $fallback])));

$split = $mk([
    $pooled('one'),
    ['conditions' => [['flag' => 'other']], 'text' => 'Interloper.', 'choices' => [['label' => 'x', 'end' => true]]],
    $pooled('two'),
    $fallback,
]);
ok('a split pool is an error', has($lint->check($split), "pool 'idle' is split by variant(s) [1]"));

ok('a pool of one warns',
   has($lint->check($mk([$pooled('lonely'), $fallback])), 'rotates between itself and itself'));

$unconditioned = $mk([
    ['pool' => 'idle', 'text' => 'No conditions.', 'choices' => [['label' => 'x', 'end' => true]]],
    $pooled('two'),
    $fallback,
]);
ok('an unconditional pooled variant is an error',
   has($lint->check($unconditioned), 'a pooled variant needs `conditions`'));

// ---------------------------------------------------------------------------
section('Fields and exits');

$d = $good;
$d['nodes']['greeting']['mood'] = 'wistful';
ok('an unknown node field is an error', has($lint->check($d), "unknown field 'mood'"));

$d = $good;
unset($d['nodes']['greeting']['text']);
ok('a node with no text is an error', has($lint->check($d), "missing required field 'text'"));

$d = $good;
$d['nodes']['greeting']['choices'][0] = ['label' => 'Both.', 'next' => 'more', 'end' => true];
ok('next and end together is an error', has($lint->check($d), 'together — a choice leads one way'));

$d = $good;
$d['nodes']['greeting']['choices'][0] = [
    'label' => 'Roll.',
    'check' => ['skill' => 'flattery', 'dc' => 10, 'on_success' => 'more', 'on_failure' => 'more'],
];
ok('a non-SRD skill is an error', has($lint->check($d), "'flattery' is not an SRD skill"));

$d = $good;
$d['nodes']['greeting']['choices'][0]['tag'] = 'ROGUE';
ok('a tag with no conditions warns', has($lint->check($d), 'advertises a requirement that is not enforced'));
same('...and only warns', 0, count(levels($lint->check($d), DialogueLint::ERROR)));

// A choice carrying only effects is legal — Mara's "A room, please" is one.
$d = $good;
$d['nodes']['greeting']['choices'][] = ['label' => 'A room, please.', 'effects' => [['rest' => 'inn']]];
same('a choice with only effects is allowed', [], $lint->check($d));

// ---------------------------------------------------------------------------
section('Expressions');

$busts = new DialogueLint([], ['ranger' => 8, 'innkeeper' => 1]);
$d = $good;
$d['nodes']['greeting']['expression'] = 8;
same('an expression within the cut range is fine', [], $busts->check($d, 'ranger'));
$d['nodes']['greeting']['expression'] = 4;
ok('an expression past the cut range is an error',
   has($busts->check($d, 'innkeeper'), "expression 4 is outside 1..1 for sprite_key 'innkeeper'"));
same('...and is skipped when no sprite is given', [], $busts->check($d, null));

// ---------------------------------------------------------------------------
section('Both node shapes lint');

same('a bare object node is a single variant', 1, count($lint->variantsOf(['text' => 'x'])));
same('a list node is its variants', 2, count($lint->variantsOf([['text' => 'a'], ['text' => 'b']])));
same('conditions on a bare node are an error', true,
     has($lint->check((static function () use ($good) {
         $d = $good;
         $d['nodes']['more']['conditions'] = [['flag' => 'x']];
         return $d;
     })()), 'only a variant may carry conditions'));

// ---------------------------------------------------------------------------
section('The shipped corpus lints clean');

// The real test. load_content.py --check passes on this content, so the linter
// must agree; anything it reports is a rule that was copied across wrongly.
$busts = [];
$bustFile = dirname(__DIR__) . '/assets/images/npcs/busts.json';
if (is_file($bustFile)) {
    $busts = json_decode((string) file_get_contents($bustFile), true) ?: [];
}

$parley = [];
foreach (db()->query("SELECT parley_node FROM encounters WHERE parley_node IS NOT NULL AND parley_node <> ''")
             ->fetchAll(PDO::FETCH_COLUMN) as $ref) {
    $bits = explode(':', (string) $ref, 2);
    if (count($bits) === 2) {
        $parley[$bits[0]][] = $bits[1];
    }
}

// The flag wiring check stands down without a world index, so the corpus pass
// has to be given the real one or it silently covers everything except that
// rule. It comes from ContentEditor rather than being gathered again here: a
// second copy of the list of columns would agree with itself and prove nothing.
$flags = (new ContentEditor(db()))->flagIndex();

$rows = db()->query(
    "SELECT npc_key, sprite_key, dialogue_json FROM npcs
      WHERE dialogue_json IS NOT NULL AND dialogue_json <> '' ORDER BY npc_key"
)->fetchAll();

$checked = 0;
$noisy = [];
$deadGates = [];
$deadEffects = 0;
foreach ($rows as $row) {
    $doc = json_decode((string) $row['dialogue_json'], true);
    if (!is_array($doc)) {
        continue;
    }
    $key = (string) $row['npc_key'];
    $l = new DialogueLint($parley[$key] ?? [], $busts, $flags['written'], $flags['read']);
    $found = $l->check($doc, (string) ($row['sprite_key'] ?? ''));
    $errors = levels($found, DialogueLint::ERROR);
    $checked++;
    if ($errors) {
        $noisy[] = $key . ': ' . $errors[0]['where'] . ' — ' . $errors[0]['message'];
    }
    foreach (levels($found, DialogueLint::WARNING) as $w) {
        if (strpos($w['message'], 'no effect anywhere sets the flag') !== false) {
            $deadGates[] = $key . ': ' . $w['where'] . ' — ' . $w['message'];
        } elseif (strpos($w['message'], 'no condition anywhere reads it') !== false) {
            $deadEffects++;
        }
    }
}

ok('every shipped conversation was linted', $checked > 50, "only {$checked} checked");
ok('no shipped conversation reports an error', $noisy === [],
   implode(' | ', array_slice($noisy, 0, 4)) . (count($noisy) > 4 ? ' …+' . (count($noisy) - 4) : ''));

ok('the world index is populated', $flags['written'] && $flags['read'],
   sprintf('%d written, %d read', count($flags['written']), count($flags['read'])));

// A dead gate is content that can never be seen, so it is held at zero the way
// an error is. The other direction is not: a flag set and read by nothing is
// often a deliberate breadcrumb, so it is counted and printed rather than
// asserted on — a number that moves is worth a look, a number that fails the
// build would just be commented out.
ok('no shipped conversation gates on a flag nothing sets', $deadGates === [],
   implode(' | ', array_slice($deadGates, 0, 3))
   . (count($deadGates) > 3 ? ' …+' . (count($deadGates) - 3) : ''));

echo "        (for information: {$deadEffects} flags set that no condition reads)" . PHP_EOL;

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);
