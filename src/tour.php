<?php
/**
 * The longer tour: the same claims as the landing page, with room to say how.
 *
 * The landing page has to be readable by somebody deciding in fifteen seconds
 * whether to keep scrolling. This one is for the reader who has decided to keep
 * scrolling and wants to know what the thing is actually made of — so it is
 * allowed to name the rule, the constant and the reason, which is the register
 * the rest of this project is written in anyway.
 *
 * Same constraint as the landing page: only what a new account can reach today.
 * No authored campaigns are named here.
 */

require_once __DIR__ . '/app/inc/public_page.php';

$nMonsters = (int) db()->query('SELECT COUNT(*) FROM monsters')->fetchColumn();
$nClasses  = (int) db()->query('SELECT COUNT(*) FROM classes')->fetchColumn();
// Counted, not written down. "ten peoples, fifteen rows" was true when it was
// typed and false an hour later, when two races were withheld.
$raceRows  = count(public_races());
$nRaces    = public_race_count();
$nSpells   = (int) db()->query('SELECT COUNT(*) FROM spells')->fetchColumn();
$nItems    = (int) db()->query('SELECT COUNT(*) FROM items')->fetchColumn();

public_head(
    'The game',
    'How Rivermark Chronicles works: the five-foot grid, the fighting pit, the '
    . 'generated delve, and what the engine actually implements.'
);
?>

<header class="lp-hero">
  <div class="lp-wrap">
    <p class="lp-eyebrow">The longer version</p>
    <h1>What you are actually playing</h1>
    <p class="lp-lead">
      A single-player role-playing game that runs in a browser tab, on the open 5e rules,
      with a tactical fight, a dungeon that is different every time, and a character sheet
      whose numbers are the ones the engine uses.
    </p>
  </div>
</header>

<section class="lp-band">
  <div class="lp-wrap">
    <p class="lp-eyebrow">One</p>
    <h2>Make somebody</h2>
    <p class="lp-lead">
      Five steps: who they are, what they do, their abilities, a gift, and a look at the
      whole thing before you commit.
    </p>
    <ul class="lp-points">
      <li><strong>Roll or arrange.</strong> Abilities are rolled and assigned; the step
          tells you which ones your class actually leans on, so a wizard is not quietly
          advised to raise Strength.</li>
      <li><strong>A race and a subrace</strong> —
          <a href="races.php"><?= $nRaces ?> peoples</a>, <?= $raceRows ?> rows — each with
          its bonuses applied and its traits printed, marked for whether the engine
          enforces them.</li>
      <li><strong>A class and a path</strong> —
          <a href="classes.php"><?= $nClasses ?> of them</a>, each with a subclass that
          arrives on its own schedule and brings features with it.</li>
      <li><strong>A face.</strong> A painted portrait for the party rail and the fight
          cards, and a 3D creator built into the first step if you want to choose the
          beard, the build and the coat.</li>
    </ul>
    <figure class="lp-figure">
      <img class="lp-shot lp-shot-wide" src="<?= asset('assets/images/site/shot-calling.jpg') ?>"
           width="1250" height="575" loading="lazy"
           alt="The Calling step of character creation: a class dropdown showing Barbarian,
                with hit die, primary ability, saving throws, features, starting gear and
                subclass listed underneath, and a background and alignment picker.">
      <figcaption>The class step says what the choice costs and grants before you make it.</figcaption>
    </figure>
  </div>
</section>

<section class="lp-band lp-band-inset">
  <div class="lp-wrap">
    <p class="lp-eyebrow">Two</p>
    <h2>Fight on a grid</h2>
    <p class="lp-lead">
      Sixteen by twelve cells of five feet each, generated for the encounter, with terrain
      on it. This is where most of the engine is.
    </p>

    <div class="lp-feature" style="margin-top:var(--sp-32)">
      <div class="lp-feature-text">
        <ul class="lp-points">
          <li><strong>Distance is a real number.</strong> Movement is spent in feet, reach
              and weapon range are distances, and a thrown weapon has a long range that
              costs you something to use.</li>
          <li><strong>Obstacles give cover</strong>, and cover is worked out per shot
              rather than per square.</li>
          <li><strong>Leaving somebody's reach provokes.</strong> Threatened squares are
              hatched on the board before you move, not explained after.</li>
          <li><strong>Advantage, flanking, Help, Dodge, Ready, Hide, Grapple and Shove</strong>
              are all on the action list, and the ones you cannot take say why.</li>
          <li><strong>The screen does no geometry.</strong> Reachable squares and their
              costs, threatened squares, and the quality of each shot are worked out on
              the server and shipped to the board — so the picture cannot drift from the
              rules.</li>
        </ul>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-combat.jpg') ?>"
             width="1200" height="568" loading="lazy"
             alt="The combat screen with a gremlin selected: its portrait, hit points,
                  armour class and challenge rating on the right, and the action list
                  showing greyed-out actions each with the reason it is unavailable.">
        <figcaption>Select a target and the action list explains itself.</figcaption>
      </figure>
    </div>

    <div class="lp-figures" style="margin-top:var(--sp-40)">
      <div class="lp-fig"><b><?= $nMonsters ?></b><span>Creatures</span></div>
      <div class="lp-fig"><b><?= $nSpells ?></b><span>Spells</span></div>
      <div class="lp-fig"><b><?= $nItems ?></b><span>Items</span></div>
      <div class="lp-fig"><b>1&ndash;<?= Rules::MAX_LEVEL ?></b><span>Levels</span></div>
    </div>
    <p class="lp-note" style="margin-top:var(--sp-12);max-width:70ch">
      The ladder runs the whole way: proficiency to +6, spell slots to 9th level, and an
      ability increase or a feat at 4th, 8th, 12th, 16th and 19th. Named features keep
      arriving past 6th for some classes and not for others — a rogue's Evasion at 7th,
      a barbarian's Brutal Critical at 9th, a paladin's Improved Divine Smite at 11th,
      a monk's Diamond Soul at 14th — and the <a href="classes.php">class pages</a> are
      the honest list: every feature the engine actually runs, with the level it arrives
      at, and separately the ones that are only printed on the sheet.
    </p>
  </div>
