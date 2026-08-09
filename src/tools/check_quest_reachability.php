<?php
/**
 * Can this party still finish the quests it is carrying?
 *
 * Why a tool rather than a look
 * -----------------------------
 * A quest advances when a `quest_stage` effect fires, and those live inside
 * dialogue. So "is this quest still finishable" is really "can this party still
 * reach the node that carries the effect", and that question cannot be answered
 * by reading the quest file — the answer is in somebody's conversation, behind
 * whatever conditions guard the variants and replies on the way to it.
 *
 * It also cannot be answered by walking the conversation in the engine, which is
 * the trap this exists to avoid. `DialogEngine::node()` applies a node's
 * `on_enter` effects as a side effect of rendering it: probing a tree by walking
 * it SETS the flags it is trying to measure, so the second probe sees a
 * different world from the first and every answer after the first is about a
 * save the tool itself created. Diagnosing a stuck quest that way produced three
 * different verdicts in three runs.
 *
 * So this walks the authored tree instead and evaluates conditions with
 * `Requirements::pass()` against a snapshot of the party's real context. Nothing
 * is written and nothing fires.
 *
 * The honest limitation: it is a single-snapshot walk. A path that opens only
 * after a reply sets a flag reads as closed here, because that flag is not set
 * yet. So a "reachable" is trustworthy and a "no path found" means *under the
 * world as it stands now* — worth investigating, not proof of a dead end. It is
 * the difference between "this is definitely fine" and "go and look at this".
 *
 *     /tools/check_quest_reachability.php?party_id=78
 *     /tools/check_quest_reachability.php              # every party with active quests
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$db = db();
$only = isset($_GET['party_id']) ? (int) $_GET['party_id'] : null;

/** Every `quest_stage` effect anywhere in a decoded dialogue tree. */
function advancing_stages($node, string $questKey, array &$out): void
{
    if (!is_array($node)) {
        return;
    }
    if (isset($node['quest_stage']['quest']) && $node['quest_stage']['quest'] === $questKey) {
        $out[] = (string) ($node['quest_stage']['stage'] ?? '?');
    }
    foreach ($node as $v) {
        advancing_stages($v, $questKey, $out);
    }
}

/**
 * The node keys this party can actually get to, without applying anything.
 *
 * Mirrors DialogEngine's own two rules — first matching variant wins, and a
 * reply whose conditions fail is not offered — and deliberately mirrors nothing
 * else. It does not need to: it is asking about shape, not about outcome.
 *
 * @return array<string, true>
 */
function reachable_nodes(array $tree, array $ctx): array
{
    $start = (string) ($tree['start'] ?? 'greeting');
    $seen = [];
    $queue = [$start];
    while ($queue) {
        $key = array_shift($queue);
        if (isset($seen[$key]) || !isset($tree['nodes'][$key])) {
            continue;
        }
        $seen[$key] = true;

        $variants = $tree['nodes'][$key];
        if (!array_is_list($variants)) {
            $variants = [$variants];
        }
        // First match wins, exactly as the engine does it. `once` is ignored:
        // a retired variant would only ever open MORE of the tree, so counting
        // it keeps this on the optimistic side, which is the right side for a
        // check whose false alarms cost somebody an investigation.
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if (!Requirements::pass($variant['conditions'] ?? null, $ctx)) {
                continue;
            }
            foreach ($variant['choices'] ?? [] as $choice) {
                if (!Requirements::pass($choice['conditions'] ?? null, $ctx)) {
                    continue;
                }
                foreach (['next'] as $k) {
                    if (isset($choice[$k]) && is_string($choice[$k])) {
                        $queue[] = $choice[$k];
                    }
                }
                foreach (['on_success', 'on_failure'] as $k) {
                    if (isset($choice['check'][$k]) && is_string($choice['check'][$k])) {
                        $queue[] = $choice['check'][$k];
                    }
                }
            }
            break;
        }
    }
    return $seen;
}

$parties = $db->query(
    'SELECT DISTINCT party_id FROM party_quests WHERE status = \'active\' ORDER BY party_id'
)->fetchAll(PDO::FETCH_COLUMN);

$npcs = $db->query('SELECT npc_key, dialogue_json FROM npcs WHERE dialogue_json IS NOT NULL')->fetchAll();

$stuckTotal = 0;
foreach ($parties as $partyId) {
    $partyId = (int) $partyId;
    if ($only !== null && $partyId !== $only) {
        continue;
    }

    $leader = (int) ($db->query(
        'SELECT character_id FROM character_party WHERE party_id = ' . $partyId . ' LIMIT 1'
    )->fetchColumn() ?: 0);
    $ctx = (new WorldState($db))->context($partyId, $leader ?: null);

    $active = $db->prepare(
        'SELECT q.quest_key, q.title, s.stage_key
           FROM party_quests pq
           INNER JOIN quests q ON q.id = pq.quest_id
           LEFT JOIN quest_stages s ON s.id = pq.current_stage_id
          WHERE pq.party_id = ? AND pq.status = \'active\'
          ORDER BY q.quest_key'
    );
    $active->execute([$partyId]);
    $rows = $active->fetchAll();

    printf("party %d — %d active quest(s)\n%s\n", $partyId, count($rows), str_repeat('-', 60));

    foreach ($rows as $row) {
        $questKey = (string) $row['quest_key'];
        $here = (string) ($row['stage_key'] ?? '-');

        // Is the stage the party is on already an ending? Then nothing is owed.
        $terminal = (int) ($db->query(
            'SELECT is_terminal FROM quest_stages s
               INNER JOIN quests q ON q.id = s.quest_id
              WHERE q.quest_key = ' . $db->quote($questKey)
            . ' AND s.stage_key = ' . $db->quote($here)
        )->fetchColumn() ?: 0);

        $found = [];
        foreach ($npcs as $npc) {
            $tree = json_decode((string) $npc['dialogue_json'], true);
            if (!is_array($tree) || !isset($tree['nodes'])) {
                continue;
            }
            $carriers = [];
            foreach ($tree['nodes'] as $nodeKey => $nodeVal) {
                $stages = [];
                advancing_stages($nodeVal, $questKey, $stages);
                if ($stages) {
                    $carriers[$nodeKey] = array_values(array_unique($stages));
                }
            }
            if (!$carriers) {
                continue;
            }
            $reach = reachable_nodes($tree, $ctx);
            foreach ($carriers as $nodeKey => $stages) {
                // A node that only re-enters the stage the party is already on
                // is not progress; it is where they are.
                $forward = array_values(array_filter($stages, static fn ($s) => $s !== $here));
                if (!$forward) {
                    continue;
                }
                if (isset($reach[$nodeKey])) {
                    $found[] = sprintf('%s:%s -> %s', $npc['npc_key'], $nodeKey, implode('/', $forward));
                }
            }
        }

        if ($terminal) {
            printf("  ok      %-26s at an ending (%s)\n", $questKey, $here);
        } elseif ($found) {
            printf("  ok      %-26s at %s\n", $questKey, $here);
            foreach (array_unique($found) as $route) {
                printf("          %-26s   via %s\n", '', $route);
            }
        } else {
            $stuckTotal++;
            printf("  STUCK   %-26s at %-24s no reachable node advances it\n", $questKey, $here);
        }
    }
    echo "\n";
}

if ($stuckTotal === 0) {
    echo "Nothing stuck.\n";
} else {
    printf("%d quest(s) with no visible way forward.\n", $stuckTotal);
    echo "Single-snapshot walk: a path that only opens after a flag is set reads\n";
    echo "as closed here. Treat each as worth looking at, not as proven broken.\n";
}
