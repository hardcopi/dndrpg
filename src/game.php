<?php
require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();
if (!session_character_id()) {
    // Allow landing; JS will redirect if no session
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Adventure — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
  <!-- The Amiga window skin. Scoped to this page; see the file header. -->
  <link rel="stylesheet" href="<?= asset('assets/css/game-amiga.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/scenefx.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/game-storybook.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/fonts.css') ?>">
  <!-- Generated dice geometry; see tools/gen_dice_css.py. -->
  <link rel="stylesheet" href="<?= asset('assets/css/dice.css') ?>">
</head>
<body class="game-page">
  <!--
    Icon sprite, inlined from assets/icons.svg.

    Inline `<symbol>`s rather than a linked file so they cost no request and
    inherit `currentColor`, which is what lets one hover rule light the whole
    button. The drawings themselves live in assets/icons.svg so that a preview
    harness can fetch them without a session — this page cannot be fetched
    without one, and a tool that scraped it got the login form.
  -->
<?= file_get_contents(__DIR__ . '/assets/icons.svg') ?>

  <!--
    The iron window. The three columns stay the game's; the plate, the corner
    caps and the status line are the frame around them, the same furniture as
    the character select. The plate is not a heading — the place's own name
    is the page's h1, written into the scene when a location loads.
  -->
  <div class="gw-window">
    <span class="gw-bracket gw-bracket-nw" aria-hidden="true"></span>
    <span class="gw-bracket gw-bracket-ne" aria-hidden="true"></span>
    <span class="gw-bracket gw-bracket-sw" aria-hidden="true"></span>
    <span class="gw-bracket gw-bracket-se" aria-hidden="true"></span>
    <div class="gw-plate" aria-hidden="true">
      <span class="gw-diamond"></span>
      <p class="gw-plate-title">Rivermark</p>
      <span class="gw-diamond"></span>
    </div>

  <!--
    Two columns while exploring: the scene (painting or chart, then the
    prose) on the left, and one rail on the right for the party, the verbs
    and a Tasks | Log | People panel. A fight keeps its own arrangement.

    This is a rewrite of a layout built for a tile map, where the map was the
    screen and everything else floated on top of it as a `pointer-events: none`
    HUD. None of that applies to a page of prose and menus: text wants a
    measure, not a viewport, and the panels that used to float now have room to
    simply sit somewhere. The overlays — combat, dialogue, the d20 ceremony,
    modals — still cover everything, because each of them is a different screen
    rather than a panel.
  -->
  <div class="game-shell">
    <!--
      The fight's top line: the encounter, the round, whose turn it is.

      It is a child of the shell rather than of `#combat-root` so that it can
      span BOTH columns — the board and the log — rather than sitting in the
      board's column alone. Initiative is a list on the left of the board now,
      so this row is only the facts you play the round off. ui-combat.js
      renders into it by id, the same arrangement `#cbt-inspector` uses; the
      CSS below shows it only in a fight, and the rail starts under it.
    -->
    <div class="cbt-top" id="cbt-top"></div>

    <main class="scene">
      <!--
        The bar is where you are AND the menu, on one line.

        It was the menu alone, justified right, with 205px of nothing beside it
        — and a `.loc-header` underneath repeating the name the chart already
        writes on the party's own node (ui-map.js draws a `.wm-label` for every
        node, `is-here` included). So the header cost 83px of a 578px column to
        say a thing that was already on screen 90px lower down.

        What is left here is the REGION, which the chart does not name anywhere.
        It is filled by game.js and deliberately never cleared: you are still
        standing in Rivermark while the fight is on, and a bar that emptied
        itself on every mode switch would flicker between screens.
      -->
      <div class="topbar" role="toolbar" aria-label="Menu">
        <div class="topbar-where" id="topbar-where" aria-hidden="true"></div>
        <div class="topbar-actions hud-icons">
          <button type="button" class="icon-btn" id="btn-journal" title="Journal (J)" aria-label="Journal">
            <svg aria-hidden="true"><use href="#i-journal"></use></svg>
          </button>
          <button type="button" class="icon-btn" id="btn-rolls" title="Recent rolls" aria-label="Recent rolls">
            <svg aria-hidden="true"><use href="#i-dice"></use></svg>
          </button>
          <button type="button" class="icon-btn" id="btn-camp" title="Camp" aria-label="Camp">
            <svg aria-hidden="true"><use href="#i-camp"></use></svg>
          </button>
