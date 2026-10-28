<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('driver');
$driver = current_driver();
$asg = driver_assignment((int) $driver['id']);

// Drivers may REQUEST a new stop - only on their own assigned route. Admin approves.
$errors = [];
if (is_post() && $asg && $asg['route_id']) {
    require_csrf();
    $name = input('name');
    $lat = input('latitude');
    $lng = input('longitude');
    $note = input('note');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) { $errors[] = 'Stop name must be 2–100 characters.'; }
    if (!valid_lat($lat) || !valid_lng($lng)) { $errors[] = 'Pick the stop location on the map.'; }
    if (mb_strlen($note) > 255) { $errors[] = 'Note is too long.'; }
    $pending = (int) q_val("SELECT COUNT(*) FROM stop_requests WHERE driver_id = ? AND status = 'pending'", [$driver['id']]);
    if ($pending >= 5) { $errors[] = 'You already have 5 pending requests. Wait for the office to review them.'; }
    if (!$errors) {
        q('INSERT INTO stop_requests (driver_id, route_id, name, latitude, longitude, note) VALUES (?, ?, ?, ?, ?, ?)',
            [$driver['id'], $asg['route_id'], $name, $lat, $lng, $note ?: null]);
        audit_log('stop_requested', 'route', (int) $asg['route_id'], ['name' => $name]);
        notify_admins('New stop request', "{$user['full_name']} requested stop \"{$name}\" on {$asg['route_name']}.", 'admin/stops.php');
        flash('success', 'Stop request sent to the transport office.');
        redirect('driver/route.php');
    }
}

page_header('Route & Stops', 'route.php', ['vendor/leaflet/leaflet.css']);
if (!$asg || !$asg['route_id']) {
    echo '<div class="alert alert-warning">No route is assigned to your bus.</div>';
    page_footer();
    exit;
}
$stops = route_stops((int) $asg['route_id']);
$requests = q_all('SELECT * FROM stop_requests WHERE driver_id = ? ORDER BY id DESC LIMIT 10', [$driver['id']]);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3" id="stops"><div class="card-header"><?= e($asg['route_name']) ?> · <?= e($asg['route_code']) ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>#</th><th>Stop</th><th>Km</th><th>Time</th><th>Dir.</th></tr></thead>
        <tbody><?php foreach ($stops as $s): ?><tr><td><?= (int) $s['stop_order'] ?></td><td><?= e($s['name']) ?></td><td><?= e($s['distance_km']) ?></td><td><?= e($s['est_arrival_time'] ? substr($s['est_arrival_time'], 0, 5) : '—') ?></td><td><?= e($s['direction']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
    </div>
    <div class="card"><div class="card-header">Request a new stop on this route</div><div class="card-body">
      <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label" for="name">Stop name</label><input class="form-control" id="name" name="name" required maxlength="100" value="<?= e(input('name')) ?>"></div>
        <div class="row g-2 mb-2">
          <div class="col"><label class="form-label" for="latitude">Latitude</label><input class="form-control" id="latitude" name="latitude" required value="<?= e(input('latitude')) ?>"></div>
          <div class="col"><label class="form-label" for="longitude">Longitude</label><input class="form-control" id="longitude" name="longitude" required value="<?= e(input('longitude')) ?>"></div>
        </div>
        <button class="btn btn-sm btn-outline-secondary mb-2" type="button" id="useMyLocation"><i class="bi bi-crosshair"></i> Use my current location</button>
        <div class="mb-2"><label class="form-label" for="note">Note (optional)</label><input class="form-control" id="note" name="note" maxlength="255"></div>
        <button class="btn btn-primary">Send request</button>
        <div class="form-text">Tip: tap on the map to pick the spot. The office reviews every request.</div>
      </form>
      <?php if ($requests): ?><hr><div class="small fw-semibold mb-1">My recent requests</div>
        <ul class="small mb-0"><?php foreach ($requests as $r): ?><li><?= e($r['name']) ?> – <?= status_badge($r['status'] === 'approved' ? 'verified' : ($r['status'] === 'pending' ? 'pending' : 'rejected')) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-7"><div id="pickerMap" class="map-lg"></div></div>
</div>
<script type="application/json" id="pickerData"><?= json_for_html(['stops' => stops_for_map((int) $asg['route_id'])]) ?></script>
<?php page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/stop-picker.js']);
