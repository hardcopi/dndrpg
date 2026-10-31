<?php
/**
 * The play screen, with no session behind it.
 *
 * The same bargain as home_preview.php and shell_probe.php: the shell is read
 * out of game.php, the stylesheets are the page's own, and only the content
 * of the rails is a fixture. A bench that redrew the columns would be
 * measuring itself.
 *
 *   /tools/game_preview.php                 the West Gate, as the window draws it
 *   /tools/game_preview.php?case=talk       the same street, with Ballow speaking
 *
 * A drawing bench. It reads game.php and never writes anything.
 */

$root = dirname(__DIR__);
$case = ($_GET['case'] ?? 'town') === 'talk' ? 'talk' : 'town';

$src  = file_get_contents($root . '/game.php');
$body = substr($src, strpos($src, '<!DOCTYPE'));
$body = preg_replace('/<\?(?:php\b|=).*?\?>/s', '', $body);
if (str_contains($body, '<?')) {
    http_response_code(500);
    exit('preview: PHP left in game.php after stripping');
}

$css = '';
foreach (['assets/css/style.css', 'assets/css/game-amiga.css', 'assets/css/dice.css'] as $p) {
    $css .= '<link rel="stylesheet" href="/' . $p . '?v=' . filemtime($root . '/' . $p) . '">' . "\n";
}
$body = preg_replace('#</head>#i', $css . '</head>', $body, 1);
$body = preg_replace('#(<body\b[^>]*>)#i',
    '$1' . "\n" . file_get_contents($root . '/assets/icons.svg'), $body, 1);
$body = preg_replace('#<script\s[^>]*\bsrc="[^"]*"[^>]*>\s*</script>#i', '', $body, -1, $dropped);
if (!$dropped) {
    http_response_code(500);
    exit('preview: no scripts found in game.php');
}

$face = static function (string $file): string {
    return '/assets/images/npcs/' . $file;
};

$party = '';
foreach ([
    ['Dontonion', 'barbarian_face.png', 'ok', 44, 44, 2, true],
    ['Kessa Dunmar', 'kessa_face.png', 'ok', 37, 37, 3, false],
    ['Brother Aldric', 'aldric_face.png', 'ok', 20, 20, 4, false],
    ['Sera', 'sera_vance_face.png', 'low', 8, 24, 5, false],
] as [$name, $img, $band, $hp, $max, $lv, $active]) {
    $pct = (int) round($hp / $max * 100);
    $party .= '<button type="button" class="party-card hp-' . $band . ($active ? ' is-active' : '') . '">'
        . '<img class="party-face" src="' . $face($img) . '" alt="">'
        . '<div class="party-name">' . $name . '</div>'
        . '<div class="hp-bar"><span class="' . $band . '" style="width:' . $pct . '%"></span></div>'
        . '<div class="party-stats"><span class="party-hp">' . $hp . '/' . $max . '</span>'
        . '<span class="party-lv">Lv ' . $lv . '</span></div>'
        . '</button>';
}

$people = '<ul class="people-list"><li><button type="button" class="person">'
    . '<span class="person-face-wrap">'
    . '<img class="person-face" src="' . $face('serjeant_ballow_face.png') . '" alt="">'
    . '<img class="person-talk" src="/assets/images/ui/talk_balloon.png" alt="">'
    . '</span>'
    . '<span class="person-text"><span class="person-name">Serjeant Ballow</span></span>'
    . '</button></li></ul>';

$prose = 'The lesser gate, and it knows it. A timber arch in a stretch of wall the town '
    . 'stopped repairing when the trade went east, propped on one side and patched on the '
    . 'other with quarry stone that does not match. Beyond it the ford road runs out through '
    . 'wet fields toward the farms and the burnt mill. One watchman stands here, and the '
    . 'posting is what the watch gives a man it has stopped expecting anything from.';

