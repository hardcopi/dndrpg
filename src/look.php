<?php
/**
 * A character's face, and how they change it.
 *
 * The Unity WebGL player used to live here — an iframe that saved through
 * `character/model` on its own — and the files remain under src/embed/,
 * unhooked. Re-dressing is now the same painted-bust picker as creation,
 * so this page is a redirect to create.php?redress=.
 *
 * Ownership is still the reason this exists as a page rather than a bare
 * Location header from characters.php: create.php is signed-in and the
 * API refuses a character that is not yours, but a bookmark to look.php
 * with somebody else's id should not dump the visitor onto the creator
 * with a stranger's name in the heading. The redirect keeps the id; the
 * creator and the API still check who owns it.
 */

require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();

$characterId = (int) ($_GET['character_id'] ?? 0);
header('Location: create.php' . ($characterId > 0 ? '?redress=' . $characterId : ''));
exit;
