<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_role('admin');

$APP_SELECT = 'SELECT a.*, u.full_name, u.email, u.phone, u.id AS user_id, s.usn, s.branch, s.year_of_study,
        r.name AS route_name, bs.name AS boarding, ds.name AS destination,
        p.id AS payment_id, p.status AS payment_status, p.transaction_reference, p.payment_date, p.payment_screenshot,
        bp.pass_number, rv.full_name AS reviewer
    FROM bus_pass_applications a
    JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id
    JOIN routes r ON r.id = a.route_id
    JOIN bus_stops bs ON bs.id = a.boarding_stop_id JOIN bus_stops ds ON ds.id = a.destination_stop_id
    LEFT JOIN payments p ON p.application_id = a.id
    LEFT JOIN bus_passes bp ON bp.application_id = a.id
    LEFT JOIN users rv ON rv.id = a.reviewed_by';

if (is_post()) {
    require_csrf();
    $id = input_int('id') ?? 0;
    $action = input('action');
    $app = q_one($APP_SELECT . ' WHERE a.id = ?', [$id]);
    if (!$app) {
        flash('danger', 'Application not found.');
        redirect('admin/applications.php');
    }
    if ($action === 'approve') {
        $busId = input_int('bus_id');
        $res = issue_pass($id, $busId ?: null, input('valid_from'), (int) $admin['id']);
        $res['ok'] ? flash('success', "Pass {$res['pass_number']} issued to {$app['full_name']}.") : flash('danger', $res['error']);
    } elseif ($action === 'reject') {
        $remarks = input('remarks');
        if (mb_strlen($remarks) < 3 || mb_strlen($remarks) > 255) {
            flash('danger', 'Enter a reason for rejection (3–255 characters).');
        } else {
            $n = q("UPDATE bus_pass_applications SET status = 'rejected', admin_remarks = ?, reviewed_by = ?, reviewed_at = ?
                    WHERE id = ? AND status IN ('payment_pending','payment_submitted','pending_approval')",
                [$remarks, $admin['id'], date('Y-m-d H:i:s'), $id])->rowCount();
            if ($n) {
                audit_log('application_rejected', 'application', $id, ['remarks' => $remarks]);
                notify((int) $app['user_id'], 'Bus-pass application rejected', "Application {$app['application_no']} was rejected: {$remarks}", 'student/applications.php');
                flash('success', 'Application rejected.' . ($app['payment_status'] === 'verified' ? ' Note: payment was already verified – arrange a refund offline.' : ''));
            } else {
                flash('warning', 'This application can no longer be rejected.');
            }
        }
    }
    redirect('admin/applications.php?id=' . $id);
}

page_header('Bus-Pass Applications', 'applications.php');

