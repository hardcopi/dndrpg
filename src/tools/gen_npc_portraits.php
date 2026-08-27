<?php
/**
 * NPC busts and faces, rendered by the 3D creator instead of painted.
 *
 *   http://localhost:8081/tools/gen_npc_portraits.php
 *
 * WHY THIS IS A PAGE AND NOT A SCRIPT. Every other art generator in `tools/` is
 * Python talking to an image model on 127.0.0.1, because that is where those
 * pictures come from. These come from somewhere with no command line: the
 * creator is a Unity WebGL build, and the only thing in this system that can
 * draw a character is a browser. Same argument ModelPortrait makes for taking
 * finished bytes from the client rather than rendering them, and the same one
 * the race-plate tool made before it.
 *
 * WHO IT DRAWS. The NPCs the painting pipeline cannot see: `tools/gen_npc_art.py`
 * reads `content/npcs/*.json`, and anybody whose key begins with an underscore
 * lives in a migration instead — deliberately, because `load_content.py`'s
 * retirement pass skips `_`-prefixed keys and a content load must not delete
 * them. That is exactly the set with no other way to get a portrait.
 *
 * A SHARED SPRITE KEY IS FINE TO READ AND NEVER SAFE TO WRITE. `mara_hearthstone`
 * points at `innkeeper.png`; rendering a new `innkeeper_bust.png` would repaint
 * the Golden Flagon's landlady in another module. So a subject is only offered
 * here when its sprite key belongs to it ALONE — anything shared is listed and
 * skipped, with the sharers named, rather than quietly overwritten.
 *
 * THE RECIPE IS SAVED BACK. The creator's `surprise()` builds a look from the
 * catalogue; that recipe is written to `npcs.sidekick_json` alongside the
 * picture, so the same NPC can be re-rendered as the same person later. A
 * portrait nobody can reproduce is one that changes silently the next time
 * anybody re-runs this.
 *
 * SAFETY. `tools/` is excluded from deploy.sh, which is why the other fifty-odd
 * files here are allowed to be unguarded and is load-bearing for this one. The
 * filename is never taken from the request: the sprite key is matched against
 * the list this page itself computed, and the path is built from the match. The
 * bytes are re-encoded through GD rather than saved, so what lands on disk is a
 * PNG this process drew, whatever went in.
 */

require_once __DIR__ . '/../app/bootstrap.php';

/**
 * The subjects, and why each is or is not renderable.
 *
 * @return array<string, array{npc_key:string,name:string,role:string,sprite_key:string,race:string,shared:string[]}>
 */
