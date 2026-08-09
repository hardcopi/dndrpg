<?php
/**
 * Give out the feats nobody was ever offered.
 *
 * Feats existed as a mechanism long before there was anything to choose. The
 * catalogue held one entry, that entry was inert, and the ceremony offered it
 * beside an ability score increase that was strictly better — so every
 * character who crossed an increase level took the increase, and every
 * companion recruited from a template arrived with no feats at all because
 * templates had nowhere to name any. Both of those are now fixable, and neither
 * fixes itself: a level already claimed is not claimed again, and a companion
 * already recruited is a `characters` row that no longer consults its template.
 *
 * Two jobs, reported separately because they go wrong in different ways:
 *
 *   Companions — a recruited companion is compared against the `feats` list on
 *                their content template. Anything on the template they do not
 *                have is granted. This is a reconstruction, not a guess: the
 *                template says what they were written with.
 *
 *   Characters — an increase level passed without a choice being recorded. That
 *                is a real hole rather than a suspicion, and it is read out of
 *                `pending_level_ups`: a resolved row whose `choices_json` names
 *                neither an `asi` nor a `feat`, or no row at all for a level the
 *                character is plainly past. The feat granted is picked from a
 *                per-class list below, which IS a guess — a defensible one, and
 *                the reason this half is opt-in on its own flag.
 *
 * Everything goes through LevelUpService::grantFeat, so what a feat is worth
 * arrives with it: Tough's hit points, Resilient's ability score, the Defense
 * fighting style's armour class. Writing `feats_json` directly would produce a
 * character whose sheet and whose feat disagree.
 *
 * Idempotent, and by record rather than by counting. A backfilled level is
 * written back to `pending_level_ups` as a settled row carrying the feat, so a
 * second run finds the choice already made and does nothing. Counting feats
 * against levels would have gone wrong the moment a companion arrived with one
 * from a template.
 *
 * Reports and changes nothing unless asked:
 *
 *     /tools/backfill_feats.php                    # what is missing
 *     /tools/backfill_feats.php?companions=1       # grant template feats
 *     /tools/backfill_feats.php?levels=1           # grant feats for passed levels
 *     /tools/backfill_feats.php?apply=1            # both
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

$applyAll = $want('apply');
$doCompanions = $applyAll || $want('companions');
$doLevels = $applyAll || $want('levels');

$db = db();
$levels = new LevelUpService($db);

/**
 * What each class is given when a level owes it a feat and nobody is present
 * to choose.
 *
 * Ordered by what the class actually does in this engine rather than by what
 * reads best: a Barbarian swings a heavy weapon so Savage Attacker and Great
 * Weapon Master are real damage, a Ranger opens with a bow so Archery is a
 * standing +2, a caster's whole turn is one spell so War Caster is the one that
 * keeps it. The first entry the character qualifies for wins, so an entry with
 * a prerequisite can sit above a safe fallback without stranding anybody.
 *
 * Nothing here is a rule. It is what a reasonable player would have picked,
 * offered because the alternative is a character who silently missed a feat.
 */
const CLASS_PREFERENCE = [
    'Barbarian' => ['savage_attacker', 'great_weapon_master', 'tough', 'resilient_constitution'],
    'Bard'      => ['actor', 'lucky', 'resilient_constitution', 'alert'],
    'Cleric'    => ['war_caster', 'healer', 'resilient_constitution', 'tough'],
    'Druid'     => ['war_caster', 'resilient_constitution', 'tough'],
    'Fighter'   => ['great_weapon_master', 'defense', 'savage_attacker', 'resilient_constitution'],
    'Monk'      => ['tough', 'alert', 'resilient_dexterity'],
    'Paladin'   => ['defense', 'resilient_constitution', 'tough'],
    'Ranger'    => ['archery', 'alert', 'resilient_dexterity'],
    'Rogue'     => ['alert', 'piercer', 'skulker', 'observant'],
    'Sorcerer'  => ['war_caster', 'resilient_constitution', 'alert'],
    'Warlock'   => ['war_caster', 'resilient_constitution', 'alert'],
    'Wizard'    => ['war_caster', 'resilient_constitution', 'tough'],
];

/** The fallback, for a class the list above does not name. */
const GENERAL_PREFERENCE = ['tough', 'alert', 'lucky', 'observant'];

/**
 * The first feat on this character's list they actually qualify for.
 *
 * Asks Feats rather than reasoning about levels here, so a prerequisite added
 * to the catalogue later cannot be quietly ignored by this tool.
 */
function pick_feat(array $character): ?string
{
    $order = array_merge(
        CLASS_PREFERENCE[trim((string) $character['class'])] ?? [],
        GENERAL_PREFERENCE
    );
    foreach ($order as $key) {
        if (Feats::qualifies($character, $key)['ok']) {
            return $key;
        }
    }
    return null;
}

$charStmt = $db->query(
    'SELECT c.*, cmp.starting_feats_json
       FROM characters c
       LEFT JOIN companions cmp ON cmp.companion_key = c.companion_key
      WHERE c.is_active = 1
      ORDER BY c.id'
);
$chars = $charStmt->fetchAll();

$pendingStmt = $db->prepare(
    'SELECT new_level, choices_json, resolved_at FROM pending_level_ups WHERE character_id = ?'
);

// A backfilled level is recorded as a settled ceremony carrying the feat.
// `hp_roll` stays NULL on a row invented here: the hit points for a level
// already lived were granted long ago by whatever path granted them, and
// writing a die that was never thrown would be inventing history rather than
// recording it.
$insertPending = $db->prepare(
    'INSERT IGNORE INTO pending_level_ups (character_id, new_level, choices_json, resolved_at)
     VALUES (?, ?, ?, NOW())'
);
$settlePending = $db->prepare(
    'UPDATE pending_level_ups SET choices_json = ?, resolved_at = COALESCE(resolved_at, NOW())
      WHERE character_id = ? AND new_level = ?'
);

