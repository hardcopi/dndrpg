<?php
/**
 * The dialogue theatre, read aloud, with no game running.
 *
 * Hearing a scene is the only way to judge one. The cut between the narrator
 * and the character is a grammar (`tools/gen_voiceover.py`), and a grammar can
 * be perfectly self-consistent and still put the word "says" in Finch's mouth,
 * or hand a companion's line to the man she is arguing with, or leave a quarter
 * of a second of silence in the middle of a sentence. None of that is visible
 * in a manifest. It is obvious within two beats of listening.
 *
 * Getting to Garrow Finch in the game means an account, a party, a module and a
 * walk across Rivermark. This is the same bench `home_preview.php` and
 * `floorplan_preview.php` are: the page's OWN markup, the page's own scripts and
 * the shipped stylesheet, with only the server's answers faked. The theatre here
 * is `ui-dialog.js` and the player is `ui-voice.js` — not copies — so a fault
 * heard here is a fault in the game.
 *
 * What is real, and load bearing that it is real:
 *
 *   - The clips come from `Voiceover::clips()`, the class the engine calls,
 *     doing its actual sha1 lookup against the actual manifest on disk. A line
 *     whose audio is missing or stale is silent here exactly as it would be in
 *     play, rather than being papered over by a fixture that lists the files.
 *   - The text comes from `content/dialog/*.json`, which is what the generator
 *     read. If the database has drifted from the files the game will be silent
 *     where this bench is not — see the note about exporting in the generator.
 *
 *   /tools/voice_preview.php                     Garrow Finch, every beat
 *   /tools/voice_preview.php?npc=kessa           somebody else, if recorded
 *   /tools/voice_preview.php?node=the_paper      one node, with its asides
 *
 * A listening bench. No database, no session, no party.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/lib/Voiceover.php';

$npcKey  = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['npc'] ?? 'garrow_finch'));
$only    = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['node'] ?? ''));
$root    = dirname(__DIR__);

/** The authored tree, straight off disk. */
function tree(string $root, string $npcKey): array
{
    $path = "{$root}/content/dialog/{$npcKey}.json";
    if (!is_file($path)) {
        return [];
    }
    return json_decode((string) file_get_contents($path), true) ?: [];
}

/** An NPC row, for the name and the portrait the theatre draws. */
function npcRow(string $root, string $key): array
{
    $path = "{$root}/content/npcs/{$key}.json";
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    return is_array($d) ? $d : [];
}

function companionRow(string $root, string $key): array
{
    $path = "{$root}/content/companions/{$key}.json";
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    return is_array($d) ? $d : [];
}

/**
 * The node payloads `dialogue/node` would have sent, one per authored variant.
 *
 * Conditions are ignored on purpose: the engine picks ONE variant for the party
 * in front of it, and the point of this page is to hear all of them without
 * arranging six playthroughs. The `vo` block is built the way the engine builds
 * it, by asking Voiceover for the authored string.
 */
