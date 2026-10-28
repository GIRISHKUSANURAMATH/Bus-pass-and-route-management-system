<?php
/**
 * Admin payment verification. The admin checks the UTR/amount against the
 * college bank/UPI statement, then VERIFIES or REJECTS. Nothing is automatic.
 */
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_role('admin');

if (is_post()) {
    require_csrf();
    $id = input_int('id') ?? 0;
    $action = input('action');
    $pay = q_one('SELECT p.*, a.application_no, a.id AS app_id, s.user_id, u.full_name
                  FROM payments p JOIN bus_pass_applications a ON a.id = p.application_id
                  JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id WHERE p.id = ?', [$id]);
    if (!$pay || $pay['status'] !== 'submitted') {
        flash('warning', 'This payment is not awaiting verification.');
        redirect('admin/payments.php');
    }
    $pdo = db();
    if ($action === 'verify') {
        $pdo->beginTransaction();
        q('UPDATE payments SET status = "verified", verified_by = ?, verified_at = ?, rejection_reason = NULL WHERE id = ? AND status = "submitted"',
            [$admin['id'], date('Y-m-d H:i:s'), $id]);
        q('UPDATE bus_pass_applications SET status = "pending_approval" WHERE id = ? AND status = "payment_submitted"', [$pay['app_id']]);
        $pdo->commit();
        audit_log('payment_verified', 'payment', $id, ['utr' => $pay['transaction_reference'], 'amount' => $pay['amount']]);
        notify((int) $pay['user_id'], 'Payment verified', "Your payment of " . money($pay['amount']) . " (UTR {$pay['transaction_reference']}) for {$pay['application_no']} is verified. Your pass will be issued shortly.", 'student/applications.php');
        flash('success', "Payment verified for {$pay['full_name']}. You can now approve the pass.");
        redirect('admin/applications.php?id=' . $pay['app_id']);
    }
    if ($action === 'reject') {
        $reason = input('reason');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            flash('danger', 'Enter a rejection reason (3–255 characters).');
            redirect('admin/payments.php');
        }
        $pdo->beginTransaction();
        // UTR is kept on the row (still unique) so the same fake UTR can't be reused by someone else;
        // the student resubmits on the same record.
        q('UPDATE payments SET status = "rejected", rejection_reason = ?, verified_by = ?, verified_at = ? WHERE id = ? AND status = "submitted"',
            [$reason, $admin['id'], date('Y-m-d H:i:s'), $id]);
        q('UPDATE bus_pass_applications SET status = "payment_pending" WHERE id = ? AND status = "payment_submitted"', [$pay['app_id']]);
        $pdo->commit();
        audit_log('payment_rejected', 'payment', $id, ['utr' => $pay['transaction_reference'], 'reason' => $reason]);
        notify((int) $pay['user_id'], 'Payment rejected', "Your payment for {$pay['application_no']} was rejected: {$reason}. Please check and resubmit.", 'student/applications.php');
        flash('success', 'Payment rejected and the student notified.');
        redirect('admin/payments.php');
    }
}

$status = input('status', 'submitted');
$q = input('q');
$where = [];
$params = [];
if (in_array($status, ['pending', 'submitted', 'verified', 'rejected'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(s.usn LIKE ? OR u.full_name LIKE ? OR p.transaction_reference LIKE ? OR a.application_no LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$base = ' FROM payments p JOIN bus_pass_applications a ON a.id = p.application_id
          JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id
          LEFT JOIN users v ON v.id = p.verified_by';
$total = (int) q_val('SELECT COUNT(*)' . $base . $w, $params);
$pg = paginate($total, 25);
$rows = q_all('SELECT p.*, a.application_no, a.id AS app_id, a.fee_amount, u.full_name, s.usn, v.full_name AS verifier'
    . $base . $w . ' ORDER BY p.submitted_at IS NULL, p.submitted_at ASC, p.id DESC LIMIT ' . $pg['limit'] . ' OFFSET ' . $pg['offset'], $params);

page_header('Payment Verification', 'payments.php');
?>
<div class="alert alert-light border small"><i class="bi bi-info-circle"></i> Check each UTR and amount against the college's bank / UPI merchant statement for
  <strong><?= e((string) setting('payment_upi_id')) ?></strong> before verifying. A UTR typed by a student is <strong>not</strong> proof of payment.</div>
<form class="row g-2 mb-3" method="get">
  <div class="col-sm-4 col-lg-3"><select class="form-select" name="status" data-autosubmit>
    <?php foreach (['submitted' => 'Submitted (to verify)', 'verified' => 'Verified', 'rejected' => 'Rejected', 'pending' => 'Not yet paid', 'all' => 'All'] as $k => $l): ?>
      <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option>
    <?php endforeach; ?>
  </select></div>
  <div class="col-sm-5 col-lg-4"><input class="form-control" name="q" placeholder="USN, name, UTR, application" value="<?= e($q) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Search</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Student</th><th>Application</th><th>Amount</th><th>UTR / Reference</th><th>Payment date</th><th>Screenshot</th><th>Status</th><th style="min-width:230px">Action</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['full_name']) ?><br><small class="text-muted"><?= e($r['usn']) ?></small></td>
    <td><a href="<?= e(url('admin/applications.php?id=' . $r['app_id'])) ?>"><?= e($r['application_no']) ?></a></td>
    <td class="fw-semibold"><?= e(money($r['amount'])) ?><?= (float) $r['amount'] !== (float) $r['fee_amount'] ? '<br><small class="text-danger">fee ' . e(money($r['fee_amount'])) . '</small>' : '' ?></td>
    <td><code><?= e($r['transaction_reference'] ?? '—') ?></code><br><small class="text-muted"><?= e($r['payment_method']) ?> · <?= e(fmt_datetime($r['submitted_at'])) ?></small></td>
    <td><?= e(fmt_date($r['payment_date'])) ?></td>
    <td><?php if ($r['payment_screenshot']): ?><a href="<?= e(url('file.php?type=payment&id=' . $r['id'])) ?>" target="_blank" rel="noopener"><img src="<?= e(url('file.php?type=payment&id=' . $r['id'])) ?>" alt="Payment screenshot" style="height:56px;max-width:80px;object-fit:cover" class="rounded border"></a><?php else: ?><span class="text-muted small">none</span><?php endif; ?></td>
    <td><?= status_badge($r['status']) ?><?php if ($r['verifier']): ?><br><small class="text-muted"><?= e($r['verifier']) ?></small><?php endif; ?>
      <?php if ($r['rejection_reason']): ?><br><small class="text-danger"><?= e($r['rejection_reason']) ?></small><?php endif; ?></td>
    <td>
      <?php if ($r['status'] === 'submitted'): ?>
      <form method="post" class="d-inline" data-confirm="Confirm that <?= e(money($r['amount'])) ?> with UTR <?= e($r['transaction_reference']) ?> was received in the college account?">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="verify">
        <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> VERIFY PAYMENT</button>
      </form>
      <form method="post" class="mt-1 d-flex gap-1" data-confirm="Reject this payment?">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="reject">
        <input class="form-control form-control-sm" name="reason" required maxlength="255" placeholder="Reason">
        <button class="btn btn-sm btn-outline-danger text-nowrap">REJECT</button>
      </form>
      <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted">No payments in this view.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<?php page_footer();
