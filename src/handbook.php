<?php
/**
 * The Player's Handbook — the catalogue, as a book for the printer.
 *
 * The third of the printed books, and the first one written for the person
 * PLAYING rather than the person running it. `adventure_print.php` prints one
 * module and `bestiary.php` prints what is going to hit you; both are the
 * referee's. This prints what a player is entitled to know before they sit
 * down: who they can be, what they can do, what the numbers mean, and what
 * every condition on the board does to them.
 *
 * OPEN TO EVERYONE, the same as classes.php, races.php and the bestiary.
 * Nothing here is about anybody — Handbook reads the catalogue tables and the
 * engine's own constants, and there is no session to need.
 *
 * The layout is `assets/css/adventure-print.css`, shared with the other two
 * books, so the three print as a set. This file is structure; Handbook is where
 * the gathering happens and nothing here queries anything except through it.
 *
 * NO NUMBER IN THIS FILE IS TYPED. Every one comes from the thing that enforces
 * it — see Handbook's header for why that matters more here than anywhere else
 * in the project. The one exception is the prose in "How a fight works", which
 * describes rules that live in CombatEngine's geometry rather than in a table
 * anything can be read out of; it is written in the register the rest of the
 * project uses and names the constants it is describing.
 *
 *   /handbook.php              the book
 *   /handbook.php?print=1      …and open the print dialogue
 */

require_once __DIR__ . '/app/inc/public_page.php';

$book = new Handbook(db());
$peoples    = $book->peoples();
$callings   = $book->callings();
$ladder     = $book->ladder();
$spells     = $book->spellsByLevel();
$conditions = $book->conditions();
$skills     = $book->skillsByAbility();
$gear       = $book->gear();

$autoPrint = ($_GET['print'] ?? '') === '1';
$spellCount = array_sum(array_map('count', $spells));
$raceNames = [];
foreach ($peoples as $p) {
    $raceNames[(string) $p['name']] = true;
}

