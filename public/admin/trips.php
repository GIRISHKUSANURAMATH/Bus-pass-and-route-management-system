<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');

if (is_post() && input('action') === 'cancel') {
    require_csrf();
    $id = input_int('id') ?? 0;
    $n = q("UPDATE trips SET status = 'cancelled', ended_at = ? WHERE id = ? AND status = 'active'", [date('Y-m-d H:i:s'), $id])->rowCount();
    if ($n) {
        audit_log('trip_force_ended', 'trip', $id);
        flash('success', 'Trip ended by admin. The driver will see "trip no longer active".');
    }
    redirect('admin/trips.php');
}

// --- Single trip: GPS path on map
if ($id = input_int('id')) {
    $t = q_one('SELECT t.*, b.bus_code, b.registration_number, r.name AS route_name, u.full_name AS driver
                FROM trips t JOIN buses b ON b.id = t.bus_id JOIN routes r ON r.id = t.route_id
                JOIN drivers d ON d.id = t.driver_id JOIN users u ON u.id = d.user_id WHERE t.id = ?', [$id]);
    page_header('Trip #' . $id, 'trips.php', ['vendor/leaflet/leaflet.css']);
    if (!$t) { echo '<div class="alert alert-warning">Not found.</div>'; page_footer(); exit; }
    $pts = q_all('SELECT latitude AS lat, longitude AS lng, received_at FROM bus_locations WHERE trip_id = ? ORDER BY id LIMIT 5000', [$id]);
    $path = array_map(fn ($p) => [(float) $p['lat'], (float) $p['lng']], $pts);
    ?>
    <a class="small" href="<?= e(url('admin/trips.php')) ?>">&larr; All trips</a>
    <div class="row g-3 mt-1">
      <div class="col-lg-4"><div class="card"><div class="card-body small"><dl class="row mb-0">
        <dt class="col-5">Bus</dt><dd class="col-7"><?= e($t['bus_code'] . ' · ' . $t['registration_number']) ?></dd>
        <dt class="col-5">Driver</dt><dd class="col-7"><?= e($t['driver']) ?></dd>
        <dt class="col-5">Route</dt><dd class="col-7"><?= e($t['route_name']) ?></dd>
        <dt class="col-5">GPS mode</dt><dd class="col-7"><?= $t['gps_mode'] === 'simulation' ? '<span class="badge text-bg-primary">DEMO SIMULATION</span>' : 'Real GPS' ?></dd>
        <dt class="col-5">Started</dt><dd class="col-7"><?= e(fmt_datetime($t['started_at'])) ?></dd>
        <dt class="col-5">Ended</dt><dd class="col-7"><?= e(fmt_datetime($t['ended_at'])) ?></dd>
        <dt class="col-5">Status</dt><dd class="col-7"><?= status_badge($t['status']) ?></dd>
        <dt class="col-5">GPS points</dt><dd class="col-7"><?= count($pts) ?></dd>
      </dl></div></div></div>
      <div class="col-lg-8"><div id="routeMap" class="map-lg"></div></div>
    </div>
    <script type="application/json" id="routeMapData"><?= json_for_html(['stops' => stops_for_map((int) $t['route_id']), 'path' => $path]) ?></script>
    <?php
    page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/route-map.js']);
    exit;
}

$status = input('status');
$where = in_array($status, ['active', 'completed', 'cancelled', 'scheduled'], true) ? ' WHERE t.status = ?' : '';
$params = $where ? [$status] : [];
$total = (int) q_val('SELECT COUNT(*) FROM trips t' . $where, $params);
$pg = paginate($total, 25);
$rows = q_all('SELECT t.*, b.bus_code, r.route_code, u.full_name AS driver,
                      (SELECT COUNT(*) FROM bus_locations l WHERE l.trip_id = t.id) AS points
               FROM trips t JOIN buses b ON b.id = t.bus_id JOIN routes r ON r.id = t.route_id
               JOIN drivers d ON d.id = t.driver_id JOIN users u ON u.id = d.user_id' . $where .
              ' ORDER BY t.id DESC LIMIT ' . $pg['limit'] . ' OFFSET ' . $pg['offset'], $params);

page_header('Trips', 'trips.php');
?>
<form class="row g-2 mb-3" method="get"><div class="col-auto"><select class="form-select" name="status" data-autosubmit>
  <option value="">All trips</option><?php foreach (['active', 'completed', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
</select></div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Trip</th><th>Bus</th><th>Route</th><th>Driver</th><th>Mode</th><th>Started</th><th>Ended</th><th>Last GPS</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): ?>
  <tr><td>#<?= (int) $t['id'] ?></td><td><?= e($t['bus_code']) ?></td><td><?= e($t['route_code']) ?></td><td><?= e($t['driver']) ?></td>
    <td><?= $t['gps_mode'] === 'simulation' ? '<span class="badge text-bg-primary">DEMO</span>' : 'GPS' ?></td>
    <td class="small"><?= e(fmt_datetime($t['started_at'])) ?></td><td class="small"><?= e(fmt_datetime($t['ended_at'])) ?></td>
    <td class="small"><?= e(time_ago($t['last_location_at'])) ?> (<?= (int) $t['points'] ?>)</td><td><?= status_badge($t['status']) ?></td>
    <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/trips.php?id=' . $t['id'])) ?>">Path</a>
      <?php if ($t['status'] === 'active'): ?><a class="btn btn-sm btn-outline-success" href="<?= e(url('admin/live-map.php?bus=' . $t['bus_id'])) ?>">Live</a>
      <form method="post" class="d-inline" data-confirm="Force-end this trip?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-outline-danger">End</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="text-muted">No trips.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<?php page_footer();
