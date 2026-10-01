<?php
/**
 * includes/auth.php
 * --------------------------------------------------------------------
 * Authentication + role helpers for Isoko Ryacu.
 *
 *   register_user($data)        — create a new user (bcrypt hash)
 *   login_user($email,$pass)     — verify + start session
 *   logout_user()               — destroy session
 *   current_user()              — array | null
 *   is_logged_in()              — bool
 *   require_login()             — redirect to login if not authed
 *   require_role($role)         — 403 if user lacks role
 *   send_reset_token($email)    — generate + (stub) email reset token
 *   verify_reset_token($token)  — find user
 *   reset_password($token,$pwd) — change password and clear token
 *
 * Security:
 *   - password_hash with bcrypt
 *   - prepared statements (no SQL injection)
 *   - session regenerated on login to prevent fixation
 *   - role checks centralized here
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

start_session();

/**
 * Role-name constants used by the registration flow.
 *
 * The project's canonical role names are uppercase (ADMIN, SELLER, USER,
 * SUPER_ADMIN) — see phase3_migrate.sql and require_role() above.
 *
 *   USER    — the standard "buyer" role. Every public registration defaults
 *             to this role unless the server-side route context explicitly
 *             requests 'seller'.
 *   SELLER  — a user who can publish listings. A seller can also do
 *             everything a buyer can do.
 *
 * For backward compatibility with databases seeded before phase3_migrate.sql
 * was applied (where roles were named 'admin', 'seller', 'buyer' in
 * lowercase), the legacy lowercase names are also accepted as fallbacks when
 * looking up the role id.
 *
 * NOTE: We intentionally do NOT hardcode the role *id*s here. The ids are
 * resolved at runtime from the roles table so that registration keeps working
 * even if the roles table has been re-seeded with different auto-increment
 * values. This is what prevents the foreign-key violation on users.role_id.
 *
 * SECURITY: The only roles that can EVER be assigned through public
 * registration are USER (buyer) and SELLER. ADMIN and SUPER_ADMIN can never
 * be requested through the registration form — there is no code path that
 * accepts those names from a browser request. See register_user() below.
 */
const REGISTRATION_ROLE_BUYER        = 'USER';       // canonical name
const REGISTRATION_ROLE_BUYER_LEGACY = 'buyer';      // pre-phase3 fallback
const REGISTRATION_ROLE_SELLER       = 'SELLER';     // canonical name
const REGISTRATION_ROLE_SELLER_LEGACY = 'seller';    // pre-phase3 fallback

/**
 * Whitelist of role hints that register_user() will accept. Anything not in
 * this list (including 'admin', 'super_admin', or random strings) is silently
 * downgraded to 'buyer'. This is the privilege-escalation guard: a browser
 * cannot cause an admin account to be created, no matter what URL parameter
 * or form field it sends.
 */
const ALLOWED_REGISTRATION_ROLE_HINTS = ['buyer', 'seller'];

/**
 * Resolve the numeric id of a role by its name, trying both the canonical
 * (uppercase) name and the legacy (lowercase) name.  If the role does not
 * exist and $createIfMissing is true, create it idempotently (INSERT IGNORE
 * on the UNIQUE name column) and return the new id.  Otherwise return null.
 *
 * @param string $roleName       Canonical role name (e.g. 'USER', 'SELLER').
 * @param string $legacyName     Pre-phase3 lowercase fallback (e.g. 'buyer').
 * @param bool   $createIfMissing If true and the role is missing, create it.
 * @return int|null  The role id, or null if it could not be resolved/created.
 */
