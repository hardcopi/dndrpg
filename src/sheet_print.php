<?php
/**
 * A character sheet for the printer — the one a player takes to a table.
 *
 * Why a page and not a PHP PDF library
 * ------------------------------------
 * Because the browser already is one. Chrome, Firefox and Safari all print to
 * PDF, they all honour `@page` and `@media print`, and they lay out text better
 * than anything that would have to be installed to compete with them. The
 * alternative is a PHP extension in `docker/php/Dockerfile`, which is pinned to
 * mirror the set enabled on hermes — so adding one here is a deployment change
 * to the production host, made in order to render a page the browser was going
 * to render anyway.
 *
 * How it is protected
 * -------------------
 * The page guard for "is anybody signed in", then Ownership for "is this yours".
 * The API's assert_character_accessible() is deliberately NOT used: it narrows
 * to the character being played or somebody in the active party, which is the
 * right question for acting inside a game and the wrong one for printing. A
 * player looking at their character list should be able to print any of their
 * own characters, including one from a game they are not currently in — the
 * same reasoning as assert_character_manageable(), and the same widening.
 *
 * A character that is not yours gets a 404 rather than a 403. A 403 confirms
 * the id exists, which would turn this page into a way of counting other
 * people's saves.
 *
 * The layout lives in assets/css/sheet-print.css; this file is structure and
 * data. Nothing here does rules arithmetic — CharacterSheet asks Rules, and a
 * template that added its own +2 anywhere would be a second rules engine
 * nobody tests.
 */

require_once __DIR__ . '/app/page_guard.php';
$user = require_signed_in_page();

/** The refusal page, in the same voice as page_guard's. */
function sheet_refuse(int $status, string $heading, string $detail): void
{
    http_response_code($status);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>No such sheet — Rivermark Chronicles</title>'
        . '<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>"></head>'
        . '<body class="auth-page"><main class="auth-card">'
        . '<h1>' . htmlspecialchars($heading, ENT_QUOTES) . '</h1>'
        . '<p class="auth-sub">' . htmlspecialchars($detail, ENT_QUOTES) . '</p>'
        . '<p class="auth-foot"><a href="index.php">Back to your characters</a></p>'
        . '</main></body></html>';
    exit;
}

$characterId = (int) ($_GET['character_id'] ?? 0);
if ($characterId <= 0) {
    sheet_refuse(400, 'Which character?', 'This page needs a character to print.');
}
if (!Ownership::character($characterId)) {
    sheet_refuse(404, 'No such character', 'There is no character of yours with that number.');
}

try {
    $sheet = (new CharacterSheet(db()))->build($characterId);
} catch (RuntimeException $e) {
    sheet_refuse(404, 'No such character', 'There is no character of yours with that number.');
}

$c = $sheet['character'];
$spellcasting = $sheet['spellcasting'];

/*
 * Auto-open the print dialogue only when asked. The button on the in-game sheet
 * asks; a link followed by hand does not, so the page can be read and checked
 * before any paper is committed to.
 */
$autoPrint = ($_GET['print'] ?? '') === '1';

// ---------------------------------------------------------------------------
// Template helpers.
//
// Short names, because they appear a few hundred times below and
// `htmlspecialchars($x, ENT_QUOTES)` written out in full turns the structure of
// the page into noise. Not one-letter names, though: these are declared in the
// global namespace after bootstrap.php has already run, so a helper called `e()`
// would be a fatal error waiting for the day somebody adds an `e()` to the
// bootstrap. `esc` and `fmt_mod` also match what the client calls the same two
// jobs — `esc()` and `Game.fmtMod()` — which is one less vocabulary to hold.
// ---------------------------------------------------------------------------

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** A modifier as a player writes it: +3, -1, +0. */
function fmt_mod(int $value): string
{
    return sprintf('%+d', $value);
}


/**
 * The bust portrait, or null if this character has no art on disk.
 *
 * Checked against the filesystem rather than assumed, because the vhost ends in
 * `try_files ... /index.php`, so a missing image does not 404 — it returns the
 * homepage HTML with a 200, and an <img> pointed at that renders as a broken
 * icon on somebody's printout.
 */
