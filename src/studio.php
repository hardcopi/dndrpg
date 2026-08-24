<?php
/**
 * The adventure studio: one module at a time, made rather than reviewed.
 *
 * `/content.php` stays as the copy desk for the world that already exists.
 * This is the blank page — create a module, draw it, write the people, play
 * it, print it. Admin only, same gate as the content editors.
 *
 *   /studio.php                 the shelf, and the form for a new adventure
 *   /studio.php?module=<key>    that module's map, scene and run
 */

require_once __DIR__ . '/app/page_guard.php';
require_admin_page();

$moduleKey = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['module'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Studio — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
</head>
<body class="studio-page">
  <?php require APP_PATH . '/inc/site_bar.php'; ?>
  <header class="admin-head">
    <div>
      <p class="auth-eyebrow">Rivermark Chronicles</p>
      <h1 id="st-title">Studio</h1>
    </div>
    <nav class="admin-nav" id="st-nav"></nav>
  </header>

  <p class="admin-error" id="st-error" role="alert" aria-live="polite"></p>

  <main id="st-main">
    <p class="help-hint">Loading…</p>
  </main>

  <script>
    window.STUDIO_MODULE = <?= json_encode($moduleKey) ?>;
    /* ui-map.js reaches for Game.esc and nothing else of the game. */
    window.Game = { esc: (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c])) };
  </script>
  <script src="<?= asset('assets/js/api.js') ?>"></script>
  <script src="<?= asset('assets/js/svg-view.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-map.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-dialog-graph.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-plan.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-chart.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-talk.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-studio.js') ?>"></script>
</body>
</html>