function npc_subjects(): array
{
    $rows = db()->query(
        "SELECT npc_key, name, role, sprite_key, description, dialogue_json
           FROM npcs
          WHERE npc_key LIKE '\\_%' AND sprite_key IS NOT NULL AND sprite_key <> ''
          ORDER BY npc_key"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    // Painted busts, not dolls. surprise() already dressed Hessa as a man
    // once; this page must not offer them again.
    $painted = ['_fp_hessa' => true, '_fp_odd' => true, '_fp_brenna' => true];
    foreach ($rows as $r) {
        if (isset($painted[(string) $r['npc_key']]) || isset($painted[(string) $r['sprite_key']])) {
            continue;
        }

        $stmt = db()->prepare(
            'SELECT name FROM npcs WHERE sprite_key = ? AND npc_key <> ? ORDER BY name'
        );
        $stmt->execute([$r['sprite_key'], $r['npc_key']]);
        $out[(string) $r['sprite_key']] = [
            'npc_key'    => (string) $r['npc_key'],
            'name'       => (string) $r['name'],
            'role'       => (string) $r['role'],
            'sprite_key' => (string) $r['sprite_key'],
            // Nothing on `npcs` says what species somebody is, and the creator
            // needs one to build from. Human unless the description says
            // otherwise — a guess the recipe then makes permanent, which is
            // why it is saved rather than re-guessed on every run.
            'race'       => npc_race_hint((string) $r['description']),
            // Read out of the DESCRIPTION, and out of nothing else. The
            // creator's `surprise()` draws from the whole catalogue and knows
            // nothing about who it is drawing, so the first run put a full
            // beard on two women. Counting pronouns across the dialogue as
            // well looked more thorough and was worse: Hessa's own lines talk
            // about two men, which outvoted her, and she came back male. A
            // description is about the person; dialogue is about whatever they
            // are talking about.
            'sex'        => npc_sex_hint((string) $r['description']),
            'shared'     => array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name'),
        ];
    }
    return $out;
}

/** A race read out of the prose, or Human. Deliberately dumb and easily overruled. */
function npc_race_hint(string $description): string
{
    foreach (['Dwarf', 'Elf', 'Halfling', 'Gnome', 'Half-Orc', 'Half-Elf', 'Sarsen'] as $race) {
        if (stripos($description, $race) !== false) {
            return $race;
        }
    }
    return 'Human';
}

/**
 * Which pronoun a description uses: 'f', 'm', or '' for neither.
 *
 * '' means the description does not say, and the creator is then left to roll
 * whatever it likes — the right answer for somebody nobody has written a
 * pronoun for. Which is also the fix when this gets one wrong: write the
 * pronoun into the description rather than adding a table here that could
 * disagree with it.
 */
function npc_sex_hint(string $prose): string
{
    $f = preg_match_all('/\b(she|her|hers)\b/i', $prose);
    $m = preg_match_all('/\b(he|him|his)\b/i', $prose);
    if ($f === $m) {
        return '';
    }
    return $f > $m ? 'f' : 'm';
}

/** Where one rendered portrait lives. Built from the MATCHED key, never the sent one. */
function npc_portrait_path(string $spriteKey, string $variant): string
{
    return APP_ROOT . '/assets/images/npcs/' . $spriteKey . '_' . $variant . '.png';
}

$subjects = npc_subjects();

// --- the write half -------------------------------------------------------
if (($_GET['write'] ?? '') !== '') {
    header('Content-Type: application/json');

    $body = json_decode((string) file_get_contents('php://input'), true);
    $sent = is_array($body) ? (string) ($body['sprite_key'] ?? '') : '';

    // Matched, not trusted. `$key` from here on is this server's string.
    $key = null;
    foreach (array_keys($subjects) as $known) {
        if (strcmp($known, $sent) === 0) {
            $key = $known;
            break;
        }
    }
    if ($key === null || $subjects[$key]['shared']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'not a subject on offer: ' . $sent]);
        exit;
    }

    $written = [];
    foreach (['bust', 'face'] as $variant) {
        $b64 = is_array($body) ? (string) ($body[$variant] ?? '') : '';
        if ($b64 === '') {
            continue;
        }
        if (strlen($b64) > 8 * 1024 * 1024) {
            http_response_code(413);
            echo json_encode(['ok' => false, 'error' => $variant . ' too big']);
            exit;
        }
        $raw = base64_decode($b64, true);
        $im = $raw === false ? false : @imagecreatefromstring($raw);
        if ($im === false) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $variant . ' is not an image']);
            exit;
        }
        // Squared on transparency rather than scaled to fit, so the figure keeps
        // its proportions and whatever shows it decides how much to show.
        $side = max(imagesx($im), imagesy($im));
        $out = imagecreatetruecolor($side, $side);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
        imagecopy($out, $im, intdiv($side - imagesx($im), 2), intdiv($side - imagesy($im), 2),
                  0, 0, imagesx($im), imagesy($im));
        $path = npc_portrait_path($key, $variant);
        @mkdir(dirname($path), 0775, true);
        imagesavealpha($out, true);
        imagepng($out, $path, 6);
        imagedestroy($im);
        imagedestroy($out);
        $written[$variant] = ['path' => str_replace(APP_ROOT . '/', '', $path),
                              'bytes' => is_file($path) ? filesize($path) : 0, 'side' => $side];
    }

    // The recipe, so this person can be drawn again as this person.
    $recipe = is_array($body) ? ($body['recipe'] ?? null) : null;
    if (is_array($recipe)) {
        db()->prepare('UPDATE npcs SET sidekick_json = ? WHERE npc_key = ?')
            ->execute([json_encode($recipe, JSON_UNESCAPED_SLASHES), $subjects[$key]['npc_key']]);
    }

    echo json_encode(['ok' => (bool) $written, 'sprite_key' => $key,
                      'written' => $written, 'recipe_saved' => is_array($recipe)]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NPC portraits from the 3D creator</title>
  <!-- Root-relative, not asset(): asset() returns a path relative to the
       REQUEST, and this page is served from /tools/, so its answer resolves to
       /tools/assets/... and 404s. A stylesheet that 404s is an ugly page; the
       module import below that 404s is a button that does nothing at all. -->
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    body { padding: 1rem 1.5rem; }
    h1 { font-family: var(--font-display); color: var(--gold); }
    .bench { display: grid; grid-template-columns: 380px 1fr; gap: 1.5rem; align-items: start; }
    #stage { width: 380px; height: 500px; background: var(--bg-inset);
             border: 1px solid var(--border); border-radius: var(--r-md); }
    #out { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.75rem; }
    #out figure { margin: 0; text-align: center; }
    #out img { width: 100%; background: var(--bg-inset);
               border: 1px solid var(--border); border-radius: var(--r-sm); }
    #out figcaption { font-size: var(--fs-xs); color: var(--text-dim); margin-top: 0.25rem; }
    #log { font-family: var(--mono); font-size: var(--fs-xs); color: var(--text-dim);
           white-space: pre-wrap; margin-top: 1rem; max-height: 18rem; overflow: auto; }
    .skip { color: var(--text-faint); font-size: var(--fs-xs); }
    button { padding: 0.5rem 1rem; }
  </style>
