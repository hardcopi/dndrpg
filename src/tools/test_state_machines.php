<?php
/**
 * The explicit state machines: every legal transition allowed, every illegal
 * one refused and logged, and the engines that write those states obeying
 * the tables.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_state_machines.php`.
 *
 * Part 1 is pure (no database). Part 2 writes a scratch party inside a
 * transaction that is rolled back, so it is safe against a database somebody
 * is playing on.
 */
declare(strict_types=1);
define('STATE_MACHINE_QUIET', true);
require_once '/var/www/html/app/bootstrap.php';

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
function same(string $n, $want, $got): void
{
    ok($n, $want === $got, 'expected ' . var_export($want, true) . ', got ' . var_export($got, true));
}

// ---------------------------------------------------------------------------
echo "== every machine: legal moves pass, everything else is refused ==\n";
foreach (StateMachines::all() as $name => $m) {
    $legal = [];
    foreach ($m->edges() as $e) {
        $legal[$e['from'] . '>' . $e['to']] = true;
        $threw = false;
        try {
            $m->guard($e['from'], $e['to'], $e['event']);
        } catch (IllegalTransition $x) {
            $threw = true;
        }
        if ($threw) {
            ok("$name: {$e['from']} -{$e['event']}-> {$e['to']} is legal", false);
        }
        $pass++;
    }
    $illegal = 0;
    $refusedOk = true;
    foreach ($m->stateNames() as $from) {
        foreach ($m->stateNames() as $to) {
            if (isset($legal["$from>$to"])) {
                continue;
            }
            $illegal++;
            $before = count(StateMachine::$refused);
            try {
                $m->guard($from, $to);
                $refusedOk = false;
                echo "         $name: $from -> $to was allowed\n";
            } catch (IllegalTransition $x) {
                $last = end(StateMachine::$refused);
                if ($x->machine !== $name || $x->from !== $from || $x->to !== $to
                    || !$last || $last['from'] !== $from || $last['to'] !== $to) {
                    $refusedOk = false;
                }
            }
        }
    }
    ok("$name: " . count($m->edges()) . " legal transitions pass", true);
    ok("$name: all $illegal illegal (from,to) pairs are refused and logged", $refusedOk);
    ok("$name: a wrong event on a legal pair is refused",
        $m->edges() === [] || !$m->can($m->edges()[0]['from'], $m->edges()[0]['to'], '__no_such_event__'));
    foreach ($m->terminal as $t) {
        ok("$name: terminal `$t` has no way out", $m->eventsFrom($t) === []);
    }
}

echo "\n== the tables say what the game needs ==\n";
$r = CompanionRoster::machine();
ok('a dead companion cannot be recruited', !$r->can('dead', 'active') && !$r->can('dead', 'camp'));
ok('a dead companion cannot turn hostile', !$r->can('dead', 'hostile'));
ok('only a recruited companion can die', $r->can('active', 'dead') && $r->can('camp', 'dead') && !$r->can('met', 'dead'));
ok('left is reversible (reconciliation scenes)', $r->can('left', 'active'));
$l = LifeState::machine();
ok('conscious cannot skip straight to stable', !$l->can('conscious', 'stable'));
ok('dying -> stable by three successes or Spare the Dying',
    $l->can('dying', 'stable', 'three_successes') && $l->can('dying', 'stable', 'stabilise'));
ok('dead cannot be healed back up', !$l->can('dead', 'conscious'));
ok('stable wakes with healing or a long rest', $l->can('stable', 'conscious', 'heal') && $l->can('stable', 'conscious', 'long_rest'));
$q = QuestStatus::machine();
ok('a finished quest cannot restart', !$q->can('completed', 'active') && !$q->can('failed', 'active'));
ok('completed and failed are both terminal', QuestStatus::isOver('completed') && QuestStatus::isOver('failed') && !QuestStatus::isOver('active'));
$c = CombatStatus::machine();
ok('every fight outcome leaves active only', array_unique(array_column($c->edges(), 'from')) === ['active']);
ok('an ended fight cannot resume', !$c->can('victory', 'active'));
$st = CombatStatus::transition(['status' => 'victory'], 'defeat', 'last_ally_falls');
same('CombatStatus::transition refuses victory -> defeat and keeps victory', 'victory', $st['status']);