function resolve_role_id_by_name(string $roleName, string $legacyName = '', bool $createIfMissing = false): ?int
{
    $pdo = db();

    // Try the canonical name, then the legacy name (case-insensitive lookup
    // so we are robust to past seeding inconsistencies).
    foreach (array_filter([$roleName, $legacyName]) as $tryName) {
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE UPPER(name) = UPPER(?) LIMIT 1');
        $stmt->execute([$tryName]);
        $row = $stmt->fetch();
        if ($row && (int) $row['id'] > 0) {
            return (int) $row['id'];
        }
    }

    if (!$createIfMissing) {
        return null;
    }

    // Role is missing — create it idempotently. INSERT IGNORE honours the
    // UNIQUE constraint on roles.name, so this never produces duplicates
    // even under concurrent requests.
    $desc = $roleName === 'USER'
        ? 'Standard user (buyer + seller)'
        : ($roleName === 'SELLER' ? 'Can publish listings + act as buyer' : $roleName);
    $pdo->prepare(
        'INSERT IGNORE INTO roles (name, description) VALUES (?, ?)'
    )->execute([$roleName, $desc]);

    // Re-fetch to get the actual id (whether we just inserted it or it was
    // concurrently created by another request).
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE UPPER(name) = UPPER(?) LIMIT 1');
    $stmt->execute([$roleName]);
    $row = $stmt->fetch();
    return $row && (int) $row['id'] > 0 ? (int) $row['id'] : null;
}

/**
 * Resolve the role id for a public registration, given a server-side role
 * hint ('buyer' or 'seller').  The hint is validated against the
 * ALLOWED_REGISTRATION_ROLE_HINTS whitelist; anything else (including
 * 'admin', 'super_admin', or empty) defaults to 'buyer'.
 *
 * This function NEVER returns the id of an ADMIN or SUPER_ADMIN role —
 * those roles can only be created through the SQL seed/migration, never
 * through public registration.
 *
 * @param string $roleHint  'buyer' or 'seller' (anything else → 'buyer').
 * @return int|null  The role id, or null if it could not be resolved/created.
 */
function resolve_registration_role_id(string $roleHint): ?int
{
    // SECURITY: normalize + whitelist the hint. This is the privilege-
    // escalation guard — no browser input can ever produce an admin role_id.
    $hint = strtolower(trim($roleHint));
    if (!in_array($hint, ALLOWED_REGISTRATION_ROLE_HINTS, true)) {
        $hint = 'buyer';
    }

    if ($hint === 'seller') {
        return resolve_role_id_by_name(
            REGISTRATION_ROLE_SELLER,
            REGISTRATION_ROLE_SELLER_LEGACY,
            true   // create the SELLER role if it's missing (idempotent)
        );
    }

    // Default: buyer (USER role)
    return resolve_role_id_by_name(
        REGISTRATION_ROLE_BUYER,
        REGISTRATION_ROLE_BUYER_LEGACY,
        true   // create the USER role if it's missing (idempotent)
    );
}

/**
 * Centralized role-based redirect used by both register.php and login.php.
 *
 * Routing rules (use the ACTUAL dashboard paths that exist in this project):
 *   ADMIN / SUPER_ADMIN → /pages/admin/dashboard.php
 *   SELLER              → /pages/seller/dashboard.php
 *   USER (buyer)        → /pages/buyer/dashboard.php
 *
 * Every role now gets its own dedicated dashboard — admins, sellers, AND
 * buyers. This is the "professional" routing: no role is sent to a bare
 * homepage; each user lands on a real dashboard with their own KPIs,
 * recent activity, and quick actions.
 *
 * If $next is provided and is a safe same-app URL, it takes precedence over
 * the role-based default (so deep-links like ?next=/pages/favorites.php still
 * work). Admins are always sent to the admin dashboard unless an explicit
 * same-app $next was requested.
 *
 * @param array|null $user  The current user array (defaults to current_user()).
 * @param string $next       Optional override URL (must be same-app, absolute path).
 * @return string            The absolute URL to redirect to.
 */
function redirect_after_auth_url(?array $user = null, string $next = ''): string
{
    $user = $user ?? current_user();
    $roleName = strtoupper($user['role_name'] ?? '');
    $base = APP_URL;

    // If a same-app $next URL was explicitly requested, honour it (this is
    // what makes ?next=/pages/some-page.php work after login). We validate
    // it strictly to prevent open-redirect attacks.
    if ($next !== '') {
        // Normalise: turn relative paths into absolute app URLs
        if (str_starts_with($next, '/')) {
            $next = $base . $next;
        }
        // Only accept URLs that start with our own APP_URL
        if (str_starts_with($next, $base . '/')) {
            return $next;
        }
    }

    // Role-based default routing — every role gets a real dashboard
    return match ($roleName) {
        'ADMIN', 'SUPER_ADMIN' => $base . '/pages/admin/dashboard.php',
        'SELLER'               => $base . '/pages/dashboard.php',
        'USER'                 => $base . '/pages/dashboard.php',
        default                => $base . '/pages/dashboard.php',
    };
}

