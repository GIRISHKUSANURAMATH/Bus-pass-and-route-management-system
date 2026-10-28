<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');

$errors = [];
if (is_post()) {
    require_csrf();
    $action = input('action');
    $id = input_int('id');
    if ($action === 'save') {
        $code = strtoupper(input('route_code'));
        $origin = input('origin');
        $dest = input('destination');
        $round = isset($_POST['is_round_trip']) ? 1 : 0;
        $name = input('name') ?: ($origin . ' → ' . $dest . ($round ? ' → ' . $origin : ''));
        $desc = input('description');
        $status = input('status');
        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $code)) { $errors[] = 'Route code: 2–20 chars (A-Z, 0-9, -).'; }
        foreach (['Origin' => $origin, 'Destination' => $dest] as $l => $val) {
            if (mb_strlen($val) < 2 || mb_strlen($val) > 80) { $errors[] = "$l must be 2–80 characters."; }
        }
        if (mb_strlen($name) > 120) { $errors[] = 'Name too long.'; }
        if (mb_strlen($desc) > 255) { $errors[] = 'Description too long.'; }
        if (!in_array($status, ['active', 'inactive'], true)) { $errors[] = 'Invalid status.'; }
        if (!$errors && q_val('SELECT 1 FROM routes WHERE route_code = ? AND id <> ?', [$code, $id ?? 0])) { $errors[] = 'Route code already exists.'; }
        if (!$errors) {
            if ($id) {
                q('UPDATE routes SET route_code = ?, name = ?, origin = ?, destination = ?, is_round_trip = ?, description = ?, status = ? WHERE id = ?',
                    [$code, $name, $origin, $dest, $round, $desc ?: null, $status, $id]);
            } else {
                q('INSERT INTO routes (route_code, name, origin, destination, is_round_trip, description, status) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$code, $name, $origin, $dest, $round, $desc ?: null, $status]);
                $id = (int) db()->lastInsertId();
            }
            audit_log('route_saved', 'route', $id, ['code' => $code]);
            flash('success', 'Route saved. Next: add its stops and fees.');
            redirect('admin/stops.php?route=' . $id);
        }
    } elseif ($action === 'delete' && $id) {
        $used = q_val('SELECT 1 FROM bus_pass_applications WHERE route_id = ? LIMIT 1', [$id]) || q_val('SELECT 1 FROM trips WHERE route_id = ? LIMIT 1', [$id]);
        if ($used) {
            flash('danger', 'This route has applications/trips on record. Set it Inactive instead.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            q('DELETE FROM bus_stops WHERE route_id = ?', [$id]);
            q('UPDATE buses SET route_id = NULL WHERE route_id = ?', [$id]);
            q('DELETE FROM routes WHERE id = ?', [$id]);
            $pdo->commit();
            audit_log('route_deleted', 'route', $id);
            flash('success', 'Route deleted.');
        }
        redirect('admin/routes.php');
    }
}

$routes = q_all("SELECT r.*, (SELECT COUNT(*) FROM bus_stops s WHERE s.route_id = r.id) AS stops,
                        (SELECT COUNT(*) FROM buses b WHERE b.route_id = r.id) AS buses,
                        (SELECT COUNT(*) FROM pass_fees f WHERE f.route_id = r.id) AS fees,
                        (SELECT COUNT(*) FROM bus_passes p WHERE p.route_id = r.id AND p.status = 'active' AND p.valid_until >= CURDATE()) AS passes
                 FROM routes r ORDER BY r.route_code");
$edit = ($eid = input_int('edit')) ? q_one('SELECT * FROM routes WHERE id = ?', [$eid]) : null;
$v = fn (string $k, string $d = '') => is_post() ? input($k, $d) : (string) ($edit[$k] ?? $d);

page_header('Routes', 'routes.php');
?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Code</th><th>Route</th><th>Stops</th><th>Buses</th><th>Active passes</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($routes as $r): ?>
    <tr>
      <td><strong><?= e($r['route_code']) ?></strong></td>
      <td><?= e($r['name']) ?><?= (int) $r['fees'] < 2 ? '<br><a class="small text-danger" href="' . e(url('admin/fees.php')) . '">fees not configured</a>' : '' ?></td>
      <td><a href="<?= e(url('admin/stops.php?route=' . $r['id'])) ?>"><?= (int) $r['stops'] ?> stops</a></td>
      <td><?= (int) $r['buses'] ?></td><td><?= (int) $r['passes'] ?></td>
      <td><?= status_badge($r['status']) ?></td>
      <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/routes.php?edit=' . $r['id'])) ?>">Edit</a>
        <form method="post" class="d-inline" data-confirm="Delete route <?= e($r['route_code']) ?> and its stops?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$routes): ?><tr><td colspan="7" class="text-muted">No routes yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div></div>
  <div class="col-xl-4"><div class="card"><div class="card-header"><?= $edit ? 'Edit route ' . e($edit['route_code']) : 'Add route' ?></div><div class="card-body">
    <?php if ($errors): ?><div class="alert alert-danger small"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="needs-validation" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
      <div class="mb-2"><label class="form-label small">Route code</label><input class="form-control form-control-sm" name="route_code" required placeholder="RT03" value="<?= e($v('route_code')) ?>"></div>
      <div class="row g-2 mb-2">
        <div class="col"><label class="form-label small">Origin</label><input class="form-control form-control-sm" name="origin" required value="<?= e($v('origin', 'Laxmeshwar')) ?>"></div>
        <div class="col"><label class="form-label small">Destination</label><input class="form-control form-control-sm" name="destination" required value="<?= e($v('destination')) ?>"></div>
      </div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="is_round_trip" name="is_round_trip" <?= (is_post() ? isset($_POST['is_round_trip']) : (bool) ($edit['is_round_trip'] ?? 1)) ? 'checked' : '' ?>><label class="form-check-label small" for="is_round_trip">Round trip (returns to origin)</label></div>
      <div class="mb-2"><label class="form-label small">Display name (auto if blank)</label><input class="form-control form-control-sm" name="name" value="<?= e($v('name')) ?>"></div>
      <div class="mb-2"><label class="form-label small">Description</label><input class="form-control form-control-sm" name="description" value="<?= e($v('description')) ?>"></div>
      <div class="mb-3"><label class="form-label small">Status</label><select class="form-select form-select-sm" name="status"><?php foreach (['active', 'inactive'] as $s): ?><option value="<?= $s ?>"<?= $v('status', 'active') === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary btn-sm"><?= $edit ? 'Save changes' : 'Add route' ?></button>
      <?php if ($edit): ?><a class="btn btn-link btn-sm" href="<?= e(url('admin/routes.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div></div></div>
</div>
<?php page_footer();
