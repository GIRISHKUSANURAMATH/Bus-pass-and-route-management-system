<?php
/**
 * Application configuration.
 *
 * Values are read from the project-root `.env` file (copy `.env.example`).
 * Nothing secret is hard-coded here; the fallbacks match a stock local
 * XAMPP install so the project still boots if `.env` is missing.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage');
define('UPLOAD_PATH', APP_ROOT . '/uploads');
define('PUBLIC_PATH', APP_ROOT . '/public');

/**
 * Minimal .env parser (KEY=VALUE, # comments, optional quotes).
 * Values are loaded into a private static array - NOT into $_ENV/putenv -
 * so they are never exposed through phpinfo() or child processes.
 */
function env(string $key, ?string $default = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = APP_ROOT . '/.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $vars[$k] = $v;
            }
        }
    }
    return array_key_exists($key, $vars) ? $vars[$key] : $default;
}

function env_bool(string $key, bool $default = false): bool
{
    $v = env($key);
    if ($v === null) {
        return $default;
    }
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

/** Read-only application config. */
function config(string $key): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [
            'app.name'        => env('APP_NAME', 'SKSVMACET Bus Pass & Route Management'),
            'app.college'     => env('APP_COLLEGE', 'SKSVMACET'),
            'app.env'         => env('APP_ENV', 'development'),
            'app.debug'       => env_bool('APP_DEBUG', false),
            // Absolute URL of the /public folder. Used for links that leave the
            // browser (bus-pass QR codes). Use your LAN IP / domain here so a
            // phone can open the verification link.
            'app.url'         => rtrim((string) env('APP_URL', ''), '/'),
            'app.timezone'    => env('APP_TIMEZONE', 'Asia/Kolkata'),
            'app.env_loaded'  => is_readable(APP_ROOT . '/.env'),

            'db.host'         => env('DB_HOST', '127.0.0.1'),
            'db.port'         => (int) env('DB_PORT', '3306'),
            'db.name'         => env('DB_NAME', 'sksvmacet_buspass'),
            'db.user'         => env('DB_USER', 'root'),
            'db.pass'         => env('DB_PASS', ''),

            'session.name'          => env('SESSION_NAME', 'SKSVM_SESSID'),
            'session.idle_minutes'  => (int) env('SESSION_IDLE_MINUTES', '30'),
            'session.absolute_hours'=> (int) env('SESSION_ABSOLUTE_HOURS', '8'),
            'security.force_https'  => env_bool('FORCE_HTTPS', false),
        ];
    }
    if (!array_key_exists($key, $cfg)) {
        throw new InvalidArgumentException('Unknown config key');
    }
    return $cfg[$key];
}

date_default_timezone_set(config('app.timezone'));
