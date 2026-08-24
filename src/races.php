<?php
/**
 * The peoples, one card per row — which means per race AND subrace, because
 * that is what the creator offers and what the ability bonuses hang off.
 *
 * Two things this page has to get right, both of them rules the rest of the
 * codebase already states:
 *
 * THE PLATE IS PER RACE, NOT PER ROW. A Wood Elf and a High Elf are both elves
 * and there is one painting of elves, found by filename rather than by a
 * column. `race_plate()` checks the disk for it, because this vhost answers a
 * missing file with the homepage HTML and a 200 — an `onerror` would never fire
 * and the card would end up showing a web page inside a picture frame.
 *
 * THE DESCRIPTION IS OURS. `races.description` is written for this valley — who
 * lends money, who witnesses contracts, who the priory disapproves of — rather
 * than lifted from the SRD, whose prose is a licensed document. It is per ROW
 * on purpose: a Wood Elf reading the High Elf's paragraph would be reading
 * about somebody else.
 */

require_once __DIR__ . '/app/inc/public_page.php';

$rows = public_races();
$count = public_race_count();

// How many of the peoples on offer come off the SRD and how many are ours.
// Counted rather than written down: the sentence below said "nine of these" for
// as long as ten races were listed, and went on saying it when two were
// withheld and eight were left.
$ours = [];
$srd = [];
foreach ($rows as $r) {
    $bucket = ((string) $r['source'] === '5e SRD') ? 'srd' : 'ours';
    ${$bucket}[(string) $r['name']] = true;
}
$nSrd = count($srd);

public_head(
    'All ' . $count . ' races',
    'Every playable people in Rivermark Chronicles, with ability bonuses, speed, '
    . 'and which of their traits the engine actually enforces.'
);
?>

<header class="lp-hero">
  <div class="lp-wrap">
    <p class="lp-eyebrow"><?= $count ?> peoples · <?= count($rows) ?> rows to choose from</p>
    <h1>Who you are</h1>
    <p class="lp-lead">
      <?= $nSrd ?> of these come off the open 5e rules.
      <?= count($ours) === 1 ? 'One does not' : count($ours) . ' do not' ?>: the
      <strong><?= e(implode(', ', array_keys($ours))) ?></strong>
      <?= count($ours) === 1 ? 'is' : 'are' ?> ours, cut for this valley out of things that
      were already in it — the haul-road, the boundary stones, the Old City's masonry —
      because the SRD has no big people in it and we would rather write one than borrow one.
    </p>
    <div class="lp-legend">
      <span><i class="lp-swatch"></i> the engine enforces it</span>
      <span><i class="lp-swatch lp-swatch-off"></i> printed on the sheet, not enforced</span>
    </div>
  </div>
</header>

<section class="lp-band">
  <div class="lp-wrap">
    <div class="lp-grid">
      <?php foreach ($rows as $r) {
          $name = (string) $r['name'];
          $subrace = trim((string) ($r['subrace'] ?? ''));
          $plate = race_plate($name);
          $traits = race_traits($name, $subrace, (string) $r['traits']);
          $bonuses = race_bonuses($r);
      ?>
        <article class="lp-card<?= $plate === null ? ' is-bare' : '' ?>">
          <?php if ($plate !== null) { ?>
            <img class="lp-plate" src="<?= asset($plate) ?>" loading="lazy"
                 alt="A man and a woman of the <?= e($name) ?> people, back to back.">
          <?php } ?>

          <h3><?= e($subrace !== '' ? $subrace : $name) ?></h3>
          <p class="lp-card-sub">
            <?= $subrace !== '' ? e($name) . ' · ' : '' ?><?= (int) $r['speed'] ?> ft speed
            <?php if (($r['source'] ?? '') !== '5e SRD') { ?>
              · <?= e((string) $r['source']) ?>
            <?php } ?>
          </p>

          <?php if ($bonuses) { ?>
            <ul class="lp-abil">
              <?php foreach ($bonuses as $b) { ?><li><?= e($b) ?></li><?php } ?>
            </ul>
          <?php } ?>

          <?php if (($r['description'] ?? '') !== '') { ?>
            <p><?= e((string) $r['description']) ?></p>
          <?php } ?>

          <?php if ($traits) { ?>
            <p class="lp-card-sub" style="margin-top:var(--sp-8)">Traits</p>
            <ul class="lp-feats">
              <?php foreach ($traits as $t) { ?>
                <li<?= $t['run'] ? '' : ' class="is-flavour"' ?>>
                  <span class="lp-feat-n"><?= $t['run'] ? 'Run' : '—' ?></span>
                  <span>
                    <span class="lp-feat-b"><?= e($t['name']) ?></span>
                    <?php if ($t['run'] && $t['blurb'] !== '') { ?>
                      <span class="lp-feat-d"> — <?= e($t['blurb']) ?></span>
                    <?php } ?>
                  </span>
                </li>
              <?php } ?>
            </ul>
          <?php } ?>
        </article>
      <?php } ?>
    </div>

    <p class="lp-note" style="margin-top:var(--sp-24);max-width:70ch">
      Darkvision is the standing example of a trait that is printed and not enforced:
      there is no light or darkness anywhere in this engine, so claiming it would be a
      lie in one direction and removing it from the sheet would be a lie in the other.
      It is printed, and said not to be enforced, which is the only honest third option.
    </p>
  </div>
</section>

<?php public_foot(); ?>
