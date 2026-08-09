<?php
/**
 * One module, as a book for the printer — the adventure a GM would buy.
 *
 * The same trade sheet_print.php makes and for the same reasons: the browser is
 * already a PDF writer, it honours `@page` and `@media print`, and it sets type
 * better than a PHP library that would first have to be added to
 * docker/php/Dockerfile — which is pinned to mirror hermes, so adding one is a
 * production deployment change made to render a page the browser was going to
 * render anyway.
 *
 * ADMIN ONLY. Not because the content is secret — a player can walk every room
 * of it — but because this is the GM's copy: it prints secret doors as secret
 * doors, quest stages with their outcomes, and where every trap is. Handing
 * that to the person playing is not a permission bug, it is spoiling the game.
 * `require_admin_page()` is the same gate `/content.php` uses.
 *
 * The layout lives in assets/css/adventure-print.css; this file is structure
 * and data, and AdventureBook is where the gathering happens. Nothing here
 * queries anything.
 *
 *   /adventure_print.php                     pick a module
 *   /adventure_print.php?module=rivermark    the book
 *   /adventure_print.php?module=…&print=1    …and open the print dialogue
 *   /adventure_print.php?module=…&art=full   …with every location's plate
 *
 * `art=full` is off by default on purpose. Rivermark has seventy-three
 * locations and most have a painting; at print resolution that is a document
 * measured in hundreds of megabytes, which is a surprise nobody wants from a
 * button labelled Export. The cover, the region maps and the cast portraits are
 * always in — they are what makes it look like a book — and the rest is asked
 * for.
 */

require_once __DIR__ . '/app/page_guard.php';
$user = require_admin_page();

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Cache-bust an asset by its own modification time — as game.php does. */
function asset(string $path): string
{
    $full = __DIR__ . '/' . $path;
    return $path . '?v=' . (is_file($full) ? filemtime($full) : '0');
}

/**
 * An image path, or null when there is no such file.
 *
 * Checked against the filesystem rather than assumed, because the vhost ends in
 * `try_files ... /index.php`: a missing image does not 404, it returns the
 * homepage HTML with a 200, and an <img> pointed at that prints as a broken
 * icon in the middle of somebody's book. CLAUDE.md records the same trap.
 */
function art(string $path): ?string
{
    return is_file(__DIR__ . '/' . $path) ? asset($path) : null;
}

/** A modifier as a stat block writes it: +3, -1, +0. */
function mod_of(int $score): string
{
    return sprintf('%+d', (int) floor(($score - 10) / 2));
}

