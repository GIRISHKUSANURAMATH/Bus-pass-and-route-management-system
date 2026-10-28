<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_role('admin');

$routes = q_all('SELECT id, route_code, name FROM routes ORDER BY route_code');
$routeId = input_int('route') ?? (int) ($routes[0]['id'] ?? 0);
$route = q_one('SELECT * FROM routes WHERE id = ?', [$routeId]);

function next_stop_code(array $route): string
{
    do {
        $code = $route['route_code'] . '-' . strtoupper(bin2hex(random_bytes(2)));
    } while (q_val('SELECT 1 FROM bus_stops WHERE stop_code = ?', [$code]));
    return $code;
}

$errors = [];
if (is_post() && $route) {
    require_csrf();
    $action = input('action');
    $id = input_int('id');
    $back = 'admin/stops.php?route=' . $route['id'];

    if ($action === 'save') {
        $name = input('name');
        $lat = input('latitude');
        $lng = input('longitude');
        $order = input_int('stop_order');
        $dir = input('direction');
        $km = input('distance_km');
        $eta = input('est_arrival_time');
        $status = input('status');
        $code = strtoupper(input('stop_code'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) { $errors[] = 'Stop name must be 2–100 characters.'; }
        if (!valid_lat($lat) || !valid_lng($lng)) { $errors[] = 'Pick valid coordinates (click on the map).'; }
        if ($order === null || $order < 1 || $order > 500) { $errors[] = 'Stop order must be 1–500.'; }
        if (!in_array($dir, ['onward', 'return', 'both'], true)) { $errors[] = 'Invalid direction.'; }
        if (!is_numeric($km) || $km < 0 || $km > 2000) { $errors[] = 'Distance from origin must be 0–2000 km.'; }
        if ($eta !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $eta)) { $errors[] = 'Time must be HH:MM.'; }
        if (!in_array($status, ['active', 'inactive'], true)) { $errors[] = 'Invalid status.'; }
        if ($code !== '' && !preg_match('/^[A-Z0-9-]{2,20}$/', $code)) { $errors[] = 'Stop code: 2–20 chars (A-Z, 0-9, -).'; }
        if ($id && !q_val('SELECT 1 FROM bus_stops WHERE id = ? AND route_id = ?', [$id, $route['id']])) { $errors[] = 'Stop not on this route.'; }
        if (!$errors && $code !== '' && q_val('SELECT 1 FROM bus_stops WHERE stop_code = ? AND id <> ?', [$code, $id ?? 0])) { $errors[] = 'Stop code already used.'; }
        if (!$errors) {
            $code = $code ?: next_stop_code($route);
            $etaVal = $eta === '' ? null : $eta . ':00';
            if ($id) {
                q('UPDATE bus_stops SET stop_code = ?, name = ?, latitude = ?, longitude = ?, stop_order = ?, direction = ?, distance_km = ?, est_arrival_time = ?, status = ? WHERE id = ?',
                    [$code, $name, $lat, $lng, $order, $dir, $km, $etaVal, $status, $id]);
            } else {
                q('INSERT INTO bus_stops (route_id, stop_code, name, latitude, longitude, stop_order, direction, distance_km, est_arrival_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$route['id'], $code, $name, $lat, $lng, $order, $dir, $km, $etaVal, $status]);
                $id = (int) db()->lastInsertId();
            }
            audit_log('stop_saved', 'bus_stop', $id, ['name' => $name, 'route' => $route['route_code']]);
            flash('success', 'Stop saved.');
            redirect($back);
        }
    } elseif ($action === 'delete' && $id) {
        $used = q_val('SELECT 1 FROM bus_pass_applications WHERE boarding_stop_id = ? OR destination_stop_id = ? LIMIT 1', [$id, $id]);
        if ($used) {
            flash('danger', 'Students have applied using this stop. Set it Inactive instead.');
        } else {
            q('DELETE FROM bus_stops WHERE id = ? AND route_id = ?', [$id, $route['id']]);
            audit_log('stop_deleted', 'bus_stop', $id);
            flash('success', 'Stop deleted.');
        }
        redirect($back);
    } elseif (in_array($action, ['up', 'down'], true) && $id) {
        $stops = route_stops((int) $route['id'], false);
        $ids = array_map(fn ($s) => (int) $s['id'], $stops);
        $i = array_search($id, $ids, true);
        $j = $action === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $pdo = db();
            $pdo->beginTransaction();
            foreach ($ids as $pos => $sid) {   // renumber 1..n so order is always clean
                q('UPDATE bus_stops SET stop_order = ? WHERE id = ?', [$pos + 1, $sid]);
            }
            $pdo->commit();
            audit_log('stops_reordered', 'route', (int) $route['id']);
        }
        redirect($back);
    } elseif (in_array($action, ['approve_request', 'reject_request'], true)) {
        $reqId = input_int('request_id') ?? 0;
        $req = q_one("SELECT sr.*, r.route_code, d.user_id FROM stop_requests sr JOIN routes r ON r.id = sr.route_id JOIN drivers d ON d.id = sr.driver_id WHERE sr.id = ? AND sr.status = 'pending'", [$reqId]);
        if ($req) {
            $approve = $action === 'approve_request';
            if ($approve) {
                $order = (int) q_val('SELECT COALESCE(MAX(stop_order), 0) + 1 FROM bus_stops WHERE route_id = ?', [$req['route_id']]);
                q('INSERT INTO bus_stops (route_id, stop_code, name, latitude, longitude, stop_order, direction, distance_km, status) VALUES (?, ?, ?, ?, ?, ?, "both", 0, "active")',
                    [$req['route_id'], next_stop_code(['route_code' => $req['route_code']]), $req['name'], $req['latitude'], $req['longitude'], $order]);
            }
            q('UPDATE stop_requests SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?', [$approve ? 'approved' : 'rejected', $admin['id'], date('Y-m-d H:i:s'), $reqId]);
            audit_log($approve ? 'stop_request_approved' : 'stop_request_rejected', 'stop_request', $reqId);
            notify((int) $req['user_id'], 'Stop request ' . ($approve ? 'approved' : 'rejected'), "Your request for stop \"{$req['name']}\" was " . ($approve ? 'approved' : 'rejected') . '.', 'driver/route.php');
            flash('success', $approve ? 'Stop added at the end of the route – adjust its order, distance and time.' : 'Request rejected.');
        }
        redirect('admin/stops.php?route=' . ($req['route_id'] ?? $route['id']));
    }
}

