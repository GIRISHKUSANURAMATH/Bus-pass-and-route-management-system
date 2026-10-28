<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('student');
$student = current_student();
$pass = student_current_pass((int) $student['id']) ?? student_latest_pass((int) $student['id']);

$routes = q_all("SELECT id, name, origin, destination, description FROM routes WHERE status = 'active' ORDER BY name");
$routeId = input_int('route') ?? ($pass ? (int) $pass['route_id'] : (int) ($routes[0]['id'] ?? 0));
$route = q_one("SELECT * FROM routes WHERE id = ? AND status = 'active'", [$routeId]);
$isMine = $pass && $route && (int) $pass['route_id'] === (int) $route['id'];
$stops = $route ? route_stops((int) $route['id']) : [];
$buses = $route ? q_all("SELECT bus_code, bus_number, registration_number, status FROM buses WHERE route_id = ? AND status <> 'inactive'", [$route['id']]) : [];

page_header('Route & Stops', 'route.php', ['vendor/leaflet/leaflet.css']);
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><select class="form-select" name="route" data-autosubmit>
    <?php foreach ($routes as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $r['id'] === $routeId ? ' selected' : '' ?>><?= e($r['name']) ?><?= $pass && (int) $pass['route_id'] === (int) $r['id'] ? ' (my route)' : '' ?></option><?php endforeach; ?>
  </select></div>
</form>
<?php if (!$route): ?>
  <div class="alert alert-info">No routes available.</div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><?= e($route['name']) ?> <?= $isMine ? '<span class="badge text-bg-success">My route</span>' : '' ?></div>
      <div class="card-body small">
        <?php if ($route['description']): ?><p class="text-muted"><?= e($route['description']) ?></p><?php endif; ?>
        <?php if ($isMine): ?><p>Boarding: <strong><?= e($pass['boarding_stop']) ?></strong> · Destination: <strong><?= e($pass['destination_stop']) ?></strong><br>Bus: <strong><?= e($pass['bus_code'] ? $pass['bus_code'] . ' (' . $pass['registration_number'] . ')' : 'Any bus on route') ?></strong></p><?php endif; ?>
        <p class="mb-1 fw-semibold">Buses on this route</p>
        <ul class="mb-0"><?php foreach ($buses as $b): ?><li><?= e($b['bus_code'] . ' · ' . $b['registration_number']) ?> <?= status_badge($b['status']) ?></li><?php endforeach; ?><?= $buses ? '' : '<li class="text-muted">None assigned yet</li>' ?></ul>
      </div>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>#</th><th>Stop</th><th>Km</th><th>Approx. time</th></tr></thead>
        <tbody><?php foreach ($stops as $s): ?>
          <tr class="<?= $isMine && in_array((int) $s['id'], [(int) $pass['boarding_stop_id'], (int) $pass['destination_stop_id']], true) ? 'table-success' : '' ?>">
            <td><?= (int) $s['stop_order'] ?></td><td><?= e($s['name']) ?></td><td><?= e($s['distance_km']) ?></td><td><?= e($s['est_arrival_time'] ? substr($s['est_arrival_time'], 0, 5) : '—') ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
  </div>
  <div class="col-lg-7"><div id="routeMap" class="map-md"></div></div>
</div>
<script type="application/json" id="routeMapData"><?= json_for_html(['stops' => stops_for_map((int) $route['id']), 'boardId' => $isMine ? (int) $pass['boarding_stop_id'] : null, 'destId' => $isMine ? (int) $pass['destination_stop_id'] : null]) ?></script>
<?php endif; ?>
<?php page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/route-map.js']);
