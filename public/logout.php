<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// Logout is POST-only + CSRF protected so a malicious link/image cannot log users out.
if (!is_post()) {
    redirect('index.php');
}
require_csrf();
$role = current_user()['role'] ?? 'student';
audit_log('logout', 'user', $_SESSION['user_id'] ?? null);
logout_user();
session_start();
session_regenerate_id(true);
flash('success', 'You have been logged out.');
redirect('login.php?role=' . $role);