$scene = '<div class="loc-inner"><div class="loc-text">'
    . '<section class="loc-sheet">'
    . '<h1 class="loc-name"><span class="loc-name-place">The West Gate</span>'
    . '<span class="loc-name-region">, Rivermark</span></h1>'
    . '<div class="loc-read"><div class="loc-prose"><p>' . $prose . '</p>'
    . '<p class="loc-ambience">The gate leaf hangs open on one hinge and has clearly hung that way for years.</p>'
    . '</div></div></section>'
    . '<div class="loc-stage"><div class="loc-map" id="scene-map">'
    . '<svg class="worldmap is-pixel" viewBox="0 0 100 75" role="img" aria-label="Map of Rivermark">'
    . '<image class="wm-art" href="/assets/images/maps/rivermark_town_pixel.png" '
    . 'x="0" y="0" width="100" height="75" preserveAspectRatio="none" style="image-rendering:pixelated"/>'
    . '</svg>'
    . '<div class="map-zoom" role="group" aria-label="Zoom">'
    . '<button type="button" class="icon-btn icon-btn-sm" aria-label="Zoom out">−</button>'
    . '<button type="button" class="icon-btn icon-btn-sm" aria-label="Fit">⌂</button>'
    . '<button type="button" class="icon-btn icon-btn-sm" aria-label="Zoom in">+</button>'
    . '</div>'
    . '<button type="button" class="icon-btn map-info" aria-label="Describe this place">i</button>'
    . '<section class="loc-actions" aria-label="Actions"><div class="action-list">'
    . '<button type="button" class="act-slot loc-act has-label" aria-label="Rest">'
    . '<svg class="act-icon" aria-hidden="true"><use href="#i-heart"></use></svg>'
    . '<span class="loc-act-label">Rest</span><kbd>R</kbd></button>'
    . '</div></section></div>'
    . '<ul class="loc-legend" aria-label="Map legend">'
    . '<li><i class="lg-you" aria-hidden="true"></i> You</li>'
    . '<li><i class="lg-way" aria-hidden="true"></i> Way out</li>'
    . '<li><i class="lg-lock" aria-hidden="true"></i> Shut</li>'
    . '<li><i class="lg-quest" aria-hidden="true"></i> Errand</li>'
    . '</ul></div>'
    . '<div class="loc-foot"><section class="loc-ways"><h2>Ways out</h2><div class="action-list">'
    . '<button type="button" class="btn loc-act"><span>Back up the High Street</span></button>'
    . '<button type="button" class="btn loc-act"><span>Out along the ford lane</span></button>'
    . '</div></section></div>'
    . '</div></div>';

$quest = '<h2>Tasks <span class="loc-count">1</span></h2>'
    . '<div class="track-body"><div class="track-current">'
    . '<div class="track-title">The Almoner\'s List</div>'
    . '<div class="track-stage">The west gate</div>'
    . '<p class="track-objective">Ask the watchman on the west arch what he has counted.</p>'
    . '</div></div>';

$log = '<div class="line cat-story">The lesser gate. One watchman, and he has already seen you.</div>'
    . '<div class="line cat-story">Serjeant Ballow is here.</div>';

$actions = '';
foreach ([['i-parley', 'Talk'], ['i-sense', 'Search'], ['i-camp', 'Camp'], ['i-journal', 'Journal']] as [$ic, $lab]) {
    $actions .= '<button type="button" class="btn action-btn">'
        . '<svg class="act-icon" aria-hidden="true"><use href="#' . $ic . '"></use></svg>'
        . '<span>' . $lab . '</span></button>';
}

$talk = '';
if ($case === 'talk') {
    $line = 'The man on the west arch is somewhere past fifty, with the collar of a serjeant '
        . 'and the posting of a probationer, and he watches you cross the whole distance from '
        . 'the High Street before he speaks. “Ballow,” he says. “Nobody comes down here to talk '
        . 'to me, so you want something, and it is not the road, because the road goes to farms.”';
    $talk = '<div class="dlg-panel" role="document">'
        . '<div class="dlg-speaker">'
        . '<img class="dlg-bust" alt="" src="/assets/images/npcs/serjeant_ballow_bust.png">'
        . '<div class="dlg-name">Serjeant Ballow</div>'
        . '<div class="dlg-role">Watch serjeant</div>'
        . '</div>'
        . '<div class="dlg-script">'
        . '<p class="dlg-line">' . $line . '</p>'
        . '<div class="dlg-choices">'
        . '<button type="button" class="dlg-choice btn"><span class="dlg-key">1</span>'
        . '<span class="dlg-label">Thirty-six families off the west road in eight months.</span></button>'
        . '<button type="button" class="dlg-choice btn"><span class="dlg-key">2</span>'
        . '<span class="dlg-label">How did you know?</span></button>'
        . '</div></div>'
        . '<button type="button" class="dlg-close btn btn-small" aria-label="End the conversation">Esc</button>'
        . '</div>';
}

$fill = json_encode([
    'party-rail'      => $party,
    'people-rail'     => $people,
    'topbar-where'    => 'Rivermark',
    'gw-status-where' => 'Rivermark',
    'location-root'   => $scene,
    'quest-tracker'   => $quest,
    'game-log'        => $log,
    'action-bar'      => $actions,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$talkJs = $case === 'talk'
    ? 'const theatre = document.getElementById("dialogue-root");'
        . 'if (theatre) { theatre.classList.remove("hidden"); theatre.innerHTML = '
        . json_encode($talk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '; }'
    : '';

$body = preg_replace('#</body>#i', <<<HTML
<script>
  const FILL = {$fill};
  for (const [id, html] of Object.entries(FILL)) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
  }
  {$talkJs}
</script>
</body>
HTML, $body, 1);

header('Content-Type: text/html; charset=utf-8');
echo $body;
