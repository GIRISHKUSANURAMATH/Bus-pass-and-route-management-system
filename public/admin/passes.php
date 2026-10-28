<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_role('admin');

if (is_post()) {
    require_csrf();
    $id = input_int('id') ?? 0;
    $pass = q_one(PASS_SELECT . ' WHERE p.id = ?', [$id]);
    if (!$pass) {
        flash('danger', 'Pass not found.');
        redirect('admin/passes.php');
    }
    switch (input('action')) {
        case 'revoke':
            q("UPDATE bus_passes SET status = 'revoked' WHERE id = ?", [$id]);
            audit_log('pass_revoked', 'bus_pass', $id, ['reason' => input('reason')]);
            notify((int) $pass['user_id'], 'Bus pass revoked', "Your bus pass {$pass['pass_number']} was revoked. " . input('reason'), 'student/pass.php');
            flash('success', 'Pass revoked.');
            break;
        case 'reactivate':
            q("UPDATE bus_passes SET status = 'active' WHERE id = ? AND valid_until >= ?", [$id, date('Y-m-d')])->rowCount()
                ? flash('success', 'Pass reactivated.') : flash('warning', 'An expired pass cannot be reactivated – extend it or ask the student to renew.');
            audit_log('pass_reactivated', 'bus_pass', $id);
            break;
        case 'extend':   // admin renewal / correction
            $until = input('valid_until');
            if (!valid_date($until) || $until < $pass['valid_from']) {
                flash('danger', 'Choose a valid end date after the start date.');
                break;
            }
            q("UPDATE bus_passes SET valid_until = ?, status = IF(? >= CURDATE() AND status = 'expired', 'active', status) WHERE id = ?", [$until, $until, $id]);
            audit_log('pass_validity_changed', 'bus_pass', $id, ['from' => $pass['valid_until'], 'to' => $until]);
            notify((int) $pass['user_id'], 'Bus pass validity updated', "Your pass {$pass['pass_number']} is now valid until " . fmt_date($until) . '.', 'student/pass.php');
            flash('success', 'Validity updated.');
            break;
        case 'change_bus':
            $busId = input_int('bus_id');
            if ($busId && !q_val('SELECT 1 FROM buses WHERE id = ? AND route_id = ?', [$busId, $pass['route_id']])) {
                flash('danger', 'That bus does not run on this route.');
                break;
            }
            q('UPDATE bus_passes SET bus_id = ? WHERE id = ?', [$busId ?: null, $id]);
            audit_log('pass_bus_changed', 'bus_pass', $id, ['bus_id' => $busId]);
            flash('success', 'Bus updated.');
            break;
    }
    redirect('admin/passes.php?view=' . $id);
}

