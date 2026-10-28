<?php
/**
 * Security configuration: limits, HTTP security headers, secure sessions.
 */
declare(strict_types=1);

// --- Login throttling -------------------------------------------------
const LOGIN_MAX_FAILS_PER_ACCOUNT = 5;     // failures per identifier+IP ...
const LOGIN_MAX_FAILS_PER_IP      = 20;    // ... and per IP ...
const LOGIN_LOCK_WINDOW_MINUTES   = 15;    // ... inside this window => locked

// --- Uploads ------------------------------------------------------------
const UPLOAD_PAYMENT_MAX_BYTES = 2 * 1024 * 1024;   // 2 MB
const UPLOAD_PROFILE_MAX_BYTES = 1 * 1024 * 1024;   // 1 MB
const UPLOAD_QR_MAX_BYTES      = 3 * 1024 * 1024;   // 3 MB
const UPLOAD_IMAGE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// --- GPS ingestion --------------------------------------------------------
const GPS_MIN_INTERVAL_SECONDS = 2;        // per-driver rate limit
const GPS_MAX_SPEED_KMPH       = 150;      // reject impossible jumps (real GPS only)
const GPS_MAX_CLOCK_SKEW_SEC   = 600;      // device timestamp tolerance

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    // Geolocation only for our own pages (driver GPS).
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');
    // All JS/CSS is served locally; only OpenStreetMap tiles are remote images.
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: https://tile.openstreetmap.org; "
        . "font-src 'self'; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (config('security.force_https') && !is_https() && PHP_SAPI !== 'cli') {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    ini_set('session.use_strict_mode', '1');      // reject uninitialised session IDs
    ini_set('session.use_only_cookies', '1');     // never accept IDs from the URL
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) (config('session.absolute_hours') * 3600));

    session_name(config('session.name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (base_path() === '' ? '/' : base_path() . '/'),
        'secure'   => is_https(),      // Secure flag whenever HTTPS is available
        'httponly' => true,            // not readable from JavaScript
        'samesite' => 'Lax',           // not sent on cross-site POSTs
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['user_id'])) {
        $idle     = config('session.idle_minutes') * 60;
        $absolute = config('session.absolute_hours') * 3600;
        $last     = (int) ($_SESSION['last_activity'] ?? $now);
        $created  = (int) ($_SESSION['created_at'] ?? $now);
        if ($now - $last > $idle || $now - $created > $absolute) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['session_expired'] = true;
        }
    }
    $_SESSION['last_activity'] = $now;
}