echo "\n== LifeState derives from what the engine already stores ==\n";
$pc = ['type' => 'pc', 'alive' => true, 'hp' => 7, 'conditions' => [], 'stable' => false];
same('on their feet', 'conscious', LifeState::of($pc));
same('at zero, unconscious, rolling', 'dying', LifeState::of(['hp' => 0, 'conditions' => [['key' => 'unconscious']]] + $pc));
same('at zero, unconscious, stable', 'stable', LifeState::of(['hp' => 0, 'stable' => true, 'conditions' => [['key' => 'unconscious']]] + $pc));
same('alive => false is dead', 'dead', LifeState::of(['alive' => false] + $pc));
same('out of combat, HP above 0 is awake', 'conscious', LifeState::ofCharacter(3, 'active'));
same('out of combat, 0 HP is stable (unconscious)', 'stable', LifeState::ofCharacter(0, 'camp'));
same('roster dead is dead whatever the HP', 'dead', LifeState::ofCharacter(5, 'dead'));
ok('only the conscious can talk', LifeState::canTalk('conscious') && !LifeState::canTalk('stable')
    && !LifeState::canTalk('dying') && !LifeState::canTalk('dead'));

echo "\n== the generated docs and JS mirror are current ==\n";
$out = [];
exec('php ' . escapeshellarg(__DIR__ . '/gen_state_machines.php') . ' --check', $out, $code);
same('gen_state_machines.php --check', 0, $code);
$js = (string) file_get_contents(__DIR__ . '/../assets/js/state-machines.js');
ok('the JS mirror carries every machine', array_reduce(array_keys(StateMachines::all()),
    fn ($carry, $n) => $carry && str_contains($js, '"' . $n . '"'), true));

