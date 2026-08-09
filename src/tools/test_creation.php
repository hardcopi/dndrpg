<?php
/**
 * Exercises the rules the character creator depends on.
 *
 * Two things the wizard makes claims about, both of which used to be wrong in a
 * way nothing would have caught:
 *
 * 1. The standard array is a *permutation* of 15/14/13/12/10/8. The old creator
 *    offered six independent dropdowns each holding all six numbers, and the
 *    server accepted whatever arrived — so 15 in every ability was two clicks
 *    away and produced a legal-looking character.
 *
 * 2. The review step promises an armour class. Creation equips the class kit and
 *    recalculates, so a review that printed a bare 10 + Dex was contradicted by
 *    the character sheet one click later. startingAc has to agree with
 *    recalculateAC for the one case creation produces — body armour, no shield.
 *
 * Needs a database, because the armour numbers are rows rather than constants.
 * Every character it creates is rolled back, so this leaves nothing behind.
 * Run in a browser at /tools/test_creation.php or `php tools/test_creation.php`.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$db = db();
$gen = new CharacterGenerator($db);

$pass = 0;
$fail = 0;
$check = function (bool $ok, string $what) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    echo "  FAIL  {$what}\n";
};

/** Run $fn with anything it writes discarded. */
$sandboxed = function (callable $fn) use ($db) {
    $db->beginTransaction();
    try {
        return $fn();
    } finally {
        $db->rollBack();
    }
};

$standard = ['strength' => 15, 'dexterity' => 14, 'constitution' => 13,
             'intelligence' => 12, 'wisdom' => 10, 'charisma' => 8];

// array_replace, not `+`: the union operator keeps the LEFT operand's value for
// a duplicate key, so `$defaults + $over` silently discards every override. The
// first version of this harness did exactly that and reported four passing tests
// that had never run — it was creating the valid standard array every time.
$make = function (array $over = []) use ($gen, $standard) {
    return $gen->create(array_replace([
        'name' => 'Harness ' . bin2hex(random_bytes(4)),
        'race' => 'Human',
        'class' => 'Fighter',
        'method' => 'standard',
        'abilities' => $standard,
        'background' => 'Soldier',
        'alignment' => 'Neutral',
        'sprite_key' => 'fighter',
    ], $over));
};

// -------------------------------------------------------------------------
echo "The standard array must be a permutation\n";

