<?php
/**
 * All twelve classes, and — the point of the page — which of their features
 * this engine actually runs.
 *
 * A class page on a game's website is normally a list of names lifted from the
 * rulebook, which tells a reader what the CLASS is and nothing about what the
 * GAME does with it. That is the gap this page exists to close, and it is the
 * same gap `Rules::CLASS_FEATURES` was written to close on the character sheet:
 * "a sheet that promises what the engine does not have is the bug this table
 * exists to make impossible to write". A page that promises it is the same bug
 * one step further from anyone who could notice.
 *
 * So every card has two lists. What the engine grants, with the level it
 * arrives at, read out of the engine's own tables. And what the class row
 * PRINTS — `classes.features`, the SRD names — with anything not in the first
 * list marked as flavour on the sheet. Neither list is typed here.
 */

require_once __DIR__ . '/app/inc/public_page.php';

$classes = public_classes();

public_head(
    'All ' . count($classes) . ' classes',
    'Every class in Rivermark Chronicles, with the hit die, saves, starting kit '
    . 'and subclass — and which features the engine actually implements.'
);
?>

<header class="lp-hero">
  <div class="lp-wrap">
    <p class="lp-eyebrow">Levels 1 to <?= Rules::MAX_LEVEL ?></p>
    <h1><?= count($classes) ?> classes</h1>
    <p class="lp-lead">
      Every one off the open 5e rules, every one playable. Each card says what the class
      is made of, and then says which of its features this game <em>runs</em> — with the
      level it arrives at — rather than which ones the rulebook lists.
    </p>
    <div class="lp-legend">
      <span><i class="lp-swatch"></i> the engine grants it, at that level</span>
      <span><i class="lp-swatch lp-swatch-off"></i> on the sheet, not yet in the engine</span>
    </div>
  </div>
</header>

<section class="lp-band">
  <div class="lp-wrap">
    <div class="lp-grid">
      <?php foreach ($classes as $c) {
          $name = (string) $c['name'];
          $sub = (string) ($c['subclass_name'] ?? '');
          $subAt = (int) ($c['subclass_level'] ?? 3);
          $run = class_features_implemented($name, $sub, $subAt);

          // The names the class row prints, minus the ones already shown above
          // as implemented. Matching is on the same lowercase-underscore rule
          // CharacterSheet::traitImplemented() uses, so "Second Wind" on the row
          // and `second_wind` in the engine are recognised as one thing.
          $runKeys = array_fill_keys(array_column($run, 1), true);
          $printed = [];
          foreach (array_filter(array_map('trim', explode(',', (string) $c['features']))) as $f) {
              $key = strtolower(str_replace([' ', '-', "'"], ['_', '_', ''], $f));
              if (!isset($runKeys[$key])) {
                  $printed[] = $f;
              }
          }
      ?>
        <article class="lp-card">
          <h3><?= e($name) ?></h3>
          <p class="lp-card-sub">
            d<?= (int) $c['hit_die'] ?> hit die
            <?php if (($c['source'] ?? '') !== '5e SRD') { ?>
              · <?= e((string) $c['source']) ?>
            <?php } ?>
          </p>

          <dl class="lp-stats">
            <dt>Primary</dt><dd><?= e(str_replace(',', ', ', (string) $c['primary_ability'])) ?></dd>
            <dt>Saves</dt><dd><?= e(str_replace(',', ', ', (string) $c['saving_throws'])) ?></dd>
            <dt>Armour</dt>
            <dd><?= ($c['armor_proficiencies'] ?? '') !== ''
                  ? e(str_replace(',', ', ', (string) $c['armor_proficiencies']))
                  : 'None' ?></dd>
            <dt>Weapons</dt>
            <dd><?= ($c['weapon_proficiencies'] ?? '') !== ''
                  ? e(str_replace(',', ', ', (string) $c['weapon_proficiencies']))
                  : 'None' ?></dd>
            <?php if ($sub !== '') { ?>
              <dt>Path</dt><dd><?= e($sub) ?>, at level <?= $subAt ?></dd>
            <?php } ?>
          </dl>

          <?php if ($run) { ?>
            <p class="lp-card-sub" style="margin-top:var(--sp-12)">What the game runs</p>
            <ul class="lp-feats">
              <?php foreach ($run as [$at, $key, $label, $blurb]) { ?>
                <li>
                  <span class="lp-feat-n">Lv <?= $at ?></span>
                  <span>
                    <span class="lp-feat-b"><?= e($label) ?></span>
                    <?php if ($blurb !== '') { ?>
                      <span class="lp-feat-d"> — <?= e($blurb) ?></span>
                    <?php } ?>
                  </span>
                </li>
              <?php } ?>
            </ul>
          <?php } ?>

          <?php if ($printed) { ?>
            <p class="lp-card-sub" style="margin-top:var(--sp-12)">Printed on the sheet</p>
            <ul class="lp-feats">
              <?php foreach ($printed as $f) { ?>
                <li class="is-flavour">
                  <span class="lp-feat-n">—</span>
                  <span class="lp-feat-b"><?= e($f) ?></span>
                </li>
              <?php } ?>
            </ul>
          <?php } ?>
        </article>
      <?php } ?>
    </div>

    <p class="lp-note" style="margin-top:var(--sp-24);max-width:70ch">
      Nothing on this page is a hand-written list. The classes come from the same table
      the character creator is built from, and the feature lists are read out of the
      tables the fight itself consults — so a class or a feature added to the game turns
      up here on its own, and one that was never implemented cannot be advertised as
      though it were.
    </p>
  </div>
</section>

<?php public_foot(); ?>
