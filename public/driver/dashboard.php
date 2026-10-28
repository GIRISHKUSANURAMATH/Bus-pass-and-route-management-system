<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('driver');
$driver = current_driver();
$asg = driver_assignment((int) $driver['id']);
$trip = active_trip_for_driver((int) $driver['id']);
$live = $asg ? bus_live_payload((int) $asg['bus_id']) : null;
$passengers = $asg ? (int) q_val("SELECT COUNT(*) FROM bus_passes WHERE status = 'active' AND valid_until >= ? AND valid_from <= ?
    AND (bus_id = ? OR (bus_id IS NULL AND route_id = ?))", [date('Y-m-d'), date('Y-m-d'), $asg['bus_id'], $asg['route_id']]) : 0;
$recent = q_all('SELECT t.*, b.bus_code FROM trips t JOIN buses b ON b.id = t.bus_id WHERE t.driver_id = ? ORDER BY t.id DESC LIMIT 5', [$driver['id']]);

page_header('Driver Dashboard', 'dashboard.php');
?>
<p class="text-muted mt-n2"><?= e($user['full_name']) ?> · Driver ID <strong><?= e($driver['driver_code']) ?></strong></p>
<?php if (!$asg): ?>
  <div class="alert alert-warning">No bus is assigned to you yet. Please contact the transport office.</div>
<?php else: ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><?= stat_card('Assigned Bus', $asg['bus_code'] . ' · ' . $asg['registration_number'], 'bus-front', 'primary') ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('Assigned Route', $asg['origin'] ? $asg['origin'] . ' → ' . $asg['destination'] : 'None', 'signpost-split', 'info', url('driver/route.php')) ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('Trip Status', $trip ? 'ACTIVE (' . ($trip['gps_mode'] === 'simulation' ? 'DEMO' : 'GPS') . ')' : 'Not started', 'clock-history', $trip ? 'success' : 'secondary', url('driver/live-tracking.php')) ?></div>
  <div class="col-6 col-lg-3"><?= stat_card('GPS Status', gps_status_text($live['status'] ?? 'no_trip'), 'broadcast', ($live['status'] ?? '') === 'live' ? 'success' : 'warning') ?></div>
</div>
<div class="row g-3 mb-4">
  <?php foreach ([
      ['live-tracking.php', $trip ? 'Stop Trip' : 'Start Trip', $trip ? 'stop-circle' : 'play-circle'],
      ['live-tracking.php', 'Live Tracking', 'broadcast'],
      ['route.php', 'View Route', 'map'],
      ['route.php#stops', 'View Stops', 'pin-map'],
      ['passengers.php', 'Passengers (' . $passengers . ')', 'people'],
      ['profile.php', 'Profile', 'person-circle'],
  ] as [$f, $l, $i]): ?>
  <div class="col-4 col-md-2"><a class="quick-action" href="<?= e(url('driver/' . $f)) ?>"><i class="bi bi-<?= $i ?>"></i><span class="small"><?= e($l) ?></span></a></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="card"><div class="card-header">Recent trips</div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Trip</th><th>Bus</th><th>Mode</th><th>Started</th><th>Ended</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $t): ?>
      <tr><td>#<?= (int) $t['id'] ?></td><td><?= e($t['bus_code']) ?></td><td><?= $t['gps_mode'] === 'simulation' ? '<span class="badge text-bg-primary">DEMO</span>' : 'Real GPS' ?></td>
      <td><?= e(fmt_datetime($t['started_at'])) ?></td><td><?= e(fmt_datetime($t['ended_at'])) ?></td><td><?= status_badge($t['status']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6" class="text-muted">No trips yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php page_footer();
