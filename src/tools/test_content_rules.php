<?php
/**
 * Test harness for the content layer: Requirements, and the pure arithmetic
 * Effects, QuestService and CompanionService are built on.
 *
 * Run with `php src/tools/test_content_rules.php`. Like test_rules.php it
 * requires the classes directly rather than bootstrap.php: bootstrap opens a
 * session and defines db(), and a test of "which line of dialogue does this
 * playthrough see" that needs MySQL running is a test nobody will run.
 *
 * Requirements is the whole point of the file. It decides, silently, whether a
 * quest branch exists — a condition that wrongly returns false does not crash,
 * it just quietly removes the option, and the only way that is ever noticed is
 * a test that builds the context by hand and asserts what should be visible.
 *
 * Exits non-zero if anything fails.
 */

declare(strict_types=1);

$lib = dirname(__DIR__) . '/app/lib';

/**
 * bootstrap.php is not loaded, and CharacterGenerator's arithmetic calls this.
 * Same body as the one in bootstrap, deliberately: if the two ever disagree
 * the tests are measuring a game nobody is playing.
 */
function ability_mod(int $score): int
{
    return (int) floor(($score - 10) / 2);
}

require_once $lib . '/Rules.php';
require_once $lib . '/WorldState.php';
require_once $lib . '/Requirements.php';
require_once $lib . '/CharacterGenerator.php';
require_once $lib . '/Effects.php';
require_once $lib . '/CompanionService.php';
// Only DialogEngine::chooseVariant() is exercised, and it is a pure static: no
// PDO handle is built, so requiring the file costs nothing but the parse.
require_once $lib . '/DialogEngine.php';

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
    ok(
        $name,
        $expected === $actual,
        'expected ' . json_encode($expected) . ', got ' . json_encode($actual)
    );
}

function threw(string $name, callable $fn): void
{
    try {
        $fn();
        ok($name, false, 'no exception raised');
    } catch (InvalidArgumentException $e) {
        ok($name, true);
    }
}

function section(string $title): void
{
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
}

/** One condition against one context. */
function holds(array $condition, array $ctx): bool
{
    return Requirements::pass([$condition], $ctx);
}

// ---------------------------------------------------------------------------
// A playthrough part-way through Act 1, built by hand. Every condition below is
// asked of this, so the shape of it is the documentation of what
// WorldState::context() is expected to hand over.

$leader = [
    'id' => 1, 'name' => 'Ser Test', 'race' => 'Half-Orc', 'subrace' => null,
    'class' => 'Fighter', 'background' => 'Soldier', 'level' => 3,
    'strength' => 16, 'dexterity' => 12, 'constitution' => 14,
    'intelligence' => 10, 'wisdom' => 8, 'charisma' => 13,
    'gold' => 120, 'origin_tags' => 'guild_member',
];

$ctx = [
    'party_id' => 7,
    'leader'   => $leader,
    'party'    => [$leader],
    'flags'    => [
        'met_kessa'       => '1',
        'mill_ledger'     => 'burned',
        'goblins_spared'  => '3',
        'nobody_spared'   => '0',
        'empty_flag'      => '',
    ],
    'quests' => [
        'ledger_and_lie' => ['status' => 'active', 'stage' => 'confront_sera', 'resolution' => null],
        'kessa_debt'     => ['status' => 'completed', 'stage' => 'the_debt', 'resolution' => 'debt_settled'],
        'burned_bridge'  => ['status' => 'failed', 'stage' => null, 'resolution' => null],
    ],
    'companions' => [
        'kessa'  => ['status' => 'active', 'approval' => 25, 'romance_stage' => 1, 'romance_locked' => false, 'character_id' => 2],
        'aldric' => ['status' => 'camp', 'approval' => -12, 'romance_stage' => 0, 'romance_locked' => false, 'character_id' => 3],
        'sera'   => ['status' => 'met', 'approval' => 0, 'romance_stage' => 0, 'romance_locked' => false, 'character_id' => null],
        'toran'  => ['status' => 'left', 'approval' => -55, 'romance_stage' => 0, 'romance_locked' => false, 'character_id' => 5],
    ],
    'items'   => ['brass_key' => 0, 'potion_healing' => 1],
    'origins' => WorldState::originTags($leader),
    'gold'    => 120,
    'level'   => 3,
];

// ---------------------------------------------------------------------------
section('An empty condition list');

same('no conditions pass', true, Requirements::pass([], $ctx));
same('a null condition list passes', true, Requirements::pass(null, $ctx));

// ---------------------------------------------------------------------------
section('Flags');

same('a set flag holds', true, holds(['flag' => 'met_kessa'], $ctx));
same('an absent flag does not', false, holds(['flag' => 'never_set'], $ctx));

// The falsiness rule. A counter at zero and a flag cleared to "" must both read
// as absent, or {"flag": "goblins_spared"} passes for a party that spared none.
same('a flag of "0" is false', false, holds(['flag' => 'nobody_spared'], $ctx));
same('a flag of "" is false', false, holds(['flag' => 'empty_flag'], $ctx));
same('a flag of "3" is true', true, holds(['flag' => 'goblins_spared'], $ctx));
same('a label flag is true', true, holds(['flag' => 'mill_ledger'], $ctx));

same('equals matches exactly', true, holds(['flag' => 'mill_ledger', 'equals' => 'burned'], $ctx));
same('equals rejects another value', false, holds(['flag' => 'mill_ledger', 'equals' => 'captain'], $ctx));
same('equals on an absent flag is false', false, holds(['flag' => 'never_set', 'equals' => 'x'], $ctx));
same('equals can match "0" as a value', true, holds(['flag' => 'nobody_spared', 'equals' => '0'], $ctx));