/** A spell level as a heading: "Cantrips", "1st-level", … */
function levelLabel(int $n): string
{
    if ($n === 0) {
        return 'Cantrips';
    }
    $suffix = match (true) {
        $n % 100 >= 11 && $n % 100 <= 13 => 'th',
        $n % 10 === 1 => 'st',
        $n % 10 === 2 => 'nd',
        $n % 10 === 3 => 'rd',
        default       => 'th',
    };
    return $n . $suffix . '-level';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>The Player's Handbook — Rivermark Chronicles</title>
  <link rel="stylesheet" href="<?= e(asset('assets/css/adventure-print.css')) ?>">
  <style>
    /* The three tables this book adds to the shared print skin. They are the
       only thing here adventure-print.css has no rule for, because neither of
       the other two books has a table in it. Kept local rather than pushed into
       the stylesheet for that reason — a rule used once belongs where it is
       used. */
    .hb-table { width: 100%; border-collapse: collapse; margin: 0.6rem 0 1rem; }
    .hb-table th, .hb-table td {
      border-bottom: 1px solid var(--rule, #c9bda6);
      padding: 0.18rem 0.4rem; text-align: left; font-size: 0.82rem;
    }
    .hb-table th { font-variant: small-caps; letter-spacing: 0.04em; }
    .hb-table td.n, .hb-table th.n { text-align: right; font-variant-numeric: tabular-nums; }

    /* The slot ladder is the one table that does not get to size itself.
       A full caster's is ten columns wide and it lives in a 3.3in measure, so
       "1st-level" headers put it half again over the column and out into the
       gutter — five of the eight ran over. `table-layout: fixed` with a width
       of 100% makes it fit BY CONSTRUCTION rather than by my arithmetic about
       padding being right, and the headers become the bare numerals the
       caption above them explains. */
    .hb-slots { table-layout: fixed; }
    .hb-slots th, .hb-slots td {
      padding: 0.14rem 0.1rem; font-size: 0.74rem; text-align: center;
      font-variant-numeric: tabular-nums;
    }
    .hb-slots th { font-variant: normal; letter-spacing: 0; }
    .hb-slots .lv { width: 2.2em; }
    .hb-slots-cap {
      font-size: 0.74rem; font-variant: small-caps; letter-spacing: 0.05em;
      margin: 0.5rem 0 0; opacity: 0.8;
    }
    .hb-asi { font-weight: 700; }
    .hb-run { font-variant: small-caps; letter-spacing: 0.04em; }
    .hb-off { opacity: 0.62; }
    .hb-entry { margin: 0 0 0.55rem; }
    .hb-entry b { font-variant: small-caps; letter-spacing: 0.03em; }
    .hb-meta { display: block; font-size: 0.78rem; opacity: 0.75; }
  </style>
</head>
<body>

<?php $admin = auth()->isAdmin(); ?>
<div class="book-bar">
  <span><b>The Player's Handbook</b> · <?= e((string) count($callings)) ?> callings ·
    <?= e((string) count($raceNames)) ?> peoples · <?= e((string) $spellCount) ?> spells</span>
  <button type="button" onclick="window.print()">Print / Save as PDF</button>
  <a href="index.php">Home</a>
  <a href="bestiary.php">Bestiary</a>
  <a href="about.php">About &amp; licence</a>
  <?php if ($admin) { ?>
    <a href="adventure_print.php">Adventure books</a>
  <?php } ?>
</div>

<!-- ------------------------------------------------------------------ Cover -->
<div class="book-page">
  <div class="cover">
    <h1>The Player's Handbook</h1>
    <p class="cover-band">Rivermark Chronicles · levels 1 to <?= e((string) Rules::MAX_LEVEL) ?></p>
    <p class="cover-blurb">Who you can be, what you can do, and what the numbers on
      your sheet are for. Everything in this book is read from the rules the game
      actually enforces — if it is printed here, it happens.</p>
    <p class="cover-foot">Rules content from the 5e System Reference Document,
      CC-BY 4.0 · setting and prose original to this game</p>
  </div>
</div>

<!-- --------------------------------------------------------------- Contents -->
<div class="book-page">
  <h1>Contents</h1>
  <p class="lede">A handbook, not an adventure. Nothing in here spoils anything:
    the creatures are in the <a href="bestiary.php">bestiary</a> and the places
    are in the referee's own book.</p>

  <div class="toc">
    <p class="toc-chapter"><b><a href="#how">How a fight works</a></b><br>
      <span class="toc-areas">The board · the turn · what an action buys</span></p>

    <p class="toc-chapter"><b><a href="#peoples">The peoples</a></b><br>
      <span class="toc-areas"><?php
        echo implode(' · ', array_map(
            static fn ($p) => '<a href="#' . e((string) $p['anchor']) . '">'
                . e((string) $p['display_name']) . '</a>',
            $peoples
        ));
      ?></span></p>

    <p class="toc-chapter"><b><a href="#callings">The callings</a></b><br>
      <span class="toc-areas"><?php
        echo implode(' · ', array_map(
            static fn ($c) => '<a href="#' . e((string) $c['anchor']) . '">'
                . e((string) $c['name']) . '</a>',
            $callings
        ));
      ?></span></p>

    <p class="toc-chapter"><b><a href="#rising">Rising</a></b><br>
      <span class="toc-areas">The ladder · ability increases · spell slots</span></p>

    <p class="toc-chapter"><b><a href="#skills">Abilities and skills</a></b></p>

    <p class="toc-chapter"><b><a href="#spells">Spells</a></b><br>
      <span class="toc-areas"><?php
        echo implode(' · ', array_map(
            static fn ($lvl) => '<a href="#sp-' . (int) $lvl . '">' . e(levelLabel((int) $lvl)) . '</a>',
            array_keys($spells)
        ));
      ?></span></p>

    <p class="toc-chapter"><b><a href="#conditions">Conditions</a></b><br>
      <span class="toc-areas"><?php
        echo implode(' · ', array_map(
            static fn ($c) => e((string) $c['label']),
            $conditions
        ));
      ?></span></p>

    <p class="toc-chapter"><b><a href="#gear">Gear</a></b><br>
      <span class="toc-areas"><?php
        echo implode(' · ', array_map(
            static fn ($k) => '<a href="#g-' . e((string) $k) . '">' . e(ucfirst((string) $k)) . '</a>',
            array_keys($gear)
        ));
      ?></span></p>
  </div>
</div>

<!-- ---------------------------------------------------------- How it works -->
<div class="book-page" id="how">
  <div class="cols">
    <h1 class="chapter-head">How a fight works</h1>

    <p>A fight is fought on a board of five-foot cells with terrain on it. Everything
      that follows is a distance or a count, and the server works all of it out —
      the screen draws the answer rather than guessing at it.</p>

    <p><b class="hb-run">The turn.</b> On your turn you have your <b>movement</b>, one
      <b>action</b>, and — if your calling gives you one — a <b>bonus action</b>.
      Movement is spent in feet out of your speed. Taking the Dash action buys your
      speed again.</p>

    <p><b class="hb-run">Reach and range.</b> A melee weapon reaches five feet unless
      it says otherwise. A thrown or fired weapon has a normal range and a long one,
      and shooting past the normal range costs you accuracy. Nothing is abstract:
      if the number says thirty feet, it is six cells.</p>

    <p><b class="hb-run">Cover.</b> Anything solid between you and what you are
      shooting at makes it harder to hit, and cover is worked out for that shot
      rather than for the square you are standing in.</p>

    <p><b class="hb-run">Opportunity attacks.</b> Stepping out of somebody's reach
      lets them swing at you as you go. The squares that will cost you are hatched
      on the board <em>before</em> you move. Taking the Disengage action means they
      do not get the swing.</p>

    <p><b class="hb-run">Advantage and disadvantage.</b> Two dice, keep the better or
      the worse. They do not stack and they cancel: whatever the reasons, you have
      one, the other, or neither.</p>

    <p><b class="hb-run">Going down.</b> At nought hit points you are dying, and each
      turn you roll a death saving throw. Three successes and you are stable; three
      failures and you are gone. Any healing at all puts you back on your feet with
      the slate wiped.</p>

    <p><b class="hb-run">Resting.</b> An hour's breather gives back a hit die and the
      features whose text says short rest. A night's camp gives back the rest — all
      your hit points, half your hit dice, and your spell slots. Where you may sleep
      is a fact about the place you are standing.</p>
  </div>
</div>

<!-- ------------------------------------------------------------- The peoples -->
<div class="book-page" id="peoples">
  <div class="cols">
    <h1 class="chapter-head">The peoples</h1>
    <p class="lede">A trait marked <span class="hb-run">Run</span> is one the game
      enforces. One left plain is printed on your sheet and nothing in the engine
      reads it — Darkvision is the standing example, because there is no light or
      darkness anywhere in this game.</p>

    <?php foreach ($peoples as $p) { ?>
      <div class="stat" id="<?= e((string) $p['anchor']) ?>">
        <p class="stat-name"><b><?= e((string) $p['display_name']) ?></b>
          <?php if (trim((string) $p['subrace']) !== '') { ?>
            <span class="hb-meta"><?= e((string) $p['name']) ?></span>
          <?php } ?>
        </p>
        <p class="stat-line">
          <?= e((string) $p['speed']) ?> ft speed<?php
            if ($p['bonuses']) { echo ' · ' . e(implode(', ', $p['bonuses'])); }
            if ((string) $p['source'] !== '5e SRD') { echo ' · ' . e((string) $p['source']); }
          ?>
        </p>
        <?php if (trim((string) ($p['description'] ?? '')) !== '') { ?>
          <p><?= e((string) $p['description']) ?></p>
        <?php } ?>
        <?php foreach ($p['trait_rows'] as $t) { ?>
          <p class="stat-trait<?= $t['run'] ? '' : ' hb-off' ?>">
            <b><?= e((string) $t['name']) ?></b>
            <?php if ($t['run']) { ?>
              <span class="hb-run">· run</span>
              <?php if ($t['blurb'] !== '') { ?> — <?= e((string) $t['blurb']) ?><?php } ?>
            <?php } else { ?>
              <span class="hb-run">· printed only</span>
            <?php } ?>
          </p>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>

<!-- ------------------------------------------------------------ The callings -->
<div class="book-page" id="callings">
  <div class="cols">
    <h1 class="chapter-head">The callings</h1>
    <p class="lede">Each entry lists what the game <em>runs</em>, with the level it
      arrives at, and separately what your sheet prints without the engine reading
      it. Both halves are read out of the same tables the fight consults.</p>

    <?php foreach ($callings as $c) { ?>
      <div class="stat" id="<?= e((string) $c['anchor']) ?>">
        <p class="stat-name"><b><?= e((string) $c['name']) ?></b></p>
        <p class="stat-line">d<?= e((string) $c['hit_die']) ?> hit die ·
          <?= e(str_replace(',', ', ', (string) $c['primary_ability'])) ?> ·
          saves in <?= e(str_replace(',', ', ', (string) $c['saving_throws'])) ?>
        </p>
        <p class="stat-line">
          Armour: <?= e(((string) $c['armor_proficiencies']) !== ''
              ? str_replace(',', ', ', (string) $c['armor_proficiencies']) : 'none') ?> ·
          Weapons: <?= e(((string) $c['weapon_proficiencies']) !== ''
              ? str_replace(',', ', ', (string) $c['weapon_proficiencies']) : 'none') ?>
          <?php if (trim((string) $c['subclass_name']) !== '') { ?>
            · <?= e((string) $c['subclass_name']) ?> at <?= e((string) $c['subclass_level']) ?>
          <?php } ?>
        </p>

        <?php if ($c['run']) { ?>
          <p class="stat-trait"><b>What the game runs</b></p>
          <?php foreach ($c['run'] as [$at, $key, $label, $blurb]) { ?>
            <p class="stat-trait"><b><?= e($label) ?></b>
              <span class="hb-run">· level <?= (int) $at ?></span>
              <?php if ($blurb !== '') { ?> — <?= e($blurb) ?><?php } ?>
            </p>
          <?php } ?>
        <?php } ?>

        <?php if ($c['printed']) { ?>
          <p class="stat-trait hb-off"><b>Printed on the sheet</b> —
            <?= e(implode(', ', $c['printed'])) ?></p>
        <?php } ?>

        <?php if ($c['slots'] !== null) { ?>
          <p class="hb-slots-cap">Spell slots — character level down the side,
            spell level across the top</p>
          <table class="hb-table hb-slots">
            <thead>
              <tr><th class="lv" scope="col">Lv</th>
                <?php foreach ($c['slots']['levels'] as $sl) { ?>
                  <th scope="col"><?= (int) $sl ?></th>
                <?php } ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($c['slots']['rows'] as $level => $counts) { ?>
                <tr><th class="lv" scope="row"><?= (int) $level ?></th>
                  <?php foreach ($counts as $n) { ?><td><?= $n > 0 ? (int) $n : '—' ?></td><?php } ?>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>

<!-- ----------------------------------------------------------------- Rising -->
<div class="book-page" id="rising">
  <div class="cols">
    <h1 class="chapter-head">Rising</h1>
    <p class="lede">Experience is banked when a fight ends and the level is claimed
      on the spot. A level in <b>bold</b> offers an ability increase or a feat.</p>
    <table class="hb-table">
      <thead><tr><th class="n">Level</th><th class="n">Experience</th>
        <th class="n">Proficiency</th><th>Also</th></tr></thead>
      <tbody>
        <?php foreach ($ladder as $row) { ?>
          <tr<?= $row['asi'] ? ' class="hb-asi"' : '' ?>>
            <td class="n"><?= (int) $row['level'] ?></td>
            <td class="n"><?= number_format($row['xp']) ?></td>
            <td class="n">+<?= (int) $row['prof'] ?></td>
            <td><?= $row['asi'] ? 'Ability increase or a feat' : '' ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <p>Your hit points go up every level by a roll of your calling's hit die plus
      your Constitution modifier. Your proficiency bonus is added to everything you
      are proficient in — attacks with weapons you know, saves your calling grants,
      and skills you trained.</p>
  </div>
</div>

<!-- ------------------------------------------------------ Abilities & skills -->
<div class="book-page" id="skills">
  <div class="cols">
    <h1 class="chapter-head">Abilities and skills</h1>
    <p class="lede">Six abilities, and every skill is rolled with one of them. A
      score of 10 is a modifier of +0, and every two points either way moves it by
      one.</p>
    <table class="hb-table">
      <thead><tr><th>Ability</th><th>Skills rolled with it</th></tr></thead>
      <tbody>
        <?php foreach (Rules::ABILITY_NAMES as $key => $name) { ?>
          <tr>
            <td><b><?= e((string) $name) ?></b></td>
            <td><?= e(implode(', ', $skills[$key] ?? [])) ?: '<span class="hb-off">—</span>' ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <p>Being <b>proficient</b> in a skill adds your proficiency bonus to it.
      <b>Expertise</b> — which a rogue gets — adds it a second time.</p>
  </div>
</div>

<!-- ----------------------------------------------------------------- Spells -->
<div class="book-page" id="spells">
  <div class="cols">
    <h1 class="chapter-head">Spells</h1>
    <p class="lede">What each spell actually does when it is cast, taken from the
      numbers the engine resolves against rather than from the description.
      Cantrips cost no slot and grow with your level.</p>

    <?php foreach ($spells as $level => $rows) { ?>
      <h2 id="sp-<?= (int) $level ?>"><?= e(levelLabel((int) $level)) ?></h2>
      <?php foreach ($rows as $s) { ?>
        <p class="hb-entry" id="<?= e((string) $s['anchor']) ?>">
          <b><?= e((string) $s['name']) ?></b>
          <?php if ((string) $s['effect_line'] !== '') { ?>
            — <?= e((string) $s['effect_line']) ?>
          <?php } ?>
          <span class="hb-meta"><?= e((string) $s['school']) ?> ·
            <?= e((string) $s['casting_time']) ?> ·
            <?= e((string) $s['range_text']) ?><?php
              if ($s['casters']) { echo ' · ' . e(implode(', ', $s['casters'])); }
            ?></span>
        </p>
      <?php } ?>
    <?php } ?>
  </div>
</div>

<!-- ------------------------------------------------------------- Conditions -->
<div class="book-page" id="conditions">
  <div class="cols">
    <h1 class="chapter-head">Conditions</h1>
    <p class="lede">Every condition the board can put on you or on something you are
      fighting, and exactly what it does. These are the engine's own words for them.</p>
    <?php foreach ($conditions as $c) { ?>
      <p class="hb-entry"><b><?= e((string) $c['label']) ?></b> —
        <?= e((string) $c['description']) ?></p>
    <?php } ?>
  </div>
</div>

<!-- ------------------------------------------------------------------- Gear -->
<div class="book-page" id="gear">
  <div class="cols">
    <h1 class="chapter-head">Gear</h1>
    <p class="lede">What you can carry and what it is worth. Treasure is left out —
      it is what a hoard is made of rather than anything you choose.</p>

    <?php foreach ($gear as $kind => $rows) { ?>
      <h2 id="g-<?= e((string) $kind) ?>"><?= e(ucfirst((string) $kind)) ?></h2>
      <?php foreach ($rows as $i) { ?>
        <p class="hb-entry">
          <b><?= e((string) $i['name']) ?></b>
          <?php if ((string) $i['line'] !== '') { ?>
            <span class="hb-meta"><?= e((string) $i['line']) ?></span>
          <?php } ?>
        </p>
      <?php } ?>
    <?php } ?>

    <p class="colophon">
      Printed from Rivermark Chronicles for the person playing it. Rules content
      from the 5e System Reference Document 5.1 and 5.2 under CC-BY 4.0; setting,
      prose and art original to this game. Not affiliated with any trademark holder.
    </p>
  </div>
</div>

<?php if ($autoPrint) { ?>
  <script>window.addEventListener('load', () => window.print());</script>
<?php } ?>
</body>
</html>