/**
 * Returns true if the current user has seller capability — i.e. they can
 * create and manage listings. This is true when EITHER:
 *   - their role is SELLER / ADMIN / SUPER_ADMIN, OR
 *   - their users.is_seller flag is 1 (a buyer who activated seller mode
 *     via /pages/sell.php).
 *
 * This is the capability model the project already uses: a single account
 * can both buy AND sell without creating a second account.
 */
function is_seller_capability(): bool
{
    $u = current_user();
    if (!$u) return false;
    $rn = strtoupper($u['role_name'] ?? '');
    if (in_array($rn, ['SELLER','ADMIN','SUPER_ADMIN'], true)) return true;
    return ((int)($u['is_seller'] ?? 0)) === 1;
}

/**
 * Create a new account. Returns ['id'=>...] on success or ['error'=>'msg'].
 *
 * Robustness contract:
 *   - The role is chosen SERVER-SIDE via $data['role_hint'] (whitelisted to
 *     'buyer' or 'seller'; anything else, including 'admin', defaults to
 *     'buyer'). This is the privilege-escalation guard — a browser cannot
 *     cause an admin account to be created, no matter what it sends.
 *   - Resolves the role_id by NAME from the roles table (never hardcoded),
 *     so the insert always satisfies the fk_users_role constraint as long
 *     as the roles table contains (or can be given) the required role.
 *   - For seller registrations, also sets users.is_seller = 1 and creates
 *     a seller_profiles row (idempotent), so the seller can immediately
 *     access /pages/seller/dashboard.php and /pages/sell.php.
 *   - Wraps the uniqueness check + insert in a transaction so a race between
 *     two concurrent registrations with the same email cannot create a
 *     duplicate row. The UNIQUE index on users.email is the final backstop,
 *     and we translate that PDO error code into a clean user-facing message.
 *   - Catches every PDOException and returns a clean, translated
 *     application-level error. The raw exception / SQLSTATE / stack trace is
 *     never surfaced to the public registration page (server-side logging
 *     via error_log when APP_DEBUG is on).
 *
 * @param array $data  Must contain: full_name, email, password.
 *                     May contain: phone, location, role_hint ('buyer'|'seller').
 *                     role_hint is ONLY read from server-side code, never from
 *                     $_POST directly — see pages/register.php.
 * @return array  ['id'=>int] on success, ['error'=>string] on failure.
 */
