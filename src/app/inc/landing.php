<?php
/**
 * The pitch — what index.php serves when nobody is signed in.
 *
 * A separate file rather than a branch inside index.php's markup, because the
 * two pages have nothing in common: the picker is a signed-in tool with a rail
 * and a detail pane, and this is a single column of argument. Interleaving them
 * with `<?php if ($signedIn) ?>` would make one file that is hard to read and
 * two designs that are hard to change.
 *
 * WHAT THIS PAGE MAY CLAIM. Only what somebody who signs up today can reach.
 * The authored campaigns are behind ADVENTURES_ENABLED and that constant is off
 * in this deployment, so Rivermark, the Old City, the Undervault and Waerhaven
 * are not mentioned anywhere here — a visitor who read about a city and then
 * could not find it would be right to feel misled. What is on offer is
 * character creation, the fighting pit and the delve, and that is what is sold.
 * Turning the adventures back on is one constant and a rewrite of this file;
 * it is deliberately not a conditional, because half a pitch assembled at
 * runtime reads like neither.
 */

require_once __DIR__ . '/public_page.php';

$nClasses  = (int) db()->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$nRaces    = public_race_count();
$nSpells   = (int) db()->query('SELECT COUNT(*) FROM spells')->fetchColumn();
$nMonsters = (int) db()->query('SELECT COUNT(*) FROM monsters')->fetchColumn();

public_head(
    'A browser RPG on the open 5e rules',
    'Roll up a character, fight on a five-foot grid, and go down a stair that '
    . 'is different every time. Free, in your browser, on the 5e SRD.'
);
?>

<header class="lp-hero">
  <div class="lp-wrap">
    <p class="lp-eyebrow">Open 5e SRD · single player · no download</p>
    <h1>Rivermark Chronicles</h1>
    <p class="lp-lead">
      Roll somebody up, take them out to the Proving Yard, and find out what they are.
      Then go down the stair, where the floors are made the night you walk them and
      nobody has drawn a map of what is down there — because nobody has been.
    </p>
    <?php public_cta(); ?>
    <p class="lp-note">Runs in the browser you already have. Nothing to install.</p>

    <div class="lp-figures">
      <div class="lp-fig"><b><?= $nClasses ?></b><span>Classes</span></div>
      <div class="lp-fig"><b><?= $nRaces ?></b><span>Races</span></div>
      <div class="lp-fig"><b><?= $nSpells ?></b><span>Spells</span></div>
      <div class="lp-fig"><b><?= $nMonsters ?></b><span>Creatures</span></div>
    </div>
  </div>
</header>

<section class="lp-band">
  <div class="lp-wrap">

    <div class="lp-feature">
      <div class="lp-feature-text">
        <p class="lp-eyebrow">Fights you can think your way through</p>
        <h2>A real grid, and a server that does the geometry</h2>
        <p>
          Every fight is fought on a generated board of five-foot cells. Movement costs
          feet. Reach and weapon range are distances. A crate between you and an archer
          is cover, and stepping out of somebody's reach lets them swing at you on the
          way past.
        </p>
        <ul class="lp-points">
          <li><strong>It tells you why.</strong> Point at a target and the action list
              says what you may do and what is stopping the rest — “you have to be
              beside them”, “nothing you are carrying reaches that far”.</li>
          <li><strong>Cover, flanking, opportunity attacks</strong> and the rest are
              worked out on the server and shipped to the board. The screen never
              guesses at a rule and then disagrees with the fight.</li>
          <li><strong>Initiative down the side</strong>, hit points and armour class on
              every card, and a log that shows the dice.</li>
        </ul>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-combat.jpg') ?>"
             width="1200" height="568" loading="lazy"
             alt="A fight in progress: initiative order down the left, four adventurers
                  and two gremlins on a five-foot grid with threatened squares hatched
                  in red, and an action list on the right showing which actions are
                  available and why the others are not.">
        <figcaption>A fair match in the Proving Yard, with a gremlin selected.</figcaption>
      </figure>
    </div>

    <div class="lp-feature lp-flip">
      <div class="lp-feature-text">
        <p class="lp-eyebrow">Somebody, rather than a set of numbers</p>
        <h2>Roll them up and they are yours</h2>
        <p>
          <?= $nRaces ?> races and <?= $nClasses ?> classes off the open 5e rules, rolled
          abilities you can arrange, a background, a starting kit, and a portrait. Then a
          full character sheet — saves, skills, proficiencies, hit dice — that prints.
        </p>
        <ul class="lp-points">
          <li><strong>A face, and a body.</strong> Painted portraits for the party rail,
              and a 3D creator built into the second step if you want to choose the
              beard.</li>
          <li><strong>Parties of four.</strong> Tab between them on one sheet, hand things
              across, and pick who walks out to a fight.</li>
          <li><strong>Levels 1 to <?= Rules::MAX_LEVEL ?>.</strong> The whole ladder —
              proficiency to +6, spell slots to 9th, an ability increase or a feat at
              4th, 8th, 12th, 16th and 19th. The named class features the engine runs
              arrive in the first six; the <a href="classes.php">class pages</a> say
              exactly which, and which are only printed.</li>
        </ul>
        <p><a class="lp-btn" href="classes.php">See all <?= $nClasses ?> classes</a></p>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-sheet.jpg') ?>"
             width="1246" height="571" loading="lazy"
             alt="The character screen: a Sarsen fighter's sheet with portrait, party
                  tabs, hit points, armour class, experience bar and ability scores,
                  beside a rail listing the account's other characters.">
        <figcaption>Your characters on the right, whoever you pick on the left.</figcaption>
      </figure>
    </div>

    <div class="lp-feature">
      <div class="lp-feature-text">
        <p class="lp-eyebrow">A dungeon nobody has drawn</p>
        <h2>The stair goes down as far as you do</h2>
        <p>
          Below the yard is a dungeon generated the moment you walk into it, in the manner
          of the old random-dungeon tables: rooms rolled from a size table, joined by
          corridors, stocked with what happens to be down there, and described in words
          that have never been written in that order before.
        </p>
        <ul class="lp-points">
          <li><strong>Traps, secret doors and wandering monsters</strong>, and a floor plan
              that fills in on graph paper as you find it.</li>
          <li><strong>An errand on every floor</strong> — a name, a hook, and rooms to
              visit — written when the floor is. It pays nothing. The monsters standing
              on it are the pay.</li>
          <li><strong>Deeper is worse.</strong> Each floor is sized against your party,
              and the one below it is sized against what you have become.</li>
        </ul>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-delve-plan.jpg') ?>"
             width="1082" height="570" loading="lazy"
             alt="A generated dungeon floor drawn on graph paper: one lit room with the
                  party token inside it, rubble hatching around the walls, two doors
                  marked, and the rest of the level still dark.">
        <figcaption>Level 1, below the Proving Yard. Everything else is still dark.</figcaption>
      </figure>
    </div>

    <div class="lp-feature lp-flip">
      <div class="lp-feature-text">
        <p class="lp-eyebrow">Or walk it from the inside</p>
        <h2>Step, turn, and look at the wall in front of you</h2>
        <p>
          Press <kbd>V</kbd> on a generated floor and the map becomes a view down the
          corridor — flat-shaded walls darkening with distance, a step at a time, turn in
          place. The same dungeon, from inside it. Press it again for the chart.
        </p>
        <ul class="lp-points">
          <li><strong>It is the same place.</strong> A step across a threshold is a real
              move, so a trap, a locked door or something waiting round the corner
              behaves exactly as it would on the map.</li>
          <li><strong>An unfound secret door is a blank wall</strong> — not a wall with a
              hint on it.</li>
        </ul>
      </div>
      <figure class="lp-figure">
        <img class="lp-shot" src="<?= asset('assets/images/site/shot-delve-view.jpg') ?>"
             width="1082" height="570" loading="lazy"
             alt="The first-person view of the same dungeon floor: flat-shaded walls in
                  brown and gold receding into darkness, with a FACING NORTH label and
                  step and turn controls.">
        <figcaption>The first-person view, facing north.</figcaption>
      </figure>
    </div>

  </div>
