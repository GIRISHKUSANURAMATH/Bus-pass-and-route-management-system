<?php
/**
 * Authentication + role-based access control.
 *
 * Every protected page/endpoint calls require_role(...) at the top.
 * The role is always read from the server-side session and re-checked
 * against the database on each request - never from the browser.
 */
declare(strict_types=1);

const ROLES = ['student', 'driver', 'admin'];

function dashboard_url(string $role): string
{
    return url($role . '/dashboard.php');
}

/** The logged-in user row (cached per request), or null. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }
    $row = q_one('SELECT id, role, email, username, full_name, phone, status FROM users WHERE id = ?', [$id]);
    // Account deleted, deactivated, or role changed since login => force logout.
    if ($row === null || $row['status'] !== 'active' || $row['role'] !== ($_SESSION['role'] ?? '')) {
        logout_user();
        return null;
    }
    $user = $row;
    return $user;
}

function current_student(): ?array
{
    static $s = false;
    if ($s === false) {
        $u = current_user();
        $s = ($u && $u['role'] === 'student')
            ? q_one('SELECT * FROM students WHERE user_id = ?', [$u['id']])
            : null;
    }
    return $s;
}

function current_driver(): ?array
{
    static $d = false;
    if ($d === false) {
        $u = current_user();
        $d = ($u && $u['role'] === 'driver')
            ? q_one('SELECT * FROM drivers WHERE user_id = ?', [$u['id']])
            : null;
    }
    return $d;
}

/**
 * Gatekeeper. Pages are redirected to the login page; API endpoints
 * (which define API_REQUEST) receive a JSON 401/403 instead.
 */
function require_role(string ...$roles): array
{
    $user = current_user();
    $api  = defined('API_REQUEST');
    if ($user === null) {
        if ($api) {
            json_response(['ok' => false, 'error' => 'Authentication required.'], 401);
        }
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? null;
        $role = in_array($roles[0] ?? '', ROLES, true) ? $roles[0] : 'student';
        redirect('login.php?role=' . $role);
    }
    if (!in_array($user['role'], $roles, true)) {
        audit_log('access_denied', null, null, ['path' => $_SERVER['SCRIPT_NAME'] ?? '', 'needed' => $roles]);
        if ($api) {
            json_response(['ok' => false, 'error' => 'You are not allowed to do that.'], 403);
        }
        http_response_code(403);
        render_error_page(403, 'Access denied', 'Your account does not have permission to open this page.');
    }
    return $user;
}

/** Number of recent failures for an identifier+IP and for the IP alone. */
function login_is_locked(string $identifier, string $ip): int
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_WINDOW_MINUTES * 60);
    $acct = (int) q_val(
        'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND ip_address = ? AND success = 0 AND attempted_at > ?',
        [$identifier, $ip, $since]
    );
    $ipFails = (int) q_val(
        'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > ?',
        [$ip, $since]
    );
    if ($acct >= LOGIN_MAX_FAILS_PER_ACCOUNT || $ipFails >= LOGIN_MAX_FAILS_PER_IP) {
        $oldest = q_val(
            'SELECT MIN(attempted_at) FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at > ?',
            [$ip, $since]
        );
        $unlock = strtotime((string) $oldest) + LOGIN_LOCK_WINDOW_MINUTES * 60;
        return max(1, (int) ceil(($unlock - time()) / 60));
    }
    return 0;
}

function record_login_attempt(string $identifier, string $ip, bool $success): void
{
    q('INSERT INTO login_attempts (identifier, ip_address, success, attempted_at) VALUES (?, ?, ?, ?)',
        [$identifier, $ip, $success ? 1 : 0, date('Y-m-d H:i:s')]);
    if ($success) {
        // a successful login clears this account's failure counter
        q('DELETE FROM login_attempts WHERE identifier = ? AND ip_address = ? AND success = 0', [$identifier, $ip]);
    }
}

/**
 * @return array{ok:bool, error?:string, user?:array}
 */
function attempt_login(string $identifier, string $password, string $role): array
{
    $identifier = mb_strtolower(trim($identifier));
    $ip = client_ip();

    if ($identifier === '' || $password === '' || !in_array($role, ROLES, true)) {
        return ['ok' => false, 'error' => 'Please enter your login ID and password.'];
    }
    $lockedFor = login_is_locked($identifier, $ip);
    if ($lockedFor > 0) {
        audit_log('login_locked', 'user', null, ['identifier' => $identifier, 'role' => $role]);
        return ['ok' => false, 'error' => "Too many failed attempts. Try again in about {$lockedFor} minute(s)."];
    }

    if ($role === 'student') {
        $user = q_one(
            'SELECT u.* FROM users u JOIN students s ON s.user_id = u.id
             WHERE u.role = "student" AND (LOWER(u.email) = ? OR LOWER(s.usn) = ?) LIMIT 1',
            [$identifier, $identifier]
        );
    } else {
        $user = q_one(
            'SELECT * FROM users WHERE role = ? AND (LOWER(email) = ? OR LOWER(username) = ?) LIMIT 1',
            [$role, $identifier, $identifier]
        );
    }

    // Always run password_verify so response time does not reveal whether the account exists.
    $hash = $user['password_hash'] ?? '$2y$10$ImtFUzXmMKMVe7s97.4WCupCARSLHbaDUpkTTrf8jeXj/SkQEDlxi';
    $valid = password_verify($password, $hash) && $user !== null;

    if (!$valid) {
        record_login_attempt($identifier, $ip, false);
        audit_log('login_failed', 'user', $user['id'] ?? null, ['identifier' => $identifier, 'role' => $role]);
        return ['ok' => false, 'error' => 'Invalid login ID or password.'];
    }
    if ($user['status'] !== 'active') {
        record_login_attempt($identifier, $ip, false);
        return ['ok' => false, 'error' => 'This account is inactive. Please contact the college transport office.'];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    record_login_attempt($identifier, $ip, true);
    login_user($user);
    return ['ok' => true, 'user' => $user];
}

function login_user(array $user): void
{
    // Session fixation protection: brand-new session ID on privilege change.
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['created_at']    = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $user['id']]);
    audit_log('login_success', 'user', (int) $user['id'], ['role' => $user['role']], (int) $user['id'], $user['role']);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
