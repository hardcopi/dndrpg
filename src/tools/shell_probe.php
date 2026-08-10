<?php
/**
 * The play screen's shell, measured at every width, in a real browser.
 *
 * This replaces tools/hud_probe.html, which measured a layout that no longer
 * exists. That page hand-built a `.hud` floating over a `.map-frame` and asked
 * whether the map still received clicks through it — an interaction model
 * deleted when the world became a location graph. Run today it reports eight
 * FAILs, all false: `.hud-party` measures 834px wide because the probe's own
 * wrapper is not `.rail-left`, so the real rule (`.hud-party .party-card
 * { width: 100% }`) resolves against a container the game does not have.
 *
 * The lesson is the one home_preview.php already learned: a bench that
 * transcribes the page's markup drifts away from it silently, and a bench that
 * still renders is worse than one that fails. So the SHELL here is read out of
 * game.php — the real three columns, the real rails, the real class names —
 * and only the CONTENT of the rails is fixture. The shell is where layout
 * lives; the content only has to be plausible enough to give it something to
 * push against.
 *
 * Even so, the fixtures are checked: a rail this file fills with cards the
 * stylesheet no longer knows about would measure a column of nothing and call
 * it a pass, so every fixture must produce a non-empty box or the verdict says
 * so. That is the failure mode that killed the last probe.
 *
 *   /tools/shell_probe.php              every width at once, with the readout
 *   /tools/shell_probe.php?w=430        one width, on its own, no harness
 *
 * Headless, for a number rather than a look:
 *
 *   google-chrome --headless --no-sandbox --disable-gpu --window-size=1600,1200 \
 *     --virtual-time-budget=8000 --dump-dom http://localhost:8081/tools/shell_probe.php
 *
 * A drawing bench. It reads game.php and never writes anything.
 */

/* The widths worth looking at, and why each one is here.
 *
 * These are not the stylesheet's breakpoints. style.css currently changes its
 * mind at 1180, 1179, 1000, 800, 780, 760, 720, 640 and at 62/60/58/52/40rem —
 * thirteen values, px and rem mixed, several within twenty pixels of each
 * other. Probing at those would only tell us the file does what the file says.
 * These are the widths people actually hold. */
const WIDTHS = [
    [1440, 780, 'laptop'],
    [1180, 760, 'small laptop — the shell\'s own top breakpoint'],
    [1024, 760, 'tablet, landscape'],
    [ 900, 780, 'the gap: below the rails, above the collapse'],
    [ 760, 800, 'where the shell folds to one column'],
    [ 600, 800, 'tablet, portrait'],
    [ 430, 860, 'a large phone'],
    [ 360, 800, 'a small phone — the floor'],
];

$root = dirname(__DIR__);
$one  = isset($_GET['w']) ? max(280, min(2400, (int) $_GET['w'])) : null;

/* ------------------------------------------------------------------ *
 * The shell, out of game.php.
 * ------------------------------------------------------------------ */

