<?php
/**
 * character/look and the look at creation, driven over real HTTP.
 *
 *   php tools/test_look_api.php
 *   RPG_TEST_SITE=http://lk2-web php tools/test_look_api.php
 *
 * Over HTTP rather than through the classes because most of what is being
 * tested is a GATE — who may dress whom, who may wear which key — and gates
 * live in the routes. Two throwaway accounts, `look_<pid>_a` and `_b`, each
 * make characters; everything they made, and the lk_ files baked for it, is
 * removed on the way out, so this is safe against a database somebody is
 * playing on (the same arrangement tools/test_ownership.sh uses).
 *
 * Skips itself, passing, when there is nothing to test against: no look
 * library in this checkout (it is gitignored, and baked separately by
 * tools/bake_looks.py), or no site answering at RPG_TEST_SITE. The looks it
 * asks for are taken from the library's own manifest, so it works on the
 * ninety-five-entry trial and on the full bake alike.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$pass = 0;
$fail = 0;

function check(bool $ok, string $what, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        return;
    }
    $fail++;
    echo "  FAIL  {$what}" . ($detail !== '' ? "  — {$detail}" : '') . "\n";
}

$site = rtrim(getenv('RPG_TEST_SITE') ?: 'http://web', '/');
$manifest = APP_ROOT . '/assets/looks/v1/manifest.json';
if (!is_file($manifest)) {
    echo "SKIP  no look library in this checkout (assets/looks/v1/manifest.json)\n";
    exit(0);
}

/** Present in the payload, and null: what a character not wearing a look carries. */
function lookless(array $c): bool
{
    return array_key_exists('look', $c) && $c['look'] === null
        && array_key_exists('look_key', $c) && $c['look_key'] === null;
}

/** One client: a cookie jar and the CSRF token that goes with it. */
final class Client
{
    private string $jar;
    private ?string $csrf = null;

