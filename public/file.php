<?php
/**
 * Serves uploaded files (stored OUTSIDE /public) after an authorization check.
 *   ?type=payment&id=<payment id>        admin, or the student who owns the payment
 *   ?type=profile&id=<student id>        admin, or that student
 *   ?type=profile&pass=<verify token>    anyone holding a currently valid pass QR (photo check by conductor)
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$type = input('type');
$id   = input_int('id');
$user = current_user();
$student = current_student();
$dir = null;
$name = null;

if ($type === 'payment' && $id) {
    $row = q_one('SELECT student_id, payment_screenshot FROM payments WHERE id = ?', [$id]);
    if ($row && $user && ($user['role'] === 'admin' || ($student && (int) $student['id'] === (int) $row['student_id']))) {
        $dir = UPLOAD_PATH . '/payment';
        $name = $row['payment_screenshot'];
    }
} elseif ($type === 'profile') {
    $token = input('pass');
    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $row = q_one("SELECT s.profile_photo FROM bus_passes p JOIN students s ON s.id = p.student_id
                      WHERE p.verification_token = ? AND p.status = 'active' AND p.valid_until >= ?", [$token, date('Y-m-d')]);
    } elseif ($id && $user && ($user['role'] === 'admin' || ($student && (int) $student['id'] === $id))) {
        $row = q_one('SELECT profile_photo FROM students WHERE id = ?', [$id]);
    }
    if (!empty($row)) {
        $dir = UPLOAD_PATH . '/profiles';
        $name = $row['profile_photo'];
    }
}

$name = safe_upload_name($name);
if (!$dir || !$name || !is_file($dir . '/' . $name)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($dir . '/' . $name);
if (!isset(UPLOAD_IMAGE_MIMES[$mime])) {
    http_response_code(415);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($dir . '/' . $name));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, max-age=300');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
readfile($dir . '/' . $name);