if ($one !== null) {
    $src  = file_get_contents($root . '/game.php');
    $body = substr($src, strpos($src, '<!DOCTYPE'));

    /* Both opening forms — game.php's asset() calls and its icon-sprite include
       are all short echoes, and stripping only `<?php` would hand every one of
       them to the browser as text. (See home_preview.php, where exactly that
       shipped.) The sprite is put back by hand below, because the icons have to
       be real for the topbar to measure its true height. */
    $body = preg_replace('/<\?(?:php\b|=).*?\?>/s', '', $body);
    if (str_contains($body, '<?')) {
        http_response_code(500);
        exit('probe: PHP left in game.php after stripping');
    }

    /* The stylesheets, which the strip took with the asset() calls. Versioned
       here too — this bench is refreshed harder than the game is. */
    $css = '';
    foreach (['assets/css/style.css', 'assets/css/dice.css'] as $p) {
        $css .= '<link rel="stylesheet" href="/' . $p . '?v=' . filemtime($root . '/' . $p) . '">' . "\n";
    }
    $body = preg_replace('#</head>#i', $css . '</head>', $body, 1);

    /* The icon sprite, inlined as game.php inlines it. Without it every
       `.icon-btn` measures an empty box and the topbar is the wrong height.

       This carries icons.svg's `<?xml ... ?>` prolog into the HTML, so the
       served page contains a `<?` that the guard above would reject. The guard
       runs before this on purpose: the prolog is not PHP, the HTML parser
       drops it as a bogus comment, and game.php puts the same bytes on the
       same page in production. Faithful, therefore, rather than sloppy. */
    $body = preg_replace('#(<body\b[^>]*>)#i',
        '$1' . "\n" . file_get_contents($root . '/assets/icons.svg'), $body, 1);

    /* Every game script goes. They need a session, and this bench has none;
       what they would draw is supplied below instead. Dropped by matching the
       tag rather than by listing filenames, so a script added to game.php
       cannot leave a 401 in the console that nobody reads. */
    $body = preg_replace('#<script\s[^>]*\bsrc="[^"]*"[^>]*>\s*</script>#i', '', $body, -1, $dropped);
    if (!$dropped) {
        http_response_code(500);
        exit('probe: no scripts found in game.php — the shell markup has moved');
    }

    /* ---------------------------------------------------------------- *
     * The fixtures.
     *
     * Hand-written, and the only hand-written thing here. Each block is the
     * markup its renderer emits — renderPartyRail(), renderPeopleRail(),
     * actionsPanel() — trimmed to one of each kind of row. They are checked
     * for size at the bottom of this file, because a fixture that stops
     * matching the stylesheet is invisible otherwise.
     * ---------------------------------------------------------------- */

    $party = '';
    foreach ([['Wren Kingsley', 'ok', 34, 38, 4, 16],
              ['Sera', 'low', 7, 31, 5, 14],
              ['Brother Aldric', 'ok', 30, 30, 4, 18]] as $i => [$n, $band, $hp, $max, $lv, $ac]) {
        $pct = (int) round($hp / $max * 100);
        $party .= '<button type="button" class="party-card hp-' . $band . ($i === 0 ? ' is-active' : '') . '">'
            . '<img class="party-face" src="/assets/images/npcs/aldric_face.png" alt="">'
            . '<div class="party-name">' . $n . '</div>'
            . '<div class="hp-bar"><span class="' . $band . '" style="width:' . $pct . '%"></span></div>'
            . '<div class="xp-bar rail-xp"><span style="width:40%"></span></div>'
            . '<div class="party-stats"><span class="party-hp">' . $hp . '/' . $max . '</span>'
            . '<span class="party-lv">L' . $lv . '</span><span class="party-ac">AC ' . $ac . '</span></div>'
            . '<div class="cond-pips"><span class="pip pip-prone">Pr</span></div>'
            . '</button>';
    }
    $party .= '<a class="party-card party-add" href="#" title="Recruit a companion">'
        . '<span class="party-add-plus">+</span><span>Recruit</span></a>';

    $people = '<div class="people-list">'
        . '<button type="button" class="person"><img class="person-face" src="/assets/images/npcs/aldric_face.png" alt="">'
        . '<span class="person-text"><span class="person-name">Harel the Innkeeper</span>'
        . '<span class="person-tag is-shop">&#9672;</span></span></button>'
        . '<button type="button" class="person"><img class="person-face" src="/assets/images/npcs/aldric_face.png" alt="">'
        . '<span class="person-text"><span class="person-name">Officer Bram</span>'
        . '<span class="person-tag is-quest">!</span></span></button>'
        . '</div><div class="people-crowd"><div class="people-list is-crowd">'
        . '<button type="button" class="person"><span class="person-text">'
        . '<span class="person-name">A carter, waiting</span></span></button></div></div>';

    /* The scene: a heading, the chart's box, and the action row. The chart is a
       plain SVG of the right aspect rather than a real region — WorldMap.svg
       needs a map payload, and what is being measured is the space it is given. */
    $scene = '<div class="loc-inner"><div class="loc-text">'
        . '<div class="loc-header"><h1 class="loc-name">The Golden Flagon</h1>'
        . '<p class="loc-region">Rivermark</p></div>'
        . '<div class="loc-map"><svg class="worldmap is-bare" viewBox="0 0 100 75" '
        . 'preserveAspectRatio="xMidYMid meet" style="width:100%;height:auto">'
        . '<rect x="0" y="0" width="100" height="75" fill="#1a1d22"></rect>'
        . '<line class="wm-edge" x1="24" y1="30" x2="66" y2="46"></line>'
        . '<circle cx="24" cy="30" r="2.4" fill="#6f5a34"></circle>'
        . '<circle cx="66" cy="46" r="2.4" fill="#6f5a34"></circle>'
        . '</svg></div>'
        . '<div class="loc-foot"><div class="loc-actions">';
    foreach ([['i-journal', 'Search'], ['i-camp', 'Rest'], ['i-dice', 'Take an hour'],
              ['i-refresh', 'Look again'], ['i-home', 'Board']] as [$ic, $lab]) {
        $scene .= '<button type="button" class="act-slot loc-act has-label">'
            . '<svg class="act-icon" aria-hidden="true"><use href="#' . $ic . '"></use></svg>'
            . '<span>' . $lab . '</span></button>';
    }
    $scene .= '</div></div></div></div>';

    $quest = '<h2 class="rail-pane-head">Tracking</h2>'
        . '<div class="track-current"><div class="track-title">The Quarry Stops Paying</div>'
        . '<div class="track-stage">Ask the foreman what changed</div>'
        . '<div class="track-where">The High Street &rarr; The Quarry Road &middot; 2 stops</div></div>';

    $log = '';
    foreach ([['story', 'You push open the door of the Golden Flagon.'],
              ['combat', 'Sera hits the goblin for 7 damage.'],
              ['story', 'Officer Bram has work, if you want it.'],
              ['combat', 'The goblin misses Wren Kingsley.']] as [$cat, $line]) {
        $log .= '<div class="line cat-' . $cat . '">' . $line . '</div>';
    }

    $actions = '';
    foreach ([['i-journal', 'Talk'], ['i-dice', 'Search'], ['i-camp', 'Camp']] as [$ic, $lab]) {
        $actions .= '<button type="button" class="btn action-btn">'
            . '<svg aria-hidden="true"><use href="#' . $ic . '"></use></svg>' . $lab . '</button>';
    }

    /* Injected by id, into the mount points game.php already declares — so a
       renamed mount point is a loud empty rail here, not a quiet one. */
    $fill = json_encode([
        'party-rail'    => $party,
        'people-rail'   => $people,
        'location-root' => $scene,
        'quest-tracker' => $quest,
        'game-log'      => $log,
        'action-bar'    => $actions,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $body = preg_replace('#</body>#i', <<<HTML
<script>
  const FILL = {$fill};
  const missing = [];
  for (const [id, html] of Object.entries(FILL)) {
    const el = document.getElementById(id);
    if (!el) { missing.push(id); continue; }
    el.innerHTML = html;
  }
  /* A mount point game.php no longer declares. The old probe's whole failure
     was measuring boxes that had quietly stopped being the game's; this makes
     that state impossible to mistake for a layout. */
  if (missing.length) {
    const w = document.createElement('div');
    w.style.cssText = 'position:fixed;inset:0 0 auto 0;z-index:9999;background:#5a1d1d;'
      + 'color:#ffd9d9;font:600 12px/1.6 monospace;padding:6px 10px';
    w.textContent = 'probe: game.php has no #' + missing.join(', #');
    document.body.appendChild(w);
  }
  window.__probeMissing = missing;
</script>
</body>
HTML, $body, 1);

    header('Content-Type: text/html; charset=utf-8');
    echo $body;
    exit;
}

/* ------------------------------------------------------------------ *
 * The harness: every width side by side, and the measurements.
 * ------------------------------------------------------------------ */
header('Content-Type: text/html; charset=utf-8');
$frames = json_encode(WIDTHS);
?>
<!doctype html>
<meta charset="utf-8">
<title>Shell probe — the play screen at every width</title>
<style>
  :root { color-scheme: dark; }
  body { margin: 0; background: #0b0d10; color: #cfd6e0;
         font: 13px/1.6 ui-monospace, SFMono-Regular, monospace; }
  h1 { font: 600 15px/1.4 ui-monospace, monospace; margin: 0; color: #e8c979; }
  header { position: sticky; top: 0; z-index: 5; background: #14161a;
           border-bottom: 1px solid #3a424e; padding: 10px 16px; }
  header p { margin: 4px 0 0; color: #98a1ae; }
  .strip { display: flex; gap: 18px; align-items: flex-start;
           overflow-x: auto; padding: 16px; }
  .cell { flex: 0 0 auto; }
  .cell figcaption { margin: 0 0 6px; color: #98a1ae; }
  .cell b { color: #d8b45c; font-weight: 600; }
  iframe { border: 1px solid #3a424e; background: #14161a; display: block; }
  pre { margin: 0; padding: 14px 16px; white-space: pre-wrap;
        border-top: 1px solid #3a424e; }
  .bad  { color: #ec8b8b; font-weight: 700; }
  .good { color: #7fdd93; }
  .warn { color: #e0c46a; }
</style>

<header>
  <h1>Shell probe — game.php's own markup, at eight widths</h1>
  <p>The shell is read out of game.php. Only the rail content is fixture. Replaces hud_probe.html, which measured the deleted floating-HUD layout.</p>
</header>

<div class="strip" id="strip"></div>
<pre id="out">measuring…</pre>

<script>
const WIDTHS = <?= $frames ?>;

const strip = document.getElementById('strip');
for (const [w, h, why] of WIDTHS) {
  const fig = document.createElement('figure');
  fig.className = 'cell';
  fig.style.margin = '0';
  fig.innerHTML = `<figcaption><b>${w}px</b> · ${why}</figcaption>`;
  const f = document.createElement('iframe');
  f.width = w; f.height = h; f.dataset.w = w;
  f.src = 'shell_probe.php?w=' + w;
  fig.appendChild(f);
  strip.appendChild(fig);
}

const rounded = (n) => Math.round(n);

/** Every element wider than the viewport, worst first. */
function overflowers(doc, vw) {
  const out = [];
  for (const el of doc.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (r.width > vw + 1 || r.right > vw + 1) {
      out.push({ sel: label(el), w: rounded(r.width), right: rounded(r.right) });
    }
  }
  // Keep the outermost offender of each chain; children inherit the sin.
  return out.sort((a, b) => b.right - a.right).slice(0, 4);
}

function label(el) {
  const c = (el.className && el.className.baseVal !== undefined)
    ? el.className.baseVal : (el.className || '');
  const cls = String(c).trim().split(/\s+/).filter(Boolean).slice(0, 3).join('.');
  return (el.id ? '#' + el.id : '') + (cls ? '.' + cls : el.tagName.toLowerCase());
}

/**
 * Tap targets below the 44px floor.
 *
 * Not a style opinion — it is the size a finger can reliably hit, and the
 * shell's controls were all drawn for a mouse. Reported per width because a
 * 26px icon button is fine on a laptop and unusable on the phone beside it.
 */
function smallTargets(doc) {
  const sel = '.icon-btn, .chip, .act-slot, .action-btn, .party-card, .person, .btn';
  const bad = new Map();
  for (const el of doc.querySelectorAll(sel)) {
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) continue;          // not drawn at this width
    if (r.width >= 44 && r.height >= 44) continue;
    const k = label(el) + ' ' + rounded(r.width) + '×' + rounded(r.height);
    bad.set(k, (bad.get(k) || 0) + 1);
  }
  return [...bad].sort((a, b) => b[1] - a[1]).slice(0, 5);
}

/** Smallest computed type actually on screen. */
function tinyType(doc) {
  const sizes = new Map();
  for (const el of doc.querySelectorAll('body *')) {
    if (!el.textContent || !el.textContent.trim()) continue;
    if (el.children.length) continue;                   // leaves only
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) continue;
    const px = parseFloat(getComputedStyle(el).fontSize);
    if (px < 12) sizes.set(px, (sizes.get(px) || 0) + 1);
  }
  return [...sizes].sort((a, b) => a[0] - b[0]);
}

addEventListener('load', () => {
  // One frame for layout to settle after the fixtures are injected.
  requestAnimationFrame(() => setTimeout(run, 120));
});

function run() {
  const out = [];
  const checks = [];

  for (const f of document.querySelectorAll('iframe')) {
    const w = Number(f.dataset.w);
    const doc = f.contentDocument;
    const win = f.contentWindow;
    if (!doc || !doc.body) { checks.push(`${w}px: frame did not load`); continue; }

    const de = doc.documentElement;
    const vw = de.clientWidth;

    out.push(`\n=== ${w}px ===`);

    if (win.__probeMissing && win.__probeMissing.length) {
      checks.push(`${w}px: game.php has no #${win.__probeMissing.join(', #')} — the fixtures went nowhere`);
    }

    // The fixtures must have produced something, or every measurement below
    // is a measurement of empty boxes. This is the check hud_probe.html did
    // not have, and not having it is why it could rot unnoticed.
    for (const id of ['party-rail', 'people-rail', 'location-root', 'game-log', 'action-bar']) {
      const el = doc.getElementById(id);
      if (!el) continue;
      const r = el.getBoundingClientRect();
      if (r.width < 2 || r.height < 2) {
        checks.push(`${w}px: #${id} measures ${rounded(r.width)}×${rounded(r.height)} — fixture drew nothing, or the pane is collapsed`);
      }
    }

    // The three columns, and whether they are still three.
    const cols = ['.rail-left', '.scene', '.rail-right']
      .map((s) => ({ s, el: doc.querySelector(s) }))
      .filter((c) => c.el)
      .map((c) => ({ ...c, r: c.el.getBoundingClientRect() }));

    for (const c of cols) {
      const vis = c.r.width > 0 && c.r.height > 0 && getComputedStyle(c.el).display !== 'none';
      out.push(`  ${c.s.padEnd(12)} ${vis ? rounded(c.r.width) + '×' + rounded(c.r.height)
        + '  at x=' + rounded(c.r.left) + ' y=' + rounded(c.r.top) : '(not drawn)'}`);
    }
    const tops = cols.filter((c) => c.r.height > 0).map((c) => rounded(c.r.top));
    const stacked = new Set(tops).size > 1;
    out.push(`  layout       ${stacked ? 'stacked' : 'side by side'}`);

    // The cardinal sin. A page that scrolls sideways on a phone is broken
    // however good it looks in the frame.
    const hOver = de.scrollWidth > vw + 1;
    out.push(`  h-scroll     ${hOver ? 'YES — ' + de.scrollWidth + 'px in a ' + vw + 'px viewport' : 'no'}`);
    if (hOver) {
      const offs = overflowers(doc, vw);
      offs.forEach((o) => out.push(`      ${o.sel} → ${o.w}px wide, right edge ${o.right}`));
      checks.push(`${w}px: the page scrolls sideways (${de.scrollWidth}px of content)`
        + (offs.length ? `, widest is ${offs[0].sel}` : ''));
    }

    const small = smallTargets(doc);
    out.push(`  tap targets  ${small.length ? small.length + ' kind(s) under 44px' : 'all ≥44px'}`);
    small.forEach(([k, n]) => out.push(`      ${k}${n > 1 ? '  ×' + n : ''}`));
    if (small.length && w <= 600) {
      checks.push(`${w}px: ${small.length} control kind(s) below the 44px tap floor — ${small[0][0]}`);
    }

    const tiny = tinyType(doc);
    out.push(`  type < 12px  ${tiny.length ? tiny.map(([px, n]) => px + 'px×' + n).join(' ') : 'none'}`);
    if (tiny.length && w <= 600) {
      const total = tiny.reduce((a, [, n]) => a + n, 0);
      checks.push(`${w}px: ${total} run(s) of text below 12px, smallest ${tiny[0][0]}px`);
    }
  }

  out.push('\n=== verdict ===');
  if (checks.length) {
    out.push(...checks.map((c) => `  FAIL  ${c}`));
  } else {
    out.push('  OK  no sideways scroll, no sub-44px controls on phone widths, no sub-12px type.');
  }

  const pre = document.getElementById('out');
  pre.textContent = out.join('\n');
  pre.innerHTML = pre.innerHTML
    .replace(/(FAIL.*)/g, '<span class="bad">$1</span>')
    .replace(/(OK  .*)/g, '<span class="good">$1</span>')
    .replace(/(YES —.*)/g, '<span class="warn">$1</span>');
}
</script>
