<?php
/**
 * Test harness for the level-up ceremony.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_levelup.php`.
 *
 * Unlike test_rules.php and test_combat.php this one NEEDS the database: the
 * whole feature is about a level being recorded in one request and claimed in
 * another, and there is nothing to test about that without somewhere to record
 * it. It works on a scratch character it creates and deletes, inside a
 * transaction it rolls back, so it can be run against a database somebody is
 * playing on.
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
        ok(
            $name,
            $needle === '' || stripos($e->getMessage(), $needle) !== false,
            'message was: ' . $e->getMessage()
        );
    }
}

$db = db();
$svc = new LevelUpService($db);

/** A throwaway character with known scores. */
function scratch(PDO $db, string $class, array $abilities = []): int
{
    $a = array_merge([
        'strength' => 10, 'dexterity' => 10, 'constitution' => 14,
        'intelligence' => 10, 'wisdom' => 10, 'charisma' => 10,
    ], $abilities);
    $db->prepare(
        'INSERT INTO characters
            (name, race, class, level, experience, strength, dexterity, constitution,
             intelligence, wisdom, charisma, max_hp, current_hp, armor_class, proficiency_bonus)
         VALUES (?, ?, ?, 1, 0, ?, ?, ?, ?, ?, ?, 10, 10, 10, 2)'
    )->execute([
        '_test_' . bin2hex(random_bytes(4)), 'Human', $class,
        $a['strength'], $a['dexterity'], $a['constitution'],
        $a['intelligence'], $a['wisdom'], $a['charisma'],
    ]);
    return (int) $db->lastInsertId();
}