function register_user(array $data): array
{
    $fullName = trim($data['full_name'] ?? '');
    $email    = trim($data['email'] ?? '');
    $phone    = trim($data['phone'] ?? '');
    $location = trim($data['location'] ?? '');
    $password = (string)($data['password'] ?? '');
    $roleHint = (string)($data['role_hint'] ?? 'buyer');

    // --- Server-side validation ---
    if ($fullName === '' || mb_strlen($fullName) < 3) {
        return ['error' => t('errors.name_short')];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => t('errors.email_invalid')];
    }
    if (strlen($password) < 8) {
        return ['error' => t('errors.password_short')];
    }

    // --- Hash password (done before any DB write so we don't open a
    //     transaction just to fail on hashing) ---
    $hash = password_hash($password, HASH_ALGO, ['cost' => HASH_COST]);
    if ($hash === false) {
        return ['error' => t('errors.password_hash')];
    }

    // --- Resolve the role id by NAME (never hardcoded). The hint is
    //     whitelisted inside resolve_registration_role_id() — 'admin',
    //     'super_admin', or any unknown value defaults to 'buyer'. ---
    try {
        $roleId = resolve_registration_role_id($roleHint);
    } catch (PDOException $e) {
        if (APP_DEBUG) error_log('[register_user] role lookup failed: ' . $e->getMessage());
        return ['error' => t('errors.registration_failed')];
    }
    if ($roleId === null) {
        // Roles table is missing AND we could not create the required role.
        // Do NOT attempt the INSERT — it would just throw the FK error again.
        if (APP_DEBUG) error_log('[register_user] registration role could not be resolved for hint: ' . $roleHint);
        return ['error' => t('errors.registration_failed')];
    }

    // Determine whether this is a seller registration (used later to set
    // is_seller flag + create seller_profiles row).
    $isSellerRegistration = (strtolower(trim($roleHint)) === 'seller');

    // --- Insert inside a transaction so concurrent registrations with the
    //     same email are serialised and the UNIQUE constraint is enforced
    //     cleanly. We catch the duplicate-key PDO error code (23000 / 1062)
    //     and translate it to the same "email taken" message the pre-check
    //     uses, so the user sees a consistent message either way. ---
    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Re-check email uniqueness inside the transaction (race-safe).
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            return ['error' => t('errors.email_taken')];
        }

        // Insert the user. For seller registrations, also set is_seller = 1
        // so /pages/sell.php doesn't try to re-promote them and so the
        // seller dashboard is immediately accessible.
        $stmt = $pdo->prepare(
            'INSERT INTO users (role_id, full_name, email, password_hash, phone, location, is_seller, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "active")'
        );
        $stmt->execute([
            $roleId, $fullName, $email, $hash,
            $phone ?: null, $location ?: null,
            $isSellerRegistration ? 1 : 0,
        ]);

        $newId = (int) $pdo->lastInsertId();

        // For seller registrations, create the seller_profiles row
        // idempotently (INSERT IGNORE — the user_id column is UNIQUE, so
        // this is safe even if a row somehow already exists).
        if ($isSellerRegistration) {
            try {
                $pdo->prepare(
                    'INSERT IGNORE INTO seller_profiles (user_id) VALUES (?)'
                )->execute([$newId]);
            } catch (PDOException $sp) {
                // seller_profiles table might not exist in older DBs that
                // haven't run phase2_migrate.sql. Log and continue — the
                // user was already inserted, so registration succeeds; the
                // seller just won't have a profile row until the migration
                // is run. This is non-fatal.
                if (APP_DEBUG) {
                    error_log('[register_user] seller_profiles insert skipped: ' . $sp->getMessage());
                }
            }
        }

        $pdo->commit();
        return ['id' => $newId, 'role_hint' => $isSellerRegistration ? 'seller' : 'buyer'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (PDOException $rb) { /* ignore */ }
        }

        // MySQL duplicate-key violation on users.email → friendly message.
        // SQLSTATE 23000 + driver code 1062 = duplicate entry.
        if ($e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['error' => t('errors.email_taken')];
        }

        // Any other DB failure (including an FK violation, should the roles
        // table be in a state we did not anticipate) is translated into a
        // clean, generic, translated message. Technical details are logged
        // server-side only.
        if (APP_DEBUG) {
            error_log('[register_user] insert failed: ' . $e->getMessage()
                     . ' (code=' . $e->getCode() . ', role_id=' . $roleId . ')');
        }
        return ['error' => t('errors.registration_failed')];
    }
}

/**
 * Attempt to log in. Returns true on success, false otherwise.
 */
/**
 * Ensure the account-level login-attempt columns exist on older databases.
 * We keep this backwards compatible so an existing Isoko Ryacu installation
 * does not require a destructive schema rebuild.
 */