$grantedTemplate = 0;
$grantedLevel = 0;
$owed = 0;
$waiting = 0;

echo "Companions, against their templates\n";
echo str_repeat('-', 74) . "\n";

$anyCompanion = false;
foreach ($chars as $c) {
    if (!$c['companion_key']) {
        continue;
    }
    $anyCompanion = true;
    $wanted = json_decode((string) ($c['starting_feats_json'] ?? ''), true);
    $wanted = is_array($wanted) ? $wanted : [];
    $missing = [];
    foreach ($wanted as $key) {
        if (is_string($key) && Feats::exists($key) && !Feats::has($c, $key)) {
            $missing[] = Feats::key($key);
        }
    }

    $notes = [];
    if ($wanted === []) {
        $notes[] = 'template names no feats';
    } elseif ($missing === []) {
        $notes[] = 'has all of ' . implode(', ', $wanted);
    } else {
        $notes[] = 'missing ' . implode(', ', $missing);
        if ($doCompanions) {
            foreach ($missing as $key) {
                try {
                    $levels->grantFeat((int) $c['id'], $key);
                    $grantedTemplate++;
                    $notes[] = 'GRANTED ' . $key;
                } catch (Throwable $e) {
                    $notes[] = 'REFUSED ' . $key . ': ' . $e->getMessage();
                }
            }
        }
    }

    printf("  %-5s %-22s %-10s %s\n", $c['id'], $c['name'], $c['class'], implode(' · ', $notes));
}
if (!$anyCompanion) {
    echo "  No recruited companions.\n";
}

echo "\nIncrease levels passed without a choice\n";
echo str_repeat('-', 74) . "\n";

foreach ($chars as $c) {
    $id = (int) $c['id'];
    $level = (int) $c['level'];

    $pendingStmt->execute([$id]);
    $rows = [];
    foreach ($pendingStmt->fetchAll() as $row) {
        $rows[(int) $row['new_level']] = $row;
    }

    $notes = [];
    foreach (Rules::ASI_LEVELS as $asiLevel) {
        if ($asiLevel > $level) {
            continue;
        }
        $row = $rows[$asiLevel] ?? null;

        // Still on the table. The player is going to be asked, and asking them
        // is better than answering for them.
        if ($row !== null && $row['resolved_at'] === null) {
            $waiting++;
            $notes[] = "level {$asiLevel} still to be claimed";
            continue;
        }

        $choices = json_decode((string) ($row['choices_json'] ?? ''), true);
        $choices = is_array($choices) ? $choices : [];
        if (isset($choices['asi']) || isset($choices['feat'])) {
            continue;
        }

        $owed++;
        // Re-read: an earlier level in this same loop may have moved their
        // ability scores, and the next pick is judged against what they are now.
        $current = $db->prepare('SELECT * FROM characters WHERE id = ?');
        $current->execute([$id]);
        $fresh = $current->fetch() ?: $c;

        $pick = pick_feat($fresh);
        if ($pick === null) {
            $notes[] = "level {$asiLevel} owes a feat, and nothing on the list fits";
            continue;
        }
        $name = Feats::get($pick)['name'];

        if (!$doLevels) {
            $notes[] = "level {$asiLevel} owes a feat — would take {$name}";
            continue;
        }

        try {
            $levels->grantFeat($id, $pick);
            $record = json_encode($choices + ['feat' => $pick, 'backfilled' => true]);
            if ($row === null) {
                $insertPending->execute([$id, $asiLevel, $record]);
            } else {
                $settlePending->execute([$record, $id, $asiLevel]);
            }
            $grantedLevel++;
            $notes[] = "level {$asiLevel}: GRANTED {$name}";
        } catch (Throwable $e) {
            $notes[] = "level {$asiLevel}: REFUSED {$pick}: " . $e->getMessage();
        }
    }

    if ($notes === []) {
        continue;
    }
    printf(
        "  %-5s %-22s %-10s lvl %-2s %s%s\n",
        $id,
        $c['name'],
        $c['class'],
        $level,
        $c['companion_key'] ? '[companion] ' : '',
        implode(' · ', $notes)
    );
}

if ($owed === 0 && $waiting === 0) {
    printf(
        "  Nobody. The first increase is at level %d and the highest character is %d.\n",
        Rules::ASI_LEVELS[0],
        max(array_map(static fn ($c) => (int) $c['level'], $chars ?: [['level' => 0]]))
    );
}

echo "\nWhat everyone holds now\n";
echo str_repeat('-', 74) . "\n";
$after = $db->query('SELECT id, name, class, level, feats_json FROM characters WHERE is_active = 1 ORDER BY id');
foreach ($after->fetchAll() as $c) {
    $held = Feats::detail($c);
    printf(
        "  %-5s %-22s %-10s %s\n",
        $c['id'],
        $c['name'],
        $c['class'],
        $held === [] ? '—' : implode(', ', array_column($held, 'name'))
    );
}

echo "\n";
if (!$doCompanions && !$doLevels) {
    echo "Reported only. Add ?companions=1 to grant the feats companion templates\n";
    echo "name, ?levels=1 to grant a feat for every increase level passed without\n";
    echo "a choice, or ?apply=1 for both.\n";
} else {
    printf(
        "Granted %d template feat(s) and %d for passed levels. %d level(s) are still\n"
        . "waiting on the player to claim them, and were left alone.\n",
        $grantedTemplate,
        $grantedLevel,
        $waiting
    );
}