// ---------------------------------------------------------------------------
echo "\n== the engines obey the tables (scratch party, rolled back) ==\n";
$db = db();
$db->beginTransaction();
try {
    $moduleId = (int) $db->query('SELECT id FROM modules WHERE module_key = "rivermark"')->fetchColumn();
    $db->prepare("INSERT INTO parties (user_id, module_id, name) VALUES (NULL, ?, 'state machine probe')")
        ->execute([$moduleId]);
    $pid = (int) $db->lastInsertId();
    $db->prepare(
        "INSERT INTO characters (name, race, class, level, experience, strength, dexterity,
            constitution, intelligence, wisdom, charisma, max_hp, current_hp, armor_class,
            proficiency_bonus, is_active)
         VALUES (?, 'Human', 'Fighter', 3, 0, 10,10,10,10,10,10, 20, 20, 12, 2, 1)"
    )->execute(['_test_' . bin2hex(random_bytes(4))]);
    $leader = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_party (party_id, character_id, slot) VALUES (?,?,1)')
        ->execute([$pid, $leader]);
    $db->prepare('UPDATE parties SET leader_character_id = ? WHERE id = ?')->execute([$leader, $pid]);

    $svc = new CompanionService($db);
    $status = function (string $k) use ($db, $pid): ?string {
        $s = $db->prepare('SELECT status FROM party_companions WHERE party_id = ? AND companion_key = ?');
        $s->execute([$pid, $k]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    };
    $row = function (string $k) use ($svc, $pid): array {
        foreach ($svc->forParty($pid) as $c) {
            if ($c['companion_key'] === $k) {
                return $c;
            }
        }
        return [];
    };

    echo "-- companion roster\n";
    $svc->meet($pid, 'kessa');
    same('meet: unmet -> met', 'met', $status('kessa'));
    $svc->recruit($pid, 'kessa');
    same('recruit: met -> active', 'active', $status('kessa'));
    $svc->dismiss($pid, 'kessa');
    same('dismiss: active -> camp', 'camp', $status('kessa'));
    $svc->activate($pid, 'kessa');
    same('activate: camp -> active', 'active', $status('kessa'));
    $svc->leave($pid, 'kessa');
    same('leave: active -> left', 'left', $status('kessa'));
    $svc->recruit($pid, 'kessa');
    same('recruit again: left -> active', 'active', $status('kessa'));
    $threw = false;
    try {
        $svc->activate($pid, 'aldric');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    ok('activate on a companion never recruited is refused', $threw);

    echo "-- companion life at camp\n";
    $kessaChar = (int) $row('kessa')['character_id'];
    same('awake companion: life_state conscious', 'conscious', $row('kessa')['life_state']);
    same('and can talk', true, $row('kessa')['can_talk']);
    same('lifeByNpcKey agrees', 'conscious', $svc->lifeByNpcKey($pid, 'kessa')['life_state'] ?? null);
    $db->prepare('UPDATE characters SET current_hp = 0 WHERE id = ?')->execute([$kessaChar]);
    same('downed companion at camp: stable (unconscious)', 'stable', $row('kessa')['life_state']);
    same('and cannot talk', false, $row('kessa')['can_talk']);
    same('lifeByNpcKey says stable', 'stable', $svc->lifeByNpcKey($pid, 'kessa')['life_state'] ?? null);
    $db->prepare('UPDATE characters SET current_hp = 4 WHERE id = ?')->execute([$kessaChar]);
    same('healed above 0: awake again', 'conscious', $row('kessa')['life_state']);
    same('an ordinary NPC is not this machine\'s business', null, $svc->lifeByNpcKey($pid, 'tobin'));

    echo "-- permadeath path (CompanionRoster::DEAD)\n";
    $svc->recruit($pid, 'aldric');
    $svc->markDead($pid, 'aldric');
    same('markDead: active -> dead', 'dead', $status('aldric'));
    same('the dead read as dead at camp', 'dead', $row('aldric')['life_state']);
    same('and cannot talk', false, $row('aldric')['can_talk']);
    $r = $svc->recruit($pid, 'aldric');
    same('recruiting the dead is refused (stays dead)', 'dead', $status('aldric'));
    ok('with a note, not an exception', in_array('Brother Aldric is dead.', $r['notes'] ?? [], true)
        || str_contains(implode(' ', $r['notes'] ?? []), 'dead'));
    $svc->turnHostile($pid, 'aldric');
    same('the dead do not turn hostile', 'dead', $status('aldric'));
    $inParty = (int) $db->query('SELECT COUNT(*) FROM character_party cp JOIN party_companions pc ON pc.character_id = cp.character_id
        WHERE pc.party_id = ' . $pid . " AND pc.companion_key = 'aldric'")->fetchColumn();
    same('and are no longer marching', 0, $inParty);

    echo "-- quest status\n";
    $quests = new QuestService($db);
    $qs = fn () => $quests->statusFor($pid, 'cellar_rats');
    same('untaken quest has no row (reads as available)', null, $qs());
    $quests->start($pid, 'cellar_rats');
    same('start: available -> active', 'active', $qs());
    $quests->advance($pid, 'cellar_rats', 'the_grain');
    same('advance: active -> active', 'active', $qs());
    $quests->advance($pid, 'cellar_rats', 'exterminated');
    same('terminal success stage: active -> completed', 'completed', $qs());
    $r = $quests->advance($pid, 'cellar_rats', 'source_found');
    same('advancing a completed quest is refused', 'completed', $qs());
    ok('with a note', str_contains(implode(' ', $r['notes'] ?? []), 'already completed'));
    $quests->fail($pid, 'cellar_rats');
    same('failing a completed quest is refused', 'completed', $qs());
    $r = $quests->start($pid, 'cellar_rats');
    same('restarting a completed quest is refused', 'completed', $qs());

    echo "-- combat life state, start to finish\n";
    $engine = new CombatEngine($db);
    $invoke = function (string $name, ...$args) use ($engine) {
        $m = new ReflectionMethod(CombatEngine::class, $name);
        $m->setAccessible(true);
        return $m->invoke($engine, ...$args);
    };
    $pcC = function (string $cid, int $charId, array $extra = []) {
        return array_merge([
            'character_id' => $charId, 'cid' => $cid, 'type' => 'pc', 'side' => 'party', 'name' => $cid,
            'class' => 'Fighter', 'level' => 3, 'x' => 4, 'y' => 5,
            'str' => 14, 'dex' => 12, 'con' => 14, 'int' => 10, 'wis' => 10, 'cha' => 10,
            'prof' => 2, 'speed' => 30, 'move_left' => 30,
            'hp' => 20, 'max_hp' => 20, 'ac' => 15, 'alive' => true,
            'conditions' => [], 'boons' => [], 'feats' => [], 'concentrating' => null,
            'stable' => false, 'death_saves' => ['success' => 0, 'fail' => 0],
        ], $extra);
    };
    $svc->recruit($pid, 'ilse');
    $ilseChar = (int) $row('ilse')['character_id'];
    $db->prepare('UPDATE characters SET current_hp = max_hp WHERE id IN (?, ?)')->execute([$kessaChar, $ilseChar]);
    $state = [
        'round' => 1, 'turn_index' => 0, 'order' => ['pc_k', 'pc_i', 'pc_l', 'mon_1'], 'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16)), 'w' => 16, 'h' => 12],
        'log' => [], 'events' => [],
        'combatants' => [
            $pcC('pc_k', $kessaChar, ['max_hp' => 20]),
            $pcC('pc_i', $ilseChar, ['max_hp' => 20]),
            $pcC('pc_l', $leader),
            ['cid' => 'mon_1', 'type' => 'monster', 'side' => 'foe', 'name' => 'Bandit',
             'x' => 5, 'y' => 5, 'hp' => 11, 'max_hp' => 11, 'ac' => 12, 'alive' => true,
             'conditions' => [], 'boons' => [], 'concentrating' => null],
        ],
    ];
    $get = function (array $s, string $cid) {
        foreach ($s['combatants'] as $c) {
            if ($c['cid'] === $cid) {
                return $c;
            }
        }
        return null;
    };
    $state = $invoke('takeDamage', $state, 'pc_k', 20);
    same('Kessa dropped to 0: dying', 'dying', LifeState::of($get($state, 'pc_k')));
    $state = $invoke('stabilise', $state, 'pc_k');
    same('Spare the Dying: stable', 'stable', LifeState::of($get($state, 'pc_k')));
    $state = $invoke('heal', $state, 'pc_k', 5);
    same('healed: conscious again', 'conscious', LifeState::of($get($state, 'pc_k')));
    $state = $invoke('takeDamage', $state, 'pc_k', 5);
    same('down again: dying, saves wiped', 'dying', LifeState::of($get($state, 'pc_k')));

    $state = $invoke('takeDamage', $state, 'pc_i', 20);
    for ($i = 0; $i < 3; $i++) {
        $state = $invoke('takeDamage', $state, 'pc_i', 1);
    }
    same('Ilse struck three times while down: dead', 'dead', LifeState::of($get($state, 'pc_i')));
    StateMachine::$refused = [];
    $state = $invoke('heal', $state, 'pc_i', 10);
    same('healing the dead is refused', 'dead', LifeState::of($get($state, 'pc_i')));
    $last = end(StateMachine::$refused) ?: [];
    ok('and logged as an illegal transition', ($last['machine'] ?? '') === 'life_state'
        && $last['from'] === 'dead' && $last['to'] === 'conscious');
    same('their hit points did not move', 0, (int) $get($state, 'pc_i')['hp']);

    // End the fight: the party breaks away. Kessa is still dying, Ilse bled out.
    $state = CombatStatus::transition($state, CombatStatus::FLED, 'flee_succeeds');
    $invoke('finalizeCombat', 0, $leader, $state);
    $hpOf = function (int $id) use ($db): int {
        return (int) $db->query('SELECT current_hp FROM characters WHERE id = ' . $id)->fetchColumn();
    };
    same('after the fight the dying companion is on 0 HP', 0, $hpOf($kessaChar));
    same('so camp reads her as unconscious', 'stable', $row('kessa')['life_state']);
    same('and the bled-out companion is carried off on 0 HP, not 1', 0, $hpOf($ilseChar));
    same('so camp reads her as unconscious too', 'stable', $row('ilse')['life_state']);
    same('neither can talk', [false, false], [$row('kessa')['can_talk'], $row('ilse')['can_talk']]);
    if (!CombatEngine::COMPANION_PERMADEATH) {
        same('permadeath off: Ilse is still on the roster', 'active', $status('ilse'));
    }
    same('the standing leader keeps their hit points', 20, $hpOf($leader));

    echo "-- long rest wakes the unconscious\n";
    $db->prepare('UPDATE characters SET current_location_id = (SELECT id FROM locations WHERE allow_camp = 1 LIMIT 1) WHERE id = ?')
        ->execute([$leader]);
    (new LocationEngine($db))->longRest($leader, 0, 'camp');
    same('Kessa awake after a long rest', 'conscious', $row('kessa')['life_state']);
    same('Ilse awake after a long rest', 'conscious', $row('ilse')['life_state']);
} finally {
    $db->rollBack();
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
