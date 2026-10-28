<?php
/**
 * Bus-pass lifecycle: expiry, lookup, issuing.
 */
declare(strict_types=1);

/** Mark passes past valid_until as expired (runs at most once per day). */
function expire_passes(): void
{
    $today = date('Y-m-d');
    if (setting('passes_expired_on', '') === $today) {
        return;
    }
    q("UPDATE bus_passes SET status = 'expired' WHERE status = 'active' AND valid_until < ?", [$today]);
    q("INSERT INTO settings (setting_key, setting_value) VALUES ('passes_expired_on', ?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$today]);
}

const PASS_SELECT = "
    SELECT p.*, s.usn, s.branch, s.year_of_study, s.profile_photo, u.full_name, u.id AS user_id,
           r.name AS route_name, r.origin, r.destination, r.route_code,
           b.bus_code, b.bus_number, b.registration_number,
           bs.name AS boarding_stop, ds.name AS destination_stop,
           a.application_no, a.duration, a.fee_amount,
           pay.status AS payment_status, pay.transaction_reference
    FROM bus_passes p
    JOIN students s ON s.id = p.student_id
    JOIN users u ON u.id = s.user_id
    JOIN routes r ON r.id = p.route_id
    LEFT JOIN buses b ON b.id = p.bus_id
    JOIN bus_stops bs ON bs.id = p.boarding_stop_id
    JOIN bus_stops ds ON ds.id = p.destination_stop_id
    JOIN bus_pass_applications a ON a.id = p.application_id
    LEFT JOIN payments pay ON pay.application_id = a.id
";

/** Effective status taking dates into account (never trust a stale 'active'). */
function pass_effective_status(array $pass): string
{
    if ($pass['status'] !== 'active') {
        return $pass['status'];
    }
    $today = date('Y-m-d');
    if ($pass['valid_until'] < $today) {
        return 'expired';
    }
    if ($pass['valid_from'] > $today) {
        return 'scheduled';
    }
    return 'active';
}

/** Currently valid pass for a student (latest), or null. */
function student_current_pass(int $studentId): ?array
{
    $today = date('Y-m-d');
    return q_one(PASS_SELECT . " WHERE p.student_id = ? AND p.status = 'active' AND p.valid_from <= ? AND p.valid_until >= ?
        ORDER BY p.valid_until DESC LIMIT 1", [$studentId, $today, $today]);
}

/** Most recent pass of any status (for dashboard / renewal). */
function student_latest_pass(int $studentId): ?array
{
    return q_one(PASS_SELECT . ' WHERE p.student_id = ? ORDER BY p.valid_until DESC, p.id DESC LIMIT 1', [$studentId]);
}

function compute_valid_until(string $validFrom, string $duration): string
{
    if ($duration === 'monthly') {
        // 1 month, never running past the end of a shorter month (31-01 -> 28-02, 15-01 -> 14-02)
        $from = new DateTimeImmutable($validFrom);
        $next = $from->modify('first day of next month');
        if ((int) $from->format('j') > (int) $next->format('t')) {
            return $next->format('Y-m-t');
        }
        return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), (int) $from->format('j'))
            ->modify('-1 day')->format('Y-m-d');
    }
    // Existing 6/12-month records: unchanged original logic
    $months = ['semester' => 6, 'annual' => 12][$duration] ?? 12;
    return (new DateTimeImmutable($validFrom))->modify("+{$months} months")->modify('-1 day')->format('Y-m-d');
}

/**
 * Approve an application and issue its pass. Requires a VERIFIED payment.
 * @return array{ok:bool, error?:string, pass_id?:int, pass_number?:string}
 */
function issue_pass(int $applicationId, ?int $busId, string $validFrom, int $adminUserId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $app = q_one('SELECT a.*, s.user_id FROM bus_pass_applications a JOIN students s ON s.id = a.student_id
                      WHERE a.id = ? FOR UPDATE', [$applicationId]);
        if (!$app) {
            throw new RuntimeException('Application not found.');
        }
        if ($app['status'] !== 'pending_approval') {
            throw new RuntimeException('Only applications with a verified payment can be approved.');
        }
        $paid = q_val("SELECT COUNT(*) FROM payments WHERE application_id = ? AND status = 'verified'", [$applicationId]);
        if (!$paid) {
            throw new RuntimeException('Payment has not been verified.');
        }
        if ($busId !== null) {
            $bus = q_one("SELECT id FROM buses WHERE id = ? AND route_id = ? AND status <> 'inactive'", [$busId, $app['route_id']]);
            if (!$bus) {
                throw new RuntimeException('Selected bus does not run on this route.');
            }
        }
        if (!valid_date($validFrom)) {
            throw new RuntimeException('Invalid "valid from" date.');
        }
        $validUntil = compute_valid_until($validFrom, $app['duration']);
        $token = bin2hex(random_bytes(32));

        q('INSERT INTO bus_passes (pass_number, application_id, student_id, route_id, bus_id, boarding_stop_id,
               destination_stop_id, academic_year, valid_from, valid_until, status, verification_token, issued_by, issued_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?, ?)', [
            'TMP' . bin2hex(random_bytes(6)), $applicationId, $app['student_id'], $app['route_id'], $busId,
            $app['boarding_stop_id'], $app['destination_stop_id'], $app['academic_year'],
            $validFrom, $validUntil, $token, $adminUserId, date('Y-m-d H:i:s'),
        ]);
        $passId = (int) $pdo->lastInsertId();
        $passNumber = 'BP' . date('Y', strtotime($validFrom)) . str_pad((string) $passId, 6, '0', STR_PAD_LEFT);
        q('UPDATE bus_passes SET pass_number = ? WHERE id = ?', [$passNumber, $passId]);
        q('UPDATE bus_pass_applications SET status = "approved", reviewed_by = ?, reviewed_at = ? WHERE id = ?',
            [$adminUserId, date('Y-m-d H:i:s'), $applicationId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof RuntimeException) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        throw $e;
    }
    audit_log('pass_issued', 'bus_pass', $passId, ['pass_number' => $passNumber, 'application_id' => $applicationId]);
    notify((int) $app['user_id'], 'Bus pass approved',
        "Your bus pass {$passNumber} is approved and valid from " . fmt_date($validFrom) . ' to ' . fmt_date($validUntil) . '.',
        'student/pass.php');
    return ['ok' => true, 'pass_id' => $passId, 'pass_number' => $passNumber];
}

function next_application_no(): string
{
    // APP + yyyymmdd + 4 random digits; uniqueness guaranteed by DB key (retry on clash)
    for ($i = 0; $i < 5; $i++) {
        $no = 'APP' . date('ymd') . random_int(1000, 9999);
        if (!q_val('SELECT 1 FROM bus_pass_applications WHERE application_no = ?', [$no])) {
            return $no;
        }
    }
    return 'APP' . date('ymdHis');
}
