<?php
/**
 * Application bootstrap: session, autoload, PDO, helpers.
 */

declare(strict_types=1);

/**
 * True when this request presented an API token rather than a cookie.
 *
 * Asked before session_start() so a Bearer request does not get a PHPSESSID
 * it will never send back. Unity stores the token; issuing a cookie beside
 * it would be a second credential the client did not ask for, and a jar
 * that later started sending it would mix an empty cookie session with a
 * live token.
 */
function request_has_bearer(): bool
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }
    }
    return preg_match('/^Bearer\s+\S+/i', $header) === 1;
}

if (session_status() === PHP_SESSION_NONE) {
    if (request_has_bearer()) {
        $_SESSION = [];
    } else {
        session_start();
    }
}

define('APP_ROOT', dirname(__DIR__));
define('APP_PATH', __DIR__);

/**
 * Whether the authored adventures are on offer.
 *
 * Off means the game is characters and random encounters and nothing else: the
 * shelf is not drawn, Play does not open a module, creation does not ask which
 * world, and every new character starts in the Proving Yard — the arena in the
 * `_freeplay` module, which is a module only because `regions.module_id` is NOT
 * NULL and a place has to hang off something.
 *
 * One constant rather than a scatter of conditions, because "for now" is the
 * whole point: the adventures are still in the database, their locations and
 * quests untouched, and turning this back on is this line. What it cannot undo
 * is the parties' module links, which were wiped deliberately and separately —
 * see backups/ for the dump taken first.
 */
define('ADVENTURES_ENABLED', false);

/** The world a character stands in when there is no adventure. */
define('FREE_PLAY_MODULE', '_freeplay');

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

/**
 * CORS for clients that are not same-origin — Unity WebGL, a local editor
 * webview. A standalone player does not ask; browsers do.
 *
 * Off unless RPG_CORS_ORIGINS is set. `local` means any http(s) localhost,
 * 127.0.0.1 or 10.0.2.2 origin (the last is the host as seen from a VM).
 * Anything else is an exact Origin match. There is no `*`: a token in the
 * Authorization header is a credential and reflecting every origin would
 * let any page that can reach this host spend it.
 */
function cors_apply(): void
{
    $origin = cors_allowed_origin();
    if ($origin === null) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
}

function cors_allowed_origin(): ?string
{
    $raw = getenv('RPG_CORS_ORIGINS');
    if ($raw === false || trim((string) $raw) === '') {
        return null;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return null;
    }
    foreach (array_map('trim', explode(',', (string) $raw)) as $item) {
        if ($item === '') {
            continue;
        }
        if ($item === 'local') {
            if ($origin === 'null') {
                return 'null';
            }
            if (preg_match('#^https?://(localhost|127\.0\.0\.1|10\.0\.2\.2)(:\d+)?$#', $origin) === 1) {
                return $origin;
            }
            continue;
        }
        if ($item === $origin) {
            return $origin;
        }
    }
    return null;
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