same('at_least compares as a number', true, holds(['flag' => 'goblins_spared', 'at_least' => 3], $ctx));
same('at_least is not satisfied by less', false, holds(['flag' => 'goblins_spared', 'at_least' => 4], $ctx));
same('at_least on an absent flag reads zero', true, holds(['flag' => 'never_set', 'at_least' => 0], $ctx));
same('at_most compares as a number', true, holds(['flag' => 'goblins_spared', 'at_most' => 3], $ctx));
same('at_most rejects a larger counter', false, holds(['flag' => 'goblins_spared', 'at_most' => 2], $ctx));

// ---------------------------------------------------------------------------
section('Combinators');

same('not inverts a passing condition', false, holds(['not' => ['flag' => 'met_kessa']], $ctx));
same('not inverts a failing condition', true, holds(['not' => ['flag' => 'never_set']], $ctx));

same('any is OR', true, holds(['any' => [['flag' => 'never_set'], ['flag' => 'met_kessa']]], $ctx));
same('any of all-false is false', false, holds(['any' => [['flag' => 'never_set'], ['flag' => 'nobody_spared']]], $ctx));
same('all is AND', true, holds(['all' => [['flag' => 'met_kessa'], ['level_at_least' => 2]]], $ctx));
same('all fails on one member', false, holds(['all' => [['flag' => 'met_kessa'], ['level_at_least' => 9]]], $ctx));

same(
    'not wraps any',
    false,
    holds(['not' => ['any' => [['flag' => 'met_kessa'], ['flag' => 'never_set']]]], $ctx)
);
same(
    'any wraps all and not',
    true,
    holds([
        'any' => [
            ['all' => [['flag' => 'never_set'], ['level_at_least' => 1]]],
            ['not' => ['flag' => 'nobody_spared']],
        ],
    ], $ctx)
);
same(
    'three deep',
    true,
    holds([
        'all' => [
            ['not' => ['any' => [['flag' => 'never_set'], ['flag' => 'empty_flag']]]],
            ['any' => [['gold_at_least' => 1000], ['ability' => 'strength', 'at_least' => 15]]],
        ],
    ], $ctx)
);

same('a list of two conditions is AND', true, Requirements::pass([['flag' => 'met_kessa'], ['level_at_least' => 3]], $ctx));
same('a list fails if either fails', false, Requirements::pass([['flag' => 'met_kessa'], ['level_at_least' => 4]], $ctx));

// ---------------------------------------------------------------------------
section('Quests');

same('status matches', true, holds(['quest' => 'ledger_and_lie', 'status' => 'active'], $ctx));
same('status rejects another status', false, holds(['quest' => 'ledger_and_lie', 'status' => 'completed'], $ctx));
same('stage matches the current stage', true, holds(['quest' => 'ledger_and_lie', 'stage' => 'confront_sera'], $ctx));
same('stage rejects a stage already left', false, holds(['quest' => 'ledger_and_lie', 'stage' => 'look_into_it'], $ctx));
same('resolution matches an ending', true, holds(['quest' => 'kessa_debt', 'resolution' => 'debt_settled'], $ctx));
same('resolution rejects another ending', false, holds(['quest' => 'kessa_debt', 'resolution' => 'debt_unpaid'], $ctx));
same('resolution is false while a quest runs', false, holds(['quest' => 'ledger_and_lie', 'resolution' => 'walked_away'], $ctx));
same('a bare quest condition means the party has it', true, holds(['quest' => 'burned_bridge'], $ctx));
same('failed is a status like any other', true, holds(['quest' => 'burned_bridge', 'status' => 'failed'], $ctx));

// An untouched quest has no row, and every form is false for it — including
// "available", which asks whether the party has been offered the job and not
// whether the quest was ever written.
same('an untouched quest is not active', false, holds(['quest' => 'never_taken', 'status' => 'active'], $ctx));
same('an untouched quest is not available', false, holds(['quest' => 'never_taken', 'status' => 'available'], $ctx));
same('an untouched quest is not completed', false, holds(['quest' => 'never_taken', 'status' => 'completed'], $ctx));
same('an untouched quest is on no stage', false, holds(['quest' => 'never_taken', 'stage' => 'anything'], $ctx));
same('an untouched quest has no resolution', false, holds(['quest' => 'never_taken', 'resolution' => 'anything'], $ctx));
same('a bare untouched quest is false', false, holds(['quest' => 'never_taken'], $ctx));
same('not-untouched is how content asks "have you started this"', true, holds(['not' => ['quest' => 'never_taken']], $ctx));

// ---------------------------------------------------------------------------
section('Companions');

same('status matches', true, holds(['companion' => 'kessa', 'status' => 'active'], $ctx));
same('a companion at camp is not active', false, holds(['companion' => 'aldric', 'status' => 'active'], $ctx));
same('met is a status', true, holds(['companion' => 'sera', 'status' => 'met'], $ctx));
same('a bare companion condition means recruited', true, holds(['companion' => 'kessa'], $ctx));
same('camp counts as recruited', true, holds(['companion' => 'aldric'], $ctx));
same('merely met does not count as recruited', false, holds(['companion' => 'sera'], $ctx));
same('one who left does not count as recruited', false, holds(['companion' => 'toran'], $ctx));

