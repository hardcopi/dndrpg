<?php
/**
 * Spell targeting: who a spell may be pointed at, and the server refusing
 * anybody else whatever the client sends.
 *
 * Run with `docker compose exec -T php php /var/www/html/tools/test_spell_target.php`.
 *
 * The board lights up `ui.spell_aim[id].targets` and lets the player click one.
 * That list and the check in doCast() must be the same rule, so the last block
 * walks every spell over every body and asks both.
 *
 * The end-to-end cast uses a scratch character inside a transaction that is
 * rolled back. Safe against a database somebody is playing on.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$engine = new CombatEngine($db);
$invoke = function (string $name, ...$args) use ($engine) {
    $m = new ReflectionMethod(CombatEngine::class, $name);
    $m->setAccessible(true);
    return $m->invoke($engine, ...$args);
};

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
function threw(string $n, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        ok($n, false, 'nothing was thrown');
    } catch (InvalidArgumentException $e) {
        ok($n, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage());
    }
}

function body(string $cid, string $side, int $x, int $y, array $over = []): array
{
    return array_merge([
        'cid' => $cid, 'type' => $side === 'party' ? 'pc' : 'monster', 'side' => $side,
        'name' => $cid, 'x' => $x, 'y' => $y, 'hp' => 20, 'max_hp' => 20, 'ac' => 12,
        'alive' => true, 'conditions' => [], 'boons' => [], 'reach_ft' => 5,
        'concentrating' => null, 'temp_hp' => 0,
        'character_id' => $side === 'party' ? 900000 + (int) substr($cid, 3) : null,
    ], $over);
}

/*
 *   y=0  . . . . . . . . . . . . . . . m2      m2: 65 ft off
 *   y=5  . . C a . . m1 . . . . . f . . .      C caster, a ally beside,
 *   y=6  . . d . . . . . . . . . . . . .      m1 20 ft, f 50 ft, d downed beside
 *   y=9  . . x                                 x: a dead ally, 20 ft
 */
function board(array $extra = []): array
{
    return [
        'round' => 1, 'turn_index' => 0, 'order' => ['pc_1'], 'status' => 'active',
        'grid' => ['terrain' => array_fill(0, 12, str_repeat('.', 16)), 'w' => 16, 'h' => 12],
        'log' => [], 'events' => [],
        'combatants' => array_merge([
            body('pc_1', 'party', 2, 5, ['name' => 'Aldric', 'class' => 'Cleric', 'level' => 3]),
            body('pc_2', 'party', 3, 5, ['name' => 'Kessa']),
            body('pc_3', 'party', 12, 5, ['name' => 'Ilse']),
            body('pc_4', 'party', 2, 6, ['name' => 'Sera', 'hp' => 0,
                'conditions' => [['key' => 'unconscious']]]),
            body('pc_5', 'party', 2, 9, ['name' => 'Jimmy', 'hp' => 0, 'alive' => false]),
            body('mon_1', 'foe', 6, 5, ['name' => 'Goblin']),
            body('mon_2', 'foe', 15, 0, ['name' => 'Far Goblin']),
        ], $extra),
    ];
}

$SP = [
    'cure'      => ['name' => 'Cure Wounds', 'target_kind' => 'ally', 'range_text' => 'Touch'],
    'word'      => ['name' => 'Healing Word', 'target_kind' => 'ally', 'range_text' => '60 feet'],
    'spare'     => ['name' => 'Spare the Dying', 'target_kind' => 'ally', 'range_text' => 'Touch'],
    'bolt'      => ['name' => 'Fire Bolt', 'target_kind' => 'enemy', 'range_text' => '120 feet'],
    'frost'     => ['name' => 'Ray of Frost', 'target_kind' => 'enemy', 'range_text' => '60 feet'],
    'vampiric'  => ['name' => 'Vampiric Touch', 'target_kind' => 'enemy', 'range_text' => 'Self'],
    'bless'     => ['name' => 'Bless', 'target_kind' => 'all_allies', 'range_text' => '30 feet'],
    'fireball'  => ['name' => 'Fireball', 'target_kind' => 'all_enemies', 'range_text' => '150 feet'],
    'hands'     => ['name' => 'Burning Hands', 'target_kind' => 'all_enemies', 'range_text' => 'Self (15-foot cone)'],
    'shield'    => ['name' => 'Shield', 'target_kind' => 'self', 'range_text' => 'Self'],
];

$s = board();
$me = $s['combatants'][0];
$aim = fn(string $k, ?array $st = null) => $engine->spellAim($st ?? $s, ($st ?? $s)['combatants'][0], $SP[$k]);
$keys = fn(array $a) => (function ($t) { $k = array_map('strval', array_keys($t)); sort($k); return $k; })($a['targets']);
$pick = function (string $k, string $cid, ?array $st = null) use ($invoke, $SP, $s) {
    $st = $st ?? $s;
    $spell = $SP[$k];
    $out = $invoke('spellTargets', $st, $st['combatants'][0], $spell, $cid, CombatEngine::castRange($spell), null);
    return array_map(static fn ($t) => (string) $t['cid'], $out);
};

echo "== how each spell is pointed ==\n";
same('Cure Wounds asks for an ally', 'ally', $aim('cure')['mode']);
same('Fire Bolt asks for an enemy', 'enemy', $aim('bolt')['mode']);
same('Burning Hands asks for a spot', 'area', $aim('hands')['mode']);
same('Shield asks for nobody', 'self', $aim('shield')['mode']);
same('Bless takes everyone it reaches, no choice', 'auto', $aim('bless')['mode']);
same('so does Fireball (no template on the card)', 'auto', $aim('fireball')['mode']);
same('Vampiric Touch ("Self") is read as a touch', ['kind' => 'touch', 'ft' => 5, 'aoe' => null],
    CombatEngine::castRange($SP['vampiric']));

