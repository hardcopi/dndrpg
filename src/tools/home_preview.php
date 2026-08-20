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
 * four API routes these two pages call and nothing else.
 *
 *   /tools/home_preview.php                      the picker: list, and a sheet
 *   /tools/home_preview.php?case=big             a longer list, for the rail
 *   /tools/home_preview.php?case=bare            the shelf, with an unpainted cover
 *   /tools/home_preview.php?case=empty           an account with nobody in it
 *   /tools/home_preview.php?case=loose           a character with no party to play
 *   /tools/home_preview.php?case=shelf           the shelf, as "New" draws it
 *   /tools/home_preview.php?pick=3               open a particular sheet
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
   served to the browser as text.

   Both opening forms, and the short echo is the one that mattered: this
   matched only the long one, so index.php's "Signed in as" line — a short-tag
   echo — survived the strip and was handed to the browser. The browser then
   ate `<?= htmlspecialchars((string) auth()->` as a bogus tag, because such a
   tag ends at the first `>` and there is one in the arrow operator, and
   printed the remaining half of the expression as the signed-in user's name.
   A stray `?>` is loud; this was quiet, and sat in the account bar of the one
   page this bench is mostly used for. */
/* Resolve `asset()` BEFORE the strip, rather than losing it to the strip.
   
   The pages emit `<link rel="stylesheet" href="<?= asset('assets/css/style.css') ?>">`
   so the browser refetches the CSS when it changes. Stripped as PHP, that
   leaves `href=""` — and a bench with no stylesheet does not look obviously
   broken, it looks like a page whose layout regressed: every `hidden` element
   gains a box, and the snapshot's element count silently rises. Which is how
   it was found: 74 elements became 77.
   
   The bench does not want the version, only the path, so the call is reduced
   to its argument. Nothing else here executes PHP and this does not either. */
$body = preg_replace("/<\\?=\\s*asset\\(\\s*'([^']+)'\\s*\\)\\s*\\?>/", '$1', $body);

$body = preg_replace('/<\?(?:php\b|=).*?\?>/s', '', $body);

/* Nothing may survive that. A page that grows a third opening form, or a
   block this cuts unevenly, would otherwise serve PHP source as page text
   again — which is exactly the failure above, and the bench cannot be trusted
   to show a layout while it is happening. */
if (str_contains($body, '<?')) {
    http_response_code(500);
    exit('preview: PHP left in ' . $page . '.php after stripping — the bench would render source as text');
}

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
   `case=empty` there means "an account with no characters in it" on both
   pages — on the picker that is also what puts the shelf on screen, because
   the shelf is what the detail pane shows when there is nobody to show.

   Which is also why `bare` empties the list: the unpainted cover is a thing
   you can only look at while the shelf is up. */
if ($page === 'index') {
    $modules = ['bare' => $bare, 'empty' => []][$case] ?? $modules;
}