same('approval_at_least holds', true, holds(['companion' => 'kessa', 'approval_at_least' => 25], $ctx));
same('approval_at_least rejects a shortfall', false, holds(['companion' => 'kessa', 'approval_at_least' => 26], $ctx));
same('approval_at_most holds', true, holds(['companion' => 'aldric', 'approval_at_most' => -10], $ctx));
same('romance_stage is at-least', true, holds(['companion' => 'kessa', 'romance_stage' => 1], $ctx));
same('romance_stage rejects a later stage', false, holds(['companion' => 'kessa', 'romance_stage' => 2], $ctx));

// Approval on someone who never joined is false, not "zero, which is neutral".
// A line gated on a companion's opinion has no business appearing before they
// are in the party to hold one.
same('approval_at_least on the unrecruited is false', false, holds(['companion' => 'sera', 'approval_at_least' => 0], $ctx));
same('approval_at_most on the unrecruited is false', false, holds(['companion' => 'sera', 'approval_at_most' => 100], $ctx));
same('romance_stage on the unrecruited is false', false, holds(['companion' => 'sera', 'romance_stage' => 0], $ctx));
same('approval on one who left is false', false, holds(['companion' => 'toran', 'approval_at_most' => 0], $ctx));

// A companion no content has ever mentioned to this party.
same('an unknown companion has no status', false, holds(['companion' => 'nobody', 'status' => 'unmet'], $ctx));
same('an unknown companion has no approval', false, holds(['companion' => 'nobody', 'approval_at_least' => -999], $ctx));

// ---------------------------------------------------------------------------
section('Origins, level, gold, items, abilities');

same('race is an origin tag', true, holds(['origin' => 'half_orc'], $ctx));
same('an origin is matched after normalising', true, holds(['origin' => 'Half-Orc'], $ctx));
same('class is an origin tag', true, holds(['origin' => 'fighter'], $ctx));
same('background is an origin tag', true, holds(['origin' => 'soldier'], $ctx));
same('a granted tag is an origin tag', true, holds(['origin' => 'guild_member'], $ctx));
same('another race is not', false, holds(['origin' => 'wood_elf'], $ctx));

same('level_at_least holds at the boundary', true, holds(['level_at_least' => 3], $ctx));
same('level_at_least rejects above', false, holds(['level_at_least' => 4], $ctx));

same('gold_at_least holds', true, holds(['gold_at_least' => 120], $ctx));
same('gold_at_least rejects above', false, holds(['gold_at_least' => 121], $ctx));

same('has_item finds a pooled item', true, holds(['has_item' => 'brass_key'], $ctx));
same('has_item is false for what nobody carries', false, holds(['has_item' => 'silvered_shortsword'], $ctx));

same('ability at_least by long name', true, holds(['ability' => 'strength', 'at_least' => 16], $ctx));
same('ability at_least by short name', true, holds(['ability' => 'str', 'at_least' => 16], $ctx));
same('ability at_least rejects a shortfall', false, holds(['ability' => 'strength', 'at_least' => 17], $ctx));
same('ability at_most holds', true, holds(['ability' => 'wisdom', 'at_most' => 8], $ctx));
same('a bare ability condition passes on any score', true, holds(['ability' => 'charisma'], $ctx));

// Read off the leader: a door that opens because someone waiting outside is
// strong reads as a bug.
$weak = $ctx;
$weak['leader'] = ['strength' => 8] + $leader;
same('the leader is who is measured', false, holds(['ability' => 'strength', 'at_least' => 16], $weak));

$leaderless = $ctx;
$leaderless['leader'] = null;
same('no leader fails an ability gate rather than throwing', false, holds(['ability' => 'strength', 'at_least' => 1], $leaderless));

// ---------------------------------------------------------------------------
section('Unknown vocabulary throws');

threw('an unknown condition key throws', static fn() => holds(['mood' => 'cheerful'], $ctx));
threw('a nested unknown key throws', static fn() => holds(['any' => [['mood' => 'cheerful']]], $ctx));
threw('an empty condition object throws', static fn() => holds([], $ctx));

// ---------------------------------------------------------------------------
section('Variant selection');

$variants = [
    ['conditions' => [['flag' => 'burned_the_mill']], 'text' => 'Out.'],
    ['conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'Kessa. Didn\'t expect you.'],
    ['text' => 'Welcome to the Golden Flagon!'],
];
same('the first passing variant wins', 'Kessa. Didn\'t expect you.', Requirements::firstMatch($variants, $ctx)['text']);

$noKessa = $ctx;
$noKessa['companions']['kessa']['status'] = 'left';
same('the unconditional fallback is last', 'Welcome to the Golden Flagon!', Requirements::firstMatch($variants, $noKessa)['text']);

$burned = $ctx;
$burned['flags']['burned_the_mill'] = '1';
same('an earlier variant takes precedence', 'Out.', Requirements::firstMatch($variants, $burned)['text']);
same('nothing matching is null, not a crash', null, Requirements::firstMatch([['conditions' => [['flag' => 'never_set']]]], $ctx));
same('no variants at all is null', null, Requirements::firstMatch([], $ctx));

// ---------------------------------------------------------------------------
section('Variant pools');

/**
 * One draw, the way DialogEngine::matchVariant() makes it.
 *
 * @return array{0:?int,1:array} the chosen authored index, and the cursor map
 *                               after the draw — the engine writes `next` back
 *                               to a party flag, and this writes it back here.
 */
function drawOnce(array $variants, array $ctx, array $cursors = [], array $retired = []): array
{
    $d = DialogEngine::chooseVariant($variants, $ctx, $retired, $cursors);
    if ($d['pool'] !== null) {
        $cursors[$d['pool']] = $d['next'];
    }
    return [$d['index'], $cursors];
}

