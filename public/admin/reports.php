<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');
$today = date('Y-m-d');

/** CSV cell - neutralise spreadsheet formula injection. */
function csv_cell(mixed $v): string
{
    $v = (string) ($v ?? '');
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

$exports = [
    'passes' => ['SELECT p.pass_number, u.full_name, s.usn, s.branch, s.year_of_study, r.name AS route, b.bus_code, bs.name AS boarding, ds.name AS destination, p.valid_from, p.valid_until, p.status
                  FROM bus_passes p JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id JOIN routes r ON r.id = p.route_id
                  LEFT JOIN buses b ON b.id = p.bus_id JOIN bus_stops bs ON bs.id = p.boarding_stop_id JOIN bus_stops ds ON ds.id = p.destination_stop_id ORDER BY p.id'],
    'payments' => ['SELECT a.application_no, u.full_name, s.usn, p.amount, p.payment_method, p.transaction_reference, p.payment_date, p.status, p.submitted_at, p.verified_at, v.full_name AS verified_by
                  FROM payments p JOIN bus_pass_applications a ON a.id = p.application_id JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id
                  LEFT JOIN users v ON v.id = p.verified_by ORDER BY p.id'],
    'applications' => ['SELECT a.application_no, a.application_type, u.full_name, s.usn, r.name AS route, a.duration, a.academic_year, a.distance_km, a.fee_amount, a.status, a.created_at
                  FROM bus_pass_applications a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN routes r ON r.id = a.route_id ORDER BY a.id'],
];
if (($x = input('export')) && isset($exports[$x])) {
    audit_log('report_exported', 'report', null, ['type' => $x]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sksvmacet-' . $x . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
    $rows = q_all($exports[$x][0]);
    if ($rows) {
        fputcsv($out, array_keys($rows[0]), ',', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($out, array_map('csv_cell', $r), ',', '"', '\\');
        }
    }
    exit;
}

$revenue = q_all("SELECT DATE_FORMAT(verified_at, '%Y-%m') AS m, SUM(amount) AS total FROM payments
                  WHERE status = 'verified' AND verified_at >= ? GROUP BY m ORDER BY m", [date('Y-m-01', strtotime('-11 months'))]);
$branch = q_all("SELECT s.branch, COUNT(*) AS n FROM bus_passes p JOIN students s ON s.id = p.student_id
                 WHERE p.status = 'active' AND p.valid_until >= ? GROUP BY s.branch ORDER BY n DESC", [$today]);
$passStatus = q_all("SELECT CASE WHEN status = 'active' AND valid_until < ? THEN 'expired' ELSE status END AS st, COUNT(*) AS n FROM bus_passes GROUP BY st", [$today]);
$appsRoute = q_all('SELECT r.route_code, COUNT(a.id) AS n FROM routes r LEFT JOIN bus_pass_applications a ON a.route_id = r.id GROUP BY r.id, r.route_code ORDER BY r.route_code');
$occupancy = q_all("SELECT r.route_code, r.name,
                           (SELECT COUNT(*) FROM buses b WHERE b.route_id = r.id AND b.status = 'active') AS buses,
                           (SELECT COALESCE(SUM(b.capacity),0) FROM buses b WHERE b.route_id = r.id AND b.status = 'active') AS capacity,
                           (SELECT COUNT(*) FROM bus_passes p WHERE p.route_id = r.id AND p.status = 'active' AND p.valid_until >= ?) AS passes,
                           (SELECT COALESCE(SUM(pay.amount),0) FROM payments pay JOIN bus_pass_applications a ON a.id = pay.application_id WHERE a.route_id = r.id AND pay.status = 'verified') AS collected
                    FROM routes r ORDER BY r.route_code", [$today]);
$totals = [
    'collected' => (float) q_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified'"),
    'awaiting'  => (float) q_val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'submitted'"),
    'apps'      => (int) q_val('SELECT COUNT(*) FROM bus_pass_applications'),
    'trips30'   => (int) q_val("SELECT COUNT(*) FROM trips WHERE started_at >= ?", [date('Y-m-d', strtotime('-30 days'))]),
];

page_header('Reports', 'reports.php');
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/reports.php?export=passes')) ?>"><i class="bi bi-download"></i> Passes CSV</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/reports.php?export=payments')) ?>"><i class="bi bi-download"></i> Payments CSV</a>
  <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/reports.php?export=applications')) ?>"><i class="bi bi-download"></i> Applications CSV</a>
  <button class="btn btn-sm btn-outline-secondary" type="button" data-print><i class="bi bi-printer"></i> Print</button>
</div>
<div class="print-area">
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?= stat_card('Verified collections', money($totals['collected']), 'cash-stack', 'success') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('Awaiting verification', money($totals['awaiting']), 'hourglass-split', 'warning') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('Applications (all time)', $totals['apps'], 'file-earmark-text', 'primary') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('Trips (last 30 days)', $totals['trips30'], 'clock-history', 'info') ?></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Verified collections by month (₹)</div><div class="card-body"><canvas id="chartRevenue" height="200"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Active passes by branch</div><div class="card-body"><canvas id="chartBranch" height="200"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Passes by status</div><div class="card-body"><canvas id="chartPassStatus" height="200"></canvas></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header">Applications by route</div><div class="card-body"><canvas id="chartAppsRoute" height="200"></canvas></div></div></div>
</div>
<div class="card"><div class="card-header">Route occupancy</div><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Route</th><th>Active buses</th><th>Seats</th><th>Active passes</th><th>Occupancy</th><th>Collected</th></tr></thead>
  <tbody><?php foreach ($occupancy as $o): $pct = $o['capacity'] > 0 ? round($o['passes'] / $o['capacity'] * 100) : 0; ?>
    <tr><td><?= e($o['route_code'] . ' · ' . $o['name']) ?></td><td><?= (int) $o['buses'] ?></td><td><?= (int) $o['capacity'] ?></td><td><?= (int) $o['passes'] ?></td>
      <td style="min-width:140px"><div class="progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $pct > 100 ? 'bg-danger' : '' ?>" style="width:<?= min(100, $pct) ?>%"><?= $pct ?>%</div></div></td>
      <td><?= e(money($o['collected'])) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div></div>
</div>
<script type="application/json" id="reportCharts"><?= json_for_html([
    'revenue' => ['labels' => array_column($revenue, 'm'), 'data' => array_map('floatval', array_column($revenue, 'total')), 'label' => '₹'],
    'branch' => ['labels' => array_column($branch, 'branch'), 'data' => array_map('intval', array_column($branch, 'n'))],
    'passStatus' => ['labels' => array_map('ucfirst', array_column($passStatus, 'st')), 'data' => array_map('intval', array_column($passStatus, 'n')),
                     'colors' => array_map(fn ($s) => ['active' => '#16a34a', 'expired' => '#64748b', 'revoked' => '#dc2626'][$s] ?? '#1d4ed8', array_column($passStatus, 'st'))],
    'appsRoute' => ['labels' => array_column($appsRoute, 'route_code'), 'data' => array_map('intval', array_column($appsRoute, 'n'))],
]) ?></script>
<?php page_footer(['vendor/chartjs/chart.umd.js', 'js/admin-charts.js']);
