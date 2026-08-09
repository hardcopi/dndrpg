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

    private PDO $db;

    /** Memoised for the request: currentUser() is asked on nearly every route. */
    private static ?array $cached = null;

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
        unset(
            $_SESSION[self::SESSION_KEY],
            $_SESSION['character_id'],
            $_SESSION['party_id']
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

    /** Forget the memoised user. For tests that switch identity mid-process. */
    public static function forgetCache(): void
    {
        self::$cached = null;
    }
}
