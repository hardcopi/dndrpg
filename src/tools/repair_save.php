<?php
/**
 * Put back what a content reload took.
 *
 * Every content table — spells, items, quests, npcs — is the parent of a foreign
 * key from player data with ON DELETE CASCADE. `sql/content.sql` used to delete
 * and re-insert every row on every load, so each reload silently emptied
 * `character_spells`, `character_inventory`, `character_known_npcs` and all of the
 * quest progress tables. The importer now upserts and cannot do this again
 * (tools/load_content.py refuses to emit the destructive form), but a save that
 * was already reloaded needs repairing by hand.
 *
 * What can honestly be restored, and what cannot:
 *
 *   Spells      — yes. A level-1 caster's list is fixed by class, so this is a
 *                 reconstruction rather than a guess. Run with --spells.
 *   Inventory   — only the class starting kit, and only for a character carrying
 *                 nothing at all. Anything bought, looted or given is gone and
 *                 this cannot know about it, so it is opt-in per character and
 *                 refuses to touch anyone still holding something. Run with
 *                 --gear.
 *   Where they are — yes, but only to somewhere safe. `characters` used to carry
 *                 a map id and a pair of tile coordinates; it now carries a
 *                 single `current_location_id`, and the move left every existing
 *                 save pointing at nothing. A character with a NULL or dangling
 *                 location cannot be played at all — `location/state` has
 *                 nowhere to describe — so this puts them in the Golden Flagon's
 *                 common room, which is where a new character starts and the one
 *                 place in the world that is always safe. It is a rescue, not a
 *                 restoration: it does not know where they were. Run with
 *                 --location.
 *   Quests      — no. Which stage of which quest a party had reached is not
 *                 derivable from anything that survived. Reported, not repaired.
 *   Known NPCs  — no, and it matters least: it re-populates itself as you talk to
 *                 people again.
 *
 * Reports and changes nothing unless asked:
 *
 *     /tools/repair_save.php                 # what is missing
 *     /tools/repair_save.php?spells=1        # re-grant caster spells
 *     /tools/repair_save.php?spells=1&gear=1 # and starting kits, where empty
 *     /tools/repair_save.php?location=1      # put the stranded back in the Flagon
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

$db = db();
$gen = new CharacterGenerator($db);

$doSpells = $want('spells');
$doGear = $want('gear');
$doLocation = $want('location');

// Where a stranded character is put back. Resolved by key rather than by id
// because ids are assigned by the content import and change whenever the world
// is reloaded; the key is what the files are written against.
const HOME_LOCATION_KEY = 'flagon_common_room';
$homeId = (new LocationEngine($db))->locationIdByKey(HOME_LOCATION_KEY);

// Companions are `characters` rows too and lost exactly the same things, so they
// are included — a cleric companion with no spells is as broken as a player one.
$chars = $db->query(
    'SELECT id, name, class, level, companion_key, current_location_id
       FROM characters WHERE is_active = 1 ORDER BY id'
)->fetchAll();

// A location id that no longer resolves reads exactly like one that was never
// set: both leave the party standing nowhere. They are counted apart anyway,
// because a dangling id means the world was reloaded under a live save and a
// NULL means the save predates locations entirely — different causes, and the
// second one is expected once.
$locationExists = $db->prepare('SELECT 1 FROM locations WHERE id = ?');
$setLocation = $db->prepare('UPDATE characters SET current_location_id = ? WHERE id = ?');

// Resolvable rows, not rows.
//
// The damage is not what it looks like. content.sql ran with
// FOREIGN_KEY_CHECKS = 0, so its DELETEs did not cascade — the player's rows
// survived and now point at ids that no longer exist. Every INNER JOIN in the app
// drops them, so the game shows a caster with no spells and a character carrying
// nothing while `SELECT COUNT(*)` cheerfully reports the rows are all still there.
// The first version of this tool counted rows and pronounced the save healthy.
$spellCount = $db->prepare(
    'SELECT COUNT(*) FROM character_spells cs
       INNER JOIN spells s ON s.id = cs.spell_id
      WHERE cs.character_id = ?'
);
$invCount = $db->prepare(
    'SELECT COUNT(*) FROM character_inventory ci
       INNER JOIN items i ON i.id = ci.item_id
      WHERE ci.character_id = ?'
);
// And the rows that resolve to nothing, which have to go before anything is
// re-granted or they linger forever as invisible clutter.
$spellOrphans = $db->prepare(
    'SELECT COUNT(*) FROM character_spells cs
      WHERE cs.character_id = ?
        AND NOT EXISTS (SELECT 1 FROM spells s WHERE s.id = cs.spell_id)'
);
$invOrphans = $db->prepare(
    'SELECT COUNT(*) FROM character_inventory ci
      WHERE ci.character_id = ?
        AND NOT EXISTS (SELECT 1 FROM items i WHERE i.id = ci.item_id)'
);
$dropSpellOrphans = $db->prepare(
    'DELETE cs FROM character_spells cs
      WHERE cs.character_id = ?
        AND NOT EXISTS (SELECT 1 FROM spells s WHERE s.id = cs.spell_id)'
);
$dropInvOrphans = $db->prepare(
    'DELETE ci FROM character_inventory ci
      WHERE ci.character_id = ?
        AND NOT EXISTS (SELECT 1 FROM items i WHERE i.id = ci.item_id)'
);

echo "Characters\n";
echo str_repeat('-', 68) . "\n";

$granted = 0;
$geared = 0;
$placed = 0;
$stranded = 0;

foreach ($chars as $c) {
    $id = (int) $c['id'];
    $spellCount->execute([$id]);
    $spells = (int) $spellCount->fetchColumn();
    $invCount->execute([$id]);
    $items = (int) $invCount->fetchColumn();

    $spellOrphans->execute([$id]);
    $deadSpells = (int) $spellOrphans->fetchColumn();
    $invOrphans->execute([$id]);
    $deadItems = (int) $invOrphans->fetchColumn();

    $expected = count(CharacterGenerator::STARTING_SPELLS[$c['class']] ?? []);
    $notes = [];

    if ($deadSpells || $deadItems) {
        $notes[] = "{$deadSpells} dead spell + {$deadItems} dead item row(s)";
        if ($doSpells || $doGear) {
            $dropSpellOrphans->execute([$id]);
            $dropInvOrphans->execute([$id]);
            $notes[] = 'CLEARED';
            // Re-read: clearing the orphans is what makes the counts below mean
            // anything.
            $spellCount->execute([$id]);
            $spells = (int) $spellCount->fetchColumn();
            $invCount->execute([$id]);
            $items = (int) $invCount->fetchColumn();
        }
    }

    if ($expected > 0 && $spells < $expected) {
        $notes[] = "knows {$spells} of {$expected} class spells";
        if ($doSpells) {
            $n = $gen->grantStartingSpells($id, (string) $c['class']);
            $granted += $n;
            $notes[] = "GRANTED {$n}";
        }
    }

    if ($items === 0) {
        $notes[] = 'carrying nothing';
        if ($doGear) {
            // Only ever for an empty inventory: re-granting a kit to somebody who
            // still has things would duplicate what they kept and hand back what
            // they sold.
            $gen->grantStartingGear($id, (string) $c['class']);
            $invCount->execute([$id]);
            $notes[] = 'GRANTED kit (' . (int) $invCount->fetchColumn() . ' items)';
            $geared++;
        }
    }

    // Where they are standing. A companion who is not travelling with anybody
    // legitimately has no location, so only characters in play are judged —
    // for the rest a NULL is the resting state, not damage.
    $loc = $c['current_location_id'] === null ? null : (int) $c['current_location_id'];
    $lost = null;
    if ($loc === null) {
        $lost = 'nowhere at all';
    } else {
        $locationExists->execute([$loc]);
        if ($locationExists->fetchColumn() === false) {
            $lost = "at location {$loc}, which no longer exists";
        }
    }

    if ($lost !== null && !$c['companion_key']) {
        $stranded++;
        $notes[] = $lost;
        if ($doLocation) {
            if ($homeId === null) {
                $notes[] = 'CANNOT PLACE (no ' . HOME_LOCATION_KEY . ')';
            } else {
                $setLocation->execute([$homeId, $id]);
                $notes[] = 'PLACED in the Flagon';
                $placed++;
            }
        }
    }

    printf(
        "  %-4s %-20s %-10s %s%s\n",
        $id,
        $c['name'],
        $c['class'],
        $c['companion_key'] ? '[companion] ' : '',
        $notes ? implode(' · ', $notes) : 'ok'
    );
}

// --- what cannot be repaired ----------------------------------------------
echo "\nQuest progress\n";
echo str_repeat('-', 68) . "\n";
$pq = (int) $db->query('SELECT COUNT(*) FROM party_quests')->fetchColumn();
$quests = (int) $db->query('SELECT COUNT(*) FROM quests')->fetchColumn();
printf("  %d quests defined; %d party quest rows survive.\n", $quests, $pq);
if ($pq === 0) {
    echo "  Nothing in progress. If a quest had been started before a content\n";
    echo "  reload, it is gone and cannot be reconstructed — the job board will\n";
    echo "  offer it again.\n";
}

echo "\nKnown NPCs\n";
echo str_repeat('-', 68) . "\n";
$kn = (int) $db->query('SELECT COUNT(*) FROM character_known_npcs')->fetchColumn();
printf("  %d rows. Repopulates itself as you talk to people; nothing to do.\n", $kn);

// --- the world itself -------------------------------------------------------
//
// If the graph is empty or the Flagon is missing there is nothing to repair a
// stranded character to, and every count above is misleading — so it is said
// here rather than left to be inferred from a failed placement.
echo "\nThe world\n";
echo str_repeat('-', 68) . "\n";
$locCount = (int) $db->query('SELECT COUNT(*) FROM locations')->fetchColumn();
$exitCount = (int) $db->query('SELECT COUNT(*) FROM location_exits')->fetchColumn();
printf("  %d location(s), %d exit(s).\n", $locCount, $exitCount);
if ($homeId === null) {
    printf("  No '%s' — load sql/content.sql; nowhere to place anybody.\n", HOME_LOCATION_KEY);
} else {
    printf("  '%s' is id %d; that is where the stranded are put.\n", HOME_LOCATION_KEY, $homeId);
}
printf("  %d character(s) in play have no usable location.\n", $stranded);

echo "\n";
if (!$doSpells && !$doGear && !$doLocation) {
    echo "Reported only. Add ?spells=1 to re-grant caster spells, ?gear=1 to\n";
    echo "re-grant starting kits to characters carrying nothing, and ?location=1\n";
    echo "to put characters who are standing nowhere back in the Flagon.\n";
} else {
    printf(
        "Granted %d spell(s) and %d starting kit(s); placed %d character(s).\n",
        $granted,
        $geared,
        $placed
    );
}
