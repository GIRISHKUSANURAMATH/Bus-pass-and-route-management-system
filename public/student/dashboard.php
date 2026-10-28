<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('student');
$student = current_student();

$pass = student_current_pass((int) $student['id']) ?? student_latest_pass((int) $student['id']);
$passStatus = $pass ? pass_effective_status($pass) : null;
$app = q_one('SELECT a.*, r.name AS route_name, p.status AS payment_status, p.id AS payment_id
              FROM bus_pass_applications a JOIN routes r ON r.id = a.route_id
              LEFT JOIN payments p ON p.application_id = a.id
              WHERE a.student_id = ? ORDER BY a.id DESC LIMIT 1', [$student['id']]);
$daysLeft = ($pass && $passStatus === 'active') ? (int) ((strtotime($pass['valid_until']) - strtotime(date('Y-m-d'))) / 86400) : null;

page_header('Welcome, ' . $user['full_name'], 'dashboard.php');
?>
<p class="text-muted mt-n2">USN <strong><?= e($student['usn']) ?></strong> · <?= e($student['branch']) ?> · <?= e(year_label((int) $student['year_of_study'])) ?> · <?= e($student['academic_year']) ?></p>

<?php if ($daysLeft !== null && $daysLeft <= 30): ?>
<div class="alert alert-warning"><i class="bi bi-hourglass-split"></i> Your bus pass expires in <?= $daysLeft ?> day(s). <a href="<?= e(url('student/apply.php')) ?>">Renew now</a>.</div>
<?php elseif ($passStatus === 'expired'): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Your bus pass has expired. <a href="<?= e(url('student/apply.php')) ?>">Apply for renewal</a>.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-xl"><?= stat_card('Bus Pass Status', $passStatus ? strtoupper($passStatus) : 'No pass', 'person-badge', $passStatus === 'active' ? 'success' : 'secondary', url('student/pass.php')) ?></div>
  <div class="col-6 col-xl"><?= stat_card('Payment Status', $app ? (STATUS_LABELS[$app['payment_status']][0] ?? '—') : '—', 'cash-coin', $app && $app['payment_status'] === 'verified' ? 'success' : 'warning', url('student/applications.php')) ?></div>
  <div class="col-6 col-xl"><?= stat_card('Assigned Route', $pass ? $pass['origin'] . ' → ' . $pass['destination'] : '—', 'signpost-split', 'primary', url('student/route.php')) ?></div>
  <div class="col-6 col-xl"><?= stat_card('Assigned Bus', $pass && $pass['bus_code'] ? $pass['bus_code'] . ' · ' . $pass['registration_number'] : '—', 'bus-front', 'info', url('student/track-bus.php')) ?></div>
  <div class="col-12 col-xl"><?= stat_card('Pass Expiry', $pass ? fmt_date($pass['valid_until']) : '—', 'calendar-event', 'warning') ?></div>
</div>

<h2 class="h6 text-uppercase text-muted">Quick actions</h2>
<div class="row g-3 mb-4">
  <?php foreach ([
      ['apply.php', 'Apply / Renew', 'file-earmark-plus'],
      ['pass.php', 'My Bus Pass', 'person-badge'],
      ['track-bus.php', 'Track Bus', 'geo-alt-fill'],
      ['route.php', 'My Route', 'signpost-split'],
      ['applications.php', 'Payment', 'qr-code'],
      ['profile.php', 'Profile', 'person-circle'],
  ] as [$f, $l, $i]): ?>
  <div class="col-4 col-md-2"><a class="quick-action" href="<?= e(url('student/' . $f)) ?>"><i class="bi bi-<?= $i ?>"></i><span class="small"><?= e($l) ?></span></a></div>
  <?php endforeach; ?>
</div>

<?php if ($app): ?>
<div class="card">
  <div class="card-header">Latest application · <?= e($app['application_no']) ?></div>
  <div class="card-body">
    <div class="row small">
      <div class="col-md-3"><div class="text-muted">Route</div><?= e($app['route_name']) ?></div>
      <div class="col-md-2"><div class="text-muted">Fee</div><?= e(money($app['fee_amount'])) ?></div>
      <div class="col-md-3"><div class="text-muted">Application</div><?= status_badge($app['status']) ?> <?= $app['application_type'] === 'renewal' && !in_array($app['status'], ['approved', 'rejected', 'cancelled'], true) ? '<span class="badge text-bg-dark">Renewal Pending</span>' : '' ?></div>
      <div class="col-md-2"><div class="text-muted">Payment</div><?= status_badge($app['payment_status']) ?></div>
      <div class="col-md-2 text-md-end mt-2 mt-md-0">
        <?php if ($app['status'] === 'payment_pending'): ?>
          <a class="btn btn-sm btn-primary" href="<?= e(url('student/payment.php?application=' . $app['id'])) ?>">Pay now</a>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($app['admin_remarks']): ?><div class="alert alert-light border mt-3 mb-0 small"><strong>Office remarks:</strong> <?= e($app['admin_remarks']) ?></div><?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card"><div class="card-body text-center py-5">
  <i class="bi bi-bus-front fs-1 text-primary"></i>
  <p class="mt-2">You have not applied for a bus pass yet.</p>
  <a class="btn btn-primary" href="<?= e(url('student/apply.php')) ?>">Apply for bus pass</a>
</div></div>
<?php endif; ?>
<?php page_footer();
