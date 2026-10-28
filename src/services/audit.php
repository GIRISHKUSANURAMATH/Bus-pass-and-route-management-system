<?php
/**
 * Audit log: who did what, when, from where.
 */
declare(strict_types=1);

function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [],
                   ?int $userId = null, ?string $role = null): void
{
    try {
        if ($userId === null && isset($_SESSION['user_id']) && is_int($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            $role   = $_SESSION['role'] ?? null;
        }
        q('INSERT INTO audit_logs (user_id, user_role, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $userId, $role, $action, $entityType, $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        app_log('audit_log failure: ' . $e->getMessage());   // never break the request because of logging
    }
}

/** File logger for technical errors (storage/logs/app.log). */
function app_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    @file_put_contents(STORAGE_PATH . '/logs/app.log', $line, FILE_APPEND | LOCK_EX);
}
