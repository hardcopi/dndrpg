<?php
/**
 * Write the database back out to content/.
 *
 * The database is the source of truth now, which is what makes this necessary
 * rather than a convenience: without a way back to files, authored work exists
 * only in a Docker volume, cannot be committed, cannot be diffed, and cannot be
 * deployed anywhere else.
 *
 *     docker compose exec -T php php /var/www/html/tools/export_content.php
 *
 * Everything ContentExporter covers — regions and their locations, NPCs, their
 * dialogue, and quests — is written by that one class. There is no --maps flag
 * any more: the tile grid became the location graph, so the maps are the region
 * files, and tools/snapshot_map.php went with the tables it read.
 *
 * Nothing is destructive: files are compared before writing, so an export that
 * changes nothing touches nothing and `git status` stays honest.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This is a command line tool.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$exporter = new ContentExporter(db());
if (!$exporter->writable()) {
    fwrite(STDERR, "Cannot write to {$exporter->root()}.\n");
    fwrite(STDERR, "The user running PHP needs write access there for exporting to work.\n");
    exit(1);
}

$result = $exporter->exportAll();

printf(
    "content: %d regions, %d npcs, %d conversations, %d quests\n",
    $result['counts']['regions'],
    $result['counts']['npcs'],
    $result['counts']['dialog'],
    $result['counts']['quests']
);

if ($result['written']) {
    printf("%d file(s) changed:\n", count($result['written']));
    foreach ($result['written'] as $w) {
        echo "  $w\n";
    }
} else {
    echo "nothing changed — the files already match the database\n";
}

echo "\nThese files are the deployable copy. Commit them.\n";
