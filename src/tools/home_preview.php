<?php
/**
 * The front page and characters.php, drawn against canned data, with no
 * account and no session.
 *
 * Both pages are behind `require_signed_in_page()`, and what is worth looking
 * at on them — three module cards side by side, a party of six wrapping, a
 * character with no party, somebody down to two hit points — needs a database
 * in a particular state to appear at all. That is a slow way to judge a
 * layout, and it means the empty and awkward cases are the ones nobody ever
 * sees until a player does.
 *
 * This serves the real page's own markup and its real inline script, against
 * the real stylesheet, with `API` replaced by a fixture. Nothing is
 * transcribed: the render code here IS the render code that ships, so a
 * layout bug visible here is a layout bug in the game. What is faked is the
 * two API routes and nothing else.
 *
 *   /tools/home_preview.php                      the module shelf
 *   /tools/home_preview.php?case=bare            a module with no cover on disk
 *   /tools/home_preview.php?case=empty           an install with no modules
 *   /tools/home_preview.php?page=characters      one module's parties
 *   /tools/home_preview.php?page=characters&case=empty
 *   /tools/home_preview.php?page=characters&case=big
 *
 * A drawing bench. It reads the pages and never writes anything.
 */

$page = ($_GET['page'] ?? 'index') === 'characters' ? 'characters' : 'index';
$case = $_GET['case'] ?? 'normal';

$src = file_get_contents(dirname(__DIR__) . '/' . $page . '.php');

// Everything from <!DOCTYPE onwards — i.e. the page with its PHP head cut off.
$body = substr($src, strpos($src, '<!DOCTYPE'));

/* The page's own PHP — the admin-only buttons, the signed-in-as line — is
   served as text rather than run, and its closing tags show up as literal
   punctuation in the header. Strip it. What goes with it is only the account
   chrome, which is not what this bench is for; the module shelf below is
   PHP-free markup and its script, untouched.

   Written without quoting a closing tag, and this is not fussiness: a `?>`
   inside a `//` comment ENDS the PHP block. The first draft of this comment
   quoted one to say what it was stripping, and the rest of this file was
   served to the browser as text. */
$body = preg_replace('/<\?php.*?\?>/s', '', $body);

// Assets are referenced relatively ("assets/css/style.css"), and this is served
// one directory down. Root them rather than moving the preview.
$body = preg_replace('#(src|href)="(assets/|api\.js)#', '$1="/$2', $body);

/* ------------------------------------------------------------------ *
 * The fixtures.
 *
 * Real shapes, taken from what `session/modules` and `session/list` actually
 * return — the field names are the contract the pages read, so a fixture that
 * invents one would prove the layout works against data that never arrives.
 * ------------------------------------------------------------------ */

$modules = [
    ['id' => 1, 'module_key' => 'rivermark', 'name' => 'Rivermark Chronicles',
     'blurb' => 'A river town, a quarry that has stopped paying, and a warren nobody wants to talk about. Start here.',
     'attribution' => 'Rules content from the System Reference Document 5.1, CC-BY 4.0.',
     'level_min' => 1, 'level_max' => 5, 'party_count' => 2],
    ['id' => 2, 'module_key' => 'old_city', 'name' => 'The Old City',
     'blurb' => 'Thirty feet under the streets, in the parts of it that were sealed for a reason.',
     'attribution' => 'Rules content from the System Reference Document 5.1, CC-BY 4.0.',
     'level_min' => 3, 'level_max' => 8, 'party_count' => 0],
    ['id' => 3, 'module_key' => 'undervault', 'name' => 'The Undervault',
     'blurb' => 'Somebody cut a stair into the hillside and stopped writing things down. It is not the same twice.',
     'attribution' => 'Dungeon levels are generated at play, in the manner of the random dungeon tables in the 1979 Dungeon Masters Guide (Appendix A).',
     'level_min' => 1, 'level_max' => 6, 'party_count' => 1],
];

/* The two cases the shelf gets wrong if nobody looks at them.
 *
 * `bare` is a module whose cover is not on disk — the card keeps its plate and
 * puts the title on a dark board, because the alternative is one card half the
 * height of the two beside it. The key is deliberately one that will never
 * exist, so this stays true however the art directory fills up.
 *
 * `empty` is a fresh install: no modules, three placeholders, and the hero's
 * "Start a New Game" left in place — it is removed only when there are module
 * cards to ask the question instead. */
$bare = [
    ['id' => 9, 'module_key' => 'no_such_module_art', 'name' => 'A Module With No Cover',
     'blurb' => 'Nothing has been painted for this one yet.',
     'attribution' => null,
     'level_min' => 2, 'level_max' => 4, 'party_count' => 0],
];

