<?php
/**
 * The monster manual — every creature, as a book for the printer.
 *
 * The same trade adventure_print.php and sheet_print.php make: the browser is
 * already a PDF writer. This is not a module's appendix. A module's book
 * prints the creatures that module sends; this prints the catalogue those
 * books draw from. A goblin is a goblin, and this is where that is written
 * down.
 *
 * ADMIN ONLY. Not because a goblin's AC is secret — a player will see it the
 * first time one swings — but because the catalogue also holds the named
 * things at the bottom of a module (The Growth, the Drowned Clerk, the Pit
 * Champion), and handing those to the person playing is spoiling the game.
 * `require_admin_page()` is the same gate `/content.php` uses.
 *
 * The layout lives in assets/css/adventure-print.css; this file is structure
 * and data, and Bestiary is where the gathering happens. Nothing here
 * queries anything except through that class.
 *
 *   /bestiary.php              the book
 *   /bestiary.php?print=1      …and open the print dialogue
 */

require_once __DIR__ . '/app/page_guard.php';
$user = require_admin_page();

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$monsters = (new Bestiary(db()))->all();
$autoPrint = ($_GET['print'] ?? '') === '1';

$byCr = [];
$byKind = [];
$bySource = [];
foreach ($monsters as $m) {
    $byCr[(string) $m['cr_label']][] = $m;
    $byKind[(string) $m['kind']][] = $m;
    $src = trim((string) ($m['source'] ?? '')) ?: 'uncredited';
    $bySource[$src][] = $m;
}
ksort($byKind, SORT_STRING);

$sourceBits = [];
foreach ($bySource as $src => $rows) {
    $sourceBits[] = count($rows) . ' from ' . $src;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>The Bestiary — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= esc(asset('assets/css/adventure-print.css')) ?>">
</head>
<body>

<div class="book-bar">
  <span><b>The Bestiary</b> · <?= esc(count($monsters)) ?> creatures</span>
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <a href="adventure_print.php">Adventure books</a>
  <a href="content.php">Back to content</a>
</div>

<!-- ------------------------------------------------------------------ Cover -->
<div class="book-page">
  <div class="cover">
    <h1>The Bestiary</h1>
    <p class="cover-band">A monster manual · <?= esc(count($monsters)) ?> creatures</p>
    <p class="cover-blurb">Every creature the valley, the Old City and the dark
      under them can send. A module's own book prints only the ones it uses;
      this is the catalogue those books draw from.</p>
    <p class="cover-foot"><?= esc(implode(' · ', $sourceBits)) ?></p>
  </div>
</div>

<!-- --------------------------------------------------------------- Contents -->
<div class="book-page">
  <h1>Contents</h1>
  <p class="lede">Ordered by challenge, which is how a referee looks a fight
    up, and again by kind, which is how a name is found when the number is
    not. Click a name to go to its block.</p>

  <div class="toc">
    <p class="toc-chapter">
      <b>By challenge</b>
    </p>
    <?php foreach ($byCr as $cr => $rows) { ?>
      <p class="toc-chapter">
        <b>Challenge <?= esc($cr) ?></b><br>
        <span class="toc-areas"><?php
          $links = [];
          foreach ($rows as $m) {
              $links[] = '<a href="#m-' . esc($m['monster_key']) . '">' . esc($m['name']) . '</a>';
          }
          echo implode(' · ', $links);
        ?></span>
      </p>
    <?php } ?>
    <p class="toc-chapter">
      <b>By kind</b>
    </p>
    <?php foreach ($byKind as $kind => $rows) { ?>
      <p class="toc-chapter">
        <b><?= esc(ucfirst($kind)) ?></b><br>
        <span class="toc-areas"><?php
          $links = [];
          foreach ($rows as $m) {
              $links[] = '<a href="#m-' . esc($m['monster_key']) . '">'
                  . esc($m['name']) . ' (' . esc($m['cr_label']) . ')</a>';
          }
          echo implode(' · ', $links);
        ?></span>
      </p>
    <?php } ?>
  </div>
</div>

<!-- ----------------------------------------------------------- Stat blocks -->
<div class="book-page">
  <div class="cols">
    <h1 class="chapter-head">Stat blocks</h1>
    <?php foreach ($monsters as $m) {
        require __DIR__ . '/app/inc/stat_block.php';
    } ?>
    <p class="colophon">
      Printed from Rivermark Chronicles for the person running it.
      Uses only content from the 5e System Reference Document under OGL 1.0a /
      CC-BY 4.0, together with creatures written for this valley. Not affiliated
      with any trademark holders.
    </p>
  </div>
</div>

<?php if ($autoPrint) { ?>
  <script>window.addEventListener('load', () => window.print());</script>
<?php } ?>
</body>
</html>