<?php /* The map button is gone with the map modal: the chart IS the scene now
         (see renderLocation), so a button that opened it opened what was
         already on screen. */ ?>
          <button type="button" class="icon-btn" id="btn-refresh" title="Reload" aria-label="Reload">
            <svg aria-hidden="true"><use href="#i-refresh"></use></svg>
          </button>
          <span class="icon-sep" aria-hidden="true"></span>
          <button type="button" class="icon-btn" id="btn-fullscreen" title="Fullscreen" aria-label="Toggle fullscreen">
            <svg aria-hidden="true"><use href="#i-expand"></use></svg>
          </button>
          <a class="icon-btn" href="index.php" title="Main menu" aria-label="Main menu">
            <svg aria-hidden="true"><use href="#i-home"></use></svg>
          </a>
        </div>
      </div>

      <!--
        aria-live on the banner, not on the thing that raised the error: an
        error is announced because it happened, and the element it happened to
        may be off screen or already gone.
      -->
      <div id="error-banner" class="error-banner hidden" role="alert"></div>

      <!--
        The three screens. Exactly one is visible: game.js toggles `hidden` on
        the first two by mode, and the dialogue theatre puts itself over the
        top when a conversation opens.
      -->
      <div class="screen" id="location-root">
        <p class="help-hint">Loading…</p>
      </div>
      <div class="screen combat-screen hidden" id="combat-root"></div>
      <div class="dialogue-theatre hidden" id="dialogue-root"></div>
    </main>

    <!--
      The one rail, on the right: the party, the verbs, then a tabbed panel for
      Tasks, Log and People here — one visible at a time, so the scene column
      gets the width the left rail used to take.

      The people are drawn on the painting as hotspots; the People tab is the
      compact list of the same cast, for whoever has no spot and for reading.
      In a fight the party and the verbs stand down, the tab bar goes, and the
      log takes the column (see game-storybook.css).
    -->
    <aside class="rail rail-right">
      <section class="rail-pane rail-party">
        <h2 class="rail-pane-head">The Party</h2>
        <div class="hud-party" id="party-rail" aria-label="Party"></div>
      </section>

      <section class="gw-actions">
        <h2 class="rail-pane-head">Actions</h2>
        <div class="hud-actions action-bar" id="action-bar" role="toolbar" aria-label="Actions"></div>
      </section>

      <section class="rail-tabs" id="rail-tabs">
        <div class="rail-tab-bar" role="tablist" aria-label="Tasks, log and people">
          <button type="button" class="rail-tab" role="tab" id="rail-tab-tasks" data-tab="tasks" aria-controls="rail-panel-tasks">Tasks</button>
          <button type="button" class="rail-tab" role="tab" id="rail-tab-log" data-tab="log" aria-controls="rail-panel-log">Log</button>
          <button type="button" class="rail-tab" role="tab" id="rail-tab-people" data-tab="people" aria-controls="rail-panel-people">People <span class="loc-count hidden" id="people-count"></span></button>
        </div>

        <div class="rail-tab-panel" id="rail-panel-tasks" data-panel="tasks" role="tabpanel" aria-labelledby="rail-tab-tasks">
          <section class="hud-quest quest-tracker" id="quest-tracker" aria-live="polite"></section>
        </div>

        <div class="rail-tab-panel" id="rail-panel-log" data-panel="log" role="tabpanel" aria-labelledby="rail-tab-log">
          <div class="hud-log" id="hud-log">
            <h2 class="rail-pane-head">Log</h2>
            <div class="hud-log-head">
              <div class="log-filters" role="group" aria-label="Filter the log">
                <button type="button" class="chip on" data-filter="all">All</button>
                <button type="button" class="chip" data-filter="combat">Combat</button>
                <button type="button" class="chip" data-filter="story">Story</button>
              </div>
              <button type="button" class="icon-btn icon-btn-sm" id="btn-log-collapse"
                      title="Collapse log" aria-label="Collapse log" aria-expanded="true">
                <svg aria-hidden="true"><use href="#i-chevron"></use></svg>
              </button>
            </div>
            <div class="log" id="game-log" role="log" aria-live="polite" aria-relevant="additions"></div>
          </div>
        </div>

        <div class="rail-tab-panel" id="rail-panel-people" data-panel="people" role="tabpanel" aria-labelledby="rail-tab-people">
          <div class="hud-people" id="people-rail" aria-label="People here"></div>
        </div>
      </section>

      <!--
        Who you are pointing at, in a fight. It lives out here rather than in
        `#combat-root` because it is the one part of the combat screen that is
        a readout and not a control: inside the board it was an overlay on the
        ground it was describing. ui-combat.js renders into it by id and does
        not care that it is somewhere else; the CSS shows it only while a
        fight is running, and the log above it gives up the space.
      -->
      <section class="cbt-inspector" id="cbt-inspector" aria-live="polite"></section>
    </aside>
  </div>

    <footer class="gw-status">
      <span class="gw-status-where" id="gw-status-where"></span>
      <span class="gw-status-conn">connected</span>
    </footer>
  </div>

  <div id="modal-root" class="modal-backdrop hidden"></div>
  <div id="check-root" class="check-root hidden"></div>

  <!--
    Plain scripts sharing globals, in dependency order. game.js publishes
    `Game`; each ui-*.js publishes one more global and reaches for the others
    only from inside functions, so nothing here depends on the order beyond
    game.js coming first — except ui-map.js, which game.js calls during a
    render and so must be defined by the time one happens, and
    ui-battlemap.js, which ui-combat.js calls during one of its own.
  -->
  <script src="<?= asset('assets/js/api.js') ?>"></script>
  <!-- The viewBox pan/zoom both charts use. Before either of them, because
       each asks for it at the moment a map is opened rather than at load. -->
  <script src="<?= asset('assets/js/svg-view.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-map.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-firstperson.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-scenefx.js') ?>"></script>
  <script src="<?= asset('assets/js/scenes-data.js') ?>"></script>
  <script src="<?= asset('assets/js/game.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-panels.js') ?>"></script>
  <script src="<?= asset('assets/js/dice-geometry.js') ?>"></script>
  <script src="<?= asset('assets/js/dice3d.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-check.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-voice.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-dialog.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-battlemap.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-combat.js') ?>"></script>
  <script src="<?= asset('assets/js/ui-levelup.js') ?>"></script>
</body>
</html>