/** The indices a party is shown over `$times` consecutive visits. */
function drawSeq(array $variants, array $ctx, int $times, array $cursors = [], array $retired = []): array
{
    $out = [];
    for ($k = 0; $k < $times; $k++) {
        [$i, $cursors] = drawOnce($variants, $ctx, $cursors, $retired);
        $out[] = $i;
    }
    return $out;
}

/**
 * matchVariant() as it stood before pools, restated so the compatibility claim
 * is measured against something rather than asserted.
 *
 * `$seen` stands in for the `seen:` world flags: the authored positions this
 * party has already been shown.
 */
function prePoolMatch(array $variants, array $ctx, array $seen = []): ?int
{
    foreach ($variants as $i => $variant) {
        if (!is_array($variant)) {
            continue;
        }
        if (!empty($variant['once']) && in_array($i, $seen, true)) {
            continue;
        }
        if (Requirements::pass($variant['conditions'] ?? null, $ctx)) {
            return $i;
        }
    }
    return null;
}

/**
 * The `retired` map DialogEngine::matchVariant() hands chooseVariant().
 *
 * Only a `once` variant can retire, so a position in `$seen` that was never
 * marked `once` does nothing — restated here rather than assumed, because the
 * comparison above is only worth anything if both sides are fed what the
 * engine would really feed them.
 */
function retiredMap(array $variants, array $seen): array
{
    $out = [];
    foreach ($variants as $i => $variant) {
        if (is_array($variant) && !empty($variant['once']) && in_array($i, $seen, true)) {
            $out[$i] = true;
        }
    }
    return $out;
}

// A greeting shaped like the ones the four companions actually have: a written
// reaction to a resolution on top, a pool of idle lines under it, and the
// unconditional fallback last.
$greeting = [
    ['conditions' => [['flag' => 'kessa_debt_fate', 'equals' => 'paid']], 'text' => 'paid'],
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'a'],
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'b'],
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'c'],
    ['text' => 'fallback'],
];

// The complaint, and the fix for it: six visits in a settled state used to be
// six of variant [1].
same('a pool rotates in authored order', [1, 2, 3], drawSeq($greeting, $ctx, 3));
same('and wraps round to the top', [1, 2, 3, 1, 2, 3, 1], drawSeq($greeting, $ctx, 7));
same('no line repeats before the pool is exhausted', 3, count(array_unique(drawSeq($greeting, $ctx, 3))));

// Priority is the thing that must not shift. A resolution the player earned
// still pre-empts the whole pool, on every visit, forever.
$paid = $ctx;
$paid['flags']['kessa_debt_fate'] = 'paid';
same('a variant above the pool still wins outright', [0, 0, 0, 0], drawSeq($greeting, $paid, 4));
same('and moves no cursor', [], drawOnce($greeting, $paid)[1]);

// And the fallback below it is still reachable when the pool's own conditions
// fail — a pool is a priority, not a floor.
$gone = $ctx;
$gone['companions']['kessa']['status'] = 'left';
same('the fallback below a pool still matches', [4, 4, 4], drawSeq($greeting, $gone, 3));

// What a draw reports.
$d = DialogEngine::chooseVariant($greeting, $ctx);
same('a pool draw names its pool', 'idle', $d['pool']);
same('and the cursor to store', 1, $d['next']);
$plain = DialogEngine::chooseVariant($greeting, $paid);
same('a plain draw names no pool', null, $plain['pool']);
same('and has no cursor to store', null, $plain['next']);

// A cursor is party state in a text column, and it outlives the pool it was
// written for. None of these may throw or select nothing.
same('a cursor past the end wraps', 2, DialogEngine::chooseVariant($greeting, $ctx, [], ['idle' => 7])['index']);
same('a cursor far past the end wraps', 1, DialogEngine::chooseVariant($greeting, $ctx, [], ['idle' => 999])['index']);
same('a negative cursor rotates rather than crashing', 3, DialogEngine::chooseVariant($greeting, $ctx, [], ['idle' => -1])['index']);
same('a cursor for a pool that is gone is ignored', 1, DialogEngine::chooseVariant($greeting, $ctx, [], ['weather' => 4])['index']);

// The release case: a save sits at cursor 2 of a three-line pool, and the next
// release cuts the pool to two. The stored cursor is now past the end.
$shrunk = [
    $greeting[0],
    $greeting[1],
    $greeting[2],
    $greeting[4],
];
same('a shrinking pool does not strand a live cursor', 1, DialogEngine::chooseVariant($shrunk, $ctx, [], ['idle' => 2])['index']);
same('and rotation continues from there', [1, 2, 1], drawSeq($shrunk, $ctx, 3, ['idle' => 2]));