</head>
<body>
  <h1>NPC portraits from the 3D creator</h1>
  <p style="color:var(--text-dim);max-width:76ch">
    The NPCs <code>gen_npc_art.py</code> cannot see — the ones that live in a migration
    rather than in <code>content/npcs/</code>. Mounts the creator once, changes the
    subject underneath it, and writes <code>&lt;sprite_key&gt;_bust.png</code> and
    <code>_face.png</code>. The recipe is saved to <code>npcs.sidekick_json</code> so the
    same person can be drawn again.
  </p>

  <p>
    <button id="go" type="button">Render <?= count(array_filter($subjects, fn ($s) => !$s['shared'])) ?></button>
    <span id="status" style="color:var(--text-faint);margin-left:0.75rem"></span>
  </p>
  <?php foreach ($subjects as $key => $s) {
      if ($s['shared']) {
          echo '<p class="skip">Skipping <b>' . e($s['name']) . '</b> — sprite key <code>'
             . e($key) . '</code> is shared with ' . e(implode(', ', $s['shared']))
             . ', and rendering it would repaint them too.</p>';
      }
  } ?>

  <div class="bench">
    <div id="stage"></div>
    <div>
      <div id="out"></div>
      <div id="log"></div>
    </div>
  </div>

  <script type="module">
    import { mountCharacter } from '/assets/js/rivermark-character.js';

    const SUBJECTS = <?= json_encode(array_values(array_filter($subjects, fn ($s) => !$s['shared']))) ?>;
    const stage = document.getElementById('stage');
    const out = document.getElementById('out');
    const log = document.getElementById('log');
    const status = document.getElementById('status');

    window.__done = [];
    window.__failed = [];

    const say = (m) => { log.textContent += m + '\n'; log.scrollTop = log.scrollHeight; };
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));

    /**
     * ONE PLAYER FOR THE WHOLE RUN.
     *
     * Mounting a fresh embed per subject and tearing it down is the obvious
     * shape and the wrong one: standing a Unity WebGL player up and knocking it
     * down repeatedly does not leave its bundle cache in a state the next
     * instance can read, and it fails claiming perfectly good AssetBundles are
     * not AssetBundles. So the player is mounted once and the subject is
     * changed underneath it. Learned the expensive way by the race-plate tool.
     */
    let embed = null;

    function mountOnce(race) {
      return new Promise((resolve, reject) => {
        let settled = false;
        embed = mountCharacter(stage, {
          race,
          mode: 'view',          // no editing panel over the figure
          spin: false,           // a still, and the same angle every time
          save: false,
          background: 'transparent',
        });
        // Errors are RECORDED, not thrown: one bundle that will not load is one
        // outfit missing, and killing the run over it loses everybody else.
        embed.on('error', (e) => say('    ! ' + ((e && e.message) || 'embed error')));
        embed.on('ready', () => { if (!settled) { settled = true; resolve(); } });
        setTimeout(() => {
          if (!settled) { settled = true; reject(new Error('player never became ready')); }
        }, 120000);
      });
    }

    /** The next portrait this player renders, or null if it does not answer. */
    function shoot(timeoutMs = 25000) {
      return new Promise((resolve) => {
        let settled = false;
        const onPortrait = (p) => {
          if (settled) return;
          settled = true;
          embed.off('portrait', onPortrait);
          resolve(p);
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
     * `surprise()` picks one SET per group out of the catalogue, and the
     * species' own `SK_<SP>_BASE_nn` — the naked body every other set is worn
     * over — is in that pool like any other. A portrait of somebody in their
     * underwear is not a portrait.
     */
    const dressed = () => {
      const torso = (embed && embed.recipe && embed.recipe.parts && embed.recipe.parts.Torso) || '';
      return torso !== '' && !/_BASE_/.test(torso);
    };

    async function render(subject) {
      say(subject.name + ' (' + subject.race + (subject.sex ? ', ' + subject.sex : '') + ')');
      // The race is changed by handing back the loaded recipe with one field
      // altered, not by loading `{v:1, race}` — a recipe naming no parts is
      // refused outright, because the assembler has nothing to build from.
      const carry = embed.recipe ? JSON.parse(JSON.stringify(embed.recipe)) : null;
      if (carry) {
        carry.race = subject.race;
        embed.load(carry);
        await wait(1200);
      }
      for (let attempt = 1; attempt <= 6; attempt++) {
        embed.surprise();
        await wait(1800);
        if (dressed()) break;
        say('    (attempt ' + attempt + ': not dressed, rerolling)');
      }
      // The catalogue has no sex, only parts, and `surprise()` draws from all of
      // them — so a woman comes back in a full beard about half the time. There
      // is nothing to set; there is a part to REMOVE. An empty parts entry is
      // dropped by the recipe validator, so deleting the group is a legal
      // recipe that simply has no facial hair in it.
      if (subject.sex === 'f' && embed.recipe && embed.recipe.parts
          && embed.recipe.parts.FacialHair) {
        const shaved = JSON.parse(JSON.stringify(embed.recipe));
        delete shaved.parts.FacialHair;
        embed.load(shaved);
        await wait(1400);
        say('    (no beard: she)');
      }
      const shot = await shoot();
      if (!shot || !shot.bust) {
        say('    FAILED — no portrait came back');
        window.__failed.push(subject.sprite_key);
        return;
      }
      const r = await fetch('?write=1', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          sprite_key: subject.sprite_key,
          bust: shot.bust.replace(/^data:image\/\w+;base64,/, ''),
          face: (shot.face || '').replace(/^data:image\/\w+;base64,/, ''),
          recipe: embed.recipe || null,
        }),
      }).then((x) => x.json());
      if (!r.ok) {
        say('    FAILED — ' + (r.error || 'write refused'));
        window.__failed.push(subject.sprite_key);
        return;
      }
      say('    wrote ' + Object.keys(r.written).join(' + ')
          + (r.recipe_saved ? ' + recipe' : ''));
      window.__done.push(subject.sprite_key);
      const fig = document.createElement('figure');
      fig.innerHTML = '<img src="/assets/images/npcs/' + subject.sprite_key
        + '_bust.png?t=' + Date.now() + '" alt=""><figcaption>' + subject.name + '</figcaption>';
      out.appendChild(fig);
    }

    document.getElementById('go').onclick = async () => {
      document.getElementById('go').disabled = true;
      status.textContent = 'mounting the creator…';
      try {
        await mountOnce(SUBJECTS.length ? SUBJECTS[0].race : 'Human');
      } catch (e) {
        say('the player never became ready: ' + e.message);
        status.textContent = 'failed';
        window.__failed.push('mount');
        return;
      }
      say('player ready');
      for (const s of SUBJECTS) {
        status.textContent = s.name + '…';
        await render(s);
      }
      status.textContent = 'done — ' + window.__done.length + ' written, '
        + window.__failed.length + ' failed';
      window.__finished = true;
    };
  </script>
</body>
</html>
