<?php
/**
 * Race plates, rendered by the 3D creator instead of painted by the image model.
 *
 *   http://localhost:8081/tools/gen_race_portraits.php
 *
 * WHY THIS IS A PAGE AND NOT A SCRIPT. Every other art generator in `tools/` is
 * Python talking to an image model on 127.0.0.1, because that is where those
 * pictures come from. These come from somewhere with no command line: the
 * creator is a Unity WebGL build, and the only thing in this system that can
 * draw a character is a browser. That is the same reason `character/portrait`
 * takes finished bytes from the client rather than rendering them — see
 * ModelPortrait's header, which is this argument at length.
 *
 * WHY IT WRITES, HAVING SAID IT WOULD NOT. The first cut of this file held the
 * pictures in `window.__plates` and left saving to whoever was driving, on the
 * argument that a tool under the web root which can write into
 * `assets/images/` is a tool which can write into `assets/images/`. That is
 * still true and it is still the risk. What made it untenable is that the
 * harness driving this cannot carry base64 back out of the page at all — it is
 * refused as encoded data — so "the driver saves it" described a driver that
 * cannot exist. The picture has to be written by the only process that can see
 * it, which is this one.
 *
 * What answers the risk instead:
 *
 *   - `tools/` is excluded from deploy.sh, so this never reaches production.
 *     That exclusion is why the other fifty-odd files here are allowed to be
 *     unguarded, and it is load-bearing for this one rather than incidental.
 *   - THE FILENAME IS NEVER TAKEN FROM THE REQUEST. The race is matched against
 *     the list this page itself computed; anything else is refused. The slug is
 *     built from the matched name, not the submitted one. This is ModelPortrait's
 *     rule — "the name is built from the character id, never from the request".
 *   - The bytes are re-encoded through GD rather than saved. What lands on disk
 *     is a PNG this process drew at a size this file chose, whatever went in.
 *
 * The races come from the same `public_races()` the public page uses, so a race
 * that is withheld gets no plate and a race added to the table gets one, with no
 * edit here.
 */

require_once __DIR__ . '/../app/inc/public_page.php';

/** One plate per RACE, not per row — a Wood Elf and a High Elf are both elves. */
$races = [];
foreach (public_races() as $r) {
    $races[(string) $r['name']] = true;
}
$races = array_keys($races);

/**
 * Where a race's rendered plate lives.
 *
 * `<slug>_model.png`, BESIDE the painted `<slug>.png` rather than over it, and
 * that is deliberate. The painting is not only the races page's: create.js's
 * `showRaceArt()` puts the same file in the creator's aside, so overwriting it
 * would silently redraw a screen nobody asked about — and would spend a
 * generated 3D bust to replace a painting of two people, which is a different
 * picture rather than a better one. race_plate() prefers this file and falls
 * back to the painting, so deleting these puts everything back.
 */
function plate_path(string $race): string
{
    return APP_ROOT . '/assets/images/races/'
        . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $race)) . '_model.png';
}

/**
 * The write half: one POST, one plate.
 *
 * Transparency is kept. The creator renders the figure on nothing, the races
 * page shows it on a card, and baking the card's colour into the file would
 * make a plate that only looks right on the page it was made for.
 */