// The opposite release case: an author adds a line, and the save picks it up.
$grown = $greeting;
array_splice($grown, 4, 0, [['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'd']]);
same('a growing pool is picked up mid-save', [3, 4, 1], drawSeq($grown, $ctx, 3, ['idle' => 2]));

// `once` retires a pool member like any other variant, and the rest close up.
// This is how a pool holds a line that fires the first time round and then
// never again — the "we have had this conversation" pattern, inside a rotation.
$greetingOnce = $greeting;
$greetingOnce[1]['once'] = true;
$greetingOnce[2]['once'] = true;
same('a pool member fires before it retires', [1, 2, 3], drawSeq($greetingOnce, $ctx, 3));
same('a retired pool member is skipped', [1, 3, 1, 3], drawSeq($greetingOnce, $ctx, 4, [], retiredMap($greetingOnce, [2])));
same('retiring all but one leaves that one', [3, 3, 3], drawSeq($greetingOnce, $ctx, 3, [], retiredMap($greetingOnce, [1, 2])));
same('a position never marked once does not retire', [1, 2, 3], drawSeq($greetingOnce, $ctx, 3, [], retiredMap($greetingOnce, [3])));

// Members may differ in their conditions; one gated on a flag simply joins the
// rotation when the flag sets, without the author restating the pool.
$conditional = [
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'a'],
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active'], ['flag' => 'deep_known']], 'text' => 'b'],
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'c'],
    ['text' => 'fallback'],
];
same('a member whose conditions fail is out of the rotation', [0, 2, 0, 2], drawSeq($conditional, $ctx, 4));
$deep = $ctx;
$deep['flags']['deep_known'] = '1';
same('and joins it when they pass', [0, 1, 2, 0], drawSeq($conditional, $deep, 4));

// Two pools in one node are two priorities, resolved the same way two plain
// variants are: whichever has the earlier live member.
$twoPools = [
    ['pool' => 'hurt', 'conditions' => [['flag' => 'wounded']], 'text' => 'h1'],
    ['pool' => 'hurt', 'conditions' => [['flag' => 'wounded']], 'text' => 'h2'],
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'i1'],
    ['pool' => 'idle', 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'i2'],
    ['text' => 'fallback'],
];
same('the lower pool runs when the upper one cannot', [2, 3, 2], drawSeq($twoPools, $ctx, 3));
$hurt = $ctx;
$hurt['flags']['wounded'] = '1';
same('the upper pool pre-empts the lower one', [0, 1, 0], drawSeq($twoPools, $hurt, 3));
// Each pool keeps its own place, so a state that comes and goes does not reset
// the state it interrupted.
[, $cur] = drawOnce($twoPools, $ctx);              // idle -> 1
[, $cur] = drawOnce($twoPools, $hurt, $cur);       // hurt -> 1, idle untouched
same('one pool advancing leaves the other where it was', ['idle' => 1, 'hurt' => 1], $cur);
same('and the interrupted pool resumes', 3, DialogEngine::chooseVariant($twoPools, $ctx, [], $cur)['index']);

same('nothing matching is a null index', null, DialogEngine::chooseVariant([['conditions' => [['flag' => 'never_set']]]], $ctx)['index']);
same('an empty variant list is a null index', null, DialogEngine::chooseVariant([], $ctx)['index']);