</section>

<section class="lp-band">
  <div class="lp-wrap">
    <p class="lp-eyebrow">Three</p>
    <h2>The Proving Yard</h2>
    <p class="lp-lead">
      Where every character starts, and where you can always get a fight. Three
      difficulties, and you choose who walks out.
    </p>
    <div class="lp-grid lp-grid-3">
      <?php foreach (PitEngine::TIERS as $tier) { ?>
        <article class="lp-card">
          <h3><?= e((string) $tier['label']) ?></h3>
          <p><?= e((string) $tier['blurb']) ?></p>
        </article>
      <?php } ?>
    </div>
    <ul class="lp-points" style="margin-top:var(--sp-20)">
      <li><strong>The bout is sized against who is coming</strong>, not against the party
          on the books — so going in alone is a different fight rather than four times
          the fight.</li>
      <li><strong>A party with nobody standing is refused</strong> rather than dealt a
          fight it would lose on the first monster turn.</li>
      <li><strong>Camp is beside it.</strong> A long rest heals the party, gives the hit
          dice back, and costs nothing.</li>
    </ul>
  </div>
</section>

<section class="lp-band lp-band-inset">
  <div class="lp-wrap">
    <p class="lp-eyebrow">Four</p>
    <h2>The delve</h2>
    <p class="lp-lead">
      A stair goes down out of the yard. What is under it is generated when you walk into
      it, in the manner of the old random-dungeon tables — and dropped again behind you,
      so the way back is not the way you came.
    </p>

    <div class="lp-feature" style="margin-top:var(--sp-32)">
      <div class="lp-feature-text">
        <ul class="lp-points">
          <li><strong>Rooms from a size table</strong> on a plan, joined by a spanning
              tree and a few loops, stocked from a contents table and described from
              depth-banded fragments — so a room on level four does not sound like a room
              on level one.</li>
          <li><strong>Traps and secret doors.</strong> Search for them; a door you have
              not found is drawn as a blank wall rather than as a wall with a hint on it.</li>
          <li><strong>Doors you can force, pick, listen at or brace shut.</strong>
              Barricading every way out of a room is one of the ways to earn a long rest
              down there.</li>
          <li><strong>Wandering monsters</strong> stocked per floor and rolled as you
              travel.</li>
          <li><strong>An errand per floor</strong>, written when the floor is — a name, a
              hook, rooms to visit, and a twist. It pays no experience and no gold on
              purpose: the pay for a floor is the monsters standing on it.</li>
        </ul>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-delve-plan.jpg') ?>"
             width="1082" height="570" loading="lazy"
             alt="A generated floor on graph paper with one room lit, the party token
                  inside it, doors marked on the threshold, and a task list on the right
                  naming the floor's errand.">
        <figcaption>The floor plan fills in as you find it.</figcaption>
      </figure>
    </div>

    <div class="lp-feature lp-flip" style="margin-top:var(--sp-40)">
      <div class="lp-feature-text">
        <h3>And from the inside</h3>
        <p>
          Press <kbd>V</kbd> and the chart becomes a view down the corridor: flat-shaded
          walls darkening with distance, a step at a time, turn in place. It is the same
          dungeon and the same rules — a step across a threshold is a real move, so
          everything that would happen on the map happens here.
        </p>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-delve-view.jpg') ?>"
             width="1082" height="570" loading="lazy"
             alt="The first-person dungeon view: flat-shaded walls receding into darkness
                  with a FACING NORTH label and step and turn controls.">
        <figcaption>Turn in place, step forward, and look at what is there.</figcaption>
      </figure>
    </div>
  </div>
</section>

<section class="lp-band">
  <div class="lp-wrap">
    <p class="lp-eyebrow">Five</p>
    <h2>Keeping them</h2>
    <div class="lp-feature">
      <div class="lp-feature-text">
        <ul class="lp-points">
          <li><strong>A party of four</strong>, tabbed across the top of one sheet.
              Pressing a tab costs nothing — the other sheets are already loaded.</li>
          <li><strong>A bag you can reach anywhere</strong>, with equipping, using, and
              handing things to whoever else is carrying something.</li>
          <li><strong>A general store</strong> that stocks ordinary goods, consumables and
              enchantment of at most +1. Anything better is something the world has to
              hand you.</li>
          <li><strong>Experience, and the level claimed on the spot</strong> — the
              ceremony happens over the battlefield with the fight still behind it.</li>
          <li><strong>Retiring somebody</strong> is on their own sheet, takes two presses,
              and is refused while they are standing on a battlefield.</li>
          <li><strong>A sheet that prints</strong>, laid out for a binder.</li>
        </ul>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-sheet.jpg') ?>"
             width="1246" height="571" loading="lazy"
             alt="The character screen showing a fighter's full sheet — portrait, party
                  tabs, hit points, armour class, speed, proficiency, experience bar,
                  ability scores, saving throws and skills — with the account's other
                  characters listed down the right.">
        <figcaption>Everything the engine knows about somebody, on one page.</figcaption>
      </figure>
    </div>
  </div>
</section>

<?php public_foot(); ?>