function pc(int $id, string $party, ?string $pname, string $name, string $race,
            string $cls, int $lvl, int $hp, int $max, string $sprite,
            string $mkey = 'rivermark', string $mname = 'Rivermark Chronicles'): array
{
    return [
        'id' => $id, 'name' => $name, 'race' => $race, 'subrace' => null,
        'class' => $cls, 'level' => $lvl, 'current_hp' => $hp, 'max_hp' => $max,
        'party_id' => $party === '' ? null : (int) $party,
        // A character with no party has no module either — a module is a
        // property of the party — so the pair travels together and both are
        // empty for the loose one below.
        'party_name' => $pname,
        'module_key' => $party === '' ? null : $mkey,
        'module_name' => $party === '' ? null : $mname,
        'sprite_key' => $sprite,
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
    // And a party in a DIFFERENT module. characters.php filters this one out —
    // it is one module's page — and the picker groups by it, so the same
    // fixture exercises both the scope and the grouping.
    pc(6, '13', 'The Long Way Down', 'Yorri Deepfast', 'Dwarf', 'Warlock', 1, 9, 9,
       'warlock', 'undervault', 'The Undervault'),
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

/* `loose` is one character and no party — the case where there is no game to
   resume, so the sheet has to say so instead of drawing a Play button that
   would open somebody else's. It is the last member of $normal, on its own. */
$sets = ['normal' => $normal, 'big' => $big, 'empty' => [], 'bare' => [],
         'shelf' => [], 'loose' => [$normal[count($normal) - 1]]];
$characters = $sets[$case] ?? $normal;
if ($page === 'characters' && $case === 'bare') {
    $characters = $normal;      // `bare` is a shelf case; it means nothing here
}

/**
 * A whole sheet for one fixture character, in the shape `session/sheet` returns.
 *
 * The numbers are INVENTED and none of them are Rules'. That is the one place
 * this bench departs from "the render code here IS the render code that ships",
 * and it is deliberate: the alternative is a database with a levelled wizard in
 * it, which is the thing the bench exists to avoid needing. What is being
 * judged here is a layout — eighteen skills in two columns, a long spell list,
 * a party name that wraps — and a layout does not care whether the Athletics
 * bonus is right. Do not read a modifier off this page and believe it.
 *
 * The SHAPE is real, though, and has to stay real: every key here is one the
 * page reads, so a field renamed in CharacterSheet and not here shows up as a
 * hole in the drawing rather than as a silent pass.
 */
function sheet_fixture(array $c): array
{
    $abil = ['STR' => 15, 'DEX' => 14, 'CON' => 13, 'INT' => 12, 'WIS' => 10, 'CHA' => 8];
    $m = static fn (int $score): int => intdiv($score - 10, 2);
    $prof = 2 + intdiv(max(1, (int) $c['level']) - 1, 4);

    $abilities = [];
    foreach ($abil as $abbr => $score) {
        $abilities[] = ['abbr' => $abbr, 'label' => $abbr, 'score' => $score, 'mod' => $m($score)];
    }
    $saves = [];
    foreach (['Strength' => 'STR', 'Dexterity' => 'DEX', 'Constitution' => 'CON',
              'Intelligence' => 'INT', 'Wisdom' => 'WIS', 'Charisma' => 'CHA'] as $label => $abbr) {
        $saves[] = ['label' => $label, 'mod' => $m($abil[$abbr]) + ($abbr === 'STR' ? $prof : 0),
                    'proficient' => $abbr === 'STR'];
    }

    // The eighteen, in the alphabetical order the sheet prints them. Written
    // out rather than taken from Rules::SKILLS, because the bench does not load
    // the app — see the note above about what is and is not real here.
    $skills = [
        ['Acrobatics', 'DEX'], ['Animal Handling', 'WIS'], ['Arcana', 'INT'],
        ['Athletics', 'STR'], ['Deception', 'CHA'], ['History', 'INT'],
        ['Insight', 'WIS'], ['Intimidation', 'CHA'], ['Investigation', 'INT'],
        ['Medicine', 'WIS'], ['Nature', 'INT'], ['Perception', 'WIS'],
        ['Performance', 'CHA'], ['Persuasion', 'CHA'], ['Religion', 'INT'],
        ['Sleight of Hand', 'DEX'], ['Stealth', 'DEX'], ['Survival', 'WIS'],
    ];
    $skillRows = [];
    foreach ($skills as $i => [$label, $abbr]) {
        $isProf = in_array($label, ['Athletics', 'Perception', 'Stealth'], true);
        $exp = $label === 'Stealth';
        $skillRows[] = [
            'label' => $label, 'ability' => $abbr,
            'mod' => $m($abil[$abbr]) + ($isProf ? $prof : 0) + ($exp ? $prof : 0),
            'proficient' => $isProf, 'expertise' => $exp,
        ];
    }

    // A caster gets the spellcasting box; everybody else gets nothing where it
    // would be. Both are worth looking at — the box is the tallest thing on the
    // sheet and a fixture that never draws it hides how the pane copes.
    $caster = in_array($c['class'], ['Wizard', 'Cleric', 'Sorcerer', 'Warlock', 'Druid'], true);
    $spellcasting = $caster ? [
        'ability' => 'Intelligence', 'ability_mod' => 3, 'save_dc' => 13, 'attack_bonus' => 5,
        'slots' => [['level' => 1, 'have' => 3, 'used' => 1, 'left' => 2]],
        'known' => [
            ['name' => 'Fire Bolt', 'level' => 0, 'school' => 'Evocation'],
            ['name' => 'Mage Hand', 'level' => 0, 'school' => 'Conjuration'],
            ['name' => 'Burning Hands', 'level' => 1, 'school' => 'Evocation'],
            ['name' => 'Magic Missile', 'level' => 1, 'school' => 'Evocation'],
            ['name' => 'Shield', 'level' => 1, 'school' => 'Abjuration'],
        ],
    ] : null;

    return [
        'sheet' => [
            'character' => $c + [
                'subclass' => null, 'background' => 'Folk Hero', 'alignment' => 'Neutral Good',
                'armor_class' => 15, 'speed' => 30, 'gold' => 42,
                // Rules::xpProgress's shape. The bar under the pills is what
                // the Random encounter button beside it is for, so a fixture
                // without one would hide half of what this pane draws.
                'xp_progress' => [
                    'level' => (int) $c['level'], 'xp' => 1273,
                    'next_level' => (int) $c['level'] + 1,
                    'earned' => 373, 'needed' => 1800, 'percent' => 20, 'remaining' => 1427,
                ],
            ],
            'proficiency_bonus' => $prof,
            'abilities' => $abilities,
            'saves' => $saves,
            'skills' => $skillRows,
            'passive_perception' => 10 + $m($abil['WIS']) + $prof,
            'initiative' => $m($abil['DEX']),
            'hit_dice' => $c['level'] . 'd10',
            'attacks' => [
                ['name' => 'Longsword', 'bonus' => $m($abil['STR']) + $prof, 'damage' => '1d8+2',
                 'damage_type' => 'slashing', 'reach' => 'melee', 'equipped' => true,
                 'notes' => 'versatile 1d10'],
                ['name' => 'Shortbow', 'bonus' => $m($abil['DEX']) + $prof, 'damage' => '1d6+2',
                 'damage_type' => 'piercing', 'reach' => 'ranged', 'equipped' => false,
                 'notes' => 'range 80/320'],
                ['name' => 'Unarmed Strike', 'bonus' => $m($abil['STR']) + $prof, 'damage' => '1',
                 'damage_type' => 'bludgeoning', 'reach' => 'melee', 'equipped' => false,
                 'notes' => ''],
            ],
            'inventory' => array_fill(0, 11, ['id' => 0]),
            'carried_weight' => 48.5,
            'features' => [
                ['source' => $c['race'], 'name' => 'Keen Senses', 'detail' => 'Applied by the game.'],
                ['source' => $c['class'], 'name' => 'Second Wind', 'detail' => ''],
                ['source' => 'Level 4', 'name' => 'Alert',
                 'detail' => 'You cannot be surprised while conscious, and you gain +5 to initiative.'],
            ],
            'proficiencies' => [
                ['label' => 'Armor', 'value' => 'Light, Medium, Shields'],
                ['label' => 'Weapons', 'value' => 'Simple, Martial'],
            ],
            'spellcasting' => $spellcasting,
        ],
        // The three difficulties, as the route ships them from PitEngine::TIERS.
        // The picker draws a button per row, so a fixture without them draws a
        // caption and no fights.
        'tiers' => [
            ['tier' => 'warmup', 'label' => 'A warm-up',    'blurb' => 'Something to break a sweat on.'],
            ['tier' => 'fair',   'label' => 'A fair match', 'blurb' => 'An honest fight, evenly made.'],
            ['tier' => 'hard',   'label' => 'A hard match', 'blurb' => 'The crowd will get its money back.'],
        ],
        'context' => [
            'party_id' => $c['party_id'],
            'party_name' => $c['party_name'],
            'module_key' => $c['module_key'],
            'module_name' => $c['module_name'],
            'party_size' => 3,
            'location_name' => 'The Golden Flagon',
            'region_name' => 'Rivermark',
        ],
    ];
}

$sheets = [];
foreach ($characters as $c) {
    $sheets[(string) $c['id']] = sheet_fixture($c);
}

/* Who the session was last playing — the picker opens that sheet rather than
   the top of the list. Deliberately NOT the first character here, so the
   bench shows whether the rail marks the right one.

   `?pick=` overrides it, which is the only way to see a sheet the bench does
   not open by itself: a screenshot cannot click, and the caster's spell box is
   the tallest thing this pane ever draws. Checked against the fixture rather
   than passed through — an id that is not in it would fail as a broken sheet
   and read as a bug in the page. */
$status = $characters ? (string) ($characters[min(1, count($characters) - 1)]['id']) : null;
$pick = (string) ($_GET['pick'] ?? '');
if ($pick !== '' && isset($sheets[$pick])) {
    $status = $pick;
}

$fixture = json_encode([
    'modules' => $modules,
    'characters' => $characters,
    'sheets' => $sheets,
    'status' => $status,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// The stub goes in where the real client would be loaded, so it is defined
// before the page's own inline script runs — exactly where api.js sits.
$stub = <<<HTML
<script>
  const FIXTURE = {$fixture};
  /* Only the two routes these pages ask for. Anything else is a mistake worth
     seeing rather than quietly satisfying. */
  const API = {
    /* Params are appended to the route here exactly as api.js appends them,
       rather than read as an argument — `API.get('x', {id: 1})` and
       `API.get('x&id=1')` are the same call to the real client and both are
       made in the pages, so a stub that understood only one of them would
       answer half the page's questions with an exception. */
    async get(route, params) {
      if (params) route += '&' + new URLSearchParams(params).toString();
      if (route === 'session/modules') return { ok: true, modules: FIXTURE.modules };
      if (route === 'session/list')    return { ok: true, characters: FIXTURE.characters };
      if (route === 'session/status')  return { ok: true, character_id: FIXTURE.status };
      /* Params arrive appended to the route, because that is how api.js sends
         them — `session/sheet&character_id=5`. Parsed rather than matched, so
         the bench does not care in what order they are put on. */
      if (route.split('&')[0] === 'session/sheet') {
        const id = new URLSearchParams(route.slice(route.indexOf('&') + 1)).get('character_id');
        const sheet = FIXTURE.sheets[id];
        if (!sheet) throw new Error('preview: no sheet fixture for character ' + id);
        return { ok: true, ...sheet };
      }
      throw new Error('preview: no fixture for ' + route);
    },
    async post(route) { throw new Error('preview: ' + route + ' is not wired up here'); },
  };
</script>
HTML;

/* Matched as a tag rather than as one exact string, and asserted.

   This was a literal `str_replace` of the whole script element. A literal
   match fails silently: the stub is simply not installed, the page's own
   script then finds the REAL `API`, fetches `session/modules` with no session,
   gets a 401, and draws an empty shelf. The bench renders, so it looks like it
   worked, and what you are judging is a layout that never received any data.
   Adding a cache-busting `?v=` to that tag — which game.php and create.php
   already do to theirs, and which the other pages are one tidy-up away from —
   would have been enough to cause it.

   A callback rather than a replacement string: `preg_replace` reads `$1` and
   `\1` in the replacement, and the fixture JSON carries authored prose. */
$body = preg_replace_callback(
    '#<script\s[^>]*\bsrc="/assets/js/api\.js[^"]*"[^>]*>\s*</script>#i',
    static fn (): string => $stub,
    $body,
    1,
    $stubbed
);
if (!$stubbed) {
    http_response_code(500);
    exit('preview: no api.js script tag in ' . $page . '.php — the fixture would not be installed');
}

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