// --- the compatibility contract ------------------------------------------
//
// 30 shipped dialogue files and a live save depend on a node without pools
// selecting exactly what it selected before pools existed. Asserted by running
// the same lists through both, rather than by reading the new one and agreeing
// with it.
$noPoolNodes = [
    'plain fallback only'   => [['text' => 'only']],
    'conditional then fall' => [
        ['conditions' => [['flag' => 'burned_the_mill']], 'text' => 'out'],
        ['conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'kessa'],
        ['text' => 'welcome'],
    ],
    'a once on top'         => [
        ['once' => true, 'conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'first time'],
        ['conditions' => [['companion' => 'kessa', 'status' => 'active']], 'text' => 'after'],
        ['text' => 'welcome'],
    ],
    'nothing matches'       => [['conditions' => [['flag' => 'never_set']], 'text' => 'no']],
    'deep conditions'       => [
        ['conditions' => [['all' => [['level_at_least' => 3], ['not' => ['flag' => 'never_set']]]]], 'text' => 'deep'],
        ['text' => 'welcome'],
    ],
    'a non-array member'    => [null, ['conditions' => [['level_at_least' => 99]], 'text' => 'no'], ['text' => 'yes']],
];
$contexts = ['as given' => $ctx, 'kessa gone' => $gone, 'debt paid' => $paid, 'wounded' => $hurt];
foreach ($noPoolNodes as $name => $variants) {
    foreach ($contexts as $cname => $c) {
        foreach ([[], [0], [0, 1]] as $seen) {
            $label = 'no-pool node is unchanged: ' . $name . ' / ' . $cname
                   . ($seen ? ' / shown ' . implode(',', $seen) : '');
            same(
                $label,
                prePoolMatch($variants, $c, $seen),
                DialogEngine::chooseVariant($variants, $c, retiredMap($variants, $seen))['index']
            );
        }
    }
}
// And writes nothing, which is the other half of "unchanged": no cursor, so no
// world flag, so no behaviour that depends on one.
foreach ($noPoolNodes as $name => $variants) {
    same('no-pool node moves no cursor: ' . $name, [], drawOnce($variants, $ctx)[1]);
}

// ---------------------------------------------------------------------------
section('Variant pools against the shipped corpus');

// The tables above are toys. This is the claim that actually matters — that
// every node in every authored dialogue file which does not use a pool selects
// exactly the variant it selected before pools existed — and it is measured on
// the corpus rather than argued about, because the corpus is what a live save
// is halfway through.
//
// Files are read straight off disk. That is the one place this harness touches
// anything outside app/lib, and it is worth it: a compatibility guarantee
// asserted only against six hand-written examples is a guarantee about six
// hand-written examples.

$dialogDir = dirname(__DIR__) . '/content/dialog';
$mine = ['kessa', 'aldric', 'ilse', 'sera'];

/** Four playthroughs far enough apart to route through different variants. */
$corpusContexts = [
    'early'   => (static function (array $c) {
        $c['flags'] = [];
        $c['quests'] = [];
        $c['companions'] = [];
        $c['level'] = 1;
        $c['gold'] = 0;
        return $c;
    })($ctx),
    'mid'     => $ctx,
    'resolved' => (static function (array $c) {
        $c['flags'] += ['kessa_debt_fate' => 'paid', 'ledger_fate' => 'reed',
                        'hills_fate' => 'escort', 'quarry_fate' => 'pact',
                        'deep_shaft_fate' => 'sealed', 'sera_leash_fate' => 'cut',
                        'aldric_fate' => 'confessed', 'met_ilse' => '1',
                        'met_aldric' => '1', 'met_sera' => '1'];
        foreach (['kessa', 'aldric', 'ilse', 'sera'] as $k) {
            $c['companions'][$k] = ['status' => 'active', 'approval' => 35,
                                    'romance_stage' => 0, 'romance_locked' => false,
                                    'character_id' => 9];
        }
        $c['level'] = 5;
        $c['gold'] = 400;
        return $c;
    })($ctx),
    'ruined'  => (static function (array $c) {
        $c['flags'] += ['quarry_fate' => 'ash', 'ledger_fate' => 'sold',
                        'hills_fate' => 'ambush', 'sera_arrested' => '1',
                        'kessa_debt_fate' => 'sold'];
        foreach (['kessa', 'aldric', 'ilse', 'sera'] as $k) {
            $c['companions'][$k] = ['status' => 'left', 'approval' => -30,
                                    'romance_stage' => 0, 'romance_locked' => false,
                                    'character_id' => null];
        }
        return $c;
    })($ctx),
];

$corpus = ['files' => 0, 'nodes' => 0, 'poolNodes' => 0, 'pools' => 0,
           'checked' => 0, 'skipped' => 0, 'mineSkipped' => 0];
/** @var array<string,int> dialogue key => how many pools it declares. */
$poolsPerFile = [];

foreach (glob($dialogDir . '/*.json') ?: [] as $path) {
    $key = basename($path, '.json');
    $doc = json_decode((string) file_get_contents($path), true);
    if (!is_array($doc) || !isset($doc['nodes'])) {
        continue;
    }
    $corpus['files']++;
    foreach ($doc['nodes'] as $nodeKey => $node) {
        $variants = array_is_list($node) ? $node : [$node];
        $corpus['nodes']++;
        $pools = [];
        foreach ($variants as $v) {
            if (is_array($v) && isset($v['pool'])) {
                $pools[(string) $v['pool']] = true;
            }
        }

        foreach ($corpusContexts as $cname => $c) {
            try {
                $draw = DialogEngine::chooseVariant($variants, $c);
                $was  = $pools ? null : prePoolMatch($variants, $c);
            } catch (Throwable $e) {
                // Another agent's file mid-edit, or a condition the loader has
                // not accepted yet. Counted rather than failed, so that one
                // half-written file does not take the whole harness down —
                // except in the four this change actually rewrote, where a
                // throw is a bug I put there.
                $corpus['skipped']++;
                if (in_array($key, $mine, true)) {
                    $corpus['mineSkipped']++;
                }
                continue;
            }
            $corpus['checked']++;

            if (!$pools) {
                if ($draw['index'] !== $was) {
                    ok("corpus: {$key}:{$nodeKey} / {$cname} unchanged", false,
                       'was ' . json_encode($was) . ', now ' . json_encode($draw['index']));
                }
                if ($draw['pool'] !== null) {
                    ok("corpus: {$key}:{$nodeKey} / {$cname} moves no cursor", false,
                       'reported pool ' . json_encode($draw['pool']));
                }
                continue;
            }

            // A pool node: rotate it right round twice and check every line it
            // can produce is one this playthrough is actually allowed to hear.
            $corpus['poolNodes']++;
            $cursors = [];
            for ($k = 0; $k < 2 * count($variants) + 3; $k++) {
                $d = DialogEngine::chooseVariant($variants, $c, [], $cursors);
                if ($d['index'] === null) {
                    break;
                }
                $chosen = $variants[$d['index']];
                if (!Requirements::pass($chosen['conditions'] ?? null, $c)) {
                    ok("corpus: {$key}:{$nodeKey} / {$cname} draws only live variants", false,
                       'drew ' . $d['index'] . ' whose conditions fail');
                    break;
                }
                // Priority: whatever it draws must sit at the same rung as the
                // first live variant — the same pool, or that variant itself.
                $firstLive = prePoolMatch($variants, $c);
                $expectPool = $variants[$firstLive]['pool'] ?? null;
                if (($chosen['pool'] ?? null) !== $expectPool) {
                    ok("corpus: {$key}:{$nodeKey} / {$cname} keeps priority", false,
                       'first live variant is ' . json_encode($expectPool)
                       . ', drew ' . json_encode($chosen['pool'] ?? null));
                    break;
                }
                if ($d['pool'] !== null) {
                    $cursors[$d['pool']] = $d['next'];
                }
            }
        }

        foreach (array_keys($pools) as $poolName) {
            $corpus['pools']++;
            $poolsPerFile[$key] = ($poolsPerFile[$key] ?? 0) + 1;
            // Contiguity, which the loader also enforces. Restated here because
            // the engine's "gather by name" only reads the way the file reads
            // if the block is a block.
            $at = [];
            foreach ($variants as $i => $v) {
                if (is_array($v) && ($v['pool'] ?? null) === $poolName) {
                    $at[] = $i;
                }
            }
            ok("corpus: {$key}:{$nodeKey} pool '{$poolName}' is contiguous",
               $at === range($at[0], $at[count($at) - 1]),
               'members at ' . implode(',', $at));
            ok("corpus: {$key}:{$nodeKey} pool '{$poolName}' has siblings",
               count($at) > 1, 'one member rotates with itself');
            foreach ($at as $i) {
                ok("corpus: {$key}:{$nodeKey} pool '{$poolName}' member {$i} is conditional",
                   isset($variants[$i]['conditions']),
                   'an unconditional member would displace the fallback');
            }
        }
    }
}

ok('corpus: dialogue files were found and read', $corpus['files'] >= 20,
   $corpus['files'] . ' files');
ok('corpus: every no-pool node still selects what it always did',
   $corpus['checked'] > 500, $corpus['checked'] . ' node/context pairs checked');
// Named per file rather than counted in total: a merge that silently drops one
// companion's rotation would still leave the total looking healthy.
foreach ($mine as $who) {
    ok("corpus: {$who} rotates their opening",
       ($poolsPerFile[$who] ?? 0) >= 1, 'no pool in that file at all');
}
ok('corpus: no companion file throws while being selected from',
   $corpus['mineSkipped'] === 0, $corpus['mineSkipped'] . ' throws in kessa/aldric/ilse/sera');
printf(
    "       %d files, %d nodes, %d node/context pairs, %d pools, %d skipped%s",
    $corpus['files'], $corpus['nodes'], $corpus['checked'],
    $corpus['pools'], $corpus['skipped'], PHP_EOL
);

// ---------------------------------------------------------------------------
section('Origin tags');

same('spaces become underscores', 'folk_hero', WorldState::tag('Folk Hero'));
same('hyphens become underscores', 'half_orc', WorldState::tag('Half-Orc'));
same('already-normalised is unchanged', 'rogue', WorldState::tag('rogue'));
same('surrounding space is trimmed', 'wood_elf', WorldState::tag('  Wood Elf  '));

// Rules::LEGACY_BACKGROUNDS lets 'Guild Artisan' claim Artisan's SKILLS, and it
// must stop there. The origin tag is derived from the string as stored, and
// three authored dialogue choices gate on `guild_artisan` — so if the alias ever
// grew into a rename of the column, that content would close with nothing said.
same('a legacy background still tags as itself', 'guild_artisan', WorldState::tag('Guild Artisan'));
same('and Folk Hero does not become Farmer', 'folk_hero', WorldState::tag('Folk Hero'));

same(
    'tags come from race, class and background',
    ['half_orc', 'fighter', 'soldier', 'guild_member'],
    WorldState::originTags($leader)
);
same(
    'a subrace is its own tag',
    ['elf', 'wood_elf', 'ranger'],
    WorldState::originTags(['race' => 'Elf', 'subrace' => 'Wood Elf', 'class' => 'Ranger'])
);
same(
    'duplicates collapse',
    ['human', 'soldier'],
    WorldState::originTags(['race' => 'Human', 'background' => 'Soldier', 'origin_tags' => 'soldier, human'])
);
same('a character with nothing has no tags', [], WorldState::originTags([]));

// ---------------------------------------------------------------------------
section('Experience and levels');

same('nothing is level 1', 1, Rules::levelForXp(0));
same('one short of 300 is still level 1', 1, Rules::levelForXp(299));
same('300 is level 2', 2, Rules::levelForXp(300));
same('900 is level 3', 3, Rules::levelForXp(900));
same('2700 is level 4', 4, Rules::levelForXp(2700));
same('6500 is level 5', 5, Rules::levelForXp(6500));
same('14000 is level 6', 6, Rules::levelForXp(14000));
// The campaign stops at 6, and an award that would carry further stops there.
same('the ladder ends at the campaign ceiling', Rules::MAX_LEVEL, Rules::levelForXp(999999));
// A single large award has to be able to carry a character two levels, which
// is the difference between recomputing from the total and stepping once.
same('one award can cross two thresholds', 3, Rules::levelForXp(0 + 950));

same('a fighter has a d10', 10, Rules::hitDie('Fighter'));
same('a barbarian has a d12', 12, Rules::hitDie('Barbarian'));
same('a wizard has a d6', 6, Rules::hitDie('Wizard'));
same('class lookup is case-insensitive', 8, Rules::hitDie('rogue'));
same('an unknown class gets a d8', 8, Rules::hitDie('Artificer'));

same('a level is half the die, plus one, plus CON', 7, Rules::hpPerLevel(10, 1));
same('a d6 caster with no CON gains 4', 4, Rules::hpPerLevel(6, 0));
same('a punishing CON never takes HP away', 1, Rules::hpPerLevel(6, -5));

// ---------------------------------------------------------------------------
section('Building a character from a template');

$halfOrc = ['name' => 'Half-Orc', 'subrace' => null, 'speed' => 30,
            'str_bonus' => 2, 'dex_bonus' => 0, 'con_bonus' => 1,
            'int_bonus' => 0, 'wis_bonus' => 0, 'cha_bonus' => 0];
$kessa = ['strength' => 16, 'dexterity' => 13, 'constitution' => 15,
          'intelligence' => 9, 'wisdom' => 11, 'charisma' => 12];
$raised = CharacterGenerator::racialAbilities($kessa, $halfOrc);
same('a racial bonus is added', 18, $raised['strength']);
same('a second racial bonus is added', 16, $raised['constitution']);
same('an ability with no bonus is untouched', 13, $raised['dexterity']);

$capped = CharacterGenerator::racialAbilities(['strength' => 19] + $kessa, $halfOrc);
same('racial bonuses stop at 20', 20, $capped['strength']);

$halfElf = ['name' => 'Half-Elf', 'subrace' => null, 'speed' => 30,
            'str_bonus' => 0, 'dex_bonus' => 0, 'con_bonus' => 0,
            'int_bonus' => 0, 'wis_bonus' => 0, 'cha_bonus' => 2];
$he = CharacterGenerator::racialAbilities(['strength' => 10, 'dexterity' => 12, 'constitution' => 12,
                                           'intelligence' => 10, 'wisdom' => 10, 'charisma' => 14], $halfElf);
same('half-elf charisma comes from the table', 16, $he['charisma']);
same('half-elf gets its free +1 DEX', 13, $he['dexterity']);
same('half-elf gets its free +1 CON', 13, $he['constitution']);

same('level 1 HP is the whole die plus CON', 12, CharacterGenerator::hitPointsAtLevel(10, 2, 1));
same('a hill dwarf gets one more per level', 13, CharacterGenerator::hitPointsAtLevel(10, 2, 1, 'Hill Dwarf'));
// d12 barbarian, +3 CON: 15 at first level, then 10 for each of two more.
same('level 3 adds two averaged levels', 35, CharacterGenerator::hitPointsAtLevel(12, 3, 3));
same('HP never lands below 1', 1, CharacterGenerator::hitPointsAtLevel(6, -5, 1));
same('level 0 is treated as level 1', 12, CharacterGenerator::hitPointsAtLevel(10, 2, 0));

$abilities = ['strength' => 16, 'dexterity' => 14, 'constitution' => 16, 'wisdom' => 12];
same('unarmoured AC is 10 plus DEX', 12, CharacterGenerator::unarmoredAc('Fighter', $abilities));
same('a barbarian adds CON as well', 15, CharacterGenerator::unarmoredAc('Barbarian', $abilities));
same('a monk adds WIS as well', 13, CharacterGenerator::unarmoredAc('Monk', $abilities));
same('missing scores are treated as 10', 10, CharacterGenerator::unarmoredAc('Fighter', []));

// ---------------------------------------------------------------------------
section('Merging effect results');

$blank = Effects::blank();
same('a blank result has every key', true, array_key_exists('level_ups', $blank) && array_key_exists('travel', $blank));
same('a blank result ends nothing', false, $blank['end_dialog']);

$a = Effects::blank();
$a['messages'][] = 'first';
$a['flags_set']['x'] = '1';
$a['approval']['kessa'] = 5;
$a['combat'] = 'mill_ambush';

$b = Effects::blank();
$b['messages'][] = 'second';
$b['flags_set']['y'] = null;
$b['approval']['kessa'] = -2;
$b['approval']['aldric'] = 3;
$b['combat'] = 'a_different_fight';
$b['end_dialog'] = true;
$b['quests_started'][] = 'ledger_and_lie';

$m = Effects::merge($a, $b);
same('messages keep their order', ['first', 'second'], $m['messages']);
same('flags accumulate', ['x' => '1', 'y' => null], $m['flags_set']);
// Two effects moving the same companion in one choice is ordinary authoring,
// and the player should feel the sum of them and not the last one.
same('approval deltas sum', 3, $m['approval']['kessa']);
same('a second companion is kept', 3, $m['approval']['aldric']);
// Two fights in one list is a content bug either way; the first at least is
// the one the author wrote first.
same('the first fight wins', 'mill_ambush', $m['combat']);
same('ending the dialogue is sticky', true, $m['end_dialog']);
same('lists concatenate', ['ledger_and_lie'], $m['quests_started']);
same('an unset scalar stays null', null, $m['shop']);

$late = Effects::blank();
$late['shop'] = 'tobin';
same('a scalar set only on the right is taken', 'tobin', Effects::merge($m, $late)['shop']);
same('merging a blank changes nothing', $m, Effects::merge($m, Effects::blank()));

// ---------------------------------------------------------------------------
section('Approval thresholds');

$companion = ['leave_at' => -40, 'hostile_at' => -70, 'romance_at' => 30];

same('a small change crosses nothing', null, CompanionService::thresholdCrossed(10, 5, $companion));
same('falling to leave_at is a departure', 'leave', CompanionService::thresholdCrossed(-35, -40, $companion));
same('falling past leave_at is a departure', 'leave', CompanionService::thresholdCrossed(-20, -50, $companion));
same('falling to hostile_at is worse', 'hostile', CompanionService::thresholdCrossed(-60, -70, $companion));
// Announced on the change that crossed it, and not again: a companion sitting
// below the line should not threaten to leave every time anyone speaks.
same('already below the line crosses nothing', null, CompanionService::thresholdCrossed(-50, -55, $companion));
same('already hostile crosses nothing', null, CompanionService::thresholdCrossed(-80, -90, $companion));
// One change that passes both is someone leaving, not someone in love.
same('a fall past both is hostile', 'hostile', CompanionService::thresholdCrossed(0, -100, $companion));
same('rising to romance_at opens the arc', 'romance', CompanionService::thresholdCrossed(29, 30, $companion));
same('rising further crosses nothing new', null, CompanionService::thresholdCrossed(30, 60, $companion));
same('climbing back over leave_at crosses nothing', null, CompanionService::thresholdCrossed(-50, -10, $companion));

$defaults = [];
same('thresholds have defaults', 'leave', CompanionService::thresholdCrossed(0, -40, $defaults));

// ---------------------------------------------------------------------------
echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
