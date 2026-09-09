<?php
/**
 * Admin authentication for the template editor under public/admin/.
 *
 * Deliberately minimal: a single shared admin password, hashed with
 * password_hash() and stored in config.php as ADMIN_PASSWORD_HASH --
 * there are no admin user accounts in the database and no registration
 * flow, because this app has exactly one administrator role and adding
 * a user table would be more surface area than it's worth here.
 * Generate the hash with scripts/make_admin_hash.php.
 *
 * If ADMIN_PASSWORD_HASH is missing or still the placeholder, the admin
 * area refuses every login rather than falling open -- an unconfigured
 * deployment must never end up with a publicly editable template.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

const ADMIN_SESSION_KEY = 'admin_authenticated';

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(SESSION_NAME);

    // Only mark the cookie Secure when the request actually arrived over
    // HTTPS -- doing it unconditionally would silently break a plain-HTTP
    // deployment (the browser would drop the cookie and login would loop).
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);

    session_start();
}

/**
 * True only when config.php carries a real bcrypt/argon hash. The
 * placeholder shipped in config.php.example doesn't count.
 */
function admin_is_configured(): bool
{
    if (!defined('ADMIN_PASSWORD_HASH')) {
        return false;
    }
    $hash = (string) ADMIN_PASSWORD_HASH;

    return $hash !== '' && str_starts_with($hash, '$') && password_get_info($hash)['algo'] !== null;
}

function admin_is_logged_in(): bool
{
    if (empty($_SESSION[ADMIN_SESSION_KEY])) {
        return false;
    }

    // Idle timeout -- an admin session left open on a shared machine
    // shouldn't stay usable indefinitely.
    $timeout = defined('ADMIN_SESSION_TIMEOUT') ? (int) ADMIN_SESSION_TIMEOUT : 3600;
    if ($timeout > 0
        && isset($_SESSION['admin_last_seen'])
        && (time() - (int) $_SESSION['admin_last_seen']) > $timeout) {
        admin_logout();
        return false;
    }

    $_SESSION['admin_last_seen'] = time();
    return true;
}

/**
 * Gate for every admin page. Redirects to the login screen instead of
 * rendering anything when the visitor isn't authenticated.
 */
function require_admin(): void
{
    if (!admin_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function admin_login(): void
{
    // New session id on privilege change, so a session id an attacker
    // may have planted before login can't become an authenticated one.
    session_regenerate_id(true);
    $_SESSION[ADMIN_SESSION_KEY] = true;
    $_SESSION['admin_last_seen'] = time();
}

function admin_logout(): void
{
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION['admin_last_seen']);
    session_regenerate_id(true);
}

function admin_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Brute-force throttle. Failed attempts are counted per IP in the
 * database rather than in the session, since anything session-based is
 * bypassed by simply not sending the cookie back.
 */
function admin_login_locked_out(string $ip): bool
{
    $max = defined('ADMIN_MAX_LOGIN_ATTEMPTS') ? (int) ADMIN_MAX_LOGIN_ATTEMPTS : 5;
    if ($max <= 0) {
        return false;
    }

    // The window is an int from config, interpolated because MySQL
    // won't take a placeholder for an INTERVAL unit's operand in every
    // server version -- the cast is what keeps this safe, and the IP
    // (the only visitor-influenced value here) is still bound.
    $minutes = admin_lockout_minutes();
    $stmt = get_db()->prepare(
        'SELECT COUNT(*) FROM admin_login_attempts
         WHERE ip_address = ? AND succeeded = 0
           AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)'
    );
    $stmt->execute([$ip]);

    return (int) $stmt->fetchColumn() >= $max;
}

function admin_lockout_minutes(): int
{
    $minutes = defined('ADMIN_LOCKOUT_MINUTES') ? (int) ADMIN_LOCKOUT_MINUTES : 15;
    return $minutes > 0 ? $minutes : 15;
}

function admin_record_login_attempt(string $ip, bool $succeeded): void
{
    $db = get_db();
    $stmt = $db->prepare(
        'INSERT INTO admin_login_attempts (ip_address, succeeded) VALUES (?, ?)'
    );
    $stmt->execute([$ip, $succeeded ? 1 : 0]);

    // Opportunistic pruning -- keeps the table from growing without
    // bound without needing a cron job for it. Housekeeping must never
    // be able to break a login, so a failure here is swallowed: the
    // attempt itself is already recorded above, and the only cost of a
    // skipped prune is some stale rows.
    if (random_int(1, 20) === 1) {
        try {
            $db->exec('DELETE FROM admin_login_attempts WHERE attempted_at < (NOW() - INTERVAL 7 DAY)');
        } catch (Throwable $e) {
            // Ignored on purpose.
        }
    }
}

/**
 * A successful login clears that IP's failed-attempt history, so a
 * legitimate admin who mistyped a few times isn't still half-locked-out
 * on their next visit.
 */
function admin_clear_login_attempts(string $ip): void
{
    $stmt = get_db()->prepare('DELETE FROM admin_login_attempts WHERE ip_address = ? AND succeeded = 0');
    $stmt->execute([$ip]);
}

/**
 * Headers for every admin page: keep them out of search indexes and out
 * of frames, and don't leak the admin URL as a referrer to whatever a
 * template body links to.
 */
function admin_send_headers(): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}