// ---------------- Detail view ----------------
if ($id = input_int('id')) {
    $a = q_one($APP_SELECT . ' WHERE a.id = ?', [$id]);
    if (!$a) {
        echo '<div class="alert alert-warning">Not found.</div>';
        page_footer();
        exit;
    }
    $buses = q_all("SELECT id, bus_code, registration_number, status FROM buses WHERE route_id = ? AND status <> 'inactive' ORDER BY bus_code", [$a['route_id']]);
    $validFrom = date('Y-m-d');
    if ($a['previous_pass_id']) {
        $prevUntil = q_val('SELECT valid_until FROM bus_passes WHERE id = ?', [$a['previous_pass_id']]);
        if ($prevUntil && $prevUntil >= $validFrom) {
            $validFrom = date('Y-m-d', strtotime($prevUntil . ' +1 day'));
        }
    }
    ?>
    <a href="<?= e(url('admin/applications.php')) ?>" class="small">&larr; All applications</a>
    <div class="row g-3 mt-1">
      <div class="col-lg-7"><div class="card"><div class="card-header d-flex justify-content-between"><span><?= e($a['application_no']) ?> · <?= e(ucfirst($a['application_type'])) ?></span><?= status_badge($a['status']) ?></div>
        <div class="card-body"><dl class="row small mb-0">
          <dt class="col-4">Student</dt><dd class="col-8"><?= e($a['full_name']) ?> · <?= e($a['usn']) ?> · <?= e($a['branch']) ?> · <?= e(year_label((int) $a['year_of_study'])) ?></dd>
          <dt class="col-4">Contact</dt><dd class="col-8"><?= e($a['email']) ?> · <?= e($a['phone']) ?></dd>
          <dt class="col-4">Route</dt><dd class="col-8"><?= e($a['route_name']) ?></dd>
          <dt class="col-4">Stops</dt><dd class="col-8"><?= e($a['boarding'] . ' → ' . $a['destination']) ?> (<?= e($a['distance_km']) ?> km)</dd>
          <dt class="col-4">Duration / Year</dt><dd class="col-8"><?= e(duration_label($a['duration'])) ?> · <?= e($a['academic_year']) ?></dd>
          <dt class="col-4">Fee</dt><dd class="col-8 fw-bold"><?= e(money($a['fee_amount'])) ?></dd>
          <dt class="col-4">Payment</dt><dd class="col-8"><?= status_badge($a['payment_status']) ?>
            <?php if ($a['transaction_reference']): ?> UTR <code><?= e($a['transaction_reference']) ?></code> on <?= e(fmt_date($a['payment_date'])) ?><?php endif; ?>
            <?php if ($a['payment_screenshot']): ?> · <a href="<?= e(url('file.php?type=payment&id=' . $a['payment_id'])) ?>" target="_blank" rel="noopener">screenshot</a><?php endif; ?>
            <?php if ($a['payment_status'] === 'submitted'): ?> · <a href="<?= e(url('admin/payments.php')) ?>">verify now</a><?php endif; ?></dd>
          <dt class="col-4">Applied</dt><dd class="col-8"><?= e(fmt_datetime($a['created_at'])) ?></dd>
          <?php if ($a['reviewer']): ?><dt class="col-4">Reviewed</dt><dd class="col-8"><?= e($a['reviewer']) ?> · <?= e(fmt_datetime($a['reviewed_at'])) ?></dd><?php endif; ?>
          <?php if ($a['admin_remarks']): ?><dt class="col-4">Remarks</dt><dd class="col-8"><?= e($a['admin_remarks']) ?></dd><?php endif; ?>
          <?php if ($a['pass_number']): ?><dt class="col-4">Pass</dt><dd class="col-8"><a href="<?= e(url('admin/passes.php?q=' . urlencode($a['pass_number']))) ?>"><?= e($a['pass_number']) ?></a></dd><?php endif; ?>
        </dl></div></div></div>
      <div class="col-lg-5">
        <?php if ($a['status'] === 'pending_approval'): ?>
        <div class="card mb-3 border-success"><div class="card-header">Approve &amp; generate bus pass</div><div class="card-body">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="approve">
            <div class="mb-2"><label class="form-label" for="bus_id">Assign bus</label>
              <select class="form-select" id="bus_id" name="bus_id">
                <?php foreach ($buses as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['bus_code'] . ' · ' . $b['registration_number']) ?><?= $b['status'] !== 'active' ? ' (' . e($b['status']) . ')' : '' ?></option><?php endforeach; ?>
                <option value="">Any bus on this route</option>
              </select></div>
            <div class="mb-3"><label class="form-label" for="valid_from">Valid from</label>
              <input class="form-control" type="date" id="valid_from" name="valid_from" required value="<?= e($validFrom) ?>">
              <div class="form-text">Valid until is calculated from the duration (<?= e($a['duration']) ?>).</div></div>
            <button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> Approve &amp; issue pass</button>
          </form>
        </div></div>
        <?php elseif (in_array($a['status'], ['payment_pending', 'payment_submitted'], true)): ?>
          <div class="alert alert-info small">Approval becomes available after the payment is <strong>verified</strong>.</div>
        <?php endif; ?>
        <?php if (in_array($a['status'], ['payment_pending', 'payment_submitted', 'pending_approval'], true)): ?>
        <div class="card border-danger"><div class="card-header">Reject application</div><div class="card-body">
          <form method="post" data-confirm="Reject this application?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="reject">
            <textarea class="form-control mb-2" name="remarks" rows="2" required maxlength="255" placeholder="Reason shown to the student"></textarea>
            <button class="btn btn-outline-danger w-100">Reject</button>
          </form>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    page_footer();
    exit;
}

// ---------------- List view ----------------
$status = input('status');
$q = input('q');
$where = [];
$params = [];
if ($status !== '' && isset(STATUS_LABELS[$status])) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(s.usn LIKE ? OR u.full_name LIKE ? OR a.application_no LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) q_val('SELECT COUNT(*) FROM bus_pass_applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id' . $w, $params);
$p = paginate($total, 25);
$rows = q_all($APP_SELECT . $w . ' ORDER BY a.id DESC LIMIT ' . $p['limit'] . ' OFFSET ' . $p['offset'], $params);
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-sm-4 col-lg-3"><select class="form-select" name="status" data-autosubmit>
    <option value="">All statuses</option>
    <?php foreach (['payment_pending', 'payment_submitted', 'pending_approval', 'approved', 'rejected', 'cancelled'] as $st): ?>
      <option value="<?= $st ?>"<?= $status === $st ? ' selected' : '' ?>><?= e(STATUS_LABELS[$st][0]) ?></option>
    <?php endforeach; ?>
  </select></div>
  <div class="col-sm-5 col-lg-4"><input class="form-control" name="q" placeholder="Search USN, name, application no." value="<?= e($q) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Search</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
  <thead><tr><th>Application</th><th>Student</th><th>Route / Stops</th><th>Fee</th><th>Payment</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['application_no']) ?><br><small class="text-muted"><?= e(fmt_date($r['created_at'])) ?> · <?= e($r['application_type']) ?></small></td>
    <td><?= e($r['full_name']) ?><br><small class="text-muted"><?= e($r['usn']) ?></small></td>
    <td><?= e($r['route_name']) ?><br><small class="text-muted"><?= e($r['boarding'] . ' → ' . $r['destination']) ?></small></td>
    <td><?= e(money($r['fee_amount'])) ?></td>
    <td><?= status_badge($r['payment_status']) ?></td>
    <td><?= status_badge($r['status']) ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/applications.php?id=' . $r['id'])) ?>">Open</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No applications.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="mt-3"><?= pagination_links($p) ?></div>
<?php page_footer();
