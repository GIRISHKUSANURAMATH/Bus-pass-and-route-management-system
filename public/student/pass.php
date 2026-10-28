<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('student');
$student = current_student();

$pass = student_current_pass((int) $student['id']) ?? student_latest_pass((int) $student['id']);
$history = q_all('SELECT pass_number, valid_from, valid_until, status FROM bus_passes WHERE student_id = ? ORDER BY id DESC', [$student['id']]);

page_header('My Bus Pass', 'pass.php');
if (!$pass): ?>
  <div class="card"><div class="card-body text-center py-5">
    <i class="bi bi-person-badge fs-1 text-primary"></i>
    <p class="mt-2">You don't have a bus pass yet. Passes are issued after your payment is verified and the application is approved.</p>
    <a class="btn btn-primary" href="<?= e(url('student/apply.php')) ?>">Apply for bus pass</a>
  </div></div>
<?php page_footer(); exit; endif;

$status = pass_effective_status($pass);
$verifyUrl = absolute_url('verify-pass.php?token=' . $pass['verification_token']);
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <button class="btn btn-primary" type="button" data-print><i class="bi bi-printer"></i> Print / Save as PDF</button>
  <a class="btn btn-outline-primary" id="downloadQr" href="#" download="<?= e($pass['pass_number']) ?>-qr.png"><i class="bi bi-download"></i> Download QR</a>
  <?php if ($status !== 'active'): ?><a class="btn btn-warning" href="<?= e(url('student/apply.php')) ?>"><i class="bi bi-arrow-repeat"></i> Renew pass</a><?php endif; ?>
</div>
<?php require __DIR__ . '/../../src/views/pass_card.php'; ?>
<?php if (count($history) > 1): ?>
<div class="card mt-4"><div class="card-header">Pass history</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Pass ID</th><th>Valid from</th><th>Valid until</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($history as $h): ?><tr><td><?= e($h['pass_number']) ?></td><td><?= e(fmt_date($h['valid_from'])) ?></td><td><?= e(fmt_date($h['valid_until'])) ?></td><td><?= status_badge($h['status']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
<?php page_footer(['vendor/qrcode/qrcode.js', 'js/pass-qr.js']);
