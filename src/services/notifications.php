<?php
/**
 * In-app notifications.
 */
declare(strict_types=1);

function notify(int $userId, string $title, string $message, ?string $link = null): void
{
    q('INSERT INTO notifications (user_id, title, message, link, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, mb_substr($title, 0, 120), mb_substr($message, 0, 500), $link, date('Y-m-d H:i:s')]);
}

function notify_admins(string $title, string $message, ?string $link = null): void
{
    foreach (q_all("SELECT id FROM users WHERE role = 'admin' AND status = 'active'") as $a) {
        notify((int) $a['id'], $title, $message, $link);
    }
}

function unread_notifications(int $userId): int
{
    return (int) q_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId]);
}
