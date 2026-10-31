<?php
/**
 * Application bootstrap: session, autoload, PDO, helpers.
 */

declare(strict_types=1);

/*
 * Errors reach the log, never the page.
 *
 * Nothing in the app set display_errors, so a web request got whatever php.ini
 * said — and the php:8.4-fpm image ships no php.ini, whose built-in default is
 * ON: a warning or an uncaught PDOException printed SQL, paths and a stack trace
 * into the HTML. CLI keeps its own settings, because the tools are read by
 * whoever ran them.
 */
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler('page_exception_handler');
}

/**
 * Whether an exception's message was written for the player to read.
 *
 * THE RULE: only the classes the app throws on purpose as refusals keep their
 * text — InvalidArgumentException ("Not without a reason…", 350-odd of them)
 * and the two auth exceptions. Everything else is generic in the browser:
 * PDOException (SQL, table and column names), Error/TypeError (file paths),
 * and RuntimeException, which is ALSO what PDOException extends and what the
 * engines throw for "this should not happen" faults naming keys, directories
 * and migrations. An allow-list, so a new kind of failure is quiet by default.
 */
function exception_is_player_facing(Throwable $e): bool
{
    // Exact classes only: library code can subclass these exceptions while
    // carrying implementation details in its message.
    return get_class($e) === InvalidArgumentException::class
        || get_class($e) === AuthRequiredException::class
        || get_class($e) === AuthForbiddenException::class;
}

/**
 * Write an unexpected failure to the PHP error log and return its reference.
 *
 * The reference goes to the browser too, so a report of "it said ref a1b2c3d4"
 * finds its log line with one grep. The trace is rebuilt WITHOUT arguments:
 * PHP's own getTraceAsString() includes them unless zend.exception_ignore_args
 * is set, and the arguments are where passwords (Auth::login, new PDO) live.
 * Nothing from the request is logged — no body, cookie, token or session id.
 */
