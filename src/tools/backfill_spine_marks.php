<?php
/**
 * Set the spine's sighting flags for scenes a party has already played.
 *
 * The problem this exists for
 * ---------------------------
 * Act I's spine is five sightings of one surveyor's mark, and the quest that
 * lets a player start collecting them — `the_same_mark` — opens once a party has
 * seen the mark in any two places. Each sighting is a `set_flag` effect on a
 * stage of an already-shipped quest: `kessa_debt`'s terms, `ledger_and_lie`'s
 * paymaster, the ledge at the bottom of Ilse's shaft, and so on.
 *
 * A stage effect fires when the stage is ENTERED. Those effects did not exist
 * until the spine was written, so for a save that is already past those stages
 * they will never fire at all: the party stood in the room, read the journal
 * entry, and the flag that records it was added to the game afterwards. The
 * scenes are spent and cannot be replayed.
 *
 * The result is a save where the spine silently cannot open. It is not an error
 * anywhere — no route fails, nothing is logged — the opener is simply never
 * offered, which is the worst shape a content bug can take. On the save this was
 * written against, four of the five sightings were already behind the party and
 * only `grain_stamp_seen` was set: one flag, and the opener wants two.
 *
 * What it does
 * ------------
 * For every stage whose effects set one of the sighting flags, every party that
 * has ENTERED that stage gets the flag. `party_quest_stages` is the record of
 * entry and it is exact — this asks "was this party in the room", not "does this
 * look like the sort of party that would have been", so nothing here is a guess.
 *
 * The mapping is read out of `quest_stages.effects_json` rather than written
 * down here. A hardcoded list would be a second copy of the content, correct
 * only until somebody moves a sighting to a different stage — and the whole
 * point of the spine is that the sightings sit inside other people's quests.
 *
 * Run it AFTER loading content, not before: it reads the effects the load
 * writes. `tools/apply_content_safely.py` is the load.
 *
 * Idempotent. A flag already set is left alone, including one set to a different
 * value by ordinary play, because the sighting flags are plain markers and the
 * only thing that matters is that they are present.
 *
 *     /tools/backfill_spine_marks.php            # what is missing, and for whom
 *     /tools/backfill_spine_marks.php?apply=1    # set them
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$argvFlags = [];
foreach (($_SERVER['argv'] ?? []) as $a) {
    $argvFlags[ltrim($a, '-')] = true;
}
$want = static fn (string $flag): bool =>
    !empty($_GET[$flag]) || isset($argvFlags[$flag]);

$apply = $want('apply');
$db = db();

/**
 * The flags a sighting can set.
 *
 * `grain_stamp_seen` is named explicitly because it predates the spine — it
 * shipped with `cellar_rats` and the spine was deliberately built to read it
 * rather than adding a parallel flag beside it. Everything else follows the
 * `mark_seen_` convention, matched by prefix so a sixth sighting needs no change
 * here.
 */
const MARK_PREFIX = 'mark_seen_';
const MARK_EXTRA = ['grain_stamp_seen'];

$stages = $db->query(
    'SELECT qs.id AS stage_id, qs.stage_key, qs.effects_json, q.quest_key
       FROM quest_stages qs
       INNER JOIN quests q ON q.id = qs.quest_id
      WHERE qs.effects_json IS NOT NULL AND qs.effects_json <> ""
      ORDER BY q.quest_key, qs.sort_order'
)->fetchAll();

/** @var array<int, array{quest:string, stage:string, flags:string[]}> */
$sightings = [];
foreach ($stages as $row) {
    $effects = json_decode((string) $row['effects_json'], true);
    if (!is_array($effects)) {
        continue;
    }
    $flags = [];
    foreach ($effects as $effect) {
        $key = is_array($effect) ? ($effect['set_flag'] ?? null) : null;
        if (!is_string($key)) {
            continue;
        }
        if (str_starts_with($key, MARK_PREFIX) || in_array($key, MARK_EXTRA, true)) {
            $flags[] = $key;
        }
    }
    if ($flags !== []) {
        $sightings[(int) $row['stage_id']] = [
            'quest' => (string) $row['quest_key'],
            'stage' => (string) $row['stage_key'],
            'flags' => array_values(array_unique($flags)),
        ];
    }
}

echo "Spine sightings found in loaded content\n";
echo "--------------------------------------\n";
if ($sightings === []) {
    echo "None. Either the spine content is not loaded yet, or no stage sets a\n";
    echo "'" . MARK_PREFIX . "*' flag. Load content first — see CLAUDE.md.\n";
    exit(0);
}
foreach ($sightings as $s) {
    printf("  %-22s %-22s %s\n", $s['quest'], $s['stage'], implode(', ', $s['flags']));
}

// Which parties entered which of those stages, and what they already hold.
$entered = $db->query(
    'SELECT DISTINCT party_id, stage_id FROM party_quest_stages'
)->fetchAll();

$held = [];
foreach ($db->query('SELECT party_id, flag_key FROM world_flags')->fetchAll() as $f) {
    $held[(int) $f['party_id']][(string) $f['flag_key']] = true;
}

/** @var array<int, string[]> */
$missing = [];
foreach ($entered as $row) {
    $stageId = (int) $row['stage_id'];
    $partyId = (int) $row['party_id'];
    if (!isset($sightings[$stageId])) {
        continue;
    }
    foreach ($sightings[$stageId]['flags'] as $flag) {
        if (!isset($held[$partyId][$flag])) {
            $missing[$partyId][$flag] = $sightings[$stageId]['quest'] . '/' . $sightings[$stageId]['stage'];
        }
    }
}

echo "\nParties\n-------\n";
$parties = $db->query('SELECT id, name FROM parties ORDER BY id')->fetchAll();
$world = new WorldState($db);
$total = 0;

foreach ($parties as $party) {
    $partyId = (int) $party['id'];
    $has = [];
    foreach ($held[$partyId] ?? [] as $flag => $_) {
        if (str_starts_with($flag, MARK_PREFIX) || in_array($flag, MARK_EXTRA, true)) {
            $has[] = $flag;
        }
    }
    $want = $missing[$partyId] ?? [];

    printf(
        "\n  party %d (%s)\n    holds %d sighting(s): %s\n",
        $partyId,
        (string) ($party['name'] ?? '—'),
        count($has),
        $has ? implode(', ', $has) : 'none'
    );

    if ($want === []) {
        echo "    nothing owed\n";
        // The opener wants two. Said out loud for a party that has played
        // nothing yet, because "nothing owed" and "cannot open the spine" look
        // identical otherwise.
        if (count($has) < 2) {
            echo "    NOTE: fewer than two sightings, so `the_same_mark` cannot open yet.\n";
            echo "          That is correct for a party that has not played those scenes.\n";
        }
        continue;
    }

    foreach ($want as $flag => $where) {
        $total++;
        printf("    %s %-22s (entered %s)\n", $apply ? 'set  ' : 'owed ', $flag, $where);
        if ($apply) {
            $world->set($partyId, $flag);
        }
    }
    if ($apply) {
        printf("    now holds %d sighting(s)\n", count($has) + count($want));
    }
}

echo "\n";
if ($total === 0) {
    echo "Nothing to do.\n";
} elseif ($apply) {
    echo "Set {$total} flag(s).\n";
} else {
    echo "{$total} flag(s) owed. Re-run with ?apply=1 to set them.\n";
}