// ---------------- Single pass view ----------------
if ($id = input_int('view')) {
    $pass = q_one(PASS_SELECT . ' WHERE p.id = ?', [$id]);
    page_header('Bus Pass', 'passes.php');
    if (!$pass) {
        echo '<div class="alert alert-warning">Not found.</div>';
        page_footer();
        exit;
    }
    $status = pass_effective_status($pass);
    $verifyUrl = absolute_url('verify-pass.php?token=' . $pass['verification_token']);
    $buses = q_all('SELECT id, bus_code, registration_number FROM buses WHERE route_id = ?', [$pass['route_id']]);
    ?>
    <a href="<?= e(url('admin/passes.php')) ?>" class="small">&larr; All passes</a>
    <div class="row g-4 mt-1">
      <div class="col-lg-6">
        <button class="btn btn-primary mb-3" type="button" data-print><i class="bi bi-printer"></i> Print pass</button>
        <a class="btn btn-outline-primary mb-3" id="downloadQr" href="#" download="<?= e($pass['pass_number']) ?>-qr.png">Download QR</a>
        <?php require __DIR__ . '/../../src/views/pass_card.php'; ?>
      </div>
      <div class="col-lg-6">
        <div class="card mb-3"><div class="card-header">Validity / renewal</div><div class="card-body">
          <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="extend">
            <div class="col"><label class="form-label" for="valid_until">Valid until</label><input class="form-control" type="date" id="valid_until" name="valid_until" value="<?= e($pass['valid_until']) ?>"></div>
            <div class="col-auto"><button class="btn btn-outline-primary">Update</button></div>
          </form>
          <p class="small text-muted mt-2 mb-0">For a paid renewal the student should apply via "Renew" so a payment record exists.</p>
        </div></div>
        <div class="card mb-3"><div class="card-header">Bus</div><div class="card-body">
          <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="change_bus">
            <div class="col"><select class="form-select" name="bus_id"><option value="">Any bus on route</option>
              <?php foreach ($buses as $b): ?><option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === (int) $pass['bus_id'] ? ' selected' : '' ?>><?= e($b['bus_code'] . ' · ' . $b['registration_number']) ?></option><?php endforeach; ?></select></div>
            <div class="col-auto"><button class="btn btn-outline-primary">Save</button></div>
          </form>
        </div></div>
        <div class="card"><div class="card-header">Status</div><div class="card-body">
          <?php if ($pass['status'] === 'active'): ?>
          <form method="post" data-confirm="Revoke this pass? The QR will show INVALID.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="revoke">
            <input class="form-control mb-2" name="reason" maxlength="200" placeholder="Reason (sent to student)">
            <button class="btn btn-outline-danger">Revoke pass</button>
          </form>
          <?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reactivate">
            <button class="btn btn-outline-success">Reactivate</button></form>
          <?php endif; ?>
          <p class="small text-muted mt-2 mb-0">Verification link: <a href="<?= e($verifyUrl) ?>" target="_blank" rel="noopener">open</a></p>
        </div></div>
      </div>
    </div>
    <?php
    page_footer(['vendor/qrcode/qrcode.js', 'js/pass-qr.js']);
    exit;
}

// ---------------- List ----------------
$filter = input('status', 'active');
$q = input('q');
$today = date('Y-m-d');
$where = [];
$params = [];
if ($filter === 'active') {
    $where[] = "p.status = 'active' AND p.valid_until >= ?";
    $params[] = $today;
} elseif ($filter === 'expired') {
    $where[] = "(p.status = 'expired' OR (p.status = 'active' AND p.valid_until < ?))";
    $params[] = $today;
} elseif ($filter === 'expiring') {
    $where[] = "p.status = 'active' AND p.valid_until BETWEEN ? AND ?";
    array_push($params, $today, date('Y-m-d', strtotime('+30 days')));
} elseif ($filter === 'revoked') {
    $where[] = "p.status = 'revoked'";
}
if ($q !== '') {
    $where[] = '(p.pass_number LIKE ? OR s.usn LIKE ? OR u.full_name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) q_val('SELECT COUNT(*) FROM bus_passes p JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id' . $w, $params);
$pg = paginate($total, 25);
$rows = q_all(PASS_SELECT . $w . ' ORDER BY p.id DESC LIMIT ' . $pg['limit'] . ' OFFSET ' . $pg['offset'], $params);

page_header('Bus Passes', 'passes.php');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-sm-4 col-lg-3"><select class="form-select" name="status" data-autosubmit>
    <?php foreach (['active' => 'Active', 'expiring' => 'Expiring in 30 days', 'expired' => 'Expired', 'revoked' => 'Revoked', 'all' => 'All'] as $k => $l): ?>
      <option value="<?= $k ?>"<?= $filter === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-sm-5 col-lg-4"><input class="form-control" name="q" placeholder="Pass ID, USN or name" value="<?= e($q) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Search</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Pass ID</th><th>Student</th><th>Route / Bus</th><th>Stops</th><th>Validity</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['pass_number']) ?></td>
    <td><?= e($r['full_name']) ?><br><small class="text-muted"><?= e($r['usn']) ?> · <?= e($r['branch']) ?></small></td>
    <td><?= e($r['origin'] . ' → ' . $r['destination']) ?><br><small class="text-muted"><?= e($r['bus_code'] ?? 'any bus') ?></small></td>
    <td class="small"><?= e($r['boarding_stop'] . ' → ' . $r['destination_stop']) ?></td>
    <td class="small"><?= e(fmt_date($r['valid_from'])) ?> – <?= e(fmt_date($r['valid_until'])) ?></td>
    <td><?= status_badge(pass_effective_status($r)) ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/passes.php?view=' . $r['id'])) ?>">View</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No passes.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<?php page_footer();