    public function __construct(private string $site)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'lookjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** [status, decoded body]. A body that is not JSON comes back as null. */
    public function call(string $route, ?array $body = null): array
    {
        if ($body !== null && $this->csrf === null) {
            $this->csrf = (string) ($this->call('auth/status')[1]['csrf_token'] ?? '');
        }
        $ch = curl_init("{$this->site}/api/index.php?r={$route}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== null) {
            curl_setopt_array($ch, [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: {$this->csrf}"],
            ]);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        // A login rotates the session, and the token with it.
        if (is_array($json) && isset($json['csrf_token'])) {
            $this->csrf = (string) $json['csrf_token'];
        }
        return [$status, $json];
    }

    /** Content type of a static file, which is the only honest asset check (CLAUDE.md). */
    public function contentType(string $path): string
    {
        $ch = curl_init("{$this->site}/{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($ch);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return $type;
    }
}

$probe = new Client($site);
[$status] = $probe->call('auth/status');
if ($status !== 200) {
    echo "SKIP  no site answering at {$site} (set RPG_TEST_SITE)\n";
    exit(0);
}

$suffix = 'look_' . getmypid();
$db = db();
$made = [];

register_shutdown_function(static function () use ($db, $suffix, &$made): void {
    $like = "{$suffix}_%";
    $ids = $db->prepare('SELECT c.id FROM characters c INNER JOIN users u ON u.id = c.user_id WHERE u.username LIKE ?');
    $ids->execute([$like]);
    $out = APP_ROOT . '/assets/images/npcs';
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (scandir($out) ?: [] as $f) {
            if (preg_match('/^lk_' . (int) $id . '_[0-9a-f]{6}(?:_bust|_face|_fig|_sheet)?\.png$/', $f)) {
                @unlink("{$out}/{$f}");
            }
        }
    }
    // busts.json gained a line per bake; take back the ones for these ids.
    $bj = "{$out}/busts.json";
    $counts = json_decode((string) @file_get_contents($bj), true) ?: [];
    $ids->execute([$like]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (array_keys($counts) as $k) {
            if (CharacterLook::ownerOf((string) $k) === (int) $id) {
                unset($counts[$k]);
            }
        }
    }
    @file_put_contents($bj, json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $db->prepare('DELETE FROM characters WHERE user_id IN (SELECT id FROM users WHERE username LIKE ?)')->execute([$like]);
    $db->prepare('DELETE FROM parties WHERE user_id IN (SELECT id FROM users WHERE username LIKE ?)')->execute([$like]);
    $db->prepare('DELETE FROM users WHERE username LIKE ?')->execute([$like]);
});

// Looks to ask for, out of the library that is actually here.
$entries = json_decode((string) file_get_contents($manifest), true)['entries'] ?? [];
$humanPlate = array_values(array_filter($entries, fn ($e) =>
    $e['choices']['race'] === 'Human' && $e['choices']['outfit'] === 'plate' && $e['choices']['hair_style'] !== null));
if (count($humanPlate) < 2) {
    echo "SKIP  the library has fewer than two Human looks in plate to dress somebody in\n";
    exit(0);
}
$picks = static fn (array $e): array => array_intersect_key($e['choices'],
    array_flip(['gender', 'hair_style', 'hair_color', 'outfit', 'outfit_color']));
[$first, $second] = $humanPlate;

$a = new Client($site);
$b = new Client($site);
foreach (['a' => $a, 'b' => $b] as $who => $c) {
    [$s, $r] = $c->call('auth/register', ['username' => "{$suffix}_{$who}", 'password' => 'look-test-pw-1']);
    if (!($r['ok'] ?? false)) {
        echo "FAIL  could not register a throwaway account ({$s}): " . json_encode($r) . "\n";
        exit(1);
    }
}

$std = ['strength' => 15, 'dexterity' => 14, 'constitution' => 13, 'intelligence' => 12, 'wisdom' => 10, 'charisma' => 8];
$create = static fn (Client $c, string $name, string $race, string $class, ?array $look = null): array =>
    $c->call('character/create', ['name' => $name, 'race' => $race, 'class' => $class,
        'method' => 'standard', 'abilities' => $std] + ($look !== null ? ['look' => $look] : []));

// ------------------------------------------------------------- creation
echo "creation\n";

[$s, $r] = $create($a, "Lk{$suffix}A", 'Human', 'Cleric', $picks($first));
$charA = $r['character'] ?? [];
check($s === 200 && ($r['ok'] ?? false), 'create with a look', "{$s} " . json_encode($r));
$idA = (int) ($charA['id'] ?? 0);
check(CharacterLook::ownerOf((string) ($charA['sprite_key'] ?? '')) === $idA, 'the new character wears lk_<their id>', (string) ($charA['sprite_key'] ?? ''));
check(($charA['look_key'] ?? null) === $first['key'], 'look_key is the library key', (string) ($charA['look_key'] ?? ''));
check(($charA['look']['race'] ?? null) === 'Human' && ($charA['look']['outfit'] ?? null) === 'plate',
    'look is the decoded picks', json_encode($charA['look'] ?? null));
$key = (string) ($charA['sprite_key'] ?? '');
foreach (['_bust', '_face', '_fig', '', '_sheet'] as $suffixKind) {
    $type = $a->contentType("assets/images/npcs/{$key}{$suffixKind}.png");
    check($type === 'image/png', "{$key}{$suffixKind}.png is served as a PNG", $type);
}

// No outfit sent: the class decides. The first entry's own outfit is put back
// only if the class would have chosen it, so this asks the defaulting rule.
$noOutfit = $picks($first);
unset($noOutfit['outfit'], $noOutfit['outfit_color']);
[$s, $r] = $create($a, "Lk{$suffix}D", 'Human', 'Fighter', $noOutfit);
$expect = $first['choices']['outfit_color'] === 'steel';
check(!$expect || (($r['character']['look']['outfit'] ?? null) === 'plate'
    && ($r['character']['look']['outfit_color'] ?? null) === 'steel'),
    'a Fighter with no outfit chosen is dressed in steel plate', "{$s} " . json_encode($r['character']['look'] ?? $r));

[$s, $r] = $create($a, "Lk{$suffix}Bad", 'Human', 'Cleric', ['gender' => 'feminine', 'hair_style' => 'mohawk']);
check($s === 400 && str_contains((string) ($r['error'] ?? ''), 'hair style'), 'a bad look refuses the creation in words', "{$s} " . json_encode($r));
$exists = $db->prepare('SELECT COUNT(*) FROM characters WHERE name = ?');
$exists->execute(["Lk{$suffix}Bad"]);
check((int) $exists->fetchColumn() === 0, 'and nothing was inserted');

// A combination the rules allow and the library has no picture of.
$looks = new CharacterLook();
$undrawn = null;
foreach (['black', 'red', 'blond', 'grey', 'white', 'auburn', 'chestnut', 'purple', 'dark brown'] as $hc) {
    foreach (['braids', 'cropped', 'shoulder'] as $hs) {
        $p = ['gender' => 'masculine', 'hair_style' => $hs, 'hair_color' => $hc, 'outfit' => 'tunic'];
        if (!$looks->exists($looks->normalise($p, 'Human'))) {
            $undrawn = $p;
            break 2;
        }
    }
}
if ($undrawn !== null) {
    [$s, $r] = $create($a, "Lk{$suffix}Undrawn", 'Human', 'Cleric', $undrawn);
    $exists->execute(["Lk{$suffix}Undrawn"]);
    check($s === 400 && (int) $exists->fetchColumn() === 0, 'a look not in the library refuses the creation', "{$s} " . json_encode($r));
}

// ------------------------------------------------------------- re-dress
echo "re-dress\n";

// Creating the Fighter made a new party and moved the session into it, and
// character/look asks the same question avatar does — is this somebody in the
// game being played — so play A again first.
[$s] = $a->call('session/select', ['character_id' => $idA]);
check($s === 200, 'A is played again');

[$s, $r] = $a->call('character/look', ['character_id' => $idA, 'look' => $picks($second)]);
$after = $r['character'] ?? [];
check($s === 200 && ($after['look_key'] ?? null) === $second['key'], 're-dressing changes the look', "{$s} " . json_encode($r));
check(($after['sprite_key'] ?? '') !== $key && CharacterLook::ownerOf((string) ($after['sprite_key'] ?? '')) === $idA,
    're-dressing gives a new lk_ key', "{$key} -> " . ($after['sprite_key'] ?? ''));
check(!is_file(APP_ROOT . "/assets/images/npcs/{$key}_bust.png"), 'the old look\'s files are gone');
$newKey = (string) ($after['sprite_key'] ?? '');
check(is_file(APP_ROOT . "/assets/images/npcs/{$newKey}.png") && is_file(APP_ROOT . "/assets/images/npcs/{$newKey}_sheet.png"),
    'the re-dressed key still has a map sprite (inherited from the class, not the deleted look)');

[$s, $r] = $a->call('character/get');
check(($r['character']['look_key'] ?? null) === $second['key'], 'character/get carries look_key');
$inParty = array_values(array_filter($r['party'] ?? [], fn ($c) => (int) $c['id'] === $idA));
check(($inParty[0]['look']['hair_style'] ?? null) === $second['choices']['hair_style'], 'the party payload carries look too');

[$s, $r] = $a->call('character/look', ['character_id' => $idA, 'look' => ['gender' => 'feminine', 'outfit' => 'chainmail', 'hair_style' => 'topknot', 'hair_color' => 'red']]);
check($s === 400, 'a bad re-dress is refused', "{$s} " . json_encode($r));
[$s, $r] = $a->call('character/get');
check(($r['character']['sprite_key'] ?? null) === $newKey, 'and the character is unchanged');

// ------------------------------------------------------------- the gates
echo "gates\n";

[$s, $r] = $create($b, "Lk{$suffix}B", 'Human', 'Wizard');
$idB = (int) ($r['character']['id'] ?? 0);
check($s === 200 && $idB > 0, 'B creates a character with no look', "{$s}");
check(array_key_exists('look', $r['character'] ?? []) && $r['character']['look'] === null
    && array_key_exists('look_key', $r['character']) && $r['character']['look_key'] === null,
    'a character with no look says so (look null, look_key present)');

[$s, $r] = $b->call('character/look', ['character_id' => $idA, 'look' => $picks($first)]);
check($s === 403, "B cannot dress A's character", "{$s} " . json_encode($r));

[$s, $r] = $b->call('character/avatar', ['character_id' => $idB, 'sprite_key' => $newKey]);
check($s === 400, "B cannot wear A's baked look through avatar", "{$s} " . json_encode($r));
[$s, $r] = $b->call('character/avatar', ['character_id' => $idB, 'sprite_key' => 'lk_' . $idB . '_zzzzzz']);
check($s === 400, 'an lk_ key not shaped like keyFor() is refused');

// ------------------------------------------------------------- clearing
echo "clearing\n";

[$s, $r] = $a->call('character/avatar', ['character_id' => $idA, 'sprite_key' => $newKey]);
check($s === 200 && ($r['character']['look_key'] ?? null) === $second['key'], 'selecting the look you wear keeps it');

[$s, $r] = $a->call('character/avatar', ['character_id' => $idA, 'sprite_key' => 'fighter']);
check($s === 200 && ($r['character']['sprite_key'] ?? null) === 'fighter', 'an old token key can be worn', "{$s} " . json_encode($r));
check(lookless($r['character'] ?? []), 'and it clears the look');

[$s, $r] = $a->call('character/look', ['character_id' => $idA, 'look' => $picks($first)]);
check($s === 200 && ($r['character']['look_key'] ?? null) === $first['key'], 'dressed again');
[$s, $r] = $a->call('character/avatar', ['character_id' => $idA, 'sprite_key' => '']);
check($s === 200 && lookless($r['character'] ?? []), 'clearing the avatar clears the look too');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