$rejects = [
    'six 15s' => array_fill_keys(array_keys($standard), 15),
    'two 15s, no 8' => ['strength' => 15, 'dexterity' => 15, 'constitution' => 13,
                        'intelligence' => 12, 'wisdom' => 10, 'charisma' => 14],
    'a 16 that is not in the array' => ['strength' => 16] + $standard,
    'only five abilities' => array_slice($standard, 0, 5),
];
foreach ($rejects as $what => $abilities) {
    $threw = false;
    try {
        $sandboxed(fn () => $make(['abilities' => $abilities]));
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    $check($threw, "should refuse {$what}");
}

// Any rearrangement of the same six numbers is fine.
foreach ([['charisma', 'strength'], ['wisdom', 'dexterity'], ['intelligence', 'constitution']] as [$a, $b]) {
    $swapped = $standard;
    [$swapped[$a], $swapped[$b]] = [$swapped[$b], $swapped[$a]];
    $ok = true;
    try {
        $sandboxed(fn () => $make(['abilities' => $swapped]));
    } catch (Throwable $e) {
        $ok = false;
        echo "        {$e->getMessage()}\n";
    }
    $check($ok, "should accept the array with {$a} and {$b} swapped");
}

// Point buy is unaffected by the new check: it has its own budget rule and its
// own legal range, and 8 across the board costs nothing.
$ok = true;
try {
    $sandboxed(fn () => $make([
        'method' => 'point_buy',
        'abilities' => array_fill_keys(array_keys($standard), 8),
    ]));
} catch (Throwable $e) {
    $ok = false;
    echo "        {$e->getMessage()}\n";
}
$check($ok, 'point buy should still accept 8s across the board');

// -------------------------------------------------------------------------
echo "\nThe review's armour class must match what creation writes\n";

$classes = $gen->listClasses();
$check($classes !== [], 'listClasses should return something');

// Three Dexterity values: one the cap bites on, one it does not, and a negative
// modifier — the case a wrong formula gets away with when Dex happens to be 10.
foreach ([8 => -1, 14 => 2, 18 => 4] as $dex => $expectedMod) {
    $check(ability_mod($dex) === $expectedMod, "ability_mod({$dex}) is {$expectedMod}");

    foreach ($classes as $cls) {
        $abilities = ['strength' => 12, 'dexterity' => $dex, 'constitution' => 13,
                      'intelligence' => 12, 'wisdom' => 10, 'charisma' => 8];

        $predicted = CharacterGenerator::startingAc($cls, $abilities);

        $actual = $sandboxed(function () use ($gen, $cls, $abilities) {
            $made = $gen->create([
                'name' => 'Harness ' . bin2hex(random_bytes(4)),
                'race' => 'Human',
                'class' => $cls['name'],
                'method' => 'point_buy',
                // Human is +1 to everything, so hand point buy the scores that
                // land on the Dexterity being tested once the race is applied.
                'abilities' => array_map(static fn ($v) => max(8, min(15, $v - 1)), $abilities),
                'background' => 'Soldier',
                'alignment' => 'Neutral',
                'sprite_key' => $cls['sprite_key'] ?: 'fighter',
            ]);
            return (int) $made['armor_class'];
        });

        // The character actually created has Dex+1 from being Human, so predict
        // against the same scores it ended up with rather than the ones asked
        // for — the point of the test is that the two formulas agree, not that
        // the racial bonus can be ignored.
        $asBuilt = $abilities;
        $asBuilt['dexterity'] = max(8, min(15, $dex - 1)) + 1;
        $predicted = CharacterGenerator::startingAc($cls, $asBuilt);

        $check(
            $predicted === $actual,
            sprintf(
                '%s with Dex %d: review says %d, creation writes %d%s',
                $cls['name'],
                $asBuilt['dexterity'],
                $predicted,
                $actual,
                $cls['starting_armor'] ? " (wearing {$cls['starting_armor']['name']})" : ' (unarmoured)'
            )
        );
    }
}

// -------------------------------------------------------------------------
echo "\nEvery class's kit resolves to real items\n";

foreach ($classes as $cls) {
    $kit = CharacterGenerator::kitFor((string) $cls['name']);
    $check($kit !== [], "{$cls['name']} has a kit");
    $check(
        count($cls['starting_gear']) === count($kit),
        sprintf(
            '%s: all %d kit keys resolve (got %s)',
            $cls['name'],
            count($kit),
            implode(', ', $cls['starting_gear']) ?: 'nothing'
        )
    );
}

// -------------------------------------------------------------------------
echo "\nA character with no party asked for gets a new one\n";

// The API used to default an absent party_id to whatever the session was
// playing, so "Create Character" on the main menu silently added a fourth member
// to a game in progress. create() itself has always been right — null means a
// fresh party — so this pins the behaviour the API now relies on.
$sandboxed(function () use ($gen, $make, $check, $db, $standard) {
    $before = (int) $db->query('SELECT COUNT(*) FROM parties')->fetchColumn();

    $a = $make(['name' => 'Harness Solo A']);
    $b = $make(['name' => 'Harness Solo B']);

    $check(!empty($a['party_id']), 'a partyless creation still lands in a party');
    $check(
        $a['party_id'] !== $b['party_id'],
        sprintf(
            'two partyless creations get different parties (got %s and %s)',
            var_export($a['party_id'], true),
            var_export($b['party_id'], true)
        )
    );

    $after = (int) $db->query('SELECT COUNT(*) FROM parties')->fetchColumn();
    $check($after === $before + 2, "two new parties exist (before {$before}, after {$after})");

    // And asking for one explicitly still joins it.
    $c = $gen->create([
        'name' => 'Harness Joiner',
        'race' => 'Human',
        'class' => 'Rogue',
        'method' => 'standard',
        'abilities' => $standard,
        'background' => 'Criminal',
        'alignment' => 'Neutral',
        'sprite_key' => 'rogue',
    ], (int) $a['party_id']);
    $check(
        (int) $c['party_id'] === (int) $a['party_id'],
        'an explicit party_id joins that party'
    );

    $size = $db->prepare('SELECT COUNT(*) FROM character_party WHERE party_id = ?');
    $size->execute([(int) $a['party_id']]);
    $check((int) $size->fetchColumn() === 2, 'and the party now holds two');

    // A character alone in its own party must still be retirable, as long as
    // another party has one. Scoping the guard to the party made every abandoned
    // new game leave a row in the character list that nothing could remove.
    $ok = true;
    $why = '';
    try {
        $gen->retire((int) $b['id']);
    } catch (Throwable $e) {
        $ok = false;
        $why = ' — ' . $e->getMessage();
    }
    $check($ok, "the only member of a party can be retired{$why}");

    // But the very last player character anywhere cannot be.
    $db->exec('UPDATE characters SET is_active = 0 WHERE companion_key IS NULL AND id NOT IN ('
        . (int) $a['id'] . ')');
    $refused = false;
    try {
        $gen->retire((int) $a['id']);
    } catch (InvalidArgumentException $e) {
        $refused = str_contains($e->getMessage(), 'last character');
    }
    $check($refused, 'the last player character anywhere is refused');
});

// -------------------------------------------------------------------------
echo "\nNames are checked before they are used\n";

$existing = $db->query('SELECT name FROM characters WHERE is_active = 1 LIMIT 1')->fetchColumn();
if ($existing) {
    $check($gen->nameTaken((string) $existing) !== null, "should report {$existing} as taken");
    $check(
        $gen->nameTaken((string) $existing, (int) $db->query(
            'SELECT id FROM characters WHERE name = ' . $db->quote((string) $existing) . ' LIMIT 1'
        )->fetchColumn()) === null,
        'should allow a character to keep its own name'
    );
} else {
    echo "  (no living characters to test against)\n";
}

$companion = $db->query('SELECT name FROM companions LIMIT 1')->fetchColumn();
if ($companion) {
    $check(
        $gen->nameTaken((string) $companion) !== null,
        "should reserve the companion name {$companion}"
    );
}
$check($gen->nameTaken('Zzyzx Quimbleforth The Unlikely') === null, 'should allow an unused name');

// -------------------------------------------------------------------------
echo "\nThe origin feat at creation\n";

// Tough through the wizard pays 2 hit points at level 1, and the feat lands
// in feats_json — proof the grant went through LevelUpService's one door
// rather than writing the column bare.
$plain = $sandboxed(fn () => $make());
$tough = $sandboxed(fn () => $make(['feat' => 'tough']));
$check(
    (int) $tough['max_hp'] === (int) $plain['max_hp'] + 2,
    sprintf('tough should be worth 2 HP at creation (%d vs %d)', $tough['max_hp'], $plain['max_hp'])
);
$check(
    in_array('tough', json_decode((string) $tough['feats_json'], true) ?: [], true),
    'the feat should be recorded in feats_json'
);

// The shelf is the origin shelf and nothing else.
foreach (['great_weapon_master' => 'a general feat', 'archery' => 'a fighting style',
          'no_such_feat' => 'a made-up key'] as $key => $label) {
    $refused = false;
    try {
        $sandboxed(fn () => $make(['feat' => $key]));
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    $check($refused, "{$label} should be refused at creation");
}

// And nothing is still nothing.
$none = $sandboxed(fn () => $make(['feat' => '']));
$check(
    (json_decode((string) $none['feats_json'], true) ?: []) === [],
    'an empty choice should grant nothing'
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
