<?php
/**
 * One creature, as a stat block.
 *
 * Drawn by both the monster manual and the adventure book's appendix, so a
 * fix to how an attack is written (or a line that was missing) cannot land
 * in one and miss the other. Expects a row from Bestiary::format() in `$m`,
 * and `esc()` already defined by the page.
 *
 * Nothing here does arithmetic. The modifier, the challenge fraction, the
 * save line and the treasure line arrive already written.
 *
 * @var array<string, mixed> $m
 */

$art = $m['art_file'] !== '' ? asset((string) $m['art_file']) : null;
$anchor = (string) ($m['monster_key'] ?? '');
?>
<div class="stat<?= $art ? ' has-art' : '' ?>"<?php
  if ($anchor !== '') {
      echo ' id="m-' . esc($anchor) . '"';
  }
?>>
  <?php if ($art) { ?><img class="stat-art" src="<?= esc($art) ?>" alt=""><?php } ?>
  <h3><?= esc($m['name']) ?></h3>
  <p class="stat-kind"><?= esc(trim((string) $m['size'] . ' ' . (string) $m['type'])) ?><?=
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
      <div><span><?= $label ?></span><?= esc($m[$col]) ?> (<?= esc(Bestiary::modOf((int) $m[$col])) ?>)</div>
    <?php } ?>
  </div>
  <hr class="stat-rule">
  <?php if ($m['save_line']) { ?>
    <p class="stat-line"><b>Saving Throws</b> <?= esc($m['save_line']) ?></p>
  <?php } ?>
  <?php if ($m['resistances']) { ?>
    <p class="stat-line"><b>Resistances</b> <?= esc(implode(', ', $m['resistances'])) ?></p>
  <?php } ?>
  <?php if ($m['immunities']) { ?>
    <p class="stat-line"><b>Immunities</b> <?= esc(implode(', ', $m['immunities'])) ?></p>
  <?php } ?>
  <?php if ($m['vulnerabilities']) { ?>
    <p class="stat-line"><b>Vulnerabilities</b> <?= esc(implode(', ', $m['vulnerabilities'])) ?></p>
  <?php } ?>
  <?php if ($m['condition_immunities']) { ?>
    <p class="stat-line"><b>Condition Immunities</b> <?= esc(implode(', ', $m['condition_immunities'])) ?></p>
  <?php } ?>
  <p class="stat-line"><b>Challenge</b> <?= esc($m['cr_label']) ?> (<?= esc($m['experience_points']) ?> XP)</p>
  <?php if ($m['loot_line']) { ?>
    <p class="stat-line"><b>Treasure</b> <?= esc($m['loot_line']) ?></p>
  <?php } ?>
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
  <?php if ($m['legendary_list']) { ?>
    <hr class="stat-rule">
    <h4>Legendary Actions</h4>
    <?php foreach ($m['legendary_list'] as $a) { ?>
      <p class="stat-trait"><?= $a['name'] ? '<b>' . esc($a['name']) . '.</b> ' : '' ?><?=
        esc($a['text']) ?></p>
    <?php } ?>
  <?php } ?>
  <?php if (!empty($m['source'])) { ?>
    <p class="stat-source"><?= esc($m['source']) ?></p>
  <?php } ?>
</div>
