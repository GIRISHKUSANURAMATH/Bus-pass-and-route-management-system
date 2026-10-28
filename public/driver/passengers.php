<?php
/** Students holding a currently valid pass for this driver's bus (or route, if the pass is not bus-specific). */
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('driver');
$driver = current_driver();
$asg = driver_assignment((int) $driver['id']);
$today = date('Y-m-d');
$rows = $asg ? q_all("SELECT p.pass_number, p.valid_until, u.full_name, s.usn, s.branch, s.year_of_study,
        bs.name AS boarding, ds.name AS destination, bs.stop_order
    FROM bus_passes p
    JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id
    JOIN bus_stops bs ON bs.id = p.boarding_stop_id JOIN bus_stops ds ON ds.id = p.destination_stop_id
    WHERE p.status = 'active' AND p.valid_from <= ? AND p.valid_until >= ?
      AND (p.bus_id = ? OR (p.bus_id IS NULL AND p.route_id = ?))
    ORDER BY bs.stop_order, u.full_name", [$today, $today, $asg['bus_id'], $asg['route_id']]) : [];

page_header('Authorized Passengers', 'passengers.php');
?>
<p class="text-muted"><?= $asg ? e($asg['bus_code'] . ' · ' . $asg['route_name']) : 'No bus assigned' ?> · <?= count($rows) ?> valid pass(es) today. Contact details are not shown to protect student privacy.</p>
<div class="card"><div class="table-responsive"><table class="table table-striped table-sm mb-0">
  <thead><tr><th>Name</th><th>USN</th><th>Branch / Year</th><th>Boarding</th><th>Destination</th><th>Pass</th><th>Valid until</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e($r['full_name']) ?></td><td><?= e($r['usn']) ?></td><td><?= e($r['branch'] . ' · ' . year_label((int) $r['year_of_study'])) ?></td>
    <td><?= e($r['boarding']) ?></td><td><?= e($r['destination']) ?></td><td><?= e($r['pass_number']) ?></td><td><?= e(fmt_date($r['valid_until'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No valid passes for your bus.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<?php page_footer();