page_header('Bus Stops', 'stops.php', ['vendor/leaflet/leaflet.css']);
if (!$route) {
    echo '<div class="alert alert-info">Create a route first. <a href="' . e(url('admin/routes.php')) . '">Go to routes</a>.</div>';
    page_footer();
    exit;
}
$stops = route_stops((int) $route['id'], false);
$edit = ($eid = input_int('edit')) ? q_one('SELECT * FROM bus_stops WHERE id = ? AND route_id = ?', [$eid, $route['id']]) : null;
$requests = q_all("SELECT sr.*, r.route_code, u.full_name FROM stop_requests sr JOIN routes r ON r.id = sr.route_id
                   JOIN drivers d ON d.id = sr.driver_id JOIN users u ON u.id = d.user_id WHERE sr.status = 'pending' ORDER BY sr.id");
$nextOrder = count($stops) + 1;
$v = function (string $k, string $d = '') use ($edit) {
    if (is_post()) { return input($k, $d); }
    $val = (string) ($edit[$k] ?? $d);
    return $k === 'est_arrival_time' && $val !== '' ? substr($val, 0, 5) : $val;
};
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><select class="form-select" name="route" data-autosubmit>
    <?php foreach ($routes as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $r['id'] === (int) $route['id'] ? ' selected' : '' ?>><?= e($r['route_code'] . ' · ' . $r['name']) ?></option><?php endforeach; ?>
  </select></div>
</form>
<?php if ($requests): ?>
<div class="card border-warning mb-3"><div class="card-header">Pending stop requests from drivers</div>
  <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
  <?php foreach ($requests as $rq): ?>
    <tr><td><?= e($rq['route_code']) ?></td><td><strong><?= e($rq['name']) ?></strong><br><small class="text-muted"><?= e($rq['full_name']) ?> · <?= e($rq['note'] ?? '') ?></small></td>
      <td class="small"><?= e($rq['latitude'] . ', ' . $rq['longitude']) ?> <a href="https://www.openstreetmap.org/?mlat=<?= e($rq['latitude']) ?>&mlon=<?= e($rq['longitude']) ?>#map=17/<?= e($rq['latitude']) ?>/<?= e($rq['longitude']) ?>" target="_blank" rel="noopener">map</a></td>
      <td class="text-nowrap">
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="route" value="<?= (int) $route['id'] ?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="request_id" value="<?= (int) $rq['id'] ?>"><button class="btn btn-sm btn-success">Approve</button></form>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="route" value="<?= (int) $route['id'] ?>"><input type="hidden" name="action" value="reject_request"><input type="hidden" name="request_id" value="<?= (int) $rq['id'] ?>"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
      </td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>#</th><th>Stop</th><th>Lat, Lng</th><th>Km</th><th>Time</th><th>Dir.</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($stops as $k => $s): ?>
      <tr<?= $edit && (int) $edit['id'] === (int) $s['id'] ? ' class="table-primary"' : '' ?>>
        <td class="text-nowrap"><?= (int) $s['stop_order'] ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="route" value="<?= (int) $route['id'] ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="up"><button class="btn btn-link btn-sm p-0" <?= $k === 0 ? 'disabled' : '' ?> aria-label="Move up"><i class="bi bi-arrow-up"></i></button></form>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="route" value="<?= (int) $route['id'] ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="down"><button class="btn btn-link btn-sm p-0" <?= $k === count($stops) - 1 ? 'disabled' : '' ?> aria-label="Move down"><i class="bi bi-arrow-down"></i></button></form></td>
        <td><?= e($s['name']) ?><br><small class="text-muted"><?= e($s['stop_code']) ?></small></td>
        <td class="small"><?= e(number_format((float) $s['latitude'], 5) . ', ' . number_format((float) $s['longitude'], 5)) ?></td>
        <td><?= e($s['distance_km']) ?></td><td><?= e($s['est_arrival_time'] ? substr($s['est_arrival_time'], 0, 5) : '—') ?></td>
        <td class="small"><?= e($s['direction']) ?></td><td><?= status_badge($s['status']) ?></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/stops.php?route=' . $route['id'] . '&edit=' . $s['id'])) ?>">Edit</a>
          <form method="post" class="d-inline" data-confirm="Delete stop <?= e($s['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="route" value="<?= (int) $route['id'] ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger">Del</button></form></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$stops): ?><tr><td colspan="8" class="text-muted">No stops yet – add the first one.</td></tr><?php endif; ?>
      </tbody>
    </table></div></div>
    <div id="pickerMap" class="map-md"></div>
    <p class="small text-muted mt-1">Click the map to set the coordinates of the stop you are adding/editing. Drag the pin to fine-tune.</p>
  </div>
  <div class="col-xl-5"><div class="card"><div class="card-header"><?= $edit ? 'Edit stop: ' . e($edit['name']) : 'Add stop to ' . e($route['route_code']) ?></div><div class="card-body">
    <?php if ($errors): ?><div class="alert alert-danger small"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="needs-validation" novalidate>
      <?= csrf_field() ?><input type="hidden" name="route" value="<?= (int) $route['id'] ?>"><input type="hidden" name="action" value="save"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <div class="row g-2">
        <div class="col-8"><label class="form-label small">Stop name</label><input class="form-control form-control-sm" name="name" required value="<?= e($v('name')) ?>"></div>
        <div class="col-4"><label class="form-label small">Code (auto)</label><input class="form-control form-control-sm" name="stop_code" value="<?= e($v('stop_code')) ?>"></div>
        <div class="col-6"><label class="form-label small" for="latitude">Latitude</label><input class="form-control form-control-sm" id="latitude" name="latitude" required value="<?= e($v('latitude')) ?>"></div>
        <div class="col-6"><label class="form-label small" for="longitude">Longitude</label><input class="form-control form-control-sm" id="longitude" name="longitude" required value="<?= e($v('longitude')) ?>"></div>
        <div class="col-4"><label class="form-label small">Order</label><input class="form-control form-control-sm" type="number" min="1" name="stop_order" required value="<?= e($v('stop_order', (string) $nextOrder)) ?>"></div>
        <div class="col-4"><label class="form-label small">Km from origin</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="distance_km" required value="<?= e($v('distance_km', '0')) ?>"></div>
        <div class="col-4"><label class="form-label small">Approx. time</label><input class="form-control form-control-sm" type="time" name="est_arrival_time" value="<?= e($v('est_arrival_time')) ?>"></div>
        <div class="col-6"><label class="form-label small">Direction</label><select class="form-select form-select-sm" name="direction"><?php foreach (['both', 'onward', 'return'] as $d): ?><option value="<?= $d ?>"<?= $v('direction', 'both') === $d ? ' selected' : '' ?>><?= ucfirst($d) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">Status</label><select class="form-select form-select-sm" name="status"><?php foreach (['active', 'inactive'] as $s): ?><option value="<?= $s ?>"<?= $v('status', 'active') === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
      </div>
      <button class="btn btn-sm btn-outline-secondary mt-2" type="button" id="useMyLocation"><i class="bi bi-crosshair"></i> Use my location</button>
      <div class="mt-3"><button class="btn btn-primary btn-sm"><?= $edit ? 'Save stop' : 'Add stop' ?></button>
        <?php if ($edit): ?><a class="btn btn-link btn-sm" href="<?= e(url('admin/stops.php?route=' . $route['id'])) ?>">Cancel</a><?php endif; ?></div>
      <p class="small text-muted mt-2 mb-0">"Km from origin" drives the fare: fee = base + per-km × |km(destination) − km(boarding)|.</p>
    </form>
  </div></div></div>
</div>
<script type="application/json" id="pickerData"><?= json_for_html(['stops' => stops_for_map((int) $route['id'])]) ?></script>
<?php page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/stop-picker.js']);
