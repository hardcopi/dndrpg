<?php
/**
 * The four public pages, and the one rule that keeps them honest.
 *
 * index.php (signed out), tour.php, classes.php and races.php are the only
 * things a visitor without an account can read, and they are the only pages in
 * this project whose job is to make a claim. That is a dangerous job here: a
 * marketing page is written once and then goes on saying whatever it said while
 * the engine moves underneath it, and the reader has no way to check.
 *
 * So NOTHING ON THESE PAGES IS TYPED TWICE. The catalogue comes from `classes`
 * and `races` — the same rows the creator is built from, so a class added to
 * the table appears here with no edit. The feature lists are read out of
 * `Rules::CLASS_FEATURES`, `Rules::SUBCLASS_FEATURES`,
 * `CombatEngine::BONUS_ACTIONS` and their neighbours, which are the tables the
 * fight itself consults. `Rules::MAX_LEVEL` is asked rather than remembered.
 *
 * The one thing written by hand is the SENTENCE describing a feature, in
 * FEATURE_COPY below, and even that degrades rather than lying: a key with no
 * sentence prints its own name, prettified. A feature added to the engine can
 * therefore be under-described here but never mis-described, and never missing.
 *
 * This is the same discipline `Rules::RACE_FEATURES` documents for the
 * character sheet — "only what is IMPLEMENTED goes in" — applied one level out,
 * to the page that sells it.
 */

require_once __DIR__ . '/../bootstrap.php';

/**
 * A sentence for each feature key the engine can grant.
 *
 * Keyed exactly as the engine keys them. Written in the second person because
 * the reader is being invited to be the one it happens to.
 */
const FEATURE_COPY = [
    // Rules::CLASS_FEATURES
    'reckless_attack'     => 'Throw your weight into it — advantage on your Strength attacks, and everyone gets advantage back on you.',
    'danger_sense'        => 'Advantage on Dexterity saves against anything you can see coming.',
    'fast_movement'       => 'Ten more feet of speed, so long as you are not in heavy armour.',
    'action_surge'        => 'Take a second full action, once between breathers.',
    'channel_divinity'    => 'Turn the undead, or Preserve Life — five hit points a level, shared out within thirty feet.',
    'martial_arts'        => 'Your fists scale with your level, and let you strike again as a bonus action.',
    'lay_on_hands'        => 'A pool of healing five points a level deep, spent a point at a time.',
    'divine_sense'        => 'Fiends and undead within sixty feet cannot hide what they are.',
    'divine_smite'        => 'Burn a spell slot on a hit for 2d8 and more, with an extra die against the unclean.',
    'uncanny_dodge'       => 'Halve the damage of one attack you saw coming.',
    'arcane_recovery'     => 'Take slots back out of a short rest, once a day.',

    // Rules::SUBCLASS_FEATURES
    'improved_critical'   => 'You crit on a 19 as well as a 20.',
    'disciple_of_life'    => 'Every healing spell you cast carries two extra points, and one more per slot level.',
    'draconic_resilience' => 'A hit point per level on top, and 13 + Dexterity when you wear no armour.',
    'dark_ones_blessing'  => 'Drop a foe and the temporary hit points arrive on their own.',
    'fast_hands'          => 'Use an item as a bonus action.',
    'colossus_slayer'     => 'An extra 1d8, once a turn, against anything already wounded.',
    'sculpt_spells'       => 'Your allies standing inside your own fireball are simply not in it.',

    // CombatEngine::BONUS_ACTIONS
    'second_wind'         => 'Regain 1d10 + your level in hit points, as a bonus action.',
    'rage'                => 'Shrug off physical damage and hit harder while it lasts.',
    'cunning_action'      => 'Dash or Disengage as a bonus action, every turn.',

    // Derived elsewhere in the engine
    'extra_attack'        => 'Two swings instead of one, every time you take the Attack action.',
    'spellcasting'        => 'Prepared or known spells, with slots that come back when you sleep.',
    'expertise'           => 'Double your proficiency bonus on the skills you are best at.',
    'sneak_attack'        => 'Extra dice when you have advantage, or when a friend is already in their face.',

    // Rules::RACE_FEATURES
    'draconic_resistance' => 'Resistance to the damage type your ancestry answers to.',
    'dwarven_resilience'  => 'Advantage on saves against poison, and you take half of it.',
    'stonecunning'        => 'Double proficiency on History checks about stonework.',
    'keen_senses'         => 'Perception, proficient, whoever you decided to be.',
    'fey_ancestry'        => 'Advantage against being charmed, and nothing puts you to sleep.',
    'gnome_cunning'       => 'Advantage on Intelligence, Wisdom and Charisma saves against magic.',
    'relentless_endurance' => 'The blow that would drop you leaves you at one hit point instead, once a day.',
    'savage_attacks'      => 'An extra damage die on a critical hit with a melee weapon.',
    'halfling_luck'       => 'Reroll a natural 1 on an attack, a check or a save.',
    'brave'               => 'Advantage on saves against fear.',
    'stout_resilience'    => 'Advantage on saves against poison, and you take half of it.',
    'quarry_built'        => 'Athletics, proficient, whether or not you wrote it down.',
    'set_fast'            => 'Advantage on the contest to resist a grapple or a shove.',
    'hellish_resistance'  => 'Half damage from fire.',
    // Enforced in CharacterGenerator rather than through RACE_FEATURES;
    // CharacterSheet::TRAITS_ENFORCED_ELSEWHERE is what knows that.
    'dwarven_toughness'   => 'A hit point per level on top of your die.',
];

