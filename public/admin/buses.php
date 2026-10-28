<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_role('admin');

$errors = [];
if (is_post()) {
    require_csrf();
    $action = input('action');
    $id = input_int('id');
    if ($action === 'save') {
        $code = strtoupper(input('bus_code'));
        $number = input('bus_number');
        $reg = strtoupper(preg_replace('/\s+/', '-', input('registration_number')));
        $name = input('bus_name');
        $cap = input_int('capacity');
        $routeId = input_int('route_id');
        $status = input('status');
        $driverId = input_int('driver_id');
        if (!preg_match('/^[A-Z0-9-]{3,20}$/', $code)) { $errors[] = 'Bus ID: 3–20 chars (A-Z, 0-9, -).'; }
        if ($number === '' || mb_strlen($number) > 20) { $errors[] = 'Bus number is required (max 20).'; }
        if (!preg_match('/^[A-Z0-9-]{4,20}$/', $reg)) { $errors[] = 'Registration number like KA-26-F-1234.'; }
        if (mb_strlen($name) > 80) { $errors[] = 'Bus name too long.'; }
        if ($cap === null || $cap < 1 || $cap > 120) { $errors[] = 'Capacity must be 1–120.'; }
        if ($routeId && !q_val('SELECT 1 FROM routes WHERE id = ?', [$routeId])) { $errors[] = 'Invalid route.'; }
        if (!in_array($status, ['active', 'inactive', 'maintenance'], true)) { $errors[] = 'Invalid status.'; }
        if (!$errors && q_val('SELECT 1 FROM buses WHERE bus_code = ? AND id <> ?', [$code, $id ?? 0])) { $errors[] = 'Bus ID already exists.'; }
        if (!$errors && q_val('SELECT 1 FROM buses WHERE registration_number = ? AND id <> ?', [$reg, $id ?? 0])) { $errors[] = 'Registration number already exists.'; }
        if (!$errors && $id && $status !== 'active' && q_val("SELECT 1 FROM trips WHERE bus_id = ? AND status = 'active'", [$id])) { $errors[] = 'Bus is on an active trip; stop the trip before changing its status.'; }
        if (!$errors) {
            if ($id) {
                q('UPDATE buses SET bus_code = ?, bus_number = ?, registration_number = ?, bus_name = ?, capacity = ?, route_id = ?, status = ? WHERE id = ?',
                    [$code, $number, $reg, $name ?: null, $cap, $routeId ?: null, $status, $id]);
            } else {
                q('INSERT INTO buses (bus_code, bus_number, registration_number, bus_name, capacity, route_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$code, $number, $reg, $name ?: null, $cap, $routeId ?: null, $status]);
                $id = (int) db()->lastInsertId();
            }
            audit_log('bus_saved', 'bus', $id, ['code' => $code]);
            $current = q_val('SELECT driver_id FROM driver_bus_assignments WHERE bus_id = ? AND is_active = 1', [$id]);
            if ((int) $current !== (int) $driverId) {
                if ($driverId) {
                    $err = assign_driver_bus($driverId, $id, (int) $admin['id']);
                } elseif ($current) {
                    $err = assign_driver_bus((int) $current, null, (int) $admin['id']);
                }
                if (!empty($err)) { flash('warning', 'Bus saved, but driver not changed: ' . $err); redirect('admin/buses.php'); }
            }
            flash('success', 'Bus saved.');
            redirect('admin/buses.php');
        }
    } elseif ($action === 'delete' && $id) {
        if (q_val('SELECT 1 FROM trips WHERE bus_id = ? LIMIT 1', [$id]) || q_val('SELECT 1 FROM bus_passes WHERE bus_id = ? LIMIT 1', [$id])) {
            flash('danger', 'This bus has trips or passes on record. Mark it Inactive instead of deleting.');
        } else {
            q('DELETE FROM buses WHERE id = ?', [$id]);
            audit_log('bus_deleted', 'bus', $id);
            flash('success', 'Bus deleted.');
        }
        redirect('admin/buses.php');
    }
}

$buses = q_all("SELECT b.*, r.name AS route_name, u.full_name AS driver_name, d.id AS driver_id,
                       (SELECT COUNT(*) FROM bus_passes p WHERE p.bus_id = b.id AND p.status = 'active' AND p.valid_until >= CURDATE()) AS passengers,
                       (SELECT t.status FROM trips t WHERE t.bus_id = b.id AND t.status = 'active' LIMIT 1) AS trip
                FROM buses b LEFT JOIN routes r ON r.id = b.route_id
                LEFT JOIN driver_bus_assignments a ON a.bus_id = b.id AND a.is_active = 1
                LEFT JOIN drivers d ON d.id = a.driver_id LEFT JOIN users u ON u.id = d.user_id
                ORDER BY b.bus_code");
$routes = q_all('SELECT id, name, status FROM routes ORDER BY name');
$drivers = q_all("SELECT d.id, d.driver_code, u.full_name FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.status = 'active' ORDER BY d.driver_code");
$edit = ($eid = input_int('edit')) ? q_one('SELECT b.*, a.driver_id FROM buses b LEFT JOIN driver_bus_assignments a ON a.bus_id = b.id AND a.is_active = 1 WHERE b.id = ?', [$eid]) : null;
$v = fn (string $k, string $d = '') => is_post() ? input($k, $d) : (string) ($edit[$k] ?? $d);

page_header('Buses', 'buses.php');
?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Bus ID</th><th>Number / Reg.</th><th>Capacity</th><th>Route</th><th>Driver</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($buses as $b): ?>
    <tr>
      <td><strong><?= e($b['bus_code']) ?></strong><br><small class="text-muted"><?= e($b['bus_name'] ?? '') ?></small></td>
      <td><?= e($b['bus_number']) ?><br><small class="text-muted"><?= e($b['registration_number']) ?></small></td>
      <td><?= (int) $b['passengers'] ?> / <?= (int) $b['capacity'] ?></td>
      <td class="small"><?= e($b['route_name'] ?? '—') ?></td>
      <td class="small"><?= e($b['driver_name'] ?? '—') ?></td>
      <td><?= status_badge($b['status']) ?><?= $b['trip'] ? ' <a class="badge text-bg-success text-decoration-none" href="' . e(url('admin/live-map.php?bus=' . $b['id'])) . '">on trip</a>' : '' ?></td>
      <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/buses.php?edit=' . $b['id'])) ?>">Edit</a>
        <form method="post" class="d-inline" data-confirm="Delete bus <?= e($b['bus_code']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$buses): ?><tr><td colspan="7" class="text-muted">No buses yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div></div>
  <div class="col-xl-4"><div class="card"><div class="card-header"><?= $edit ? 'Edit bus ' . e($edit['bus_code']) : 'Add bus' ?></div><div class="card-body">
    <?php if ($errors): ?><div class="alert alert-danger small"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="needs-validation" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <div class="row g-2">
        <div class="col-6"><label class="form-label small">Bus ID</label><input class="form-control form-control-sm" name="bus_code" required placeholder="BUS003" value="<?= e($v('bus_code')) ?>"></div>
        <div class="col-6"><label class="form-label small">Bus number</label><input class="form-control form-control-sm" name="bus_number" required placeholder="SKSVM-03" value="<?= e($v('bus_number')) ?>"></div>
        <div class="col-12"><label class="form-label small">Registration number</label><input class="form-control form-control-sm text-uppercase" name="registration_number" required placeholder="KA-26-F-1234" value="<?= e($v('registration_number')) ?>"></div>
        <div class="col-8"><label class="form-label small">Bus name (optional)</label><input class="form-control form-control-sm" name="bus_name" value="<?= e($v('bus_name')) ?>"></div>
        <div class="col-4"><label class="form-label small">Capacity</label><input class="form-control form-control-sm" type="number" min="1" max="120" name="capacity" required value="<?= e($v('capacity', '50')) ?>"></div>
        <div class="col-12"><label class="form-label small">Route</label><select class="form-select form-select-sm" name="route_id"><option value="">— None —</option>
          <?php foreach ($routes as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $v('route_id') === (string) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?><?= $r['status'] !== 'active' ? ' (inactive)' : '' ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small">Driver</label><select class="form-select form-select-sm" name="driver_id"><option value="">— None —</option>
          <?php foreach ($drivers as $d): ?><option value="<?= (int) $d['id'] ?>"<?= $v('driver_id') === (string) $d['id'] ? ' selected' : '' ?>><?= e($d['driver_code'] . ' · ' . $d['full_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small">Status</label><select class="form-select form-select-sm" name="status">
          <?php foreach (['active', 'inactive', 'maintenance'] as $s): ?><option value="<?= $s ?>"<?= $v('status', 'active') === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
      </div>
      <button class="btn btn-primary btn-sm mt-3"><?= $edit ? 'Save changes' : 'Add bus' ?></button>
      <?php if ($edit): ?><a class="btn btn-link btn-sm mt-3" href="<?= e(url('admin/buses.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div></div></div>
</div>
<?php page_footer();
