<?php
/**
 * Public bus-pass verification (target of the QR code on every pass).
 * The QR contains ONLY a random 256-bit token - no personal data.
 * Shows the minimum a conductor needs; no internal IDs are exposed.
 */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$token = input('token');
$pass = null;
$limited = !rate_limit('verify:' . client_ip(), 60, 60);
if (!$limited && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $pass = q_one(PASS_SELECT . ' WHERE p.verification_token = ?', [$token]);
}
$status = $pass ? pass_effective_status($pass) : 'invalid';
$valid = $status === 'active';
if ($pass) {
    audit_log('pass_verified', 'bus_pass', (int) $pass['id'], ['result' => $status]);
}

$labels = [
    'active' => 'VALID',
    'expired' => 'INVALID – EXPIRED',
    'scheduled' => 'NOT YET VALID',
    'revoked' => 'INVALID – REVOKED',
    'invalid' => 'INVALID',
];
guest_header('Verify Bus Pass');
?>
<div class="card shadow guest-card">
  <div class="card-body p-4 text-center">
    <div class="fw-bold fs-3 text-primary"><?= e(college_name()) ?></div>
    <div class="text-muted small mb-3">Bus Pass Verification</div>
    <?php if ($limited): ?>
      <div class="alert alert-warning">Too many checks from this device. Please wait a minute.</div>
    <?php else: ?>
    <div class="py-3 rounded-3 mb-3 <?= $valid ? 'bg-success' : 'bg-danger' ?> text-white">
      <i class="bi <?= $valid ? 'bi-patch-check-fill' : 'bi-x-octagon-fill' ?> fs-1"></i>
      <div class="fs-4 fw-bold"><?= e($labels[$status] ?? 'INVALID') ?></div>
      <div class="small">Checked <?= e(date('d-m-Y h:i A')) ?></div>
    </div>
    <?php if ($pass): ?>
      <?php if ($pass['profile_photo'] && $valid): ?>
        <img class="bp-photo mb-2" src="<?= e(url('file.php?type=profile&pass=' . $token)) ?>" alt="Student photo">
      <?php endif; ?>
      <dl class="row text-start small mb-0">
        <dt class="col-5">Student</dt><dd class="col-7"><?= e($pass['full_name']) ?></dd>
        <dt class="col-5">USN</dt><dd class="col-7"><?= e($pass['usn']) ?></dd>
        <dt class="col-5">Branch</dt><dd class="col-7"><?= e($pass['branch']) ?></dd>
        <dt class="col-5">Pass ID</dt><dd class="col-7"><?= e($pass['pass_number']) ?></dd>
        <dt class="col-5">Route</dt><dd class="col-7"><?= e($pass['origin'] . ' → ' . $pass['destination']) ?></dd>
        <dt class="col-5">Boarding → Dest.</dt><dd class="col-7"><?= e($pass['boarding_stop'] . ' → ' . $pass['destination_stop']) ?></dd>
        <dt class="col-5">Bus</dt><dd class="col-7"><?= e($pass['bus_code'] ? $pass['bus_code'] . ' (' . $pass['registration_number'] . ')' : 'Any bus on route') ?></dd>
        <dt class="col-5">Validity</dt><dd class="col-7"><?= e(fmt_date($pass['valid_from']) . ' to ' . fmt_date($pass['valid_until'])) ?></dd>
      </dl>
    <?php else: ?>
      <p class="text-muted mb-0">This QR code does not match any bus pass issued by <?= e(college_name()) ?>.</p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php guest_footer();