/**
 * A feature key as a name: `divine_smite` becomes "Divine Smite".
 *
 * The small words stay small — `disciple_of_life` is "Disciple of Life", not
 * "Disciple Of Life", which is what a bare ucwords() produces and what shipped
 * on the first cut of the class page. Never the first word, which is a title's
 * first word whatever it is.
 */
function feature_name(string $key): string
{
    $small = ['of', 'the', 'a', 'an', 'and', 'or', 'on', 'in', 'to'];
    $words = explode(' ', str_replace('_', ' ', $key));
    foreach ($words as $i => $w) {
        $words[$i] = ($i > 0 && in_array($w, $small, true)) ? $w : ucfirst($w);
    }
    return implode(' ', $words);
}

/** Its sentence, or nothing — the caller decides what a missing one looks like. */
function feature_blurb(string $key): string
{
    return FEATURE_COPY[$key] ?? '';
}

/**
 * Everything the engine actually grants a member of this class, in level order.
 *
 * Read from the engine's own tables, never from a list kept here. Each row is
 * `[level, key, name, blurb]`; `level` is the level it arrives at, so a reader
 * can see what the first six levels of a class are shaped like.
 *
 * @return list<array{0:int,1:string,2:string,3:string}>
 */
function class_features_implemented(string $class, ?string $subclass, ?int $subclassLevel): array
{
    $out = [];

    foreach (Rules::CLASS_FEATURES[$class] ?? [] as $key => $at) {
        $out[] = [(int) $at, $key];
    }

    // A bonus action is a class feature that happens to live in the combat
    // engine because that is the only place it can be spent. First level in
    // every case — BONUS_ACTIONS carries no level because there is nothing to
    // carry: you have it as soon as you have the class.
    $bonus = CombatEngine::BONUS_ACTIONS[$class] ?? null;
    if ($bonus !== null) {
        $out[] = [1, (string) $bonus['key']];
    }

    if (isset(Rules::SPELLCASTING_ABILITY[$class])) {
        $out[] = [1, 'spellcasting'];
    }
    if (Rules::expertiseCount($class, Rules::MAX_LEVEL) > 0) {
        $out[] = [1, 'expertise'];
    }
    if (CombatEngine::sneakAttackDice($class, 1) > 0) {
        $out[] = [1, 'sneak_attack'];
    }
    if (in_array($class, Rules::EXTRA_ATTACK_CLASSES, true)) {
        $out[] = [Rules::EXTRA_ATTACK_AT, 'extra_attack'];
    }

    // The subclass arrives on its own schedule and its features on theirs; a
    // feature is offered at whichever of the two comes later, because a
    // Champion's expanded crit cannot arrive before there is a Champion.
    $subclass = trim((string) $subclass);
    if ($subclass !== '') {
        foreach (Rules::SUBCLASS_FEATURES[$subclass] ?? [] as $key => $at) {
            $out[] = [max((int) $at, (int) $subclassLevel), $key];
        }
    }

    // Anything past the level cap would be a promise nobody can collect on.
    $out = array_values(array_filter($out, static fn ($r) => $r[0] <= Rules::MAX_LEVEL));

    usort($out, static fn ($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));

    return array_map(
        static fn ($r) => [$r[0], $r[1], feature_name($r[1]), feature_blurb($r[1])],
        $out
    );
}

/**
 * The traits a race's row PRINTS, split from the traits the engine RUNS.
 *
 * `races.traits` is prose and stays prose. `Rules::RACE_FEATURES` is the
 * implemented set, and it is keyed by the printed name lowercased with spaces
 * and hyphens turned into underscores — the same rule
 * `CharacterSheet::traitImplemented()` uses, and the same reason "Quarry-Built"
 * and `quarry_built` agreeing is a requirement rather than a coincidence.
 *
 * @return list<array{name:string,key:string,run:bool,blurb:string}>
 */
function race_traits(string $race, ?string $subrace, string $traits): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', $traits))) as $name) {
        // The Half-Elf row carries a parenthetical explaining how the SRD's
        // "+1 to two of your choice" is applied here. It is a note to a reader
        // of the table, not part of the trait's name.
        $clean = trim(preg_replace('/\s*\(.*$/', '', $name));
        // CharacterSheet answers this, rather than this file deriving the key
        // itself: two of the printed names do not turn into their engine key by
        // rule, and one trait is enforced somewhere RACE_FEATURES cannot be
        // asked about at all.
        $out[] = [
            'name'  => $clean,
            'key'   => CharacterSheet::traitFeatureKey($clean),
            'run'   => CharacterSheet::traitEnforced($race, $subrace, $clean),
            'blurb' => feature_blurb(CharacterSheet::traitFeatureKey($clean)),
        ];
    }
    return $out;
}

