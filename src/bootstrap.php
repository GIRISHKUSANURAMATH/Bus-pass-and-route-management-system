<?php
/**
 * Front bootstrap - every public PHP file starts with:
 *     require __DIR__ . '/../src/bootstrap.php';      (path depth varies)
 */
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/../config/security.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/services/audit.php';
require __DIR__ . '/services/settings.php';
require __DIR__ . '/services/notifications.php';
require __DIR__ . '/services/rate_limit.php';
require __DIR__ . '/services/uploads.php';
require __DIR__ . '/services/fees.php';
require __DIR__ . '/services/passes.php';
require __DIR__ . '/services/tracking.php';
require __DIR__ . '/services/account.php';
require __DIR__ . '/services/driver_api.php';
require __DIR__ . '/views/layout.php';

// ---------------------------------------------------------------------
// Error handling: log everything, show users nothing sensitive.
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    $ref = strtoupper(bin2hex(random_bytes(4)));
    app_log("[{$ref}] " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $isDb = $e instanceof PDOException;
    $msg = $isDb
        ? 'The database is unavailable or not set up. (Admins: check .env and that schema.sql was imported.)'
        : 'Something went wrong on our side.';
    if (config('app.debug')) {
        $msg .= ' [' . get_class($e) . ': ' . $e->getMessage() . ']';
    }
    if (defined('API_REQUEST')) {
        json_response(['ok' => false, 'error' => $msg . " Ref: {$ref}"], 500);
    }
    http_response_code(500);
    render_error_page(500, 'Server error', $msg . " Reference: {$ref}");
});

send_security_headers();
start_secure_session();
expire_passes();