if (($_GET['write'] ?? '') !== '') {
    header('Content-Type: application/json');

    $body = json_decode((string) file_get_contents('php://input'), true);
    $sent = is_array($body) ? (string) ($body['race'] ?? '') : '';
    $b64  = is_array($body) ? (string) ($body['bust'] ?? '') : '';

    // Matched, not trusted. `$race` from here on is this server's string.
    $race = null;
    foreach ($races as $known) {
        if (strcasecmp($known, $sent) === 0) {
            $race = $known;
            break;
        }
    }
    if ($race === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'not a race on offer: ' . $sent]);
        exit;
    }
    if (strlen($b64) > 8 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'too big']);
        exit;
    }

    $raw = base64_decode($b64, true);
    $im = $raw === false ? false : @imagecreatefromstring($raw);
    if ($im === false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'not an image']);
        exit;
    }

    // Square it on transparency rather than scaling to fit: the figure keeps its
    // proportions and the page decides how much of the square to show.
    $side = max(imagesx($im), imagesy($im));
    $out = imagecreatetruecolor($side, $side);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopy(
        $out,
        $im,
        intdiv($side - imagesx($im), 2),
        intdiv($side - imagesy($im), 2),
        0,
        0,
        imagesx($im),
        imagesy($im)
    );

    $path = plate_path($race);
    @mkdir(dirname($path), 0775, true);
    imagesavealpha($out, true);
    $ok = imagepng($out, $path, 6);
    imagedestroy($im);
    imagedestroy($out);

    echo json_encode([
        'ok'    => (bool) $ok,
        'race'  => $race,
        'path'  => str_replace(APP_ROOT . '/', '', $path),
        'bytes' => is_file($path) ? filesize($path) : 0,
        'side'  => $side,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Race plates from the 3D creator</title>
  <!-- Root-relative, not asset(): asset() returns a path relative to the
       REQUEST, and this page is served from /tools/, so its answer resolves to
       /tools/assets/... and 404s. floorplan_preview.php carries the same line
       for the same reason. The trap bites the module import below harder — a
       stylesheet that 404s is an ugly page, a module that 404s is a button that
       does nothing at all. -->
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    body { padding: 1rem 1.5rem; }
    h1 { font-family: var(--font-display); color: var(--gold); }
    .bench { display: grid; grid-template-columns: 380px 1fr; gap: 1.5rem; align-items: start; }
    #stage { width: 380px; height: 500px; background: var(--bg-inset);
             border: 1px solid var(--border); border-radius: var(--r-md); }
    #out { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem; }
    #out figure { margin: 0; text-align: center; }
    #out img { width: 100%; background: var(--bg-inset);
               border: 1px solid var(--border); border-radius: var(--r-sm); }
    #out figcaption { font-size: var(--fs-xs); color: var(--text-dim); margin-top: 0.25rem; }
    #log { font-family: var(--mono); font-size: var(--fs-xs); color: var(--text-dim);
           white-space: pre-wrap; margin-top: 1rem; max-height: 16rem; overflow: auto; }
    button { padding: 0.5rem 1rem; }
  </style>
