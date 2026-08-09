<?php
/**
 * End every open delve, once, at deploy.
 *
 * The dungeon dressing (doors that lock, passages that bite) changed what
 * DelveEngine writes for a floor — but an OPEN delve's rows were written by
 * the old code, while its chart is regenerated from the seed by the new. Left
 * alone, the map would draw a locked door on an exit row that has no lock in
 * it: two descriptions of one floor, disagreeing. A delve is hours-long
 * per-party scratch that the engine already treats as disposable, so the
 * honest fix is the small one: the party climbs out, the floor stops
 * existing, and the next descent is built whole by the new code.
 *
 * Goes through DelveEngine->end(), never raw SQL: end() moves the party to
 * the Mouth BEFORE dropping the region. characters.current_location_id is
 * ON DELETE SET NULL, so deleting rows first would strand every character
 * mid-air with no location — the save-repair case, manufactured in bulk.
 *
 * Run:  docker compose exec -T php php /var/www/html/tools/end_open_delves.php
 * Safe to run twice; the second pass finds nothing to do.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = db();
$engine = new DelveEngine($db);

$open = $db->query('SELECT party_id, depth FROM dungeon_delves')->fetchAll(PDO::FETCH_ASSOC);
if (!$open) {
    echo "No open delves. Nothing to do.\n";
    exit(0);
}

foreach ($open as $delve) {
    $partyId = (int) $delve['party_id'];
    $engine->end($partyId);
    echo "Party {$partyId}: brought up from level {$delve['depth']}, floor dropped.\n";
}
echo count($open) . " delve(s) ended.\n";