function nodes(string $root, string $npcKey, string $only): array
{
    $tree = tree($root, $npcKey);
    $npc  = npcRow($root, $npcKey);
    $out  = [];

    foreach (($tree['nodes'] ?? []) as $nodeKey => $variants) {
        if ($only !== '' && $nodeKey !== $only) {
            continue;
        }
        $list = array_is_list($variants) ? $variants : [$variants];
        foreach ($list as $i => $variant) {
            $text = (string) ($variant['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $inter = [];
            foreach ($variant['interjections'] ?? [] as $line) {
                $who = (string) ($line['companion'] ?? '');
                $raw = (string) ($line['text'] ?? '');
                if ($raw === '') {
                    continue;
                }
                $comp = companionRow($root, $who);
                $inter[] = [
                    'companion'  => $who,
                    'name'       => (string) ($comp['name'] ?? $who),
                    'sprite_key' => (string) ($comp['sprite_key'] ?? ''),
                    'text'       => $raw,
                    'approval'   => 0,
                    'vo'         => Voiceover::clips($npcKey, $raw),
                ];
            }
            $out[] = [
                'label'   => $nodeKey . (count($list) > 1 ? " [{$i}]" : ''),
                'npc_key' => $npcKey,
                'speaker' => [
                    'name'       => (string) ($npc['name'] ?? $npcKey),
                    'role'       => (string) ($npc['role'] ?? ''),
                    'sprite_key' => (string) ($npc['sprite_key'] ?? ''),
                    'image_url'  => null,
                    'bust'       => 'assets/images/npcs/'
                        . rawurlencode((string) ($npc['sprite_key'] ?? '')) . '_bust.png',
                ],
                'text'          => $text,
                'vo'            => Voiceover::clips($npcKey, $text),
                'interjections' => $inter,
                'choices'       => [],
            ];
        }
    }
    return $out;
}

$nodes = nodes($root, $npcKey, $only);
$cast  = Voiceover::cast();
$clips = 0;
$silent = 0;
foreach ($nodes as $n) {
    foreach (array_merge([$n], $n['interjections']) as $blk) {
        count($blk['vo']) ? $clips += count($blk['vo']) : $silent++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Voiceover bench — <?= htmlspecialchars($npcKey) ?></title>
<!-- The clip and portrait URLs the engine ships are relative, which is right
     for game.php at the web root and wrong for a bench living under /tools/:
     without this every one of them resolves to /tools/assets/... , 404s to the
     homepage HTML, and fails to decode as silence that looks like a bug in the
     player. Same trap CLAUDE.md records for verifying assets by status code. -->
<base href="/">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
  /* The bench's own chrome, kept plainly apart from the game's. */
  .bench { padding: var(--sp-16); font-family: var(--sans); }
  .bench h1 { margin: 0 0 var(--sp-4); }
  .bench-note { color: var(--text-dim); font-size: var(--fs-sm); max-width: 60ch; }
  .bench-cast { display: flex; flex-wrap: wrap; gap: var(--sp-8); margin: var(--sp-12) 0; }
  .bench-cast span {
    font-family: var(--mono); font-size: var(--fs-2xs);
    border: 1px solid var(--border); border-radius: var(--r-sm);
    padding: var(--sp-2) var(--sp-8); color: var(--text-dim);
  }
  .bench-list { display: flex; flex-wrap: wrap; gap: var(--sp-6); margin-top: var(--sp-12); }
  .bench-list button { font-family: var(--mono); font-size: var(--fs-2xs); }
  .bench-warn { color: var(--danger); }
</style>
</head>
<body>
<div class="bench">
  <h1>Voiceover bench</h1>
  <p class="bench-note">
    The real theatre and the real player, against the manifest on disk. Pick a
    node to play it; the <strong>Voice</strong> box under the portrait is the
    game's own toggle and is remembered in this browser.
    <?php if ($silent): ?>
      <br><span class="bench-warn"><?= $silent ?> block(s) have no audio</span> —
      recorded before the line was edited, or never recorded.
    <?php endif; ?>
  </p>

  <div class="bench-cast">
    <span>narrator <?= htmlspecialchars((string) ($cast['narrator'] ?? '?')) ?></span>
    <?php foreach (($cast['voices'] ?? []) as $who => $voice): ?>
      <span><?= htmlspecialchars($who) ?> <?= htmlspecialchars($voice) ?></span>
    <?php endforeach; ?>
  </div>
  <p class="bench-note"><?= count($nodes) ?> variant(s), <?= $clips ?> clip(s).</p>

  <div class="bench-list" id="bench-list"></div>
</div>

<div id="dialogue-root" class="dialogue-root hidden"></div>

<script>
/* The slice of `game.js` the theatre reaches for. Everything here is either a
   pure helper copied in behaviour (not in code — these are one-liners) or a
   deliberate no-op for a route this bench has no server for. */
window.Game = {
  TILE_CACHE_VER: 'bench',
  state: { companions: <?= json_encode(array_map(
      static fn($k) => ['companion_key' => $k, 'name' => ucfirst($k), 'sprite_key' => $k],
      array_keys($cast['voices'] ?? [])
  )) ?> },
  esc: (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),
  withTileVer: (u) => u,
  bodySpriteUrl: (u) => u || '',
  spriteArtUrl: (key, suffix) => 'assets/images/npcs/' + key + (suffix || '') + '.png',
  variantUrl: (u) => u,
  reducedMotion: () => window.matchMedia('(prefers-reduced-motion: reduce)').matches,
  isTypingTarget: () => false,
  log: () => {},
  showError: (m) => console.error(m),
  mergeCharacter: () => {},
  refreshAll: () => {},
  mapFadeThrough: (fn) => fn && fn(),
  startCombat: () => {},
};
window.UI = {
  approvalBand: () => ({ cls: '' }),
  pushOverlay: () => {},
  openCamp: () => {}, openJournal: () => {}, openShop: () => {},
};

const NODES = <?= json_encode($nodes, JSON_UNESCAPED_UNICODE) ?>;

/* The one fake, and it is the right place to put it: the theatre is entered
   through `Dialog.openNode()` exactly as the game enters it — session, overlay,
   key handling and all — and only the server's answer is served from the
   fixture. Reaching past that into renderNode() would skip the half of the file
   most likely to break. */
let pending = null;
window.API = {
  post: (route) => route === 'dialogue/node'
    ? Promise.resolve({ ok: true, node: pending })
    : Promise.reject(new Error('the bench has no server for ' + route)),
};

const list = document.getElementById('bench-list');
NODES.forEach((node) => {
  const b = document.createElement('button');
  b.className = 'btn btn-small';
  b.textContent = node.label + (node.vo.length ? '' : ' (silent)');
  b.onclick = () => {
    pending = node;
    window.Dialog.openNode(node.npc_key, node.label);
  };
  list.appendChild(b);
});
</script>
<script src="/assets/js/ui-voice.js"></script>
<script src="/assets/js/ui-dialog.js"></script>
</body>
</html>