/* Index only: characters.php looks its own module up in this same list, and
   `case=empty` there means "an account with no characters in it", not "an
   install with no modules". */
if ($page === 'index') {
    $modules = ['bare' => $bare, 'empty' => []][$case] ?? $modules;
}

function pc(int $id, string $party, ?string $pname, string $name, string $race,
            string $cls, int $lvl, int $hp, int $max, string $sprite): array
{
    return [
        'id' => $id, 'name' => $name, 'race' => $race, 'subrace' => null,
        'class' => $cls, 'level' => $lvl, 'current_hp' => $hp, 'max_hp' => $max,
        'party_id' => $party === '' ? null : (int) $party,
        'party_name' => $pname, 'module_key' => 'rivermark',
        'module_name' => 'Rivermark Chronicles', 'sprite_key' => $sprite,
    ];
}

$normal = [
    // A full party, out of level order on purpose: the page sorts them and a
    // fixture already sorted would not show whether it does.
    pc(1, '10', "Wren Kingsley's Party", 'Wren Kingsley', 'Human', 'Fighter', 4, 34, 38, 'fighter'),
    pc(2, '10', "Wren Kingsley's Party", 'Sera', 'Elf', 'Rogue', 5, 7, 31, 'rogue'),
    pc(3, '10', "Wren Kingsley's Party", 'Brother Aldric', 'Human', 'Cleric', 4, 30, 30, 'cleric'),
    // A second party in the same module, so the grouping has to actually group.
    pc(4, '11', "Dontonion's Party", 'Dontonion', 'Dwarf', 'Barbarian', 2, 21, 24, 'barbarian'),
    // And somebody who belongs to no party at all.
    pc(5, '', null, 'Halfway-made Wizard', 'Gnome', 'Wizard', 1, 6, 6, 'wizard'),
];

// Six across, to see the members grid wrap, with a long party name over it.
$big = [];
$names = ['Wren Kingsley', 'Sera of the Low Quarter', 'Brother Aldric',
          'Dontonion', 'Marrow', 'Tessaly Vane'];
$cls   = ['Fighter', 'Rogue', 'Cleric', 'Barbarian', 'Ranger', 'Sorcerer'];
foreach ($names as $i => $n) {
    $big[] = pc(20 + $i, '12',
        'The Company of the Broken Wheel and Whatever Is Left of It',
        $n, 'Human', $cls[$i], 6 - ($i % 3), ($i === 1 ? 2 : 40 - $i), 45, strtolower($cls[$i]));
}

$sets = ['normal' => $normal, 'big' => $big, 'empty' => []];
$characters = $sets[$case] ?? $normal;

$fixture = json_encode([
    'modules' => $modules,
    'characters' => $characters,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// The stub goes in where the real client would be loaded, so it is defined
// before the page's own inline script runs — exactly where api.js sits.
$stub = <<<HTML
<script>
  const FIXTURE = {$fixture};
  /* Only the two routes these pages ask for. Anything else is a mistake worth
     seeing rather than quietly satisfying. */
  const API = {
    async get(route) {
      if (route === 'session/modules') return { ok: true, modules: FIXTURE.modules };
      if (route === 'session/list')    return { ok: true, characters: FIXTURE.characters };
      throw new Error('preview: no fixture for ' + route);
    },
    async post(route) { throw new Error('preview: ' + route + ' is not wired up here'); },
  };
</script>
HTML;

$body = str_replace('<script src="/assets/js/api.js"></script>', $stub, $body);

/* A banner, so nobody mistakes the bench for the site.

   Matched as a tag rather than as the literal string "<body>": index.php grew
   a class on it and the banner silently stopped appearing on the one page this
   bench is mostly used for. A bench that quietly turns into an unlabelled copy
   of the site is worse than one that fails. */
$note = htmlspecialchars("preview · {$page}.php · case: {$case}", ENT_QUOTES);
$banner = '
  <div style="position:fixed;top:0;left:0;right:0;z-index:99;background:#3a2c12;
              color:#e8c979;font:600 12px/1.9 monospace;text-align:center;
              border-bottom:1px solid #6b5426">' . $note . '</div>
  <div style="height:26px"></div>';
$body = preg_replace('/(<body\b[^>]*>)/i', '$1' . $banner, $body, 1, $hit);
if (!$hit) {
    http_response_code(500);
    exit('preview: no <body> in ' . $page . '.php');
}

header('Content-Type: text/html; charset=utf-8');
echo $body;