function portrait(?string $spriteKey): ?string
{
    $key = trim((string) $spriteKey);
    if ($key === '') {
        return null;
    }
    foreach (['_bust', '_face'] as $suffix) {
        $path = 'assets/images/npcs/' . $key . $suffix . '.png';
        if (is_file(__DIR__ . '/' . $path)) {
            return asset($path);
        }
    }
    return null;
}

/** Ruled blank lines for a box the player fills in with a pen. */
function rules_lines(int $count): string
{
    return '<div class="rules">' . str_repeat('<span class="rule"></span>', $count) . '</div>';
}

/** A row of empty circles to be ticked off in pencil. */
function bubbles(int $count): string
{
    return str_repeat('<span class="bub"></span>', $count);
}

$portraitUrl = portrait($c['sprite_key'] ?? null);
$xp = $c['xp_progress'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= esc($c['name']) ?> — Character Sheet</title>
  <link rel="stylesheet" href="<?= asset('assets/css/sheet-print.css') ?>">
</head>
<body>

<!--
  Screen only. The print stylesheet removes it outright rather than hiding it,
  because a hidden element still takes up space and half an inch of nothing at
  the top of page one is what pushes the bottom of the sheet onto page two.
-->
<div class="sheet-toolbar no-print">
  <button type="button" class="primary" id="btn-print">Print / Save as PDF</button>
  <a class="tb" href="game.php">Back to the game</a>
  <a class="tb" href="index.php">Characters</a>
  <span class="spacer"></span>
  <span class="who"><strong><?= esc($c['name']) ?></strong> · level <?= esc($c['level']) ?> <?= esc($c['class']) ?></span>
</div>

<p class="sheet-hint no-print">
  Set the paper to <strong>US Letter, portrait</strong> and turn on
  <strong>Background graphics</strong> — the filled dots that mark a proficiency
  are backgrounds, and a printer told to skip them prints every skill as if you
  had none. Margins can stay at Default; the page sets its own.
  <?php if ($spellcasting === null): ?>
  There is no spell page: a <?= esc($c['class']) ?> has nothing to put on one.
  <?php endif; ?>
</p>

<!-- ===================================================================== -->
<!-- Page one                                                              -->
<!-- ===================================================================== -->
<div class="sheet-page">

  <div class="sheet-top">
    <div class="name-block">
      <?php if ($portraitUrl !== null): ?>
      <img class="portrait" src="<?= esc($portraitUrl) ?>" alt="">
      <?php endif; ?>
      <div class="who">
        <h1 class="char-name"><?= esc($c['name']) ?></h1>
        <span class="char-name-label">Character Name</span>
      </div>
    </div>

    <div class="header-grid">
      <div class="field">
        <span class="field-value">
          <?= esc($c['class']) ?> <?= esc($c['level']) ?><?= $c['subclass'] ? ' · ' . esc($c['subclass']) : '' ?>
        </span>
        <span class="field-label">Class &amp; Level</span>
      </div>
      <div class="field">
        <span class="field-value"><?= esc($c['background'] ?: '') ?></span>
        <span class="field-label">Background</span>
      </div>
      <div class="field">
        <!-- The account that owns the character, not whoever pressed Print —
             CharacterSheet resolves it, and says there why. $user is still what
             the page guard handed back, and is the fallback for the character
             nobody owns. -->
        <span class="field-value"><?= esc($sheet['owner'] ?: $user['username']) ?></span>
        <span class="field-label">Player Name</span>
      </div>
      <div class="field">
        <span class="field-value">
          <?= esc($c['race']) ?><?= $c['subrace'] ? ' · ' . esc($c['subrace']) : '' ?>
        </span>
        <span class="field-label">Race</span>
      </div>
      <div class="field">
        <span class="field-value"><?= esc($c['alignment'] ?: '') ?></span>
        <span class="field-label">Alignment</span>
      </div>
      <div class="field">
        <span class="field-value mono-num">
          <?= esc(number_format((int) ($c['experience'] ?? 0))) ?><?php
            // The ladder stops at level 6, and xp_progress reports no next
            // level there. Printing "14,000 / " with nothing after it would
            // read as a truncation rather than as the top of the table.
            if ($xp && $xp['next_level'] !== null):
          ?> / <?= esc(number_format((int) Rules::XP_THRESHOLDS[$xp['next_level']])) ?><?php endif; ?>
        </span>
        <span class="field-label">Experience Points</span>
      </div>
    </div>
  </div>

  <div class="sheet-cols">

    <!-- ---------------------------------------------------------------- -->
    <!-- Left: the ability spine, saves, skills                            -->
    <!-- ---------------------------------------------------------------- -->
    <div class="col">
      <div class="ability-row">
        <div class="ability-col">
          <?php foreach ($sheet['abilities'] as $ab): ?>
          <div class="ability-box">
            <span class="abbr"><?= esc($ab['abbr']) ?></span>
            <span class="score"><?= esc($ab['score']) ?></span>
            <span class="mod"><?= esc(fmt_mod($ab['mod'])) ?></span>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="col">
          <!-- Inspiration is a token the DM hands out at the table. The game
               has no such thing, so it prints as an empty box. -->
          <div class="mini-row">
            <span class="mini-blank"></span>
            <span class="mini-label">Inspiration</span>
          </div>
          <div class="mini-row">
            <span class="mini-value"><?= esc(fmt_mod($sheet['proficiency_bonus'])) ?></span>
            <span class="mini-label">Proficiency Bonus</span>
          </div>

          <div class="panel">
            <div class="panel-head">Saving Throws</div>
            <div class="panel-body">
              <ul class="stat-list">
                <?php foreach ($sheet['saves'] as $save): ?>
                <li class="<?= $save['proficient'] ? 'is-prof' : '' ?>">
                  <span class="bub <?= $save['proficient'] ? 'on' : '' ?>"></span>
                  <span class="val"><?= esc(fmt_mod($save['mod'])) ?></span>
                  <span class="nm"><?= esc($save['label']) ?></span>
                  <span></span>
                </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">Skills</div>
            <div class="panel-body">
              <ul class="stat-list">
                <?php foreach ($sheet['skills'] as $skill): ?>
                <li class="<?= $skill['proficient'] ? 'is-prof' : '' ?><?= $skill['expertise'] ? ' is-exp' : '' ?>">
                  <span class="bub <?= $skill['proficient'] ? 'on' : '' ?><?= $skill['expertise'] ? ' dbl' : '' ?>"></span>
                  <span class="val"><?= esc(fmt_mod($skill['mod'])) ?></span>
                  <span class="nm"><?= esc($skill['label']) ?></span>
                  <span class="abil"><?= esc($skill['ability']) ?></span>
                </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="mini-row passive-row">
        <span class="mini-value"><?= esc($sheet['passive_perception']) ?></span>
        <span class="mini-label">Passive Wisdom (Perception)</span>
      </div>

      <div class="panel">
        <div class="panel-head">Other Proficiencies &amp; Languages</div>
        <div class="panel-body">
          <ul class="prof-list">
            <?php foreach ($sheet['proficiencies'] as $prof): ?>
            <li><strong><?= esc($prof['label']) ?>:</strong> <?= esc($prof['value']) ?></li>
            <?php endforeach; ?>
          </ul>
          <!-- `races` carries no language column, so languages are a blank the
               player fills in rather than a guess printed as fact. -->
          <?= rules_lines(2) ?>
        </div>
      </div>
    </div>

    <!-- ---------------------------------------------------------------- -->
    <!-- Middle: defences, attacks, equipment                              -->
    <!-- ---------------------------------------------------------------- -->
    <div class="col">
      <div class="defence-row">
        <div class="box">
          <span class="big"><?= esc($c['armor_class']) ?></span>
          <span class="box-label">Armor Class</span>
        </div>
        <div class="box">
          <span class="big"><?= esc(fmt_mod($sheet['initiative'])) ?></span>
          <span class="box-label">Initiative</span>
        </div>
        <div class="box">
          <span class="big"><?= esc($c['speed']) ?></span>
          <span class="box-label">Speed (ft)</span>
        </div>
      </div>

      <div class="box hp-box">
        <span class="hp-max">Hit Point Maximum <?= esc($c['max_hp']) ?></span>
        <span class="hp-current"><?= esc($c['current_hp']) ?></span>
        <span class="box-label">Current Hit Points</span>
      </div>

      <div class="box hp-temp">
        <span class="blank-line"></span>
        <span class="box-label">Temporary Hit Points</span>
      </div>

      <div class="dice-saves">
        <div class="box hitdice-box">
          <span class="total">Total</span>
          <span class="dice"><?= esc($sheet['hit_dice']) ?></span>
          <span class="box-label">Hit Dice</span>
        </div>
        <div class="box death-saves">
          <div class="ds-row">
            <span class="ds-label">Successes</span>
            <span class="ds-bubbles"><?= bubbles(3) ?></span>
          </div>
          <div class="ds-row">
            <span class="ds-label">Failures</span>
            <span class="ds-bubbles"><?= bubbles(3) ?></span>
          </div>
          <span class="box-label">Death Saves</span>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">Attacks &amp; Spellcasting</div>
        <div class="panel-body">
          <table class="atk-table">
            <thead>
              <tr>
                <th>Name</th>
                <th class="n">Atk</th>
                <th>Damage / Type</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sheet['attacks'] as $atk): ?>
              <tr>
                <td>
                  <span class="wname"><?= esc($atk['name']) ?></span>
                  <?php if ($atk['equipped']): ?><span class="eq">· in hand</span><?php endif; ?>
                  <?php if ($atk['notes'] !== ''): ?>
                  <span class="props"><?= esc($atk['notes']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="n bonus"><?= esc(fmt_mod($atk['bonus'])) ?></td>
                <td><?= esc($atk['damage']) ?> <span class="props"><?= esc($atk['damage_type']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if ($spellcasting !== null): ?>
          <p class="atk-note">
            Spell save DC <?= esc($spellcasting['save_dc']) ?> ·
            spell attack <?= esc(fmt_mod($spellcasting['attack_bonus'])) ?> · spells on the next page.
          </p>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">Equipment</div>
        <div class="panel-body">
          <!--
            Only gold is filled in. The game keeps one purse and calls it gold,
            so the other four denominations are blanks a table playing with
            mixed coin can use.
          -->
          <div class="coin-row">
            <div class="coin"><span class="amt"></span><span class="cn">cp</span></div>
            <div class="coin"><span class="amt"></span><span class="cn">sp</span></div>
            <div class="coin"><span class="amt"></span><span class="cn">ep</span></div>
            <div class="coin"><span class="amt mono-num"><?= esc((int) ($c['gold'] ?? 0)) ?></span><span class="cn">gp</span></div>
            <div class="coin"><span class="amt"></span><span class="cn">pp</span></div>
          </div>

          <?php if ($sheet['inventory']): ?>
          <ul class="gear-list">
            <?php foreach ($sheet['inventory'] as $item): ?>
            <li>
              <span class="<?= (int) $item['is_equipped'] === 1 ? 'worn' : '' ?>">
                <?= esc($item['name']) ?><?php
                  if ((int) $item['is_equipped'] === 1): ?> <span class="qty">(worn)</span><?php endif; ?>
              </span>
              <span class="qty">×<?= esc((int) $item['quantity']) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
          <div class="gear-foot">Carried <?= esc($sheet['carried_weight']) ?> lb</div>
          <?php else: ?>
          <?= rules_lines(6) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ---------------------------------------------------------------- -->
    <!-- Right: the written-in boxes, then features                        -->
    <!-- ---------------------------------------------------------------- -->
    <div class="col">
      <!--
        Four empty boxes, deliberately. `characters` has no column for any of
        these, and a generated ideal is a personality assigned to somebody
        else's character. Blank lines invite the player to write; invented
        prose tells them what they already think.
      -->
      <div class="panel foot-label">
        <div class="panel-body"><?= rules_lines(4) ?></div>
        <div class="panel-head">Personality Traits</div>
      </div>
      <div class="panel foot-label">
        <div class="panel-body"><?= rules_lines(3) ?></div>
        <div class="panel-head">Ideals</div>
      </div>
      <div class="panel foot-label">
        <div class="panel-body"><?= rules_lines(3) ?></div>
        <div class="panel-head">Bonds</div>
      </div>
      <div class="panel foot-label">
        <div class="panel-body"><?= rules_lines(3) ?></div>
        <div class="panel-head">Flaws</div>
      </div>

      <div class="panel">
        <div class="panel-head">Features &amp; Traits</div>
        <div class="panel-body">
          <?php if ($sheet['features']): ?>
          <ul class="trait-list">
            <?php foreach ($sheet['features'] as $feature): ?>
            <li>
              <span class="fname"><?= esc($feature['name']) ?></span>
              <span class="fsrc"><?= esc($feature['source']) ?></span>
              <?php if ($feature['detail'] !== ''): ?>
              <span class="fdetail"><?= esc($feature['detail']) ?></span>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <?= rules_lines(6) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="sheet-foot">
    <span>Rivermark Chronicles · <?= esc($c['name']) ?> · printed <?= esc(date('j M Y')) ?></span>
    <span>5e SRD content under OGL 1.0a / CC-BY 4.0</span>
  </div>
</div>

<?php if ($spellcasting !== null): ?>
<?php
/*
 * Page two, for casters only.
 *
 * The level blocks are dealt into two columns by weight rather than in
 * alternation: a block costs its heading plus one row per spell, and a Cleric
 * whose 1st-level list is six spells long while their cantrips are two would
 * otherwise leave one column twice the height of the other. Cheap, and it
 * keeps the fold in the middle of the page where a reader expects it.
 */
$blocks = [];
$levels = [0];
foreach ($spellcasting['slots'] as $slot) {
    $levels[] = (int) $slot['level'];
}
foreach (array_keys($spellcasting['by_level']) as $known) {
    $levels[] = (int) $known;
}
$levels = array_values(array_unique($levels));
sort($levels);

foreach ($levels as $level) {
    $slot = null;
    foreach ($spellcasting['slots'] as $candidate) {
        if ((int) $candidate['level'] === $level) {
            $slot = $candidate;
        }
    }
    $spells = $spellcasting['by_level'][$level] ?? [];
    $blocks[] = [
        'level'  => $level,
        'slots'  => $slot === null ? 0 : (int) $slot['have'],
        'spells' => $spells,
        'weight' => 3 + count($spells) * 2,
    ];
}

$columns = [[], []];
$weights = [0, 0];
foreach ($blocks as $block) {
    $into = $weights[0] <= $weights[1] ? 0 : 1;
    $columns[$into][] = $block;
    $weights[$into] += $block['weight'];
}
?>
<div class="sheet-page">
  <div class="spell-top">
    <div class="name-block">
      <?php if ($portraitUrl !== null): ?>
      <img class="portrait" src="<?= esc($portraitUrl) ?>" alt="">
      <?php endif; ?>
      <div class="who">
        <h1 class="char-name"><?= esc($c['name']) ?></h1>
        <span class="char-name-label"><?= esc($c['class']) ?> <?= esc($c['level']) ?> · Spellcasting</span>
      </div>
    </div>
    <div class="spell-head-fields">
      <div class="box">
        <span class="big"><?= esc($spellcasting['ability']) ?></span>
        <span class="box-label">Spellcasting Ability</span>
      </div>
      <div class="box">
        <span class="big"><?= esc($spellcasting['save_dc']) ?></span>
        <span class="box-label">Spell Save DC</span>
      </div>
      <div class="box">
        <span class="big"><?= esc(fmt_mod($spellcasting['attack_bonus'])) ?></span>
        <span class="box-label">Spell Attack Bonus</span>
      </div>
    </div>
  </div>

  <div class="spell-cols">
    <?php foreach ($columns as $column): ?>
    <div>
      <?php foreach ($column as $block): ?>
      <div class="spell-level">
        <div class="spell-level-head">
          <span class="lvl"><?= $block['level'] === 0 ? '&mdash;' : esc($block['level']) ?></span>
          <span class="lvl-label"><?= $block['level'] === 0 ? 'Cantrips' : 'Level ' . esc($block['level']) . ' spells' ?></span>
          <?php if ($block['slots'] > 0): ?>
          <!--
            Empty circles, always. The game knows how many slots are spent
            right now, but a printed sheet is used away from the game and the
            player tracks expenditure in pencil. Printing tonight's two spent
            slots onto paper somebody carries for a month would be printing a
            moment as if it were a fact — the same reason the death saves above
            are blank.
          -->
          <span class="slot-dots">
            <span class="slots-label"><?= esc($block['slots']) ?> slots</span>
            <?= bubbles($block['slots']) ?>
          </span>
          <?php else: ?>
          <span class="slot-dots"><span class="slots-label">at will</span></span>
          <?php endif; ?>
        </div>

        <?php if ($block['spells']): ?>
        <ul class="spell-list">
          <?php foreach ($block['spells'] as $spell): ?>
          <li>
            <span class="prep"></span>
            <span>
              <span class="sname"><?= esc($spell['name']) ?></span>
              <span class="smeta">
                <?= esc(implode(' · ', array_filter([
                    (string) ($spell['school'] ?? ''),
                    (string) ($spell['casting_time'] ?? ''),
                    (string) ($spell['range_text'] ?? ''),
                    (string) ($spell['components'] ?? ''),
                    (string) ($spell['duration'] ?? ''),
                ], 'strlen'))) ?>
              </span>
              <?php
                // What the spell asks of its target, in the words a player at a
                // table needs: a die and a type, or a saving throw and what a
                // success buys. Assembled from the same columns the combat
                // engine resolves the spell from, so the paper and the game
                // cannot disagree about which save it is.
                $effect = [];
                if (!empty($spell['damage_dice'])) {
                    $effect[] = $spell['damage_dice'] . ' ' . (string) ($spell['damage_type'] ?? '');
                }
                if (($spell['resolution'] ?? '') === 'save' && !empty($spell['save_ability'])) {
                    $effect[] = strtoupper((string) $spell['save_ability']) . ' save'
                        . (($spell['save_effect'] ?? '') === 'half' ? ', half on a success' : ', negates');
                } elseif (($spell['resolution'] ?? '') === 'attack') {
                    $effect[] = 'spell attack';
                }
                if ((int) ($spell['concentration'] ?? 0) === 1) {
                    $effect[] = 'concentration';
                }
              ?>
              <?php if ($effect): ?>
              <span class="smeta"><strong><?= esc(implode(' · ', $effect)) ?></strong></span>
              <?php endif; ?>
              <?php if (!empty($spell['description'])): ?>
              <span class="smeta"><?= esc($spell['description']) ?></span>
              <?php endif; ?>
            </span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="spell-empty">None known yet.</p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="sheet-foot">
    <span>Rivermark Chronicles · <?= esc($c['name']) ?> · spells · printed <?= esc(date('j M Y')) ?></span>
    <span>5e SRD content under OGL 1.0a / CC-BY 4.0</span>
  </div>
</div>
<?php endif; ?>

<script>
  document.getElementById('btn-print').addEventListener('click', function () {
    window.print();
  });

<?php if ($autoPrint): ?>
  /*
   * Wait for `load`, not DOMContentLoaded. The portrait is the only image on
   * the page and it is small, but a print dialogue opened before it decodes
   * captures the layout without it and leaves a hole where the face should be.
   */
  window.addEventListener('load', function () {
    window.print();
  });
<?php endif; ?>
</script>
</body>
</html>