/** The ability bonuses on a race row, as ["STR +2", "CON +1"]. */
function race_bonuses(array $row): array
{
    $out = [];
    foreach (['str', 'dex', 'con', 'int', 'wis', 'cha'] as $a) {
        $n = (int) ($row[$a . '_bonus'] ?? 0);
        if ($n !== 0) {
            $out[] = strtoupper($a) . ' ' . ($n > 0 ? '+' : '') . $n;
        }
    }
    return $out;
}

/** Every class, in the creator's own order. */
function public_classes(): array
{
    return db()->query(
        'SELECT name, hit_die, primary_ability, saving_throws, armor_proficiencies,
                weapon_proficiencies, subclass_name, subclass_level, features, source
           FROM classes ORDER BY name'
    )->fetchAll();
}

/**
 * Every race row a visitor could actually become — one per race AND SUBRACE,
 * which is what the creator offers.
 *
 * `RACES_WITHHELD` is subtracted here, and that is the whole reason this page
 * cannot simply `SELECT * FROM races`. The rows stay in the table for the
 * characters who already are one; what the table does not know is that the
 * creator will not offer them. Listing a race a visitor cannot choose is
 * advertising something nobody can have, which is the one failure these pages
 * exist to be incapable of.
 */
function public_races(): array
{
    $rows = db()->query(
        'SELECT name, subrace, speed, traits, description, source,
                str_bonus, dex_bonus, con_bonus, int_bonus, wis_bonus, cha_bonus
           FROM races ORDER BY name, subrace'
    )->fetchAll();

    return array_values(array_filter(
        $rows,
        static fn ($r) => !in_array((string) $r['name'], RACES_WITHHELD, true)
    ));
}

/** How many distinct races are on offer, as opposed to how many rows there are. */
function public_race_count(): int
{
    $names = [];
    foreach (public_races() as $r) {
        $names[(string) $r['name']] = true;
    }
    return count($names);
}

/** Shorthand, because these pages are mostly text. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * The top of a public page.
 *
 * style.css first and landing.css second: the second is written to layer on the
 * first and would lose to it at equal specificity if the order were reversed.
 */
function public_head(string $title, string $description): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> — Rivermark Chronicles</title>
  <meta name="description" content="<?= e($description) ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/landing.css') ?>">
</head>
<body class="lp">
  <?php require APP_PATH . '/inc/site_bar.php'; ?>
<?php
}

/**
 * The pair of buttons that ends every public page.
 *
 * Asks whether registration is actually open rather than assuming it. An admin
 * can close it from /admin.php and `RPG_REGISTRATION=0` wins over that, so
 * "Create an account" is a button that can be switched off underneath this
 * page — and a gold button leading to a form that refuses is worse than no
 * button, because gold is this interface's promise that a thing can be done.
 */
function public_cta(): void
{
    $open = auth()->registrationOpen();
    ?>
      <div class="lp-cta">
        <?php if ($open) { ?>
          <a class="lp-btn lp-btn-go" href="login.php?mode=register">Create an account</a>
          <a class="lp-btn" href="login.php">Sign in</a>
        <?php } else { ?>
          <a class="lp-btn lp-btn-go" href="login.php">Sign in</a>
        <?php } ?>
      </div>
      <?php if (!$open) { ?>
        <p class="lp-note">New accounts are closed at the moment.</p>
      <?php } ?>
<?php
}

/**
 * The bottom of one: the last invitation, then the licence.
 *
 * The attribution is not decoration. SRD 5.1 and 5.2 are used under CC-BY 4.0
 * and the obligation follows the content onto whatever page shows it, which
 * these pages do — they print class names, race names and feature names
 * straight out of the tables.
 */
function public_foot(bool $withCta = true): void
{
    if ($withCta) {
        ?>
  <section class="lp-close">
    <div class="lp-wrap">
      <p class="lp-eyebrow">Free, and in your browser</p>
      <h2>Make somebody and go down the stair</h2>
      <p class="lp-lead" style="margin:0 auto">
        No download, no client, no card. An account, a character, and the Proving Yard.
      </p>
      <?php public_cta(); ?>
    </div>
  </section>
<?php
    }
    ?>
  <footer class="lp-foot">
    <div class="lp-wrap">
      <p>
        Rivermark Chronicles is a single-player role-playing game built on the
        <strong>5e System Reference Document</strong>. It is not affiliated with,
        endorsed by, or sponsored by any holder of trademarks in fantasy tabletop
        games.
      </p>
      <p>
        Rules content from SRD 5.1 and SRD 5.2, used under
        <a href="about.php">CC-BY 4.0 — full attribution and licence</a>.
        Setting, prose and art are original to this game.
      </p>
    </div>
  </footer>
</body>
</html>
<?php
}
