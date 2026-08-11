<?php
/**
 * Application bootstrap: session, autoload, PDO, helpers.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_ROOT', dirname(__DIR__));
define('APP_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $paths = [
        APP_PATH . '/lib/' . $class . '.php',
        APP_PATH . '/models/' . $class . '.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

/**
 * Shared PDO connection (singleton).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = require APP_PATH . '/config/database.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['dbname'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $code = 400, array $extra = []): void
{
    json_response(array_merge(['ok' => false, 'error' => $message], $extra), $code);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        json_error('Method not allowed', 405);
    }
}

function ability_mod(int $score): int
{
    return (int) floor(($score - 10) / 2);
}

/**
 * The party the session is playing.
 *
 * Falls back to deriving it from the active character, because the session only
 * carries it when set_active_character() was given one — and a session that
 * predates the party, or was established down a path that did not know it, then
 * reports "no party" forever.
 *
 * That failed open in the worst way. LocationEngine uses this to hide
 * companions who are travelling with you from the tiles they were recruited on;
 * with no party id it hid nobody, so a recruited Brother Aldric was both in the
 * party and still standing in the inn offering to join it. Nothing errored — the
 * filter simply had nothing to filter by.
 *
 * The derived value is written back to the session, so this costs one query per
 * session rather than one per request.
 */
function session_party_id(): ?int
{
    if (isset($_SESSION['party_id'])) {
        return (int) $_SESSION['party_id'];
    }
    $characterId = session_character_id();
    if (!$characterId) {
        return null;
    }
    $partyId = WorldState::partyIdFor(db(), $characterId);
    if ($partyId) {
        $_SESSION['party_id'] = $partyId;
    }
    return $partyId;
}

function session_character_id(): ?int
{
    return isset($_SESSION['character_id']) ? (int) $_SESSION['character_id'] : null;
}

/**
 * The shared Auth for this request.
 *
 * A singleton for the same reason db() is: Auth memoises the current user, and
 * a second instance would be a second cache free to disagree with the first
 * about who is signed in.
 */
function auth(): Auth
{
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth(db());
    }
    return $auth;
}

/**
 * The signed-in user's id, or null.
 *
 * Sits beside session_character_id() and session_party_id() because callers
 * reach for it in the same places, but note it is NOT read from $_SESSION here.
 * Auth::currentUser() checks the account still exists and is still active, so a
 * user switched off mid-session stops being signed in on their next request
 * rather than whenever something next happens to look.
 */
function session_user_id(): ?int
{
    return auth()->userId();
}

function set_active_character(int $characterId, ?int $partyId = null): void
{
    $_SESSION['character_id'] = $characterId;
    if ($partyId !== null) {
        $_SESSION['party_id'] = $partyId;
    }
}

/**
 * Cache-bust an asset by its own modification time.
 *
 * filemtime rather than a hand-bumped constant because the one thing certain
 * about a version constant is that somebody will forget it.
 *
 * This lived as three byte-identical copies — in game.php, sheet_print.php and
 * adventure_print.php — while the other seven pages linked `style.css` with no
 * version at all. That is not a tidiness point. The first deploy of the
 * rebuilt interface went out and returning browsers went on using July's
 * 47KB stylesheet, which has no rule for `.module-card`, `.rail-pane` or
 * `.cbt-float` because those components did not exist when it was written: new
 * markup, old paint. nginx sends no `Cache-Control` for CSS, only an ETag and
 * a Last-Modified, so a browser falls back to heuristic freshness — roughly a
 * tenth of the file's age — and a two-week-old stylesheet at an unchanging URL
 * is not re-fetched for over a day.
 *
 * THE tokens.css CASE. style.css pulls the design tokens in with
 * `@import url('tokens.css')`, and a relative @import resolves without the
 * query string, so there is no URL for a caller to version. Folding tokens'
 * mtime in here is the answer available without a build step: a change to the
 * tokens alone still moves style.css's URL, and since both files are written
 * by the same deploy their Last-Modified is the same minute, which leaves the
 * import's heuristic freshness at nearly zero and the browser revalidates it.
 * That is a strong mitigation rather than a guarantee — the guarantee is a
 * `Cache-Control` header on the server, which is not in this repository.
 *
 * The @import stays regardless: thirteen benches under tools/ link style.css
 * and nothing else, and it is what makes the stylesheet self-sufficient.
 */
function asset(string $path): string
{
    $root = dirname(__DIR__);
    $full = $root . '/' . $path;
    $v = is_file($full) ? filemtime($full) : 0;

    if (str_ends_with($path, 'assets/css/style.css')) {
        $tokens = $root . '/assets/css/tokens.css';
        if (is_file($tokens)) {
            $v = max($v, filemtime($tokens));
        }
    }

    return $path . '?v=' . $v;
}
