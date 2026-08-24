<?php
/**
 * Who is this, and what may they do.
 *
 * The one place either question is answered. Before this there were two half
 * answers and no whole one: `characters.user_id` was written as the constant 1
 * and never read, and the map editor had its own private notion of being
 * unlocked (a shared password in an environment variable, compared in
 * api/index.php). Neither knew about the other, so "logged in" and "allowed"
 * were unrelated ideas.
 *
 * Two roles, `admin` and `user`, because two is what the game needs: someone who
 * authors the world and someone who plays in it. Everything a role may do is
 * decided by asking this class, never by re-reading the session elsewhere — a
 * permission check that reads $_SESSION directly is a permission check that will
 * eventually disagree with this one.
 *
 * On hashing: password_hash() with the platform default, which is Argon2id on
 * this PHP and bcrypt where it is not. The algorithm is deliberately not pinned,
 * so a future PHP that defaults to something better is an upgrade rather than a
 * migration, and needsRehash() moves existing accounts up on their next login.
 */

declare(strict_types=1);

class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER  = 'user';

    /** Session key. Namespaced so it cannot collide with character_id/party_id. */
    private const SESSION_KEY = 'auth_user_id';

    /** How long a Bearer token is accepted after it is issued. */
    public const TOKEN_TTL_SECONDS = 60 * 60 * 24 * 30;

    /** Live tokens kept per account; issuing another revokes the oldest. */
    public const TOKEN_MAX_LIVE = 8;

    /**
     * Keys the cookie session and a token share.
     *
     * These are the play-state the API already parks in $_SESSION. A token
     * client has no cookie, so they are written into api_tokens.state_json
     * at the end of the request and poured back into $_SESSION at the start
     * of the next. Adding a key here is how a new piece of session state
     * becomes visible to Unity; adding one only in $_SESSION is how it
     * silently vanishes for that client.
     */
    private const TOKEN_STATE_KEYS = [
        'character_id',
        'party_id',
        'rolled_abilities',
        'pending_checks',
    ];

    private PDO $db;

    /** Memoised for the request: currentUser() is asked on nearly every route. */
    private static ?array $cached = null;

    /** Row id of the Bearer token that authenticated this request, if any. */
    private ?int $tokenId = null;

    private bool $persistRegistered = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // =======================================================================
    // Reading the current identity
    // =======================================================================

    /** The signed-in user, or null. */
    public function currentUser(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached ?: null;
        }
        $this->tokenId = null;

        $bearer = self::bearerToken();
        if ($bearer !== null) {
            $user = $this->userFromToken($bearer);
            self::$cached = $user ?? [];
            return $user;
        }

        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$id) {
            self::$cached = [];
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT id, username, email, role, is_active, last_login_at FROM users WHERE id = ?'
        );
        $stmt->execute([(int) $id]);
        $user = $stmt->fetch();

        // A deactivated account is signed out on its next request rather than
        // at some later checkpoint: the session outlives the decision to switch
        // the account off, and the whole point of deactivating is that it takes
        // effect now.
        if (!$user || (int) $user['is_active'] !== 1) {
            $this->logout();
            self::$cached = [];
            return null;
        }

        self::$cached = $user;
        return $user;
    }

    /** True when this request authenticated with a Bearer token. */
    public function usingToken(): bool
    {
        if (self::$cached === null) {
            $this->currentUser();
        }
        return $this->tokenId !== null;
    }

    public function userId(): ?int
    {
        $u = $this->currentUser();
        return $u ? (int) $u['id'] : null;
    }

    public function isSignedIn(): bool
    {
        return $this->currentUser() !== null;
    }

    public function isAdmin(): bool
    {
        $u = $this->currentUser();
        return $u !== null && $u['role'] === self::ROLE_ADMIN;
    }

    // =======================================================================
    // Signing in and out
    // =======================================================================

    /**
     * Verify a username and password and start a session.
     *
     * The failure message is the same whether the account does not exist, has no
     * password set, is deactivated or the password is simply wrong. Saying which
     * turns the login form into a way of asking whether a username is taken.
     *
     * @throws InvalidArgumentException on any failure
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $hash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';

        // Hash something anyway when the account is missing or passwordless, so
        // a wrong username returns in the same time as a wrong password. Without
        // it the difference is measurable and the form becomes a user oracle.
        if ($hash === '') {
            password_verify($password, '$2y$12$usesomesillystringfor.eSalt.To.Waste.Time.Here.At.Least.OK');
            throw new InvalidArgumentException('That username and password do not match.');
        }

        if (!password_verify($password, $hash) || (int) $user['is_active'] !== 1) {
            throw new InvalidArgumentException('That username and password do not match.');
        }

        // A new session id at the moment privilege changes, so a session id an
        // attacker planted before login is not the one that ends up signed in.
        self::regenerateSession();

        // Moving up to a stronger algorithm costs one rehash on one login, and
        // only for accounts still on the old one.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        }

        $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int) $user['id']]);

        $_SESSION[self::SESSION_KEY] = (int) $user['id'];
        self::$cached = null;

        // The previous player's character and party must not survive into the
        // new session. They are the sharpest edge here: leave them and the next
        // person to sign in on this browser starts inside somebody else's save.
        unset($_SESSION['character_id'], $_SESSION['party_id']);

        return $this->currentUser() ?? [];
    }

    public function logout(): void
    {
        // A Bearer client has no cookie session to clear. If this request
        // presented a token we have not yet resolved (logout is public and
        // does not go through requireUser), look it up here so Sign Out
        // revokes the secret rather than unsetting nothing.
        if ($this->tokenId === null) {
            $raw = self::bearerToken();
            if ($raw !== null) {
                $stmt = $this->db->prepare(
                    'SELECT id FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL'
                );
                $stmt->execute([hash('sha256', $raw)]);
                $found = $stmt->fetchColumn();
                if ($found) {
                    $this->tokenId = (int) $found;
                }
            }
        }
        if ($this->tokenId !== null) {
            $this->revokeTokenId($this->tokenId);
            $this->tokenId = null;
        }
        unset(
            $_SESSION[self::SESSION_KEY],
            $_SESSION['character_id'],
            $_SESSION['party_id'],
            $_SESSION['rolled_abilities'],
            $_SESSION['pending_checks']
        );
        self::$cached = null;
        self::regenerateSession();
    }

    /**
     * Issue a new session id, where that is possible at all.
     *
     * Guarded on headers_sent() because session_regenerate_id() cannot work once
     * output has begun — it warns and returns false. In a real request nothing
     * has been echoed by the time a login is processed, so this is the ordinary
     * path; under CLI, where the test harness runs and output starts
     * immediately, it is not, and the guard is the difference between a clean
     * run and a page of warnings.
     *
     * Deliberately silent when it cannot run. The alternative — throwing — would
     * make a session-fixation defence into an outage on any page that had
     * already sent a byte, which trades a small risk for a certain one.
     */
    private static function regenerateSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    // =======================================================================
    // Creating accounts
    // =======================================================================

    public const MIN_PASSWORD = 8;

    /**
     * Create an account.
     *
     * Used by both public registration and the admin screen; `$role` is the only
     * difference, and the route decides it — this refuses to be the thing that
     * works out whether the caller was allowed to ask.
     *
     * @throws InvalidArgumentException on a bad username, password or duplicate
     */
    public function register(string $username, string $password, string $email = '', string $role = self::ROLE_USER): array
    {
        $username = trim($username);
        $email = trim($email);

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            throw new InvalidArgumentException(
                'A username is 3 to 50 characters, letters, numbers, dot, dash or underscore.'
            );
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException(
                'A password needs at least ' . self::MIN_PASSWORD . ' characters.'
            );
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That email address does not look right.');
        }
        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_USER], true)) {
            throw new InvalidArgumentException('Unknown role.');
        }

        try {
            $this->db->prepare(
                'INSERT INTO users (username, email, role, password_hash) VALUES (?, ?, ?, ?)'
            )->execute([
                $username,
                $email !== '' ? $email : null,
                $role,
                password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $e) {
            // The UNIQUE index is what actually decides, not a SELECT beforehand:
            // two people registering the same name at once would both pass a
            // check-then-insert.
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException('That username is taken.');
            }
            throw $e;
        }

        $id = (int) $this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT id, username, email, role, is_active FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    /** Set or replace an account's password. */
    public function setPassword(int $userId, string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException(
                'A password needs at least ' . self::MIN_PASSWORD . ' characters.'
            );
        }
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        // A password change is a reason to distrust every outstanding token:
        // the old secret may have been why they were issued.
        $this->revokeAllTokens($userId);
    }

    // =======================================================================
    // Whether the public may sign themselves up
    // =======================================================================

    /**
     * The environment wins over the database.
     *
     * RPG_REGISTRATION=0 closes signup on a host regardless of what an admin has
     * clicked, which is the point of having it: a public deployment can refuse
     * registration in a way no compromised admin session can switch back on.
     * With the variable unset, the admin screen decides.
     */
    public function registrationOpen(): bool
    {
        $env = getenv('RPG_REGISTRATION');
        if ($env !== false) {
            return !in_array(strtolower((string) $env), ['0', 'off', 'false', 'no'], true);
        }
        return $this->setting('registration_open', '1') !== '0';
    }

    public function setRegistrationOpen(bool $open): void
    {
        $this->db->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute(['registration_open', $open ? '1' : '0']);
    }

    /** True when the environment has taken the decision out of the admin's hands. */
    public function registrationLockedByEnv(): bool
    {
        return getenv('RPG_REGISTRATION') !== false;
    }

    private function setting(string $key, string $default): string
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    // =======================================================================
    // Gates
    // =======================================================================

    /**
     * These throw rather than emitting a response, so one class serves both the
     * JSON API and the HTML pages — api/index.php already turns an
     * InvalidArgumentException into a 400 and can map these to 401/403, while a
     * page catches them and redirects to the login form.
     */
    public function requireUser(): array
    {
        $u = $this->currentUser();
        if ($u === null) {
            throw new AuthRequiredException('Sign in to continue.');
        }
        return $u;
    }

    public function requireAdmin(): array
    {
        $u = $this->requireUser();
        if ($u['role'] !== self::ROLE_ADMIN) {
            throw new AuthForbiddenException('That is for administrators.');
        }
        return $u;
    }

    // =======================================================================
    // Bearer tokens
    // =======================================================================

    /**
     * Issue a token for this account. The secret is returned once.
     *
     * @return array{token: string, expires_at: string}
     */
    public function issueToken(int $userId, string $label = 'api'): array
    {
        $label = preg_replace('/[^A-Za-z0-9_.-]/', '', $label) ?? '';
        $label = substr($label, 0, 60);
        if ($label === '') {
            $label = 'api';
        }

        $this->pruneTokens($userId);

        $raw = 'rpg_' . bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+' . self::TOKEN_TTL_SECONDS . ' seconds'));
        $this->db->prepare(
            'INSERT INTO api_tokens (user_id, token_hash, label, expires_at)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $userId,
            hash('sha256', $raw),
            $label,
            $expires->format('Y-m-d H:i:s'),
        ]);

        return [
            'token'      => $raw,
            'expires_at' => $expires->format('c'),
        ];
    }

    /** Revoke every live token for this account. */
    public function revokeAllTokens(int $userId): void
    {
        $this->db->prepare(
            'UPDATE api_tokens SET revoked_at = NOW()
              WHERE user_id = ? AND revoked_at IS NULL'
        )->execute([$userId]);
        if ($this->tokenId !== null) {
            $this->tokenId = null;
        }
    }

    /**
     * Write the play-state bag back onto the token that authenticated this
     * request. A no-op for cookie sessions, and for a token already revoked
     * this request (logout).
     */
    public function persistTokenState(): void
    {
        if ($this->tokenId === null) {
            return;
        }
        $state = [];
        foreach (self::TOKEN_STATE_KEYS as $key) {
            if (array_key_exists($key, $_SESSION)) {
                $state[$key] = $_SESSION[$key];
            }
        }
        $this->db->prepare(
            'UPDATE api_tokens SET state_json = ?, last_used_at = NOW() WHERE id = ? AND revoked_at IS NULL'
        )->execute([
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->tokenId,
        ]);
    }

    private static function bearerToken(): ?string
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
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }
        return $m[1];
    }

    private function userFromToken(string $raw): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id AS token_id, t.state_json,
                    u.id, u.username, u.email, u.role, u.is_active, u.last_login_at
               FROM api_tokens t
               INNER JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ?
                AND t.revoked_at IS NULL
                AND t.expires_at > NOW()'
        );
        $stmt->execute([hash('sha256', $raw)]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['is_active'] !== 1) {
            return null;
        }

        $this->tokenId = (int) $row['token_id'];
        $this->hydrateTokenState($row['state_json'] ?? null);
        $this->registerPersist();

        unset($row['token_id'], $row['state_json']);
        return $row;
    }

    private function hydrateTokenState(mixed $json): void
    {
        $decoded = [];
        if (is_string($json) && $json !== '') {
            $parsed = json_decode($json, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        } elseif (is_array($json)) {
            $decoded = $json;
        }
        foreach (self::TOKEN_STATE_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $_SESSION[$key] = $decoded[$key];
            } else {
                unset($_SESSION[$key]);
            }
        }
    }

    private function registerPersist(): void
    {
        if ($this->persistRegistered) {
            return;
        }
        $this->persistRegistered = true;
        register_shutdown_function(function () {
            $this->persistTokenState();
        });
    }

    private function revokeTokenId(int $tokenId): void
    {
        $this->db->prepare(
            'UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL'
        )->execute([$tokenId]);
    }

    private function pruneTokens(int $userId): void
    {
        $this->db->prepare(
            'UPDATE api_tokens SET revoked_at = NOW()
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at < NOW()'
        )->execute([$userId]);

        $stmt = $this->db->prepare(
            'SELECT id FROM api_tokens
              WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
              ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $over = count($ids) - self::TOKEN_MAX_LIVE + 1;
        if ($over <= 0) {
            return;
        }
        $drop = array_slice($ids, 0, $over);
        $placeholders = implode(',', array_fill(0, count($drop), '?'));
        $this->db->prepare(
            "UPDATE api_tokens SET revoked_at = NOW() WHERE id IN ({$placeholders})"
        )->execute($drop);
    }

    /** Forget the memoised user. For tests that switch identity mid-process. */
    public static function forgetCache(): void
    {
        self::$cached = null;
    }
}
