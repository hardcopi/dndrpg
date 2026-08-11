<?php
/**
 * The content editors: NPCs, their conversations, quests, and places.
 *
 * Places are here rather than in a map tool because there is no map any more —
 * a location is prose, a handful of exits and a dot on a chart, which is a
 * form. What the old editor lacked was writes that survived a rebuild, and that
 * came from the database becoming the source of truth.
 */

require_once __DIR__ . '/app/page_guard.php';
require_admin_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Content — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="admin-page">
  <header class="admin-head">
    <div>
      <p class="auth-eyebrow">Rivermark Chronicles</p>
      <h1>Content</h1>
    </div>
    <nav class="admin-nav">
      <a class="btn" href="game.php">Adventure</a>
      <a class="btn" href="admin.php">Accounts</a>
      <!--
        Two exports, and they are not the same thing. "Export to files" writes
        the database back to content/**/*.json so the work can be committed;
        this one prints the module as the book a GM would run it from. The
        wording says which is which — a button called Export beside a button
        called Export is how somebody publishes a PDF when they meant to commit.
      -->
      <a class="btn" href="adventure_print.php"
         title="The module as a printed adventure — areas, cast, quests and stat blocks. Print it to PDF.">Export as PDF</a>
      <button type="button" class="btn btn-primary" id="btn-export">Export to files</button>
    </nav>
  </header>

  <p class="admin-note" id="export-result"></p>

  <div class="ce-tabs" role="tablist">
    <button type="button" class="ce-tab is-on" data-tab="npcs" role="tab">People</button>
    <button type="button" class="ce-tab" data-tab="quests" role="tab">Quests</button>
    <button type="button" class="ce-tab" data-tab="places" role="tab">Places</button>
    <button type="button" class="ce-tab" data-tab="talk" role="tab">Conversations</button>
  </div>

  <main class="ce-main">
    <aside class="ce-list" id="ce-list"><p class="help-hint">Loading…</p></aside>
    <section class="ce-detail" id="ce-detail">
      <p class="help-hint">Choose somebody on the left.</p>
    </section>
  </main>

  <p class="admin-error" id="ce-error" role="alert" aria-live="polite"></p>

  <script src="assets/js/api.js"></script>
  <script src="assets/js/ui-dialog-graph.js"></script>
  <script src="assets/js/ui-content.js"></script>
</body>
</html>
