<?php
/**
 * The gate every HTML page stands behind.
 *
 * The API denies by default in its front controller; this is the same idea for
 * the pages, and it exists as one file for the same reason — a page that forgets
 * to include it is a page nobody has to sign in to see, and that is not a
 * mistake anyone notices by looking.
 *
 * Redirects rather than erroring: a signed-out visitor asking for game.php has
 * not done anything wrong, they have arrived early. `next` carries where they
 * were going so signing in puts them there rather than at the front door.
 *
 *   require_once __DIR__ . '/app/page_guard.php';           // any signed-in user
 *   require_once __DIR__ . '/app/page_guard.php'; require_admin_page();
 */

require_once __DIR__ . '/bootstrap.php';

/** Send a signed-out visitor to the login form, remembering where they wanted to be. */
function require_signed_in_page(): array
{
    $user = auth()->currentUser();
    if ($user !== null) {
        return $user;
    }
    // Only the path and query, never a full URL built from Host — the header is
    // attacker-controlled and this value ends up in a redirect.
    $target = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? 'index.php', PHP_URL_PATH));
    $query = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '');
    $next = $target . ($query !== '' ? '?' . $query : '');

    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}

/**
 * As above, and an administrator besides.
 *
 * An ordinary player who reaches an admin page is shown a refusal rather than a
 * login form: they are signed in already, and sending them to log in again
 * would be telling them to try harder at something that cannot work.
 */
function require_admin_page(): array
{
    $user = require_signed_in_page();
    if ($user['role'] !== Auth::ROLE_ADMIN) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Not for you — Rivermark Chronicles</title>'
            . '<link rel="stylesheet" href="assets/css/style.css"></head>'
            . '<body class="auth-page"><main class="auth-card">'
            . '<h1>That is for administrators</h1>'
            . '<p class="auth-sub">You are signed in as '
            . htmlspecialchars((string) $user['username'], ENT_QUOTES) . '.</p>'
            . '<p class="auth-foot"><a href="index.php">Back to your characters</a></p>'
            . '</main></body></html>';
        exit;
    }
    return $user;
}
