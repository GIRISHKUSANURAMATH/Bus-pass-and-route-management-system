<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('admin');
$today = date('Y-m-d');

$stats = [
    'students'      => (int) q_val("SELECT COUNT(*) FROM students"),
    'active_passes' => (int) q_val("SELECT COUNT(*) FROM bus_passes WHERE status = 'active' AND valid_until >= ? AND valid_from <= ?", [$today, $today]),
    'pending_apps'  => (int) q_val("SELECT COUNT(*) FROM bus_pass_applications WHERE status IN ('payment_pending','payment_submitted','pending_approval')"),
    'pending_pay'   => (int) q_val("SELECT COUNT(*) FROM payments WHERE status = 'submitted'"),
    'to_approve'    => (int) q_val("SELECT COUNT(*) FROM bus_pass_applications WHERE status = 'pending_approval'"),
    'buses'         => (int) q_val('SELECT COUNT(*) FROM buses'),
    'active_buses'  => (int) q_val("SELECT COUNT(*) FROM buses WHERE status = 'active'"),
    'routes'        => (int) q_val('SELECT COUNT(*) FROM routes'),
    'active_trips'  => (int) q_val("SELECT COUNT(*) FROM trips WHERE status = 'active'"),
    'live_buses'    => (int) q_val("SELECT COUNT(*) FROM trips WHERE status = 'active' AND last_location_at >= ?",
                        [date('Y-m-d H:i:s', time() - int_setting('gps_live_seconds'))]),
    'revenue'       => (float) q_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified'"),
];

$byRoute = q_all("SELECT r.route_code AS name, COUNT(p.id) AS n FROM routes r
                  LEFT JOIN bus_passes p ON p.route_id = r.id AND p.status = 'active' AND p.valid_until >= ?
                  GROUP BY r.id, r.route_code ORDER BY r.route_code", [$today]);
$payStatus = q_all('SELECT status, COUNT(*) AS n FROM payments GROUP BY status');
$recent = q_all("SELECT l.action, l.details, l.created_at, u.full_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
                 WHERE l.action IN ('application_created','payment_submitted','payment_verified','payment_rejected','pass_issued',
                                    'application_rejected','trip_started','trip_stopped','student_registered')
                 ORDER BY l.id DESC LIMIT 12");
$actionLabels = [
    'application_created' => ['New application', 'file-earmark-plus', 'primary'],
    'payment_submitted' => ['Payment submitted', 'cash', 'info'],
    'payment_verified' => ['Payment verified', 'check2-circle', 'success'],
    'payment_rejected' => ['Payment rejected', 'x-circle', 'danger'],
    'pass_issued' => ['Pass approved', 'person-badge', 'success'],
    'application_rejected' => ['Application rejected', 'x-octagon', 'danger'],
    'trip_started' => ['Driver started trip', 'play-circle', 'success'],
    'trip_stopped' => ['Driver stopped trip', 'stop-circle', 'secondary'],
    'student_registered' => ['Student registered', 'person-plus', 'primary'],
];

page_header('Admin Dashboard', 'dashboard.php');
if (!config('app.env_loaded')): ?>
  <div class="alert alert-warning small"><strong>.env not found</strong> – running on built-in XAMPP defaults. Copy <code>.env.example</code> to <code>.env</code> and set APP_URL (needed for pass QR codes on phones).</div>
<?php endif; ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Total Students', $stats['students'], 'mortarboard', 'primary', url('admin/students.php')) ?></div>
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Active Bus Passes', $stats['active_passes'], 'person-badge', 'success', url('admin/passes.php')) ?></div>
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Pending Applications', $stats['pending_apps'], 'file-earmark-text', 'warning', url('admin/applications.php')) ?></div>
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Pending Payments', $stats['pending_pay'], 'cash-coin', 'danger', url('admin/payments.php')) ?></div>
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Total / Active Buses', $stats['buses'] . ' / ' . $stats['active_buses'], 'bus-front', 'info', url('admin/buses.php')) ?></div>
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Total Routes', $stats['routes'], 'signpost-split', 'primary', url('admin/routes.php')) ?></div>
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Active Trips', $stats['active_trips'], 'clock-history', 'success', url('admin/trips.php')) ?></div>
  <div class="col-6 col-md-4 col-xl-3"><?= stat_card('Live Buses', $stats['live_buses'], 'broadcast', 'success', url('admin/live-map.php')) ?></div>
</div>
<?php if ($stats['pending_pay'] || $stats['to_approve']): ?>
<div class="alert alert-info d-flex flex-wrap gap-3 align-items-center">
  <span><i class="bi bi-bell"></i> Action needed:</span>
  <?php if ($stats['pending_pay']): ?><a class="btn btn-sm btn-primary" href="<?= e(url('admin/payments.php')) ?>">Verify <?= $stats['pending_pay'] ?> payment(s)</a><?php endif; ?>
  <?php if ($stats['to_approve']): ?><a class="btn btn-sm btn-success" href="<?= e(url('admin/applications.php?status=pending_approval')) ?>">Approve <?= $stats['to_approve'] ?> pass(es)</a><?php endif; ?>
</div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Active passes by route</div><div class="card-body"><canvas id="chartRoutes" height="220"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Payments by status</div><div class="card-body"><canvas id="chartPayments" height="220"></canvas>
    <div class="small text-muted text-center mt-2">Verified collections: <strong><?= e(money($stats['revenue'])) ?></strong></div></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header">Recent activity</div>
    <ul class="list-group list-group-flush small">
      <?php foreach ($recent as $r): [$label, $icon, $color] = $actionLabels[$r['action']]; ?>
      <li class="list-group-item d-flex gap-2"><i class="bi bi-<?= $icon ?> text-<?= $color ?>"></i>
        <div class="flex-grow-1"><strong><?= e($label) ?></strong> <span class="text-muted">· <?= e($r['full_name'] ?? 'system') ?></span><br><span class="text-muted"><?= e(time_ago($r['created_at'])) ?></span></div></li>
      <?php endforeach; ?>
      <?php if (!$recent): ?><li class="list-group-item text-muted">No activity yet.</li><?php endif; ?>
    </ul>
  </div></div>
</div>
<script type="application/json" id="dashCharts"><?= json_for_html([
    'routes' => ['labels' => array_column($byRoute, 'name'), 'data' => array_map('intval', array_column($byRoute, 'n'))],
    'payments' => ['labels' => array_map(fn ($s) => ucfirst($s), array_column($payStatus, 'status')), 'data' => array_map('intval', array_column($payStatus, 'n')),
                   'colors' => array_map(fn ($s) => ['pending' => '#f59e0b', 'submitted' => '#1d4ed8', 'verified' => '#16a34a', 'rejected' => '#dc2626'][$s] ?? '#64748b', array_column($payStatus, 'status'))],
]) ?></script>
<?php page_footer(['vendor/chartjs/chart.umd.js', 'js/admin-charts.js']);