/** 1/8 rather than 0.125 — a challenge rating is written as a fraction. */
function cr_text($cr): string
{
    $v = (float) $cr;
    if ($v <= 0) {
        return '0';
    }
    // A loose tolerance on purpose: the column is DECIMAL and a bandit is
    // stored as 0.13, not 0.125. Tight matching printed "Challenge 0.13",
    // which is a number no stat block has ever carried.
    foreach ([8 => '1/8', 4 => '1/4', 2 => '1/2'] as $d => $label) {
        if (abs($v - 1 / $d) < 0.02) {
            return $label;
        }
    }
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/**
 * A region's plate with its area numbers on it, or without them.
 *
 * WHY THE STRETCH IS DELIBERATE. `locations.map_x` / `map_y` are positions in
 * the chart's own field — 0–100 across, 0–75 down — and ui-map.js draws the
 * plate into exactly that field with `preserveAspectRatio="none"`. So the game
 * stretches the painting to the field, and this has to stretch it the same way
 * or every pin drifts by however much the file's aspect differs. `object-fit:
 * fill` is that same instruction in CSS. It is the one place in this file that
 * has to agree with the client, and it agrees by copying the rule rather than
 * by hoping the plates are all 4:3.
 *
 * The numbers are the whole point of the map in the GM's copy: an entry headed
 * "4. The Ford" is no use unless the ford can be found on the drawing. The
 * handout copy is the same plate with the pins left off — see Appendix D.
 */
function map_frame(array $region, string $src, bool $numbered): string
{
    $pins = '';
    if ($numbered) {
        foreach ($region['locations'] as $loc) {
            if ($loc['map_x'] === null || $loc['map_y'] === null) {
                continue;
            }
            $left = (float) $loc['map_x'];
            $top = ((float) $loc['map_y'] / 75) * 100;
            $pins .= sprintf(
                '<span class="map-pin" style="left:%.2f%%;top:%.2f%%" title="%s">%d</span>',
                $left,
                $top,
                esc($loc['name']),
                (int) $loc['number']
            );
        }
    }
    return '<div class="map-frame"><img src="' . esc($src) . '" alt="">' . $pins . '</div>';
}

$book = new AdventureBook(db());
$moduleKey = trim((string) ($_GET['module'] ?? ''));
$fullArt = ($_GET['art'] ?? '') === 'full';
$autoPrint = ($_GET['print'] ?? '') === '1';

/*
 * The seed for a module whose levels are made at play.
 *
 * Rolled here rather than in AdventureBook, which stays deterministic so that
 * the same arguments always produce the same book. That is the whole trick:
 * the seed is printed on the page and `?seed=` reprints it exactly, so a
 * referee who liked the dungeon they got can hand out the same one again, and
 * one who did not can reload. Bounded to what DelveEngine itself rolls, so a
 * printed floor and a played floor cannot come from different ranges.
 */
$delveSeed = isset($_GET['seed'])
    ? max(1, min(0x7FFFFFFF, (int) $_GET['seed']))
    : random_int(1, 0x7FFFFFFF);

// ---------------------------------------------------------------------------
// No module named: the picker.
// ---------------------------------------------------------------------------
if ($moduleKey === '') {
    $modules = $book->catalogue();
    ?><!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Export an adventure — Rivermark Chronicles</title>
      <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="admin-page">
      <header class="admin-head">
        <div>
          <p class="auth-eyebrow">Rivermark Chronicles</p>
          <h1>Export an adventure</h1>
        </div>
        <nav class="admin-nav">
          <a class="btn" href="content.php">Content</a>
          <a class="btn" href="game.php">Adventure</a>
        </nav>
      </header>
      <main class="container">
        <p class="help-hint">The GM's copy: every area numbered, the cast, the
          quests with their outcomes, and a stat block for everything the module
          sends. Print it from the browser to get a PDF.</p>
        <div class="home-grid">
          <?php foreach ($modules as $m) { ?>
            <section class="panel">
              <h2><?= esc($m['name']) ?></h2>
              <p class="help-hint">Levels <?= esc($m['level_min']) ?>–<?= esc($m['level_max']) ?><?=
                (int) $m['is_active'] === 1 ? '' : ' · withdrawn' ?></p>
              <p><?= esc($m['blurb']) ?></p>
              <p>
                <a class="btn btn-primary"
                   href="adventure_print.php?module=<?= urlencode($m['module_key']) ?>">Open the book</a>
                <a class="btn"
                   href="adventure_print.php?module=<?= urlencode($m['module_key']) ?>&amp;art=full"
                   title="Also print the painting for every location. Much larger.">With every plate</a>
              </p>
            </section>
          <?php } ?>
        </div>
      </main>
    </body>
    </html>
    <?php
    exit;
}

$data = $book->build($moduleKey, $delveSeed);
if ($data === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<title>No such adventure — Rivermark Chronicles</title>'
        . '<link rel="stylesheet" href="assets/css/style.css"></head>'
        . '<body class="auth-page"><main class="auth-card">'
        . '<h1>No such adventure</h1>'
        . '<p class="auth-sub">There is no module with that key.</p>'
        . '<p class="auth-foot"><a href="adventure_print.php">Pick one</a></p>'
        . '</main></body></html>';
    exit;
}

$module = $data['module'];
$cover = art('assets/images/modules/' . $module['module_key'] . '.jpg');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($module['name']) ?> — the adventure</title>
  <link rel="stylesheet" href="<?= esc(asset('assets/css/adventure-print.css')) ?>">
</head>
<body>

<div class="book-bar">
  <span><b><?= esc($module['name']) ?></b> · <?= esc($data['counts']['regions']) ?> chapters ·
    <?= esc($data['counts']['locations']) ?> areas<?php
    // A generated module has almost no areas and almost no encounter rows, and
    // saying so was accurate and useless: the Undervault read "4 areas, 0
    // creatures" over a book holding five levels of fights. What it rolls
    // counts, because that is what somebody would be running.
    if (!empty($data['delve']['floors'])) {
        echo ' · ' . esc(count($data['delve']['floors'])) . ' rolled levels';
    } ?> ·
    <?= esc(count($data['monsters'])) ?> creatures</span>
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <?php
  // Keep whichever options are already on when following one of these, or
  // adding the plates would silently re-roll the dungeon and printing the
  // dungeon again would silently drop the plates.
  $link = static function (array $over) use ($moduleKey, $fullArt, $delveSeed, $data): string {
      $q = ['module' => $moduleKey];
      if ($fullArt) {
          $q['art'] = 'full';
      }
      if (!empty($data['delve']['floors'])) {
          $q['seed'] = $delveSeed;
      }
      return '?' . http_build_query(array_merge($q, $over));
  };
  ?>
  <?php if (!$fullArt) { ?>
    <a href="<?= esc($link(['art' => 'full'])) ?>">Add every location plate</a>
  <?php } else { ?>
    <a href="<?= esc($link(['art' => null])) ?>">Without the plates</a>
  <?php } ?>
  <?php if (!empty($data['delve']['floors'])) { ?>
    <span class="book-seed">delve seed <b><?= esc($data['delve']['seed']) ?></b></span>
    <a href="<?= esc($link(['seed' => null])) ?>">Roll another delve</a>
  <?php } ?>
  <a href="content.php">Back to content</a>
</div>

<!-- ------------------------------------------------------------------ Cover -->
<div class="book-page">
  <div class="cover">
    <?php if ($cover) { ?><img class="cover-art" src="<?= esc($cover) ?>" alt=""><?php } ?>
    <h1><?= esc($module['name']) ?></h1>
    <p class="cover-band">An adventure for characters of level
      <?= esc($module['level_min']) ?>–<?= esc($module['level_max']) ?></p>
    <p class="cover-blurb"><?= esc($module['blurb']) ?></p>
    <p class="cover-foot"><?= esc($module['attribution'] ?? '') ?></p>
  </div>
</div>

<!-- --------------------------------------------------------------- Contents -->
<div class="book-page">
  <h1>Contents</h1>
  <p class="lede">This is the GM's copy. Secret ways are marked as secret, traps
    are named where they lie, and every quest carries the outcome it ends in —
    none of which the players' side of the screen shows.</p>

  <div class="toc">
    <?php foreach ($data['regions'] as $n => $region) { ?>
      <p class="toc-chapter">
        <b>Chapter <?= esc($n + 1) ?> · <?= esc($region['name']) ?></b><br>
        <span class="toc-areas"><?= esc(implode(' · ', array_map(
          static fn ($l) => $l['number'] . '. ' . $l['name'],
          $region['locations']
        ))) ?></span>
      </p>
    <?php } ?>
    <p class="toc-chapter">
      <b>Appendix A · The cast</b><br>
      <span class="toc-areas"><?= esc(count($data['cast'])) ?> people</span>
    </p>
    <p class="toc-chapter">
      <b>Appendix B · Work and trouble</b><br>
      <span class="toc-areas"><?= esc(count($data['quests'])) ?> quests</span>
    </p>
    <p class="toc-chapter">
      <b>Appendix C · Bestiary</b><br>
      <span class="toc-areas"><?= esc(count($data['monsters'])) ?> creatures,
        <?= esc($data['counts']['encounters'] + $data['counts']['rolled']) ?> encounters</span>
    </p>
    <p class="toc-chapter">
      <b>Appendix D · Maps to hand out</b><br>
      <span class="toc-areas">the same maps, a page each, with the numbers left off</span>
    </p>
    <p class="toc-chapter">
      <b>Appendix E · Cards for the people</b><br>
      <span class="toc-areas">four to a page, to cut out and keep behind the screen</span>
    </p>
    <?php if (!empty($data['delve']['floors'])) { ?>
      <p class="toc-chapter">
        <b>Appendix F · A delve, rolled</b><br>
        <span class="toc-areas">seed <?= esc($data['delve']['seed']) ?> ·
          <?= esc(count($data['delve']['floors'])) ?> levels, with plans and
          <?= esc($data['counts']['rolled']) ?> fights</span>
      </p>
    <?php } ?>
  </div>
</div>

<!-- --------------------------------------------------------------- Chapters -->
<?php foreach ($data['regions'] as $n => $region) {
    $map = art('assets/images/maps/' . $region['region_key'] . '.png');
    ?>
  <div class="book-page">
    <div class="cols">
      <div class="chapter-head">
        <h1>Chapter <?= esc($n + 1) ?> · <?= esc($region['name']) ?></h1>
        <?php if ($map) { ?>
          <div class="chapter-map"><?= map_frame($region, $map, true) ?></div>
          <p class="map-caption">The numbers are the areas below.</p>
        <?php } ?>
        <?php if (trim((string) $region['description']) !== '') { ?>
          <p class="lede"><?= esc($region['description']) ?></p>
        <?php } ?>
      </div>

      <?php foreach ($region['locations'] as $loc) {
          $plate = $fullArt ? art('assets/images/locations/' . $loc['location_key'] . '.png') : null;
          ?>
        <div class="area">
          <p class="area-head"><span class="area-num"><?= esc($loc['number']) ?>.</span>
            <?= esc($loc['name']) ?></p>
          <?php if ($plate) { ?><img class="area-art" src="<?= esc($plate) ?>" alt=""><?php } ?>

          <?php if (trim((string) $loc['first_visit_text']) !== '') { ?>
            <div class="read-aloud"><p><?= esc($loc['first_visit_text']) ?></p></div>
          <?php } ?>
          <div class="read-aloud"><p><?= esc($loc['description']) ?></p></div>

          <dl class="notes">
            <?php if ($loc['people']) { ?>
              <dt>Who is here</dt>
              <?php foreach ($loc['people'] as $p) { ?>
                <dd><b><?= esc($p['name']) ?></b><?= $p['role'] ? ', ' . esc($p['role']) : '' ?>
                  <?php if ((int) $p['is_quest_giver'] === 1) { ?><span class="tag">work</span><?php } ?>
                  <?php if ((int) $p['is_merchant'] === 1) { ?><span class="tag">sells</span><?php } ?>
                  <?php if ((int) $p['is_ambient'] === 1) { ?><span class="tag">walk-on</span><?php } ?>
                </dd>
              <?php } ?>
            <?php } ?>

            <?php if ($loc['exits']) { ?>
              <dt>Ways out</dt>
              <?php foreach ($loc['exits'] as $e) {
                  $cond = $e['conditions'];
                  ?>
                <dd><?= esc($e['label'] ?: $e['to_name']) ?> →
                  <?= esc($e['to_name']) ?><?php
                    if ($e['to_region'] !== $region['name']) {
                        echo ' <span class="tag">' . esc($e['to_region']) . '</span>';
                    }
                    if ((int) $e['is_hidden'] === 1) {
                        echo ' <span class="tag">secret</span>';
                    }
                    if (!empty($cond['locked']) || !empty($cond['required_flag'])) {
                        echo ' <span class="tag">shut</span>';
                    }
                    if (!empty($cond['attempt'])) {
                        echo ' <span class="tag">' . esc($cond['attempt']) . '</span>';
                    }
                  ?></dd>
              <?php } ?>
            <?php } ?>

            <?php if ($loc['items']) { ?>
              <dt>To be found here</dt>
              <?php foreach ($loc['items'] as $it) { ?>
                <dd><b><?= esc($it['name']) ?></b><?= $it['value_gp'] ? ' · ' . esc($it['value_gp']) . ' gp' : '' ?>
                  <?= $it['description'] ? '— ' . esc($it['description']) : '' ?></dd>
              <?php } ?>
            <?php } ?>

            <?php if ($loc['trap']) { ?>
              <dt>Trap</dt>
              <dd><b><?= esc($loc['trap']['name'] ?? 'A trap') ?></b>
                <?php if (!empty($loc['trap']['save'])) { ?>
                  · DC <?= esc($loc['trap']['dc'] ?? '?') ?>
                  <?= esc(strtoupper((string) $loc['trap']['save'])) ?>
                <?php } ?>
                <?php if (!empty($loc['trap']['damage'])) { ?> · <?= esc($loc['trap']['damage']) ?><?php } ?>
                <?php if (!empty($loc['trap']['text'])) { ?><br><?= esc($loc['trap']['text']) ?><?php } ?>
              </dd>
            <?php } ?>

            <?php
            $here = array_values(array_filter(
                $data['encounters'],
                static fn ($e) => (int) ($e['location_id'] ?? 0) === (int) $loc['id']
            ));
            if ($here) { ?>
              <dt>Waiting here</dt>
              <?php foreach ($here as $e) { ?>
                <dd><b><?= esc($e['name']) ?></b><?php
                  if ((int) $e['is_ambush'] === 1) { echo ' <span class="tag">ambush</span>'; }
                  echo ' · ' . esc(implode(', ', array_map(
                      static fn ($m) => $m['quantity'] . '× ' . $m['name'],
                      $e['roster']
                  )));
                ?></dd>
              <?php } ?>
            <?php } ?>

            <?php
            $flags = [];
            if ((int) $loc['has_job_board'] === 1) { $flags[] = 'a job board'; }
            if ((int) $loc['allow_camp'] === 1) { $flags[] = 'somewhere to camp'; }
            if ($loc['inn_cost'] !== null) { $flags[] = 'beds at ' . (int) $loc['inn_cost'] . ' gp'; }
            if ((int) $loc['hidden_until_visited'] === 1) { $flags[] = 'off the map until found'; }
            if ((int) $loc['random_encounter_pct'] > 0) {
                $flags[] = (int) $loc['random_encounter_pct'] . '% wandering on arrival';
            }
            if ($flags) { ?>
              <dt>Also</dt>
              <dd><?= esc(implode(' · ', $flags)) ?></dd>
            <?php } ?>
          </dl>
        </div>
      <?php } ?>
    </div>
  </div>
<?php } ?>

<!-- ------------------------------------------------------- Appendix A: cast -->
<div class="book-page">
  <div class="cols">
    <h1 class="chapter-head">Appendix A · The cast</h1>
    <?php foreach ($data['cast'] as $p) {
        $bust = art('assets/images/npcs/' . $p['sprite_key'] . '_bust.png')
             ?? art('assets/images/npcs/' . $p['sprite_key'] . '_face.png');
        ?>
      <div class="person">
        <div class="person-head">
          <?php if ($bust) { ?><img class="person-bust" src="<?= esc($bust) ?>" alt=""><?php } ?>
          <div>
            <div class="person-name"><?= esc($p['name']) ?></div>
            <div class="person-where"><?= esc($p['role']) ?> · <?= esc($p['location_name']) ?></div>
          </div>
        </div>
        <p><?= esc($p['description']) ?></p>
      </div>
    <?php } ?>
  </div>
</div>

<!-- ----------------------------------------------------- Appendix B: quests -->
<div class="book-page">
  <div class="cols">
    <h1 class="chapter-head">Appendix B · Work and trouble</h1>
    <?php foreach ($data['quests'] as $q) { ?>
      <div class="quest">
        <h3><?= esc($q['title']) ?></h3>
        <p class="quest-meta">
          <?= $q['giver_name'] ? 'From ' . esc($q['giver_name']) : 'No giver' ?>
          <?= $q['target_name'] ? ' · at ' . esc($q['target_name']) : '' ?>
          <?= (int) $q['on_job_board'] === 1 ? ' · posted' : '' ?>
          <?= $q['required_level'] ? ' · level ' . esc($q['required_level']) . '+' : '' ?>
          · <?= esc($q['reward_xp']) ?> XP<?= $q['reward_gold'] ? ', ' . esc($q['reward_gold']) . ' gp' : '' ?>
        </p>
        <p><?= esc($q['description']) ?></p>
        <?php if ($q['stages']) { ?>
          <ol class="quest-stages">
            <?php foreach ($q['stages'] as $s) { ?>
              <li><b><?= esc($s['title']) ?></b> — <?= esc($s['objective']) ?><?php
                if ((int) $s['is_terminal'] === 1) {
                    echo ' <span class="tag">ends: ' . esc($s['resolution'] ?: 'done') . '</span>';
                }
              ?></li>
            <?php } ?>
          </ol>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>

<!-- --------------------------------------------------- Appendix C: bestiary -->
<div class="book-page">
  <div class="cols">
    <h1 class="chapter-head">Appendix C · Bestiary</h1>
    <?php foreach ($data['monsters'] as $m) { ?>
      <?php $art = art('assets/images/monsters/' . $m['art_key'] . '_bust.png'); ?>
      <div class="stat<?= $art ? ' has-art' : '' ?>">
        <?php if ($art) { ?><img class="stat-art" src="<?= esc($art) ?>" alt=""><?php } ?>
        <h3><?= esc($m['name']) ?></h3>
        <p class="stat-kind"><?= esc(trim($m['size'] . ' ' . $m['type'])) ?><?=
          $m['alignment'] ? ', ' . esc($m['alignment']) : '' ?></p>
        <hr class="stat-rule">
        <p class="stat-line"><b>Armour Class</b> <?= esc($m['armor_class']) ?></p>
        <p class="stat-line"><b>Hit Points</b> <?= esc($m['hit_points']) ?><?=
          $m['hit_dice'] ? ' (' . esc($m['hit_dice']) . ')' : '' ?></p>
        <?php /* The column already reads "30 ft., climb 30 ft." — a unit
                 appended here printed it twice. */ ?>
        <p class="stat-line"><b>Speed</b> <?= esc($m['speed']) ?></p>
        <hr class="stat-rule">
        <div class="stat-abilities">
          <?php foreach ([
              'STR' => 'strength', 'DEX' => 'dexterity', 'CON' => 'constitution',
              'INT' => 'intelligence', 'WIS' => 'wisdom', 'CHA' => 'charisma',
          ] as $label => $col) { ?>
            <div><span><?= $label ?></span><?= esc($m[$col]) ?> (<?= mod_of((int) $m[$col]) ?>)</div>
          <?php } ?>
        </div>
        <hr class="stat-rule">
        <?php if ($m['resistances']) { ?>
          <p class="stat-line"><b>Resistances</b> <?= esc(implode(', ', $m['resistances'])) ?></p>
        <?php } ?>
        <?php if ($m['immunities']) { ?>
          <p class="stat-line"><b>Immunities</b> <?= esc(implode(', ', $m['immunities'])) ?></p>
        <?php } ?>
        <p class="stat-line"><b>Challenge</b> <?= esc(cr_text($m['challenge_rating'])) ?>
          (<?= esc($m['experience_points']) ?> XP)</p>
        <?php if ($m['traits_list']) { ?>
          <hr class="stat-rule">
          <?php foreach ($m['traits_list'] as $t) { ?>
            <p class="stat-trait"><?= $t['name'] ? '<b>' . esc($t['name']) . '.</b> ' : '' ?><?=
              esc($t['text']) ?></p>
          <?php } ?>
        <?php } ?>
        <?php if ($m['actions_list']) { ?>
          <hr class="stat-rule">
          <h4>Actions</h4>
          <?php foreach ($m['actions_list'] as $a) { ?>
            <p class="stat-trait"><?= $a['name'] ? '<b>' . esc($a['name']) . '.</b> ' : '' ?><?=
              esc($a['text']) ?></p>
          <?php } ?>
        <?php } ?>
      </div>
    <?php } ?>

    <?php
    /*
     * Wandering monsters get a TABLE, because the person running the game is
     * the die. The engine draws these itself on every travel hop and no player
     * or GM ever sees the list; on paper that list is the whole point, so the
     * region's random pool is numbered and given the chance it is rolled at.
     *
     * The chance is per location arrived at, not per region — it is a column on
     * `locations` — so what is stated is the range actually authored, and a
     * region whose places all agree states one number. Read off the chapters
     * this book already gathered rather than asked for again.
     */
    $wandering = [];
    foreach ($data['encounters'] as $e) {
        if ((int) $e['is_random'] === 1) {
            $wandering[(string) $e['chapter']][] = $e;
        }
    }
    $pct = [];
    foreach ($data['regions'] as $region) {
        foreach ($region['locations'] as $loc) {
            $n = (int) ($loc['random_encounter_pct'] ?? 0);
            if ($n > 0) {
                $pct[(string) $region['name']][] = $n;
            }
        }
    }
    ?>
    <?php if ($wandering) { ?>
      <h2>Wandering monsters</h2>
      <?php foreach ($wandering as $chapter => $rows) {
          $chances = $pct[$chapter] ?? [];
          $lo = $chances ? min($chances) : 0;
          $hi = $chances ? max($chances) : 0;
          ?>
        <table class="wander">
          <?php /* A region with one wandering encounter is not a d1, and one
                   with two is barely a table. Say what is true instead: the
                   die when there is something to roll, a plain statement when
                   there is not. The thinness is the CONTENT's — these chapters
                   carry one or two wanderers each — and a book that dressed
                   that up as a table would be hiding it from the only person
                   who could fix it. */ ?>
          <caption><?= esc($chapter) ?> ·
            <?= count($rows) > 1
                ? 'roll d' . esc(count($rows))
                : 'the only thing that wanders here' ?><br>
            <span><?php if ($lo > 0) {
                echo $lo === $hi
                    ? esc($lo) . ' in 100 on arriving somewhere in this chapter'
                    : esc($lo) . '–' . esc($hi) . ' in 100 on arriving, by area';
            } else {
                echo 'when the chapter says so';
            } ?></span></caption>
          <tbody>
            <?php foreach (array_values($rows) as $i => $e) { ?>
              <tr>
                <?php if (count($rows) > 1) { ?>
                  <td class="wander-roll"><?= esc($i + 1) ?></td>
                <?php } ?>
                <td><b><?= esc(implode(', ', array_map(
                    static fn ($m) => $m['quantity'] . ' × ' . $m['name'], $e['roster']
                ))) ?></b> · <?= esc($e['name']) ?>
                  <span class="tag"><?= esc($e['difficulty']) ?></span></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      <?php } ?>
    <?php } ?>

    <?php
    $setPiece = array_values(array_filter(
        $data['encounters'],
        static fn ($e) => (int) $e['is_random'] !== 1
    ));
    ?>
    <?php if ($setPiece) { ?>
      <h2>Set-piece encounters</h2>
    <?php } ?>
    <?php foreach ($setPiece as $e) { ?>
      <p class="stat-line"><b><?= esc($e['name']) ?></b> ·
        <?= esc($e['location_name'] ?: $e['chapter']) ?> ·
        <?= esc($e['difficulty']) ?> ·
        <?= esc(implode(', ', array_map(
            static fn ($m) => $m['quantity'] . '× ' . $m['name'], $e['roster']
        ))) ?></p>
    <?php } ?>

    <?php if (!$data['encounters'] && !empty($data['delve']['floors'])) { ?>
      <p class="help-note">Every fight in this adventure is rolled rather than
        written: the creatures above are the ones the delve in Appendix F sends,
        in its rooms and on its wandering tables. Nothing here is pinned to a
        place, because none of the places exist until somebody goes down.</p>
    <?php } ?>

    <p class="colophon">
      Printed from Rivermark Chronicles for the person running it.
      Uses only content from the 5e System Reference Document under OGL 1.0a /
      CC-BY 4.0. Not affiliated with any trademark holders.
    </p>
  </div>
</div>

<!-- ------------------------------------------------- Appendix D: handouts -->
<?php
/*
 * The players' copy of every map, one to a page.
 *
 * The same plate the chapter opens with and the same stretch, WITHOUT the pins:
 * a numbered map is the GM's, and handing it across the table gives away how
 * many places there are and that number four matters. A page each so it can be
 * printed, cut out or shown on a tablet without the rest of the book on it.
 *
 * Only regions that have a painted plate. A region with none has nothing to
 * hand out — the game falls back to bare parchment and its own ink for those,
 * and a blank sheet captioned "Hollow Fen" would be worse than no page.
 */
$plates = [];
foreach ($data['regions'] as $region) {
    $src = art('assets/images/maps/' . $region['region_key'] . '.png');
    if ($src) {
        $plates[] = [$region, $src];
    }
}
?>
<?php if ($plates) { ?>
  <div class="book-page">
    <h1>Appendix D · Maps to hand out</h1>
    <p class="lede">One page each, without the area numbers. Everything the
      players may see, and nothing they should not.</p>
    <p class="lede"><?= esc(count($plates)) ?> of
      <?= esc($data['counts']['regions']) ?> chapters have a painted map; the
      rest are drawn by the game from the places themselves and have no plate to
      print.</p>
  </div>
  <?php foreach ($plates as [$region, $src]) { ?>
    <div class="book-page handout">
      <div class="handout-frame"><?= map_frame($region, $src, false) ?></div>
      <p class="handout-name"><?= esc($region['name']) ?></p>
    </div>
  <?php } ?>
<?php } ?>

<!-- ---------------------------------------------------- Appendix E: cards -->
<?php
/*
 * The cast again, four to a page, as cards to cut out.
 *
 * Appendix A is the cast to READ — a page of people in one column, which is
 * how you meet them. This is the cast to USE: one person to a card, big enough
 * to find on a table, with the face, what they are, where they stand and what
 * they want on it. A GM running a scene reaches for a card; a GM preparing one
 * reads the appendix.
 *
 * `array_chunk` by four rather than letting them flow, because a card is a
 * thing to cut out: four to a page with the cuts on the halves means two
 * straight passes of a guillotine, and a flowed grid that happened to put
 * three on one page and one on the next means a wasted sheet.
 *
 * What they want comes from the quests this book already gathered, keyed by
 * giver. Nothing is queried again — a second question about who gives what is
 * a second answer waiting to disagree with Appendix B.
 */
$wants = [];
foreach ($data['quests'] as $q) {
    if (!empty($q['giver_key'])) {
        $wants[$q['giver_key']][] = $q['title'];
    }
}
?>
<?php foreach (array_chunk($data['cast'], 4) as $sheet) { ?>
  <div class="book-page cards">
    <?php foreach ($sheet as $p) {
        $bust = art('assets/images/npcs/' . $p['sprite_key'] . '_bust.png')
             ?? art('assets/images/npcs/' . $p['sprite_key'] . '_face.png');
        $theirs = $wants[$p['npc_key']] ?? [];
        ?>
      <div class="card">
        <?php if ($bust) { ?>
          <img class="card-art" src="<?= esc($bust) ?>" alt="">
        <?php } else { ?>
          <div class="card-art card-art-blank"></div>
        <?php } ?>
        <div class="card-body">
          <p class="card-name"><?= esc($p['name']) ?></p>
          <p class="card-role"><?= esc($p['role']) ?>
            <?php if ((int) $p['is_merchant'] === 1) { ?><span class="tag">sells</span><?php } ?>
            <?php if ((int) $p['is_quest_giver'] === 1) { ?><span class="tag">work</span><?php } ?>
          </p>
          <p class="card-where"><?= esc($p['location_name']) ?></p>
          <p class="card-text"><?= esc($p['description']) ?></p>
          <?php if ($theirs) { ?>
            <p class="card-wants"><b>Wants:</b> <?= esc(implode(' · ', $theirs)) ?></p>
          <?php } ?>
        </div>
      </div>
    <?php } ?>
  </div>
<?php } ?>

<!-- ----------------------------------------------- Appendix F: a rolled delve -->
<?php
/*
 * A module whose levels are made at play cannot be printed from its rows: the
 * Undervault has four authored locations and a generator, and a book built out
 * of the database prints the stair head and stops. So one delve is rolled for
 * the page and its seed printed with it — `?seed=` brings the same one back,
 * to the room.
 *
 * The plans are drawn by `window.WorldMap.svg`, the renderer the game itself
 * uses, handed a payload in the shape `location/state` sends. That is not a
 * convenience: doors in the old-school grammar, stair ticks and graph-paper
 * ruling are two hundred lines of drawing, and a PHP copy of them would agree
 * with the screen exactly until the first time one of the two was fixed.
 * tools/floorplan_preview.php already drives it from a plain page with no game
 * behind it; this is the same trick with a paper skin in the stylesheet.
 */
if (!empty($data['delve']['floors'])) {
    $delve = $data['delve'];
    ?>
  <div class="book-page">
    <div class="chapter-head">
      <h1>Appendix F · A delve, rolled</h1>
      <p class="lede">The Undervault is not written, it is rolled: four
        locations at the surface, and everything below them made when a party
        walks down the stair. No two delves are the same and none of them can
        be printed in advance — so here is one, whole, with the number that
        made it.</p>
      <p class="seed-band">Seed <b><?= esc($delve['seed']) ?></b> ·
        <?= esc(count($delve['floors'])) ?> levels ·
        <span class="seed-note">the same seed always makes the same dungeon;
          print this page again with
          <code>?module=<?= esc($moduleKey) ?>&amp;seed=<?= esc($delve['seed']) ?></code>
          and every room is where it was</span></p>
    </div>
  </div>

  <?php foreach ($delve['floors'] as $floor) { ?>
    <div class="book-page">
      <div class="cols">
        <div class="chapter-head">
          <h1>Level <?= esc($floor['depth']) ?></h1>
          <div class="chapter-map">
            <div class="floor-plan" data-floor="<?= esc($floor['depth']) ?>"></div>
          </div>
          <p class="map-caption">The numbers are the areas below.
            Seed <?= esc($delve['seed']) ?>, level <?= esc($floor['depth']) ?>.</p>
          <p class="lede"><?= esc($floor['atmosphere']['label']) ?>.
            <?= esc($floor['atmosphere']['air']) ?>
            Down at area <?= esc($floor['stair']) ?>;
            in at area <?= esc($floor['entrance']) ?>.</p>

          <?php if (!empty($floor['wandering']['rows'])) { ?>
            <table class="wander">
              <caption>Wandering monsters · roll d<?= esc($floor['wandering']['die']) ?><br>
                <span>1 in <?= esc($floor['wandering']['chance']) ?> on entering a passage ·
                  sized for <?= esc($floor['party']['size']) ?> characters of level
                  <?= esc($floor['party']['level']) ?></span></caption>
              <tbody>
                <?php foreach ($floor['wandering']['rows'] as $row) { ?>
                  <tr>
                    <td class="wander-roll"><?= esc($row['roll']) ?></td>
                    <td><b><?= esc($row['quantity']) ?> × <?= esc($row['monster']) ?></b>,
                      <?= esc($row['doing']) ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          <?php } ?>
        </div>

        <?php foreach ($floor['rooms'] as $room) {
            $ways = array_values(array_filter(
                $floor['corridors'],
                static fn ($c) => (int) $c['a'] === (int) $room['id']
                    || (int) $c['b'] === (int) $room['id']
            ));
            ?>
          <div class="area">
            <p class="area-head"><span class="area-num"><?= esc($room['number']) ?>.</span>
              <?= esc($room['name']) ?>
              <?php if ($room['role'] === 'stair') { ?><span class="tag">stair down</span><?php } ?>
              <?php if ($room['role'] === 'entrance') { ?><span class="tag">way in</span><?php } ?>
            </p>
            <div class="read-aloud"><p><?= esc($room['description']) ?></p></div>
            <dl class="notes">
              <?php if ($ways) { ?>
                <dt>Ways out</dt>
                <?php foreach ($ways as $c) {
                    $far = (int) $c['a'] === (int) $room['id'] ? $c['to'] : $c['from'];
                    ?>
                  <dd><?= esc($c['name']) ?> → area <?= esc($far) ?>
                    <?php if (!empty($c['door']) && $c['door'] !== 'open' && $c['door'] !== 'arch') { ?>
                      <span class="tag"><?= esc($c['door']) ?></span>
                    <?php } ?>
                    <?php if (!empty($c['trap'])) { ?>
                      <span class="tag">trap: <?= esc($c['trap']['name']) ?>
                        (<?= esc(strtoupper($c['trap']['save'])) ?> DC <?= esc($c['trap']['dc']) ?>,
                        <?= esc($c['trap']['damage']) ?>)</span>
                    <?php } ?>
                  </dd>
                <?php } ?>
              <?php } ?>
              <?php
              // DungeonGen::CONTENTS in the words a stocked dungeon uses. The
              // bare keys are what the generator thinks; a referee reading a
              // page at speed wants the sentence. `empty` says nothing on
              // purpose — the room's own description already has.
              $stocked = [
                  'monster'  => 'Something lives here.',
                  'hoard'    => 'Something lives here, and it is sitting on something.',
                  'treasure' => 'Treasure, unguarded — which is not the same as unprotected.',
                  'trap'     => 'A trap, in the room rather than on the way in.',
                  'trick'    => 'A trick: something that is not what it looks like.',
                  'boss'     => 'The worst thing on this level, and it knows the ground.',
              ][$room['kind'] ?? ''] ?? null;
              ?>
              <?php if ($stocked !== null) { ?>
                <dt>What is here</dt>
                <dd><?php if (!empty($room['holds'])) { ?>
                    <b><?= esc($room['holds']['quantity']) ?> ×
                      <?= esc($room['holds']['name']) ?></b> —
                  <?php } ?><?= esc($stocked) ?></dd>
              <?php } ?>
            </dl>
          </div>
        <?php } ?>
      </div>
    </div>
  <?php } ?>

  <script>
    /* ui-map.js reaches for Game.esc and nothing else of the game — the same
       shim tools/floorplan_preview.php uses. */
    window.Game = { esc: (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c])) };
    window.BOOK_FLOORS = <?= json_encode(
        array_map(static fn ($f) => ['depth' => $f['depth'], 'map' => $f['map']], $delve['floors']),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?>;
  </script>
  <script src="<?= esc(asset('assets/js/ui-map.js')) ?>"></script>
  <script>
    /* No `ways` and no party: nothing in a book is somewhere you can walk to
       from where you are standing, because nobody is standing anywhere. */
    window.BOOK_FLOORS.forEach((f) => {
      const host = document.querySelector(`.floor-plan[data-floor="${f.depth}"]`);
      if (host) host.innerHTML = window.WorldMap.svg(f.map, { ways: new Map() });
    });
  </script>
<?php } ?>

<?php if ($autoPrint) { ?>
  <script>window.addEventListener('load', () => window.print());</script>
<?php } ?>
</body>
</html>
