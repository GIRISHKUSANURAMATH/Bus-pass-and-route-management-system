<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');

$action = input('action_filter');
$q = input('q');
$where = [];
$params = [];
if ($action !== '' && preg_match('/^[a-z_]+$/', $action)) {
    $where[] = 'l.action = ?';
    $params[] = $action;
}
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR l.ip_address LIKE ? OR l.details LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) q_val('SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id' . $w, $params);
$pg = paginate($total, 50);
$rows = q_all('SELECT l.*, u.full_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id' . $w . ' ORDER BY l.id DESC LIMIT ' . $pg['limit'] . ' OFFSET ' . $pg['offset'], $params);
$actions = array_column(q_all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
$security = ['login_failed', 'login_locked', 'csrf_rejected', 'access_denied', 'duplicate_utr_attempt', 'gps_jump_rejected', 'password_change_failed'];

page_header('Audit Logs', 'audit-logs.php');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-sm-4 col-lg-3"><select class="form-select" name="action_filter" data-autosubmit><option value="">All actions</option>
    <?php foreach ($actions as $a): ?><option<?= $a === $action ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-5 col-lg-4"><input class="form-control" name="q" placeholder="User, IP or details" value="<?= e($q) ?>"></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Filter</button></div>
  <div class="col-auto align-self-center small text-muted"><?= $total ?> entries</div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 small">
  <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr class="<?= in_array($r['action'], $security, true) ? 'table-warning' : '' ?>">
    <td class="text-nowrap"><?= e(fmt_datetime($r['created_at'])) ?></td>
    <td><?= e($r['full_name'] ?? '—') ?><?= $r['user_role'] ? ' <span class="text-muted">(' . e($r['user_role']) . ')</span>' : '' ?></td>
    <td><code><?= e($r['action']) ?></code></td>
    <td><?= e(($r['entity_type'] ?? '') . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) ?></td>
    <td style="max-width:360px" class="text-break"><?= e($r['details'] ?? '') ?></td>
    <td><?= e($r['ip_address']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">No entries.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<p class="small text-muted">Highlighted rows are security events (failed logins, lockouts, CSRF rejections, access denials, duplicate UTRs, rejected GPS jumps).</p>
<?php page_footer();
