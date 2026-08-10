<?php
require_once __DIR__ . '/app/page_guard.php';
require_signed_in_page();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create Character — Rivermark Chronicles</title>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <!-- Generated geometry for the 3D dice; see tools/gen_dice_css.py. -->
  <link rel="stylesheet" href="assets/css/dice.css?v=<?= filemtime(__DIR__ . '/assets/css/dice.css') ?>">
</head>
<!--
  The creator is a wizard, and it is deliberately the one page in the game that
  does not scroll on a desktop. Every step is a panel of its own inside a fixed
  frame: `body.wizard-page` cannot scroll, `.wiz-body` is the only flexible
  region, and each step is sized to fit inside it.

  It used to be one long form — name, race, class, background, alignment, six
  ability scores, a twelve-portrait grid and nine layer pickers stacked down a
  page you had to scroll three times to see the bottom of. Everything was
  visible, which sounds like a virtue, and none of it was legible.

  Six steps, `.wiz-step` sections switched by `data-step`, is a rewrite of the
  furniture and not of the logic: the same fields post the same payload.
-->
<body class="wizard-page">
  <form id="create-form" class="wizard" novalidate>
    <header class="wiz-head">
      <div class="wiz-titles">
        <h1 id="wiz-title">Create a Character</h1>
        <p id="wiz-blurb">Open 5e SRD races and classes.</p>
      </div>
      <a class="btn btn-small" href="index.php">← Leave</a>
    </header>

    <!--
      The step rail is both progress and navigation: a step you have completed
      is a button back to it, a step ahead of you is not. Rendered by JS so the
      state and the markup cannot drift.
    -->
    <ol class="wiz-rail" id="wiz-rail" aria-label="Creation steps"></ol>

    <div id="form-error" class="error-banner hidden" role="alert"></div>

    <div class="wiz-body">
      <!-- 1. Who they are -->
      <section class="wiz-step" data-step="identity" hidden>
        <!--
          Two columns: what you fill in on the left, who you would be on the
          right. The plate is 4:3 and was reading as a banner across a 560px
          column — the picture is a third of the step's height there and pushes
          the description and the trait line below the fold.

          The aside collapses to nothing when there is no plate (see
          `.wiz-split:has()`), so a race nobody has painted gets the single
          column this step had before rather than a column of empty air.
        -->
        <div class="wiz-split">
          <div class="wiz-split-main">
            <label for="name">Name</label>
            <input id="name" name="name" maxlength="50" autocomplete="off"
                   placeholder="e.g. Elara Stormwind">
            <p id="name-check" class="field-note"></p>

            <div class="wiz-pair">
              <div>
                <label for="race">Race</label>
                <select id="race"></select>
              </div>
              <div>
                <label for="subrace">Subrace</label>
                <select id="subrace"></select>
              </div>
            </div>
            <!-- Only the races we wrote carry a description; an SRD race
                 leaves it empty and the paragraph collapses. -->
            <p id="racial-lore" class="wiz-lore"></p>
            <p id="racial-preview" class="help-hint"></p>
          </div>

          <!-- The art is by filename rather than by a column — the same
               convention the module covers use — so `onerror` is what decides
               there isn't one. -->
          <div class="wiz-split-aside">
            <img id="racial-art" class="wiz-race-art" alt="" hidden>
          </div>
        </div>
      </section>

      <!-- 2. What they do -->
      <section class="wiz-step" data-step="class" hidden>
        <div class="wiz-narrow">
          <div class="wiz-pair">
            <div>
              <label for="class">Class</label>
              <select id="class"></select>
            </div>
            <div>
              <label for="background">Background</label>
              <!--
                The sixteen open backgrounds, and only those. This list used to
                offer six, of which 'Folk Hero' — the default — was not one the
                rules layer knows, so the commonest character in the game was
                built with no background skills and nothing said so.
                Rules::BACKGROUND_SKILLS is the authority; anything added here
                that is not a key there grants nothing.
              -->
              <select id="background">
                <option>Acolyte</option>
                <option>Artisan</option>
                <option>Charlatan</option>
                <option>Criminal</option>
                <option>Entertainer</option>
                <option>Farmer</option>
                <option>Guard</option>
                <option>Guide</option>
                <option>Hermit</option>
                <option>Merchant</option>
                <option>Noble</option>
                <option>Sage</option>
                <option>Sailor</option>
                <option>Scribe</option>
                <option selected>Soldier</option>
                <option>Wayfarer</option>
              </select>
            </div>
          </div>
          <p id="class-preview" class="help-hint"></p>

          <label for="alignment">Alignment</label>
          <select id="alignment">
            <option>Lawful Good</option>
            <option>Neutral Good</option>
            <option>Chaotic Good</option>
            <option selected>Neutral</option>
            <option>Lawful Neutral</option>
            <option>Chaotic Neutral</option>
            <option>Lawful Evil</option>
            <option>Neutral Evil</option>
            <option>Chaotic Evil</option>
          </select>
        </div>
      </section>

      <!-- 3. Numbers -->
      <section class="wiz-step" data-step="abilities" hidden>
        <div class="wiz-narrow">
          <div class="tabs" role="tablist" aria-label="How to generate ability scores">
            <button type="button" class="tab active" data-method="standard" role="tab">Standard Array</button>
            <button type="button" class="tab" data-method="point_buy" role="tab">Point Buy (27)</button>
            <button type="button" class="tab" data-method="random" role="tab">Random (4d6 drop low)</button>
          </div>
          <p id="method-note" class="help-hint"></p>
          <button type="button" id="random-roll" class="btn btn-small hidden">Roll All</button>
          <!--
            4d6 drop lowest, thrown by the server and replayed here. The dice are
            the real ones: api/?r=meta/roll_abilities rolls them, stashes the six
            totals in the session, and character/create will only accept an
            assignment of exactly those numbers. Before this the roll was a
            client-side "preview" that the server threw away and re-rolled, which
            nobody would notice while it was a number changing in a box and
            everybody would notice with dice on the screen.
          -->
          <div id="roll-trays" class="roll-trays hidden"></div>
          <div id="abilities" class="ability-grid"></div>
          <p class="help-hint">Racial bonuses are applied on top of these; you will
            see the finished scores on the last step.</p>
        </div>
      </section>

      <!--
        4. Appearance. Two ways to look like somebody: a portrait picks one of
        the whole actors cut from the packs — striking, costumed, and shared with
        an NPC — while building one stacks the modular T&C layers instead, which
        is plainer but nobody else's.

        The built preview stacks the layers in the browser so a click is instant.
        The composite that ends up in the game is baked server-side on submit,
        because the renderer wants one sprite sheet, not eight.
      -->
      <section class="wiz-step" data-step="look" hidden>
        <div class="tabs" role="tablist" aria-label="How to choose a look">
          <button type="button" class="tab active" data-look="preset" role="tab" aria-selected="true">Pick a portrait</button>
          <button type="button" class="tab" data-look="built" role="tab" aria-selected="false">Build one</button>
        </div>

        <div id="look-preset" class="look-pane">
          <div class="avatar-grid" id="avatar-grid" role="radiogroup" aria-label="Portraits"></div>
        </div>

        <div id="look-built" class="look-pane hidden">
          <div class="pd-layout">
            <!--
              Two views of the same layer stack. The bust is what a player
              actually looks at — it is the face in every conversation — and
              judging a haircut from a 40px-tall walking figure was guesswork.
              Both are composited from the identical ordered layer list, so they
              cannot disagree, which is the same guarantee Paperdoll::bake gives
              server-side.
            -->
            <div class="pd-stage">
              <div class="pd-bust" id="pd-bust" aria-label="Your character's portrait"></div>
              <div class="pd-preview" id="pd-preview" aria-label="Your character"></div>
              <div class="pd-stage-actions">
                <button type="button" class="btn btn-small" id="pd-facing" title="Turn them around">Turn</button>
                <button type="button" class="btn btn-small" id="pd-random">Randomise</button>
              </div>
              <p class="help-hint" id="pd-note"></p>
            </div>
            <!-- Two columns of pickers, so nine categories fit without scrolling. -->
            <div class="pd-pickers" id="pd-pickers"></div>
          </div>
        </div>
      </section>

      <!-- 5. One knack from the life before adventuring. Optional on purpose:
           "take nothing" is a real card, so the step never blocks a new player
           who has no idea what a feat is. -->
      <section class="wiz-step" data-step="gift" hidden>
        <p class="help-hint">
          Everyone was something before they took up the sword. Pick one knack
          that life left them with — or none, and decide later at a level-up.
        </p>
        <div class="avatar-grid gift-grid" id="gift-grid" role="radiogroup" aria-label="Origin feats"></div>
      </section>

      <!-- 6. The finished character, before committing to it -->
      <section class="wiz-step" data-step="review" hidden>
        <div class="review-layout">
          <div class="review-portrait">
            <div class="pd-bust" id="review-bust"></div>
            <h2 id="review-name"></h2>
            <p class="help-hint" id="review-sub"></p>
          </div>
          <div class="review-facts">
            <div class="ability-grid" id="review-abilities"></div>
            <div class="review-stats" id="review-stats"></div>
            <dl class="review-list" id="review-list"></dl>
          </div>
        </div>
      </section>
    </div>

    <footer class="wiz-nav">
      <button type="button" class="btn" id="wiz-back">← Back</button>
      <p class="wiz-reason" id="wiz-reason" aria-live="polite"></p>
      <button type="button" class="btn btn-primary" id="wiz-next">Next →</button>
      <button type="submit" class="btn btn-primary hidden" id="submit-btn">Create &amp; Enter Game</button>
    </footer>
  </form>

  <?php
    /*
     * The creator builds its own art URLs (faces, busts, paperdoll layers) and
     * never loads game.js, so TILE_CACHE_VER is not even in scope here -- which
     * meant a re-slice left this page showing stale portraits forever.
     *
     * Version by the manifests the art is cut alongside rather than by time():
     * they are rewritten exactly when their directory is re-sliced, so the URL
     * changes when the art does and not on every page load.
     */
    $artVer = max(array_map(
        static fn(string $m): int => @filemtime(__DIR__ . '/' . $m) ?: 0,
        ['assets/images/npcs/busts.json', 'assets/images/paperdoll/index.json']
    ));
  ?>
  <script>window.ART_VER = <?= json_encode((string) $artVer) ?>;</script>
  <script src="assets/js/api.js?v=<?= filemtime(__DIR__ . '/assets/js/api.js') ?>"></script>
  <script src="assets/js/dice-geometry.js?v=<?= filemtime(__DIR__ . '/assets/js/dice-geometry.js') ?>"></script>
  <script src="assets/js/dice3d.js?v=<?= filemtime(__DIR__ . '/assets/js/dice3d.js') ?>"></script>
  <script src="assets/js/create.js?v=<?= filemtime(__DIR__ . '/assets/js/create.js') ?>"></script>
</body>
</html>
