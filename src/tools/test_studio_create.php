<?php
/**
 * The studio's create verbs: a blank module that did not exist this morning.
 *
 *   docker compose exec -T php php /var/www/html/tools/test_studio_create.php
 *
 * ContentEditor could only revise rows the files had already put in the
 * database. These verbs are what make a fourth module possible without
 * opening JSON. The test builds one, refuses the mistakes the loader would
 * refuse, and tears it down. Safe against a database somebody is playing on:
 * the module key is unique per run and deleteModule refuses if a party is in
 * it — which nothing here creates.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

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
        echo "  FAIL $n" . ($d !== '' ? "\n         $d" : '') . "\n";
    }
}

function threw(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
}

echo "== studio create verbs ==\n";

$db = db();
$ed = new ContentEditor($db);
$tag = 'st' . bin2hex(random_bytes(3));
$moduleId = null;
$importedId = null;

try {
    $msg = threw(fn () => $ed->createModule(['module_key' => 'Bad Key', 'name' => 'No']));
    ok('a bad module key is refused', $msg !== null && str_contains((string) $msg, 'lowercase'));

    $made = $ed->createModule([
        'module_key' => $tag,
        'name'       => 'Studio Test Hole',
        'blurb'      => 'A hole that exists only for this test.',
        'level_min'  => 1,
        'level_max'  => 3,
        'region_type'=> 'dungeon',
    ]);
    $moduleId = (int) $made['module']['id'];
    ok('a module is created inactive', (int) $made['module']['is_active'] === 0);
    ok('it has a start location key', $made['module']['start_location_key'] === $tag . '_door');
    ok('a region was made', ($made['region']['region_key'] ?? '') === $tag . '_region');
    ok('a start room was made', ($made['location']['location_key'] ?? '') === $tag . '_door');

    $regionId = (int) $made['region']['id'];
    $doorId = (int) $made['location']['id'];

    $far = $ed->saveLocation([
        'location_key' => $tag . '_far',
        'region_id'    => $regionId,
        'name'         => 'The far room',
        'location_type'=> 'room',
        'description'  => 'Further in.',
        'map_x'        => 70,
        'map_y'        => 50,
    ]);
    ok('a second room saves', ($far['location_key'] ?? '') === $tag . '_far');
    $farId = (int) $far['id'];

    $ed->saveExit([
        'from_location_id' => $doorId,
        'to_location_id'   => $farId,
        'label'            => 'On into the dark',
    ]);
    $ed->saveExit([
        'from_location_id' => $farId,
        'to_location_id'   => $doorId,
        'label'            => 'Back to the door',
    ]);
    $door = $ed->getLocation($doorId);
    ok('the rooms are joined', count($door['exits']) === 1);

    $flagon = (int) $db->query(
        "SELECT id FROM locations WHERE location_key = 'flagon_common_room'"
    )->fetchColumn();
    $cross = threw(fn () => $ed->saveExit([
        'from_location_id' => $doorId,
        'to_location_id'   => $flagon,
        'label'            => 'Out of the module',
    ]));
    ok('a cross-module exit is refused', $cross !== null && str_contains((string) $cross, 'module'));

    $npc = $ed->createNpc([
        'npc_key'     => $tag . '_warden',
        'name'        => 'The Warden',
        'role'        => 'Doorkeeper',
        'description' => 'Waits by the door.',
        'location_id' => $doorId,
    ]);
    ok('a person is created without art', $npc['npc_key'] === $tag . '_warden');
    ok('and they stand in the start room', (int) $npc['location_id'] === $doorId);

    $msg = threw(fn () => $ed->createNpc([
        'npc_key'    => $tag . '_ghost',
        'name'       => 'Ghost',
        'sprite_key' => 'no_such_sprite',
    ]));
    ok('a missing sprite is refused', $msg !== null && str_contains((string) $msg, 'No art'));

    $quest = $ed->createQuest([
        'quest_key'    => $tag . '_job',
        'title'        => 'Look in the far room',
        'giver_npc_id' => (int) $npc['id'],
    ]);
    ok('a quest is created with an ending', count($quest['stages']) === 1);
    ok('that stage is terminal', (int) $quest['stages'][0]['is_terminal'] === 1);

    $quest = $ed->addStage((int) $quest['id'], [
        'stage_key' => 'go_there',
        'title'     => 'Go there',
        'objective' => 'Walk to the far room.',
        'target_location_id' => $farId,
    ]);
    ok('a second stage can be added', count($quest['stages']) === 2);
    $order = array_column($quest['stages'], 'stage_key');
    ok('new work sits before the ending', $order[0] === 'go_there' && $order[1] === 'done');
    ok('and it points at the far room',
        (int) $quest['stages'][0]['target_location_id'] === $farId);

    $fight = $ed->createEncounter([
        'encounter_key' => $tag . '_rats',
        'name'          => 'Something in the far room',
        'location_id'   => $farId,
        'is_ambush'     => 1,
        'monsters'      => [['monster_key' => 'goblin', 'quantity' => 2]],
    ]);
    ok('a fight is placed', ($fight['encounter_key'] ?? '') === $tag . '_rats');
    ok('and names the goblin', ($fight['monsters'][0]['monster_key'] ?? '') === 'goblin');

    $msg = threw(fn () => $ed->createEncounter([
        'encounter_key' => $tag . '_void',
        'name'          => 'Nothing',
        'location_id'   => $farId,
        'monsters'      => [['monster_key' => 'not_a_monster', 'quantity' => 1]],
    ]));
    ok('an unknown creature is refused', $msg !== null && str_contains((string) $msg, 'bestiary'));

    $seed = json_decode((string) ($made['region']['plan_json'] ?? ''), true);
    ok('a dungeon is seeded with a plan', is_array($seed) && count($seed['rooms'] ?? []) === 1);
    ok('and the start room is on it', ($seed['rooms'][0]['key'] ?? '') === $tag . '_door');

    $plan = [
        'rooms' => [
            ['id' => 0, 'key' => $tag . '_door', 'name' => 'The door you came in by',
             'gx' => 1, 'gy' => 1, 'w' => 2, 'h' => 2],
            ['id' => 1, 'key' => $tag . '_east', 'name' => 'East chamber',
             'gx' => 5, 'gy' => 1, 'w' => 2, 'h' => 2],
            ['id' => 2, 'key' => $tag . '_south', 'name' => 'South chamber',
             'gx' => 1, 'gy' => 4, 'w' => 2, 'h' => 2],
            ['id' => 3, 'key' => $tag . '_end', 'name' => 'The end',
             'gx' => 5, 'gy' => 4, 'w' => 2, 'h' => 2],
        ],
        'halls' => [
            ['a' => 0, 'b' => 1, 'door' => 'door'],
            ['a' => 1, 'b' => 3],
            ['a' => 3, 'b' => 2, 'hidden' => true],
        ],
    ];
    $saved = $ed->savePlan($regionId, $plan);
    ok('a four-room plan saves', count($saved['plan']['rooms'] ?? []) === 4);

    $idOf = [];
    foreach ($plan['rooms'] as $r) {
        $qid = $db->prepare('SELECT id FROM locations WHERE location_key = ?');
        $qid->execute([$r['key']]);
        $idOf[(int) $r['id']] = (int) $qid->fetchColumn();
    }
    ok('every plan room is a location', !in_array(0, $idOf, true) && count($idOf) === 4);

    $planIds = array_values($idOf);
    $exits = $db->prepare(
        'SELECT from_location_id, to_location_id, is_hidden
           FROM location_exits
          WHERE from_location_id IN (?,?,?,?) AND to_location_id IN (?,?,?,?)'
    );
    $exits->execute(array_merge($planIds, $planIds));
    $rows = $exits->fetchAll();
    ok('each hall is a pair of exits', count($rows) === 6);

    $hidden = 0;
    foreach ($rows as $e) {
        if ((int) $e['is_hidden'] === 1) {
            $hidden++;
        }
    }
    ok('the secret hall is hidden both ways', $hidden === 2);

    $adj = [];
    foreach ($rows as $e) {
        $adj[(int) $e['from_location_id']][] = (int) $e['to_location_id'];
    }
    $seen = [$idOf[0] => true];
    $queue = [$idOf[0]];
    while ($queue) {
        $at = array_shift($queue);
        foreach ($adj[$at] ?? [] as $to) {
            if (!isset($seen[$to])) {
                $seen[$to] = true;
                $queue[] = $to;
            }
        }
    }
    ok('the four rooms are walkable', count($seen) === 4);

    $chart = $saved['chart'];
    ok('the chart carries the compiled floorplan',
        is_array($chart['floorplan'] ?? null) && count($chart['floorplan']['rooms'] ?? []) === 4);

    $plan['halls'] = [
        ['a' => 0, 'b' => 1, 'door' => 'door'],
        ['a' => 3, 'b' => 2, 'hidden' => true],
    ];
    $ed->savePlan($regionId, $plan);
    $exits->execute(array_merge($planIds, $planIds));
    ok('removing a hall drops those exits', count($exits->fetchAll()) === 4);

    $clash = $plan;
    $clash['rooms'][1]['gx'] = 3;
    $msg = threw(fn () => $ed->savePlan($regionId, $clash));
    ok('an overlapping plan is refused', $msg !== null && str_contains((string) $msg, 'overlaps'));

    $town = $ed->createRegion([
        'module_id'   => $moduleId,
        'region_key'  => $tag . '_town',
        'name'        => 'A town',
        'region_type' => 'town',
    ]);
    $msg = threw(fn () => $ed->savePlan((int) $town['id'], $plan));
    ok('a town will not take a plan', $msg !== null && str_contains((string) $msg, 'dungeon'));

    $sprites = $ed->listSprites();
    ok('the sprite catalogue is the faces on disk', count($sprites) > 0);
    ok('and every listed key has a face',
        $sprites === [] || is_file(dirname(__DIR__) . '/assets/images/npcs/' . $sprites[0] . '_face.png'));

    $scoped = $ed->listNpcs($moduleId);
    ok('people are listed for this module only',
        count($scoped) === 1 && ($scoped[0]['npc_key'] ?? '') === $tag . '_warden');
    $rivermarkFolk = $ed->listNpcs();
    ok('the unscoped list is still the whole world', count($rivermarkFolk) > count($scoped));

    $scopedQ = $ed->listQuests($moduleId);
    ok('quests are listed for this module only',
        count($scopedQ) === 1 && ($scopedQ[0]['quest_key'] ?? '') === $tag . '_job');

    $termId = (int) $quest['stages'][1]['id'];
    $msg = threw(fn () => $ed->deleteStage($termId));
    ok('the last ending cannot be deleted', $msg !== null && str_contains((string) $msg, 'only ending'));
    $goId = (int) $quest['stages'][0]['id'];
    $after = $ed->deleteStage($goId);
    ok('a non-ending stage can be removed', count($after['stages']) === 1);

    $printed = (new AdventureBook($db))->build($tag);
    ok('the book exists for a draft', $printed !== null);
    $chapter = $printed['regions'][0] ?? [];
    ok('and the chapter carries the compiled plan',
        is_array($chapter['map']['floorplan'] ?? null)
        && count($chapter['map']['floorplan']['rooms'] ?? []) === 4);
    ok('the plan labels are the area numbers',
        ($chapter['map']['nodes'][0]['name'] ?? '') !== '');

    $doc = [
        'start' => 'hail',
        'nodes' => [
            'hail' => [
                'text' => 'There is something in the far room.',
                'choices' => [
                    [
                        'label'   => 'I will look.',
                        'effects' => [['start_quest' => $tag . '_job']],
                        'next'    => 'good',
                    ],
                    ['label' => 'Not now.', 'end' => true],
                ],
            ],
            'good' => [
                'text' => 'Then go.',
                'choices' => [['label' => 'Leave.', 'end' => true]],
            ],
        ],
    ];
    $savedDlg = $ed->saveDialogue((int) $npc['id'], $doc);
    ok('a conversation saves', ($savedDlg['dialogue']['start'] ?? '') === 'hail');

    $rich = $doc;
    $rich['nodes']['hail']['choices'][0]['effects'] = [
        ['start_quest' => $tag . '_job'],
        ['travel' => ['location' => $tag . '_door']],
        ['give_gold' => 5],
        ['approval' => ['aldric' => 1]],
        ['pressure' => 1],
    ];
    $rich['nodes']['hail']['choices'][0]['conditions'] = [
        ['flag' => 'seen_the_door'],
        ['quest' => $tag . '_job', 'status' => 'not_started'],
        ['gold_at_least' => 0],
        ['companion' => 'aldric', 'status' => 'active'],
    ];
    $savedRich = $ed->saveDialogue((int) $npc['id'], $rich);
    $fx0 = $savedRich['dialogue']['nodes']['hail']['choices'][0]['effects'] ?? [];
    $travel = null;
    foreach ($fx0 as $e) {
        if (isset($e['travel'])) {
            $travel = $e['travel'];
        }
    }
    ok('travel is stored as a location object',
        is_array($travel) && ($travel['location'] ?? '') === $tag . '_door');
    ok('and the rare effects stay on the reply',
        count($fx0) === 5);

    $here = $ed->getLocation($farId);
    ok('the far room lists its fight',
        count($here['encounters'] ?? []) === 1
        && ($here['encounters'][0]['encounter_key'] ?? '') === $tag . '_rats');

    $here = $ed->addLocationItem($farId, 'dart');
    ok('loot can be dropped', count($here['items'] ?? []) >= 1);
    $dropId = (int) $here['items'][0]['id'];
    $here = $ed->removeLocationItem($dropId);
    $leftLoot = array_filter($here['items'] ?? [], static fn ($it) => (int) $it['id'] === $dropId);
    ok('and picked back up', $leftLoot === []);

    $beats = $ed->moduleRun($moduleId);
    $kinds = array_column($beats, 'kind');
    ok('the run starts by arriving', ($kinds[0] ?? '') === 'arrive');
    ok('and has a talk beat', in_array('talk', $kinds, true));
    ok('and a fight beat', in_array('fight', $kinds, true));

    $on = $ed->setModuleActive($moduleId, true);
    ok('a draft can be put on the shelf', (int) $on['is_active'] === 1);
    $off = $ed->setModuleActive($moduleId, false);
    ok('and taken off again', (int) $off['is_active'] === 0);

    $moved = $ed->savePosition($farId, 80, 20);
    ok('a node can be moved',
        abs((float) $moved['map_x'] - 80) < 0.01 && abs((float) $moved['map_y'] - 20) < 0.01);

    $im = imagecreatetruecolor(8, 6);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 140));
    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);
    $data = 'data:image/png;base64,' . base64_encode($png);

    $msg = threw(fn () => $ed->saveArt('plate', $tag . '_region', $data));
    ok('a dungeon refuses a world plate', $msg !== null && str_contains((string) $msg, 'plan is the map'));

    $cover = $ed->saveArt('cover', $tag, $data);
    ok('a cover can be uploaded', ($cover['kind'] ?? '') === 'cover');
    $coverPath = dirname(__DIR__) . '/assets/images/modules/' . $tag . '.jpg';
    ok('and the file is on disk', is_file($coverPath));
    if (is_file($coverPath)) {
        unlink($coverPath);
    }

    $plate = $ed->saveArt('plate', $tag . '_town', $data);
    ok('a town can take a plate', ($plate['kind'] ?? '') === 'plate');
    $platePath = dirname(__DIR__) . '/assets/images/maps/' . $tag . '_town.png';
    ok('and that file is on disk', is_file($platePath));
    if (is_file($platePath)) {
        unlink($platePath);
    }

    $pack = $ed->packModule($moduleId);
    ok('a bundle names the format', ($pack['format'] ?? '') === 'rivermark.adventure');
    ok('and carries the plan', count($pack['regions'][0]['plan']['rooms'] ?? []) === 4);
    ok('and the person', count($pack['npcs'] ?? []) === 1);
    ok('and their conversation', count($pack['dialog'] ?? []) === 1);
    ok('and the fight', count($pack['encounters'] ?? []) >= 1);

    $copy = json_decode(str_replace($tag, $tag . 'b', json_encode($pack)), true);
    $in = $ed->importBundle($copy);
    $importedId = (int) $in['module']['id'];
    ok('the bundle can be imported under a new key',
        ($in['module']['module_key'] ?? '') === $tag . 'b');
    $copyRegion = $db->prepare('SELECT plan_json FROM regions WHERE region_key = ?');
    $copyRegion->execute([$tag . 'b_region']);
    $copyPlan = json_decode((string) $copyRegion->fetchColumn(), true);
    ok('the imported plan has four rooms', count($copyPlan['rooms'] ?? []) === 4);
    $copyNpc = $db->prepare('SELECT id FROM npcs WHERE npc_key = ?');
    $copyNpc->execute([$tag . 'b_warden']);
    ok('and the person came with it', $copyNpc->fetchColumn() !== false);

    $gone = $ed->deleteModule($moduleId);
    $moduleId = null;
    ok('the draft can be deleted', !empty($gone['deleted']));
    $left = $db->prepare('SELECT id FROM modules WHERE module_key = ?');
    $left->execute([$tag]);
    ok('and it is gone', $left->fetchColumn() === false);
    $npcLeft = $db->prepare('SELECT id FROM npcs WHERE npc_key = ?');
    $npcLeft->execute([$tag . '_warden']);
    ok('its people went with it', $npcLeft->fetchColumn() === false);
    $qLeft = $db->prepare('SELECT id FROM quests WHERE quest_key = ?');
    $qLeft->execute([$tag . '_job']);
    ok('and its quests', $qLeft->fetchColumn() === false);

    $ed->deleteModule($importedId);
    $importedId = null;
    $copyLeft = $db->prepare('SELECT id FROM modules WHERE module_key = ?');
    $copyLeft->execute([$tag . 'b']);
    ok('the imported copy can be deleted', $copyLeft->fetchColumn() === false);
} catch (Throwable $e) {
    $fail++;
    echo '  FAIL unexpected: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    if ($moduleId !== null) {
        try {
            $ed->deleteModule($moduleId);
        } catch (Throwable $e) {
            echo "  cleanup failed: {$e->getMessage()}\n";
        }
    }
    if ($importedId !== null) {
        try {
            $ed->deleteModule($importedId);
        } catch (Throwable $e) {
            echo "  import cleanup failed: {$e->getMessage()}\n";
        }
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