function log_exception(Throwable $e, string $where = 'api'): string
{
    $id = bin2hex(random_bytes(4));
    $route = $where === 'api' ? (string) ($_GET['r'] ?? '') : (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $lines = [];
    for ($x = $e, $depth = 0; $x !== null && $depth < 5; $x = $x->getPrevious(), $depth++) {
        $lines[] = ($depth ? 'caused by ' : '') . get_class($x) . ': ' . $x->getMessage()
            . ' at ' . $x->getFile() . ':' . $x->getLine();
        foreach ($x->getTrace() as $i => $f) {
            $lines[] = sprintf(
                '  #%d %s%s%s()',
                $i,
                isset($f['file']) ? $f['file'] . ':' . ($f['line'] ?? '?') . ' ' : '',
                isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') : '',
                $f['function'] ?? '?'
            );
        }
    }
    error_log("rpg {$where} [{$id}] " . ($route !== '' ? "{$route} " : '') . implode("\n", $lines));
    return $id;
}

/** The one sentence a browser gets for a failure it was not meant to see. */
function generic_error_message(string $id): string
{
    return "Something went wrong on our side. If it keeps happening, mention ref {$id}.";
}

/**
 * Answer an API request with a generic 500, having logged the real cause.
 *
 * Same JSON shape as every other error (`ok`, `error`) — api.js shows
 * `data.error` and reads nothing else — plus `error_id`.
 */
function json_internal_error(Throwable $e): void
{
    $id = log_exception($e, 'api');
    json_error(generic_error_message($id), 500, ['error_id' => $id]);
}

/** Last resort for a page (not the API, which catches its own): log it, say little. */
function page_exception_handler(Throwable $e): void
{
    $id = log_exception($e, 'page');
    $message = htmlspecialchars(generic_error_message($id), ENT_QUOTES);
    if (headers_sent()) {
        // Replace any not-yet-sent buffered page. If headers/body already went
        // to the client, a short inline notice is safer than a second document.
        if (ob_get_level() > 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title></head><body><p>' . $message . '</p></body></html>';
        } else {
            echo '<p>' . $message . '</p>';
        }
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error — Rivermark Chronicles</title></head>'
        . '<body><p>' . $message . '</p>'
        . '<p><a href="/">Back to the front page</a></p></body></html>';
}

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

/**
 * True when this request arrived over TLS.
 *
 * Read from what PHP-FPM was told by nginx itself — `HTTPS` from fastcgi_params,
 * or the port it accepted on — and never from X-Forwarded-Proto. hermes has no
 * proxy in front of it, so that header can only have been written by the
 * client, and trusting it would let anybody switch the Secure flag off.
 */
function request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/**
 * The session cookie's attributes, in one place.
 *
 * session_start(), session_regenerate_id() and anything that ever expires the
 * cookie all have to send the same path, domain and flags, or the browser keeps
 * two cookies under one name and sends whichever it likes. So they all read
 * this rather than restating it.
 *
 * The lifetime and domain are left as php.ini has them, and the name stays
 * PHPSESSID: an existing signed-in session has to survive the deploy that adds
 * these flags, and changing either would orphan the cookie it is carried in.
 * Secure is conditional so the plain-http dev stack still has a session at all.
 */
function session_cookie_params(): array
{
    return [
        'lifetime' => (int) ini_get('session.cookie_lifetime'),
        'path'     => '/',
        'domain'   => (string) ini_get('session.cookie_domain'),
        'secure'   => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

if (session_status() === PHP_SESSION_NONE) {
    if (request_has_bearer()) {
        $_SESSION = [];
    } else {
        // Strict mode refuses a session id the server never issued, which is
        // what makes fixation need a real stolen id rather than an invented
        // one. Ids that already exist on disk — the owner's, mid-deploy — are
        // still accepted.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        if (!headers_sent()) {
            session_set_cookie_params(session_cookie_params());
        }
        session_start();
    }
}

/** Where the CSRF token lives in $_SESSION. */
const CSRF_SESSION_KEY = 'csrf_token';

/**
 * This session's CSRF token, made on first ask.
 *
 * Lazily, so a session that began before tokens existed is handed one on its
 * next page load or auth/status rather than being signed out for lacking it.
 * Auth unsets it whenever the session id is regenerated, so a token learned
 * against a planted session id dies with that id.
 */
function csrf_token(): string
{
    $token = $_SESSION[CSRF_SESSION_KEY] ?? null;
    if (!is_string($token) || strlen($token) !== 64) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[CSRF_SESSION_KEY] = $token;
    }
    return $token;
}

/** The `<meta>` api.js reads its token from. Goes in the <head> of any page that loads it. */
function csrf_meta(): string
{
    return '<meta name="csrf-token" content="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Whether this request has to prove it came from one of our own pages.
 *
 * Every method that can change something does, with three exemptions that are
 * each about there being no ambient credential to ride on:
 *
 * - A Bearer request. The secret is in a header a cross-site page cannot set,
 *   and bootstrap never even starts the cookie session for one.
 * - Safe methods. Nothing behind GET is meant to change game state; the two
 *   that touch bookkeeping (dialogue/npc, inventory/store) say so where they
 *   do it.
 * - A request with no session cookie AND none of the headers every current
 *   browser puts on a cross-site POST (Origin, Sec-Fetch-*). That is the Unity
 *   player and the curl harnesses signing in to get a token — they have no
 *   page to read a token from. A browser cannot qualify: a forged login from
 *   another site always carries Origin, so login CSRF stays closed even for a
 *   visitor with no cookie yet.
 */
function csrf_required(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return false;
    }
    if (request_has_bearer()) {
        return false;
    }
    if (isset($_COOKIE[session_name()])) {
        return true;
    }
    return isset($_SERVER['HTTP_ORIGIN'])
        || isset($_SERVER['HTTP_SEC_FETCH_SITE'])
        || isset($_SERVER['HTTP_SEC_FETCH_MODE']);
}

/**
 * Refuse a state-changing request that does not carry this session's token.
 *
 * The flag in the answer is what api.js keys its one refresh-and-retry on, so a
 * tab holding a token from before a login elsewhere recovers by itself.
 */
function csrf_enforce(): void
{
    if (!csrf_required()) {
        return;
    }
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $held = $_SESSION[CSRF_SESSION_KEY] ?? null;
    if ($sent === '' || !is_string($held) || !hash_equals($held, $sent)) {
        json_error('This page is out of date with your session. Reload it and try again.', 403, ['csrf' => true]);
    }
}

define('APP_ROOT', dirname(__DIR__));
define('APP_PATH', __DIR__);

/**
 * Whether the authored adventures are on offer.
 *
 * On: the shelf is drawn, New asks which campaign, creation honours `?module=`,
 * and `session/modules` lists every active module. A party made from that shelf
 * is inserted with that module's id and wakes in its opening room.
 *
 * Off means the game is characters and random encounters and nothing else: the
 * shelf is not drawn, Play does not open a module, creation does not ask which
 * world, and every new character starts in the Proving Yard — the arena in the
 * `_freeplay` module, which is a module only because `regions.module_id` is NOT
 * NULL and a place has to hang off something. `_freeplay` stays `is_active = 0`
 * either way, so it is never a card on the shelf.
 *
 * One constant rather than a scatter of conditions. Turning this on does not
 * write `parties.module_id`. A party that already has NULL stays NULL and stays
 * in the Proving Yard until a new party is created from the shelf, or until
 * somebody sets the column on purpose. This file ships no SQL for that.
 */
define('ADVENTURES_ENABLED', true);

/** The world a character stands in when there is no adventure. */
define('FREE_PLAY_MODULE', '_freeplay');

/**
 * Races that exist but are not on offer.
 *
 * Put away rather than deleted: the rows stay in the `races` table, every
 * character who already is one keeps their sheet, their traits and their look,
 * and nothing about the API changes. The only thing that goes is the option to
 * become one. The race plates (assets/images/races/) and the painted tokens
 * already exist; emptying this array is how they come back.
 *
 * IT LIVES HERE BECAUSE TWO THINGS HAVE TO AGREE ABOUT IT. It began as a
 * `const RACES_WITHHELD` inside create.js, which was the only place that needed
 * it while the creator was the only place a race was offered. Then the public
 * races page started listing the catalogue and listed all ten — advertising two
 * races to a visitor that the creator would not let them choose, which is the
 * exact failure that page is built to be incapable of. A second copy in PHP
 * would have fixed the symptom and guaranteed the next one, so the list moved
 * here and create.php hands it to create.js as `window.RACES_WITHHELD`.
 */
define('RACES_WITHHELD', []);

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
    // JSON only. This used to fall back to $_POST, which made every route
    // reachable by a plain cross-site <form> — no script, no preflight. Every
    // client we have (api.js, Unity, the curl harnesses) sends JSON.
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
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
