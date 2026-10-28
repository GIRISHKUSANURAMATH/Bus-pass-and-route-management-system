<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('student');
$student = current_student();

// Cancel an unpaid application (own only)
if (is_post() && input('action') === 'cancel') {
    require_csrf();
    $id = input_int('application_id') ?? 0;
    $n = q("UPDATE bus_pass_applications a JOIN payments p ON p.application_id = a.id
            SET a.status = 'cancelled'
            WHERE a.id = ? AND a.student_id = ? AND a.status = 'payment_pending' AND p.status IN ('pending','rejected')",
        [$id, $student['id']])->rowCount();
    if ($n) {
        audit_log('application_cancelled', 'application', $id);
        flash('success', 'Application cancelled.');
    } else {
        flash('warning', 'This application can no longer be cancelled.');
    }
    redirect('student/applications.php');
}

$apps = q_all('SELECT a.*, r.name AS route_name, bs.name AS boarding, ds.name AS destination,
                      p.status AS payment_status, p.transaction_reference, p.payment_date, p.rejection_reason, p.id AS payment_id,
                      p.payment_screenshot, bp.pass_number
               FROM bus_pass_applications a
               JOIN routes r ON r.id = a.route_id
               JOIN bus_stops bs ON bs.id = a.boarding_stop_id
               JOIN bus_stops ds ON ds.id = a.destination_stop_id
               LEFT JOIN payments p ON p.application_id = a.id
               LEFT JOIN bus_passes bp ON bp.application_id = a.id
               WHERE a.student_id = ? ORDER BY a.id DESC', [$student['id']]);

page_header('My Applications & Payments', 'applications.php');
?>
<div class="mb-3"><a class="btn btn-primary" href="<?= e(url('student/apply.php')) ?>"><i class="bi bi-file-earmark-plus"></i> New application / renewal</a></div>
<?php if (!$apps): ?>
  <div class="card"><div class="card-body text-muted">No applications yet.</div></div>
<?php endif; ?>
<?php foreach ($apps as $a): ?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between gap-2">
    <span><?= e($a['application_no']) ?> · <?= e(ucfirst($a['application_type'])) ?> · <?= e(fmt_date($a['created_at'])) ?></span>
    <span><?= status_badge($a['status']) ?> <?= $a['application_type'] === 'renewal' && in_array($a['status'], ['payment_pending', 'payment_submitted', 'pending_approval'], true) ? '<span class="badge text-bg-dark">Renewal Pending</span>' : '' ?></span>
  </div>
  <div class="card-body">
    <div class="row small g-2">
      <div class="col-md-3"><div class="text-muted">Route</div><?= e($a['route_name']) ?></div>
      <div class="col-md-3"><div class="text-muted">Stops</div><?= e($a['boarding'] . ' → ' . $a['destination']) ?> (<?= e($a['distance_km']) ?> km)</div>
      <div class="col-md-2"><div class="text-muted">Duration / Year</div><?= e(ucfirst($a['duration'])) ?> · <?= e($a['academic_year']) ?></div>
      <div class="col-md-2"><div class="text-muted">Fee</div><strong><?= e(money($a['fee_amount'])) ?></strong></div>
      <div class="col-md-2"><div class="text-muted">Payment</div><?= status_badge($a['payment_status']) ?></div>
    </div>
    <?php if ($a['transaction_reference']): ?>
      <div class="small mt-2 text-muted">UTR <code><?= e($a['transaction_reference']) ?></code> · paid on <?= e(fmt_date($a['payment_date'])) ?>
        <?php if ($a['payment_screenshot']): ?> · <a href="<?= e(url('file.php?type=payment&id=' . $a['payment_id'])) ?>" target="_blank" rel="noopener">screenshot</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($a['payment_status'] === 'rejected' && $a['rejection_reason']): ?><div class="alert alert-danger small mt-2 mb-0">Payment rejected: <?= e($a['rejection_reason']) ?></div><?php endif; ?>
    <?php if ($a['admin_remarks']): ?><div class="alert alert-light border small mt-2 mb-0"><strong>Office remarks:</strong> <?= e($a['admin_remarks']) ?></div><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mt-3">
      <?php if ($a['status'] === 'payment_pending'): ?>
        <a class="btn btn-sm btn-primary" href="<?= e(url('student/payment.php?application=' . $a['id'])) ?>"><i class="bi bi-qr-code"></i> <?= $a['payment_status'] === 'rejected' ? 'Resubmit payment' : 'Pay now' ?></a>
        <form method="post" data-confirm="Cancel this application?">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
          <button class="btn btn-sm btn-outline-danger">Cancel</button>
        </form>
      <?php elseif ($a['status'] === 'approved' && $a['pass_number']): ?>
        <a class="btn btn-sm btn-success" href="<?= e(url('student/pass.php')) ?>"><i class="bi bi-person-badge"></i> View pass <?= e($a['pass_number']) ?></a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php page_footer();