function ensure_login_attempt_storage(): void
{
    static $done = false;
    if ($done) return;

    try {
        $pdo = db();
        $cols = [];
        $q = $pdo->query('SHOW COLUMNS FROM users');
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[strtolower((string) $row['Field'])] = true;
        }
        if (!isset($cols['failed_login_attempts'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN failed_login_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER status');
        }
        if (!isset($cols['login_locked_until'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN login_locked_until DATETIME NULL AFTER failed_login_attempts');
        }
        $done = true;
    } catch (Throwable $e) {
        // Never expose schema details to a visitor. The migration SQL shipped
        // with the project can also be run manually by the administrator.
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log('[login-security] storage check failed: ' . $e->getMessage());
        }
    }
}

/**
 * Attempt to log in with account-level brute-force protection.
 *
 * Policy: 5 wrong passwords in a row => 15-minute lockout. A successful
 * login resets the counter. The user-facing message remains deliberately
 * generic and never reveals whether an email exists.
 */
function login_user(string $email, string $password): bool
{
    ensure_login_attempt_storage();
    start_session();
    unset($_SESSION['_login_feedback']);

    $pdo = db();
    $maxAttempts = max(1, (int) (defined('LOGIN_MAX_ATTEMPTS') ? LOGIN_MAX_ATTEMPTS : 5));
    $lockMinutes = max(1, (int) (defined('LOGIN_LOCKOUT_MINUTES') ? LOGIN_LOCKOUT_MINUTES : 15));

    $stmt = $pdo->prepare(
        'SELECT id, role_id, full_name, email, password_hash, avatar_path, is_seller, status,
                COALESCE(failed_login_attempts, 0) AS failed_login_attempts, login_locked_until
         FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Do not disclose whether the account/email exists.
    if (!$user) {
        $_SESSION['_login_feedback'] = ['type' => 'invalid', 'remaining' => null];
        return false;
    }
    if ($user['status'] !== 'active') {
        $_SESSION['_login_feedback'] = ['type' => 'invalid', 'remaining' => null];
        return false;
    }

    // An active lock blocks authentication even if the password is correct.
    if (!empty($user['login_locked_until'])) {
        $lockedUntilTs = strtotime((string) $user['login_locked_until']);
        if ($lockedUntilTs !== false && $lockedUntilTs > time()) {
            $seconds = $lockedUntilTs - time();
            $_SESSION['_login_feedback'] = [
                'type' => 'locked',
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ];
            return false;
        }

        // Lockout expired: start a fresh attempt window.
        $pdo->prepare(
            'UPDATE users SET failed_login_attempts = 0, login_locked_until = NULL WHERE id = ?'
        )->execute([(int) $user['id']]);
        $user['failed_login_attempts'] = 0;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) ($user['failed_login_attempts'] ?? 0) + 1;

        if ($attempts >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
            $pdo->prepare(
                'UPDATE users SET failed_login_attempts = ?, login_locked_until = ? WHERE id = ?'
            )->execute([$attempts, $lockedUntil, (int) $user['id']]);

            $_SESSION['_login_feedback'] = [
                'type' => 'locked',
                'minutes' => $lockMinutes,
            ];
        } else {
            $pdo->prepare(
                'UPDATE users SET failed_login_attempts = ?, login_locked_until = NULL WHERE id = ?'
            )->execute([$attempts, (int) $user['id']]);

            $_SESSION['_login_feedback'] = [
                'type' => 'invalid',
                'remaining' => $maxAttempts - $attempts,
            ];
        }
        return false;
    }

    // Correct password: reset the failed-attempt counter and unlock account.
    $pdo->prepare(
        'UPDATE users SET failed_login_attempts = 0, login_locked_until = NULL WHERE id = ?'
    )->execute([(int) $user['id']]);

    // --- Persist user info in session (do NOT store the password hash) ---
    session_regenerate_id(true);
    // Start a fresh inactivity window after successful authentication.
    $_SESSION['_idle_last_activity'] = time();
    $_SESSION['user'] = [
        'id'         => (int) $user['id'],
        'role_id'    => (int) $user['role_id'],
        'role_name'  => role_name((int) $user['role_id']),
        'full_name'  => $user['full_name'],
        'email'      => $user['email'],
        'avatar'     => $user['avatar_path'],
        'is_seller'  => (int) ($user['is_seller'] ?? 0),
    ];

    return true;
}

/**
 * Destroy the current session and cookies.
 */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Return the currently logged-in user array, or null.
 */
function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!$user) return null;

    // Server-side idle timeout: never trust the browser alone. If there has
    // been no authenticated request for the configured period, destroy the
    // session before returning the user.
    $now = time();
    $lastActivity = (int) ($_SESSION['_idle_last_activity'] ?? $now);
    if (defined('IDLE_SESSION_TIMEOUT') && IDLE_SESSION_TIMEOUT > 0
        && ($now - $lastActivity) >= IDLE_SESSION_TIMEOUT) {
        logout_user();
        return null;
    }

    // A request made by an authenticated user counts as activity. The
    // browser-side heartbeat also reaches this function while the user is
    // actively using an open page.
    $_SESSION['_idle_last_activity'] = $now;

    // Keep a lightweight real last-seen heartbeat.  It is throttled so normal
    // page requests do not cause a database write on every call to current_user().
    $now = time();
    $lastTouch = (int) ($_SESSION['_last_seen_touch'] ?? 0);
    if ($now - $lastTouch >= 30) {
        try {
            db()->prepare("UPDATE users SET last_seen_at = NOW() WHERE id = ? AND status = 'active'")
                ->execute([(int) $user['id']]);
            $_SESSION['_last_seen_touch'] = $now;
        } catch (Throwable $e) {
            // Presence must never break authentication if an older database has
            // not yet run the presence migration.
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * Redirect guest users to the login page.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('info', t('errors.unauthorized'));
        redirect(APP_URL . '/pages/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
}

/**
 * Send a 403 if the user does not have the given role.
 *
 * Phase 3 role names (uppercase in DB):
 *   'SUPER_ADMIN' — full platform administration
 *   'ADMIN'       — standard admin
 *   'SELLER'      — can publish listings
 *   'USER'        — normal user (buyer + seller)
 *
 * The special value 'admin' is a shortcut that allows both ADMIN and
 * SUPER_ADMIN roles.
 */
function require_role(string $role): void
{
    require_login();
    $user = current_user();
    $userRole = $user['role_name'] ?? '';

    $allowed = match (strtolower($role)) {
        'super_admin' => ['SUPER_ADMIN'],
        'admin'       => ['ADMIN', 'SUPER_ADMIN'],
        'seller'      => ['SELLER', 'ADMIN', 'SUPER_ADMIN'],
        'user'        => ['USER', 'SELLER', 'ADMIN', 'SUPER_ADMIN'],
        default       => [strtolower($role)],
    };

    if (!in_array($userRole, $allowed, true)) {
        http_response_code(403);
        // Friendly translated 403 page (no internal details leaked)
        $title = t('errors.access_denied');
        $body  = t('errors.access_denied');
        http_response_code(403);
        echo "<!doctype html><html><head><title>403 — $title</title>"
           . "<meta charset=utf-8><meta name=viewport content='width=device-width, initial-scale=1'>"
           . "<style>body{font-family:Inter,sans-serif;background:#0b1817;color:#f0f7f6;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px;text-align:center}"
           . "h1{font-size:clamp(2rem,5vw,3rem);margin:0 0 10px;color:#14a594}"
           . "p{font-size:1.1rem;color:#b5c7c5;max-width:50ch;margin:0 auto 20px}"
           . "a{display:inline-block;padding:12px 24px;background:#14a594;color:#fff;text-decoration:none;border-radius:10px;font-weight:600}"
           . "</style></head><body>"
           . "<div><h1>403</h1><p>" . e($body) . "</p>"
           . "<a href='" . e(APP_URL) . "/'>" . e(t('nav.home')) . "</a></div>"
           . "</body></html>";
        exit;
    }
}

/**
 * Require the SUPER_ADMIN role specifically (not even standard ADMIN).
 */
function require_super_admin(): void
{
    require_role('super_admin');
}

/**
 * Returns true if the current user is an admin (ADMIN or SUPER_ADMIN).
 */
function is_admin(): bool
{
    $u = current_user();
    if (!$u) return false;
    return in_array($u['role_name'] ?? '', ['ADMIN', 'SUPER_ADMIN'], true);
}

/**
 * Returns true if the current user is the SUPER_ADMIN.
 */
function is_super_admin(): bool
{
    $u = current_user();
    return $u && ($u['role_name'] ?? '') === 'SUPER_ADMIN';
}

/**
 * Returns true if the current user is the primary Super Admin (cannot be removed).
 */
function is_primary_super_admin(): bool
{
    $u = current_user();
    return $u && ($u['role_name'] ?? '') === 'SUPER_ADMIN' && !empty($u['is_primary_super_admin']);
}

/**
 * All available admin permission keys.
 * Super Admins implicitly have ALL permissions.
 * Assistant Super Admins have a configurable subset.
 * Normal Admins have a default subset.
 */
function admin_permission_keys(): array
{
    return [
        'users'          => 'Manage Users',
        'sellers'        => 'Manage Sellers',
        'admins'         => 'Manage Administrators',
        'products'       => 'Manage Products',
        'categories'     => 'Manage Categories',
        'orders'         => 'Manage Orders',
        'payments'       => 'Manage Payments',
        'withdrawals'    => 'Manage Withdrawals',
        'delivery'       => 'Manage Delivery',
        'disputes'       => 'Manage Disputes',
        'reviews'        => 'Manage Reviews',
        'support'        => 'Manage Support',
        'promotions'     => 'Manage Promotions',
        'notifications'  => 'Manage Notifications',
        'reports'        => 'View Reports',
        'media'          => 'Manage Media',
        'content'        => 'Manage Content',
        'settings'       => 'Manage Settings',
        'audit_logs'     => 'View Audit Logs',
    ];
}

/**
 * Default permissions for a normal ADMIN (not assistant, not super).
 */
function default_admin_permissions(): array
{
    return ['users', 'sellers', 'products', 'categories', 'orders', 'reviews', 'reports', 'support'];
}

/**
 * Get the permissions array for the current user.
 * Super Admins get ALL permissions.
 * Others get their stored permissions (or defaults if none stored).
 */
function admin_permissions(): array
{
    $u = current_user();
    if (!$u) return [];
    if (($u['role_name'] ?? '') === 'SUPER_ADMIN') {
        return array_keys(admin_permission_keys());
    }
    // Try to load from admin_permissions table
    try {
        $stmt = db()->prepare('SELECT permissions FROM admin_permissions WHERE user_id = ?');
        $stmt->execute([(int)$u['id']]);
        $row = $stmt->fetchColumn();
        if ($row) {
            $perms = json_decode($row, true);
            return is_array($perms) ? $perms : [];
        }
    } catch (PDOException $e) {}
    // Default for normal admins
    if (($u['role_name'] ?? '') === 'ADMIN') {
        return default_admin_permissions();
    }
    return [];
}

/**
 * Check if the current user has a specific admin permission.
 * Super Admins always have all permissions.
 */
function has_admin_permission(string $permission): bool
{
    return in_array($permission, admin_permissions(), true);
}

/**
 * Require a specific admin permission (403 if not).
 * Super Admins always pass.
 */
function require_admin_permission(string $permission): void
{
    require_login();
    if (!has_admin_permission($permission)) {
        http_response_code(403);
        echo '<!doctype html><html><head><title>403 — Access Denied</title>'
           . '<meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1">'
           . '<style>body{font-family:Inter,sans-serif;background:#0b1817;color:#f0f7f6;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px;text-align:center}'
           . 'h1{font-size:clamp(2rem,5vw,3rem);margin:0 0 10px;color:#14a594}'
           . 'p{font-size:1.1rem;color:#b5c7c5;max-width:50ch;margin:0 auto 20px}'
           . 'a{display:inline-block;padding:12px 24px;background:#14a594;color:#fff;text-decoration:none;border-radius:10px;font-weight:600}'
           . '</style></head><body>'
           . '<div><h1>403</h1><p>You do not have permission to access this area.</p>'
           . '<a href="' . e(APP_URL) . '/pages/admin/dashboard.php">Back to Dashboard</a></div>'
           . '</body></html>';
        exit;
    }
}

/**
 * Returns true if the current user is an Assistant Super Admin.
 */
function is_assistant_super_admin(): bool
{
    $u = current_user();
    if (!$u) return false;
    if (($u['role_name'] ?? '') !== 'ADMIN') return false;
    try {
        $stmt = db()->prepare('SELECT is_assistant FROM admin_permissions WHERE user_id = ?');
        $stmt->execute([(int)$u['id']]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Appoint a user as Assistant Super Admin with given permissions.
 * Only callable by a Super Admin (server-side check).
 */
function appoint_assistant_admin(int $userId, array $permissions, int $grantedBy): bool
{
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO admin_permissions (user_id, is_assistant, permissions, granted_by, granted_at)
         VALUES (?, 1, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE is_assistant = 1, permissions = ?, granted_by = ?, updated_at = NOW()'
    )->execute([
        $userId, json_encode($permissions), $grantedBy,
        json_encode($permissions), $grantedBy,
    ]);
    // Promote role to ADMIN if not already
    $stmt = $pdo->prepare('SELECT r.name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id = ?');
    $stmt->execute([$userId]);
    $roleName = strtoupper($stmt->fetchColumn() ?: '');
    if (!in_array($roleName, ['ADMIN', 'SUPER_ADMIN'], true)) {
        $adminRole = $pdo->query("SELECT id FROM roles WHERE name = 'ADMIN' LIMIT 1")->fetchColumn();
        if ($adminRole) {
            $pdo->prepare('UPDATE users SET role_id = ?, is_seller = 1, updated_at = NOW() WHERE id = ?')
                ->execute([(int)$adminRole, $userId]);
        }
    }
    // Update session if it's the current user
    if ($userId === (int)(current_user()['id'] ?? 0)) {
        $_SESSION['user']['role_name'] = 'ADMIN';
    }
    return true;
}

/**
 * Revoke assistant super admin privileges (revert to normal admin).
 */
function revoke_assistant_admin(int $userId): bool
{
    $pdo = db();
    $pdo->prepare(
        'UPDATE admin_permissions SET is_assistant = 0, permissions = ?, updated_at = NOW() WHERE user_id = ?'
    )->execute([json_encode(default_admin_permissions()), $userId]);
    return true;
}

/**
 * Look up a role name by id.  Returns the role name in its DB case
 * (USER, SELLER, ADMIN, SUPER_ADMIN).
 */
function role_name(int $roleId): string
{
    static $cache = [];
    if (isset($cache[$roleId])) return $cache[$roleId];
    $stmt = db()->prepare('SELECT name FROM roles WHERE id = ?');
    $stmt->execute([$roleId]);
    $row = $stmt->fetch();
    // Normalize: always return uppercase role name
    return $cache[$roleId] = $row ? strtoupper($row['name']) : 'GUEST';
}

/**
 * Generate a password-reset token and store it (with expiry).
 * In production, send this via email. For Phase 1, we display it on the
 * forgot-password page so a developer can test the reset flow.
 */
function send_reset_token(string $email): ?array
{
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return null;

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
    $upd = db()->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?');
    $upd->execute([$token, $expires, $user['id']]);

    return ['token' => $token, 'expires' => $expires];
}

/**
 * Validate a reset token. Returns user array or null.
 */
function verify_reset_token(string $token): ?array
{
    if (strlen($token) !== 64) return null;
    $stmt = db()->prepare(
        'SELECT id, email FROM users
         WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Change the user's password using a valid reset token.
 */
function reset_password(string $token, string $newPassword): bool
{
    if (strlen($newPassword) < 8) return false;
    $user = verify_reset_token($token);
    if (!$user) return false;

    $hash = password_hash($newPassword, HASH_ALGO, ['cost' => HASH_COST]);
    $stmt = db()->prepare(
        'UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?'
    );
    return $stmt->execute([$hash, $user['id']]);
}

/**
 * Production safety net: warn (server log only — never shown to a visitor)
 * if a well-known demo/seed credential documented in this repository
 * (sql/seed.sql, sql/install_all.sql) is still present in the users table.
 * Those hashes and their plaintext passwords are public in source control,
 * so any account still using one is a live account-takeover risk.
 *
 * This never blocks the request and never appears in any HTTP response —
 * it only writes to the PHP error log so an operator notices it. Throttled
 * to at most once per hour via the app cache to avoid log spam or extra
 * DB load on every request.
 */
function warn_if_demo_credentials_active(): void
{
    if (!defined('APP_ENV') || APP_ENV !== 'production') return;
    if (!function_exists('app_cache_get') || !function_exists('app_cache_set')) return;

    $cacheKey = 'isoko:demo_cred_check';
    if (app_cache_get($cacheKey) !== null) return; // checked recently
    app_cache_set($cacheKey, 1, 3600);

    // Known demo password_hash values documented in plain text in
    // sql/seed.sql and sql/install_all.sql.
    $knownDemoHashes = [
        '$2y$10$up5zrOoUlz6T0i3MvNGtmOYrDD7CP9EVcubl3e5um0MYA2RslC7Pm', // ethiennemugisha35@gmail.com / password
        '$2y$10$XdY.1jpfSLXwayJRXTYoxu9HkQkkUGqDaaBp.c63km7IOXsJfbxiu', // admin@isoko.rw / Admin@12345
    ];

    try {
        $placeholders = implode(',', array_fill(0, count($knownDemoHashes), '?'));
        $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE password_hash IN ($placeholders)");
        $stmt->execute($knownDemoHashes);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            error_log(
                "[SECURITY WARNING] {$count} account(s) in production still use a password hash "
                . "that is documented in plain text in this repository's seed SQL files. "
                . "Rotate or disable these accounts immediately - the credentials are public."
            );
        }
    } catch (Throwable $e) {
        // Never let this check break the app.
    }
}