echo "\n== who each could take from here ==\n";
same('a touch reaches you, the friend beside you and the one down at your feet',
    ['pc_1', 'pc_2', 'pc_4'], $keys($aim('cure')));
same('Healing Word carries 60 ft: Ilse at 50 is in, the dead are not',
    ['pc_1', 'pc_2', 'pc_3', 'pc_4'], $keys($aim('word')));
same('Fire Bolt reaches both goblins', ['mon_1', 'mon_2'], $keys($aim('bolt')));
same('Ray of Frost only the near one', ['mon_1'], $keys($aim('frost')));
same('Vampiric Touch nobody — the goblin is 20 ft off', [], $keys($aim('vampiric')));
$close = board([body('mon_3', 'foe', 3, 4, ['name' => 'Rat'])]);
same('— and the rat at your shoulder once it is there', ['mon_3'], $keys($aim('vampiric', $close)));

echo "\n== the server refuses what the board would not offer ==\n";
same('a cure on the friend beside you lands on them', ['pc_2'], $pick('cure', 'pc_2'));
same('a cure with nobody named is the caster\'s own', ['pc_1'], $pick('cure', ''));
threw('a cure on a friend 50 ft off is refused, not swapped for yourself',
    fn () => $pick('cure', 'pc_3'), 'walk up beside');
threw('a cure on a goblin is refused', fn () => $pick('cure', 'mon_1'), 'own side');
threw('a cure on the dead is refused', fn () => $pick('cure', 'pc_5'), 'past the help');
threw('a cure on somebody who is not there is refused', fn () => $pick('cure', 'pc_99'), 'own side');
same('Spare the Dying reaches the one who is down', ['pc_4'], $pick('spare', 'pc_4'));
same('Healing Word carries to Ilse', ['pc_3'], $pick('word', 'pc_3'));
threw('Fire Bolt at a friend is refused', fn () => $pick('bolt', 'pc_2'), 'ally');
threw('Ray of Frost at the far goblin is refused with the distance',
    fn () => $pick('frost', 'mon_2'), 'carries 60');
threw('Fire Bolt at nobody is refused', fn () => $pick('bolt', ''), 'Invalid spell target');
threw('Vampiric Touch at 20 ft is refused', fn () => $pick('vampiric', 'mon_1'), 'touch');
same('and lands on the rat beside you', ['mon_3'], $pick('vampiric', 'mon_3', $close));

echo "\n== the board's list and the server's check are one rule ==\n";
$boards = ['open floor' => $s, 'a rat close' => $close];
foreach ($boards as $label => $st) {
    foreach (['cure', 'word', 'spare', 'bolt', 'frost', 'vampiric'] as $k) {
        $offer = $engine->spellAim($st, $st['combatants'][0], $SP[$k])['targets'];
        $bad = [];
        foreach ($st['combatants'] as $c) {
            $cid = (string) $c['cid'];
            try {
                $took = $pick($k, $cid, $st) === [$cid];
            } catch (InvalidArgumentException $e) {
                $took = false;
            }
            if ($took !== isset($offer[$cid])) {
                $bad[] = $cid . ($took ? ' accepted but not offered' : ' offered but refused');
            }
        }
        ok("{$SP[$k]['name']} on {$label}", !$bad, implode('; ', $bad));
    }
}

echo "\n== end to end: a refused cast spends nothing ==\n";
$db->beginTransaction();
try {
    $db->prepare(
        "INSERT INTO characters (name, race, class, level, strength, dexterity, constitution,
            intelligence, wisdom, charisma, max_hp, current_hp, armor_class, speed,
            proficiency_bonus, spell_slots_json)
         VALUES (?, 'Human', 'Cleric', 3, 10,10,12,10,16,10, 20,20, 14, 30, 2, '{}')"
    )->execute(['__aim_probe_' . bin2hex(random_bytes(3))]);
    $id = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO character_spells (character_id, spell_id, prepared)
                  SELECT ?, id, 1 FROM spells WHERE spell_key = ?')->execute([$id, 'cure_wounds']);
    $cureId = (int) $db->query("SELECT id FROM spells WHERE spell_key = 'cure_wounds'")->fetchColumn();
    $live = board();
    $live['combatants'][0] = array_merge($live['combatants'][0], [
        'character_id' => $id, 'type' => 'pc', 'wis' => 16, 'prof' => 2, 'feats' => [],
        'action_used' => false, 'level' => 3, 'class' => 'Cleric',
    ]);
    $live['combatants'][2]['hp'] = 5; // Ilse is hurt, and 50 ft away
    threw('Cure Wounds sent at Ilse across the room is refused',
        fn () => $invoke('doCast', $live, $cureId, 'pc_3', null, null, null), 'walk up beside');
    $slots = json_decode((string) $db->query("SELECT spell_slots_json FROM characters WHERE id = {$id}")
        ->fetchColumn(), true) ?: [];
    same('and the slot is still there', [], $slots);
    $live['combatants'][1]['hp'] = 4; // Kessa is hurt, and beside you
    $after = $invoke('doCast', $live, $cureId, 'pc_2', null, null, null);
    $kessa = array_values(array_filter($after['combatants'], fn ($c) => $c['cid'] === 'pc_2'))[0];
    ok('the same cure at Kessa beside you mends her', $kessa['hp'] > 4, "hp {$kessa['hp']}");
    $ui = $engine->spellAim($live, $live['combatants'][0], $db->query(
        "SELECT * FROM spells WHERE spell_key = 'cure_wounds'")->fetch());
    same('and the real cure_wounds row is an ally spell by touch', ['pc_1', 'pc_2', 'pc_4'], $keys($ui));
} finally {
    $db->rollBack();
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