</head>
<body>
  <h1>Race plates from the 3D creator</h1>
  <p style="color:var(--text-dim);max-width:74ch">
    Mounts the creator once per race, waits for it to settle, asks for a bust, and posts
    it back to be written as <code>assets/images/races/&lt;race&gt;.png</code>.
    Transparency is kept — the races page puts its own card behind it.
  </p>

  <p>
    <button id="go" type="button">Render all <?= count($races) ?></button>
    <span id="status" style="color:var(--text-faint);margin-left:0.75rem"></span>
  </p>

  <div class="bench">
    <div id="stage"></div>
    <div>
      <div id="out"></div>
      <div id="log"></div>
    </div>
  </div>

  <script type="module">
    import { mountCharacter } from '/assets/js/rivermark-character.js';

    const RACES = <?= json_encode($races) ?>;
    const stage = document.getElementById('stage');
    const out = document.getElementById('out');
    const log = document.getElementById('log');
    const status = document.getElementById('status');

    window.__done = [];
    window.__failed = [];

    const say = (m) => { log.textContent += m + '\n'; log.scrollTop = log.scrollHeight; };
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));

    /**
     * ONE PLAYER FOR THE WHOLE RUN, and that is the whole design of this file.
     *
     * The first version mounted a fresh embed per race and tore it down after
     * the shot, which is the obvious shape and the wrong one: it failed with
     * `Fetched .../fantasy-villagers-lowerbody-02 but it is not an AssetBundle`
     * on bundles that are demonstrably fine — all 147 carry the UnityFS magic
     * and nginx serves them as octet-stream at full length. What was actually
     * happening is that standing a Unity WebGL player up and knocking it down
     * eight times in three minutes does not leave its bundle cache in a state
     * the next instance can read.
     *
     * So the player is mounted once and the race is CHANGED underneath it.
     * `load()` sets the race, `surprise()` rerolls the look for it — Surprise
     * reads `_look.Race` and the embed passes RaceFixed, true because the mount
     * named a race, so it cannot turn a dwarf into an elf — and BecomeRace runs
     * last inside it, which is what puts the ears and the build back.
     */
    let embed = null;
    let lastError = null;

    function mountOnce(race) {
      return new Promise((resolve, reject) => {
        let settled = false;
        embed = mountCharacter(stage, {
          race,
          mode: 'view',        // no editing panel over the figure
          spin: false,         // a still, and the same angle every time
          save: false,
          background: 'transparent',
        });
        // Errors are RECORDED, not thrown. A bundle that will not load is one
        // outfit missing, and killing the run over it would lose the seven
        // races that had nothing wrong with them.
        embed.on('error', (e) => {
          lastError = (e && e.message) || 'embed error';
          say('    ! ' + lastError);
        });
        embed.on('ready', () => { if (!settled) { settled = true; resolve(); } });
        setTimeout(() => {
          if (!settled) { settled = true; reject(new Error('player never became ready')); }
        }, 120000);
      });
    }

    /** The next portrait this player renders, or null if it does not answer. */
    function shoot(timeoutMs = 20000) {
      return new Promise((resolve) => {
        let settled = false;
        const onPortrait = ({ bust }) => {
          if (settled) return;
          settled = true;
          embed.off('portrait', onPortrait);
          resolve(bust);
        };
        embed.on('portrait', onPortrait);
        embed.portrait();
        setTimeout(() => {
          if (settled) return;
          settled = true;
          embed.off('portrait', onPortrait);
          resolve(null);
        }, timeoutMs);
      });
    }

    /**
     * Is the character wearing anything on top?
     *
     * Surprise picks one SET per group out of the catalogue, and the species'
     * own `SK_<SP>_BASE_nn` — the naked body every other set is worn over — is
     * in that pool like any other. `Torso` naming a BASE set is precisely
     * "wearing nothing", and a plate of somebody in their underwear is not a
     * plate. The recipe comes back on the `change` event, which Adopt announces
     * after every reroll.
     */
    const dressed = () => {
      const torso = (embed && embed.recipe && embed.recipe.parts && embed.recipe.parts.Torso) || '';
      return torso !== '' && !/_BASE_/.test(torso);
    };

    async function renderRace(race) {
      // The race is changed by handing back the recipe that is already loaded
      // with one field altered, NOT by loading `{v:1, race}`. A recipe with no
      // parts in it is refused outright — "Nothing in this recipe names a part
      // that exists" — because the assembler has nothing to build. The parts
      // carried over here are the previous race's and they do not survive:
      // `surprise()` below constructs a fresh Appearance from the catalogue for
      // whatever `_look.Race` now says. This recipe only has to be VALID long
      // enough to set the race.
      const carry = embed.recipe ? JSON.parse(JSON.stringify(embed.recipe)) : null;
      if (carry) {
        carry.race = race;
        embed.load(carry);
        await wait(2500);
      }

      // ALWAYS ROLL ONCE, then keep rolling while it is bare.
      //
      // `while (!dressed())` was wrong and the wrongness was invisible: after
      // the first race the torso is already a real outfit, so the condition was
      // false on entry and surprise() never ran again. Changing `race` on the
      // recipe moves the race and nothing else — BecomeRace lives inside
      // Surprise, not inside load — so races two through eight came out as
      // byte-identical copies of race one, elves included, with no ears. A
      // do-while is the whole fix.
      //
      // Six rolls is generous — a bare torso is one set among many — and taking
      // what there is afterwards beats a hole in the set, because the operator
      // can see the result and press the button again.
      let rolls = 0;
      do {
        rolls++;
        embed.surprise();
        await wait(2500);
      } while (rolls < 6 && !dressed());
      say(dressed() ? '    dressed on roll ' + rolls
                    : '    still bare after ' + rolls + ' rolls — shooting anyway');

      // Let the last rebuild finish drawing before the camera reads the frame.
      await wait(1200);
      return shoot();
    }

    async function write(race, bust) {
      const r = await fetch('?write=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ race, bust }),
      });
      const j = await r.json();
      if (!j.ok) throw new Error(j.error || 'write failed');
      return j;
    }

    async function run() {
      out.innerHTML = '';
      log.textContent = '';
      window.__done = [];
      window.__failed = [];

      status.textContent = 'starting the player…';
      say('mounting once, on ' + RACES[0]);
      try {
        await mountOnce(RACES[0]);
      } catch (e) {
        say('FAILED to start — ' + e.message);
        status.textContent = 'the player never started';
        return { done: [], failed: RACES.slice() };
      }

      for (const race of RACES) {
        status.textContent = 'rendering ' + race + '…';
        say('\u2192 ' + race);
        try {
          const bust = await renderRace(race);
          if (!bust) throw new Error('no portrait came back');
          const saved = await write(race, bust);
          say('  written ' + saved.path + ' (' + Math.round(saved.bytes / 1024) + ' KB, '
              + saved.side + 'px)');
          const fig = document.createElement('figure');
          const img = document.createElement('img');
          // Cache-bust: the file just changed at a URL the page may already hold.
          img.src = '/' + saved.path + '?t=' + Date.now();
          const cap = document.createElement('figcaption');
          cap.textContent = race;
          fig.append(img, cap);
          out.appendChild(fig);
          window.__done.push(race);
        } catch (e) {
          say('  FAILED — ' + e.message);
          window.__failed.push(race);
        }
      }

      status.textContent = 'done: ' + window.__done.length + ' of ' + RACES.length
        + (window.__failed.length ? ' — failed: ' + window.__failed.join(', ') : '');
      return { done: window.__done, failed: window.__failed };
    }

    window.__run = run;
    document.getElementById('go').addEventListener('click', run);
  </script>
</body>
</html>