</section>

<section class="lp-band lp-band-inset">
  <div class="lp-wrap">
    <p class="lp-eyebrow">Three ways to get hit</p>
    <h2>The Proving Yard takes all comers</h2>
    <p class="lp-lead">
      A ring of trodden earth inside a fence of lashed poles. Pick a difficulty, pick who
      walks out, and the yard finds you an opponent worth the trouble. Nobody has to
      travel anywhere; the fight is where you are standing.
    </p>
    <div class="lp-grid lp-grid-3">
      <?php foreach (PitEngine::TIERS as $tier) { ?>
        <article class="lp-card">
          <h3><?= e((string) $tier['label']) ?></h3>
          <p><?= e((string) $tier['blurb']) ?></p>
        </article>
      <?php } ?>
    </div>
    <p class="lp-note" style="margin-top:var(--sp-16)">
      The party is sized against who is actually coming, so going in alone is a
      different fight rather than the same one four times over.
    </p>
  </div>
</section>

<section class="lp-band">
  <div class="lp-wrap">
    <h2>What else is in it</h2>
    <div class="lp-grid">
      <article class="lp-card">
        <h3>A bag, and a shop</h3>
        <p>
          Buy, sell, equip and hand things across the party. The general store stocks
          ordinary goods, consumables and enchantment of at most +1 — anything better is
          something the world has to give you.
        </p>
      </article>
      <article class="lp-card">
        <h3>Rests that mean something</h3>
        <p>
          An hour's breather gives back a hit die and the features whose text says short
          rest. A night's camp gives back the rest. Where you may sleep is a fact about
          the place you are standing.
        </p>
      </article>
      <article class="lp-card">
        <h3>Dice you can watch</h3>
        <p>
          Every roll is shown with its parts — the die, the modifier, the target — and
          the log keeps them. Turn on the 3D dice and watch them land.
        </p>
      </article>
      <article class="lp-card">
        <h3>A sheet that prints</h3>
        <p>
          The full character sheet on paper, laid out for a binder, with the numbers the
          engine actually uses rather than a second set worked out for printing.
        </p>
      </article>
      <article class="lp-card">
        <h3>Your account, your characters</h3>
        <p>
          Characters and parties belong to the account that made them. Nobody else's list
          is reachable from yours.
        </p>
      </article>
      <article class="lp-card">
        <h3>Open rules, credited</h3>
        <p>
          Built on SRD 5.1 and 5.2 under CC-BY 4.0, with the licence and the attribution
          on <a href="about.php">its own page</a> rather than in a footnote.
        </p>
      </article>
    </div>
    <div class="lp-cta" style="justify-content:flex-start">
      <a class="lp-btn" href="tour.php">Take the longer tour</a>
      <a class="lp-btn" href="classes.php">Classes</a>
      <a class="lp-btn" href="races.php">Races</a>
    </div>
  </div>
</section>

<?php public_foot(); ?>