function charRow(PDO $db, int $id): array
{
    $s = $db->prepare('SELECT * FROM characters WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch();
}

$db->beginTransaction();

try {
    // -----------------------------------------------------------------------
    section('Recording a level does not grant hit points');

    $wizard = scratch($db, 'Wizard');
    $before = charRow($db, $wizard);
    same('the scratch character starts at 10 hp', 10, (int) $before['max_hp']);

    same('two levels crossed records two ceremonies', 2, $svc->record($wizard, 1, 3));
    same('and hit points have not moved', 10, (int) charRow($db, $wizard)['max_hp']);
    same('replaying the same span records nothing new', 0, $svc->record($wizard, 1, 3));
    same('nor does a span going backwards', 0, $svc->record($wizard, 3, 2));

    $pending = $db->prepare('SELECT * FROM pending_level_ups WHERE character_id = ? ORDER BY new_level');
    $pending->execute([$wizard]);
    $rows = $pending->fetchAll();
    same('the levels recorded are 2 and 3', [2, 3], array_map('intval', array_column($rows, 'new_level')));

    // -----------------------------------------------------------------------
    section('Rolling the hit die');

    $first = (int) $rows[0]['id'];
    $c = $svc->ceremony($first);
    same('a Wizard rolls a d6', 6, $c['hp']['hit_die']);
    same('CON 14 is a +2', 2, $c['hp']['con_mod']);
    ok('the roll is outstanding', !$c['hp']['done']);
    ok('a level 2 offers no ability increase', !$c['asi']['offered']);

    $result = $svc->rollHp($first);
    $face = (int) $result['roll'];
    ok('the die lands within its own faces', $face >= 1 && $face <= 6, "showed {$face}");
    same('and the gain is the roll plus CON', $face + 2, (int) $result['gained']);
    same('maximum hit points move by exactly that', 10 + $face + 2, (int) charRow($db, $wizard)['max_hp']);

    threw('rolling the same hit die twice is refused', fn () => $svc->rollHp($first), 'already been rolled');

    // Not settled yet — a Wizard at level 2 also learns a spell, and a ceremony
    // is only over when every step it offered has been taken.
    $settled = $db->prepare('SELECT resolved_at FROM pending_level_ups WHERE id = ?');
    $settled->execute([$first]);
    ok('a caster with a spell still to pick stays open', $settled->fetchColumn() === null);
    ok('and the roll itself is marked done', $svc->ceremony($first)['hp']['done']);

    // -----------------------------------------------------------------------
    section('A 1 is a 1');

    // The roll is straight, with no floor — the explicit decision. A d6 caster
    // with CON 6 (-2) can roll a 1 and gain -1. What must NOT happen is a
    // maximum hit point total falling below the character's level.
    $frail = scratch($db, 'Wizard', ['constitution' => 6]);
    same('CON 6 is a -2', -2, Rules::abilityMod(6));
    same('a rolled 1 with -2 CON is -1, unclamped', -1, Rules::hpFromRoll(1, -2));
    same('a rolled 1 with -4 CON is -3, still unclamped', -3, Rules::hpFromRoll(1, -4));
    // The two paths differ deliberately: the average is floored because nothing
    // is watching it, the roll is not because somebody is.
    same('while the average path floors at 1', 1, Rules::hpPerLevel(6, -4));
    same('and is not floored when it need not be', 2, Rules::hpPerLevel(6, -2));

    // -----------------------------------------------------------------------
    section('Level 4: ability score increase');

    $fighter = scratch($db, 'Fighter', ['strength' => 15]);
    $svc->record($fighter, 3, 4);
    $db->prepare('UPDATE characters SET level = 4 WHERE id = ?')->execute([$fighter]);
    $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 4');
    $row->execute([$fighter]);
    $asiPending = (int) $row->fetchColumn();

    $c = $svc->ceremony($asiPending);
    ok('level 4 offers the increase', $c['asi']['offered']);
    ok('and Grappler is on the list', in_array('grappler', array_column($c['asi']['feats'], 'key'), true));

    threw('three points is not an increase', fn () => $svc->chooseAsi($asiPending, ['str' => 3], null), 'exactly 2');
    threw('one point is not either', fn () => $svc->chooseAsi($asiPending, ['str' => 1], null), 'exactly 2');
    threw('nor a made-up ability', fn () => $svc->chooseAsi($asiPending, ['luck' => 2], null), 'No such ability');
    threw('nor three abilities', fn () => $svc->chooseAsi($asiPending, ['str' => 1, 'dex' => 1, 'con' => 1], null), '');

    $svc->chooseAsi($asiPending, ['str' => 2], null);
    same('strength went up by 2', 17, (int) charRow($db, $fighter)['strength']);
    threw('and the choice cannot be made twice', fn () => $svc->chooseAsi($asiPending, ['dex' => 2], null), 'already');

    // -----------------------------------------------------------------------
    section('A CON increase pays out retroactively');

    $cleric = scratch($db, 'Cleric', ['constitution' => 13]);
    $db->prepare('UPDATE characters SET level = 4, max_hp = 30, current_hp = 30 WHERE id = ?')->execute([$cleric]);
    $svc->record($cleric, 3, 4);
    $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 4');
    $row->execute([$cleric]);
    $conPending = (int) $row->fetchColumn();

    same('CON 13 is a +1', 1, Rules::abilityMod(13));
    $svc->chooseAsi($conPending, ['con' => 2], null);
    same('CON 15 is a +2', 2, Rules::abilityMod((int) charRow($db, $cleric)['constitution']));
    // One extra hit point for each of the four levels already lived.
    same('four levels each gain a hit point', 34, (int) charRow($db, $cleric)['max_hp']);

    // -----------------------------------------------------------------------
    section('Feats, and their prerequisites');

    $weak = scratch($db, 'Fighter', ['strength' => 8]);
    $db->prepare('UPDATE characters SET level = 4 WHERE id = ?')->execute([$weak]);
    $svc->record($weak, 3, 4);
    $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 4');
    $row->execute([$weak]);
    $weakPending = (int) $row->fetchColumn();

    // Looked up by key rather than taken from the head of the list: the
    // catalogue is thirty entries now and the first of them is the Ability
    // Score Improvement, which everybody qualifies for.
    $offer = $svc->ceremony($weakPending)['asi']['feats'];
    $byKey = array_column($offer, null, 'key');
    ok('the increase itself is on the list', isset($byKey[LevelUpService::ASI_FEAT]));
    same('Grappler is offered but not eligible at STR 8', false, $byKey['grappler']['eligible']);
    threw('and taking it anyway is refused', fn () => $svc->chooseAsi($weakPending, null, 'grappler'), 'needs Strength 13');
    threw('as is a feat that does not exist', fn () => $svc->chooseAsi($weakPending, null, 'sharpshooter'), 'No such feat');

    $strong = scratch($db, 'Fighter', ['strength' => 16]);
    $db->prepare('UPDATE characters SET level = 4 WHERE id = ?')->execute([$strong]);
    $svc->record($strong, 3, 4);
    $row->execute([$strong]);
    $strongPending = (int) $row->fetchColumn();
    $svc->chooseAsi($strongPending, null, 'grappler');
    same('the feat is recorded on the character', ['grappler'], Feats::taken(charRow($db, $strong)));
    // SRD 5.2's Grappler carries +1 Strength, where 5.1's carried none. A feat
    // is no longer the alternative to an increase; most of them are a smaller
    // increase with something attached.
    same('and Grappler brings its own +1 Strength', 17, (int) charRow($db, $strong)['strength']);

    // -----------------------------------------------------------------------
    section('Choosing spells');

    $warlock = scratch($db, 'Warlock');
    $db->prepare('UPDATE characters SET level = 5 WHERE id = ?')->execute([$warlock]);
    $svc->record($warlock, 4, 5);
    $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 5');
    $row->execute([$warlock]);
    $spellPending = (int) $row->fetchColumn();

    $c = $svc->ceremony($spellPending);
    same('a Warlock learns one spell', 1, $c['spells']['offered']);
    $available = $c['spells']['available'];
    ok('and has something to choose from', count($available) > 0, count($available) . ' offered');

    $levels = array_unique(array_map('intval', array_column($available, 'level')));
    sort($levels);
    ok('nothing above what they can cast', max($levels) <= 3, 'levels offered: ' . json_encode($levels));

    // Every offer must be on the Warlock's own list.
    $wrongClass = $db->prepare(
        'SELECT COUNT(*) FROM spells WHERE spell_key = ? AND NOT FIND_IN_SET(?, class_list)'
    );
    $offClass = 0;
    foreach ($available as $s) {
        $wrongClass->execute([$s['spell_key'], 'Warlock']);
        $offClass += (int) $wrongClass->fetchColumn();
    }
    same('none of them belong to another class', 0, $offClass);

    threw('choosing two when one is due is refused', function () use ($svc, $spellPending, $available) {
        $svc->chooseSpells($spellPending, [$available[0]['spell_key'], $available[1]['spell_key']]);
    }, 'exactly 1');
    threw('as is a spell not on the list', fn () => $svc->chooseSpells($spellPending, ['fireball']), 'cannot learn');

    $pick = (string) $available[0]['spell_key'];
    $svc->chooseSpells($spellPending, [$pick]);
    $known = $db->prepare(
        'SELECT COUNT(*) FROM character_spells cs JOIN spells s ON s.id = cs.spell_id
          WHERE cs.character_id = ? AND s.spell_key = ?'
    );
    $known->execute([$warlock, $pick]);
    same('the spell is learned', 1, (int) $known->fetchColumn());
    ok('and no longer on offer', !in_array(
        $pick,
        array_column($svc->ceremony($spellPending)['spells']['available'] ?? [], 'spell_key'),
        true
    ));

    // -----------------------------------------------------------------------
    section('A level with nothing to choose is not a ceremony');

    $rogue = scratch($db, 'Rogue');
    $svc->record($rogue, 1, 2);
    $row = $db->prepare('SELECT id FROM pending_level_ups WHERE character_id = ? AND new_level = 2');
    $row->execute([$rogue]);
    $rogueP = (int) $row->fetchColumn();
    $c = $svc->ceremony($rogueP);
    ok('a Rogue is offered no spells', $c['spells']['offered'] === 0);
    ok('and no ability increase at level 2', !$c['asi']['offered']);
    ok('so only the hit die is outstanding', !$c['hp']['done'] && $c['asi']['done'] && $c['spells']['done']);
} finally {
    $db->rollBack();
}

echo PHP_EOL . str_repeat('-', 52) . PHP_EOL;
printf("%d passed, %d failed%s", $RESULTS['pass'], $RESULTS['fail'], PHP_EOL);

exit($RESULTS['fail'] > 0 ? 1 : 0);
