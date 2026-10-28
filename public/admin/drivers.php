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
        $name = preg_replace('/\s+/', ' ', input('full_name'));
        $email = mb_strtolower(input('email'));
        $username = mb_strtolower(input('username'));
        $phone = input('phone');
        $code = strtoupper(input('driver_code'));
        $license = input('license_number');
        $pw = (string) ($_POST['password'] ?? '');
        $existing = $id ? q_one('SELECT d.*, u.id AS uid FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id]) : null;
        $uid = $existing['uid'] ?? 0;

        if (!preg_match("/^[\\p{L} .'-]{3,120}$/u", $name)) { $errors[] = 'Enter a valid name.'; }
        if (!valid_email($email)) { $errors[] = 'Enter a valid email.'; }
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) { $errors[] = 'Username: 3–60 chars (a-z, 0-9, . _ -).'; }
        if (!valid_phone($phone)) { $errors[] = 'Enter a valid 10-digit phone.'; }
        if (!preg_match('/^[A-Z0-9-]{3,20}$/', $code)) { $errors[] = 'Driver ID: 3–20 chars (A-Z, 0-9, -).'; }
        if (mb_strlen($license) > 30) { $errors[] = 'Licence number too long.'; }
        if (!$existing || $pw !== '') {
            if ($p = password_problem($pw)) { $errors[] = $p; }
        }
        if (!$errors) {
            if (q_val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $uid])) { $errors[] = 'Email already in use.'; }
            if (q_val('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $uid])) { $errors[] = 'Username already in use.'; }
            if (q_val('SELECT 1 FROM drivers WHERE driver_code = ? AND id <> ?', [$code, $id ?? 0])) { $errors[] = 'Driver ID already in use.'; }
        }
        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            if ($existing) {
                q('UPDATE users SET full_name = ?, email = ?, username = ?, phone = ? WHERE id = ?', [$name, $email, $username, $phone, $uid]);
                if ($pw !== '') {
                    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $uid]);
                }
                q('UPDATE drivers SET driver_code = ?, license_number = ? WHERE id = ?', [$code, $license ?: null, $id]);
            } else {
                q('INSERT INTO users (role, email, username, password_hash, full_name, phone) VALUES ("driver", ?, ?, ?, ?, ?)',
                    [$email, $username, password_hash($pw, PASSWORD_DEFAULT), $name, $phone]);
                $uid = (int) $pdo->lastInsertId();
                q('INSERT INTO drivers (user_id, driver_code, license_number) VALUES (?, ?, ?)', [$uid, $code, $license ?: null]);
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            audit_log($existing ? 'driver_updated' : 'driver_created', 'driver', $id, ['code' => $code]);
            flash('success', $existing ? 'Driver updated.' : 'Driver account created.');
            redirect('admin/drivers.php');
        }
    } elseif ($action === 'assign' && $id) {
        $busId = input_int('bus_id');
        $err = assign_driver_bus($id, $busId ?: null, (int) $admin['id']);
        $err ? flash('danger', $err) : flash('success', $busId ? 'Bus assigned.' : 'Driver unassigned.');
        redirect('admin/drivers.php');
    } elseif ($action === 'toggle' && $id) {
        $d = q_one('SELECT u.id, u.status FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id]);
        if ($d) {
            if ($d['status'] === 'active' && active_trip_for_driver($id)) {
                flash('danger', 'Stop the driver\'s active trip first.');
            } else {
                $new = $d['status'] === 'active' ? 'inactive' : 'active';
                q('UPDATE users SET status = ? WHERE id = ?', [$new, $d['id']]);
                audit_log('driver_status_changed', 'driver', $id, ['status' => $new]);
                flash('success', "Driver {$new}.");
            }
        }
        redirect('admin/drivers.php');
    }
}

$drivers = q_all('SELECT d.*, u.full_name, u.email, u.username, u.phone, u.status, u.last_login_at,
                         b.id AS bus_id, b.bus_code, b.registration_number, r.name AS route_name,
                         (SELECT COUNT(*) FROM trips t WHERE t.driver_id = d.id AND t.status = "active") AS active_trips
                  FROM drivers d JOIN users u ON u.id = d.user_id
                  LEFT JOIN driver_bus_assignments a ON a.driver_id = d.id AND a.is_active = 1
                  LEFT JOIN buses b ON b.id = a.bus_id LEFT JOIN routes r ON r.id = b.route_id
                  ORDER BY d.driver_code');
$buses = q_all('SELECT b.id, b.bus_code, b.registration_number, b.status, u.full_name AS driver_name
                FROM buses b LEFT JOIN driver_bus_assignments a ON a.bus_id = b.id AND a.is_active = 1
                LEFT JOIN drivers d ON d.id = a.driver_id LEFT JOIN users u ON u.id = d.user_id ORDER BY b.bus_code');
$edit = ($eid = input_int('edit')) ? q_one('SELECT d.*, u.full_name, u.email, u.username, u.phone FROM drivers d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$eid]) : null;
$f = fn (string $k) => e(is_post() ? input($k) : ($edit[$k] ?? ''));

page_header('Drivers', 'drivers.php');
?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Driver</th><th>Contact</th><th>Assigned bus / route</th><th>Status</th><th style="min-width:250px">Assign bus</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($drivers as $d): ?>
      <tr>
        <td><strong><?= e($d['driver_code']) ?></strong> · <?= e($d['full_name']) ?><br><small class="text-muted">@<?= e($d['username']) ?><?= $d['active_trips'] ? ' · <span class="text-success">on trip</span>' : '' ?></small></td>
        <td class="small"><?= e($d['phone']) ?><br><?= e($d['email']) ?></td>
        <td class="small"><?= $d['bus_code'] ? e($d['bus_code'] . ' · ' . $d['registration_number']) . '<br>' . e($d['route_name'] ?? 'no route') : '<span class="text-muted">Not assigned</span>' ?></td>
        <td><?= status_badge($d['status']) ?></td>
        <td><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
          <select class="form-select form-select-sm" name="bus_id"><option value="">— Unassign —</option>
            <?php foreach ($buses as $b): ?><option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === (int) $d['bus_id'] ? ' selected' : '' ?>><?= e($b['bus_code']) ?><?= $b['driver_name'] && (int) $b['id'] !== (int) $d['bus_id'] ? ' (now: ' . e($b['driver_name']) . ')' : '' ?></option><?php endforeach; ?>
          </select><button class="btn btn-sm btn-outline-primary">Save</button></form></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/drivers.php?edit=' . $d['id'])) ?>">Edit</a>
          <form method="post" class="d-inline" data-confirm="Change this driver's status?"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
          <button class="btn btn-sm btn-outline-<?= $d['status'] === 'active' ? 'danger' : 'success' ?>"><?= $d['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$drivers): ?><tr><td colspan="6" class="text-muted">No drivers yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div></div>
    <p class="small text-muted mt-2">A bus can have only one active driver and a driver only one bus; reassigning automatically ends the previous assignment (history is kept).</p>
  </div>
  <div class="col-xl-4">
    <div class="card"><div class="card-header"><?= $edit ? 'Edit driver ' . e($edit['driver_code']) : 'Add driver' ?></div><div class="card-body">
      <?php if ($errors): ?><div class="alert alert-danger small"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" class="needs-validation" novalidate autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
        <div class="mb-2"><label class="form-label small">Driver ID</label><input class="form-control form-control-sm" name="driver_code" required placeholder="DRV003" value="<?= $f('driver_code') ?>"></div>
        <div class="mb-2"><label class="form-label small">Full name</label><input class="form-control form-control-sm" name="full_name" required value="<?= $f('full_name') ?>"></div>
        <div class="mb-2"><label class="form-label small">Phone (contact only)</label><input class="form-control form-control-sm" name="phone" required pattern="[6-9][0-9]{9}" value="<?= $f('phone') ?>"></div>
        <div class="mb-2"><label class="form-label small">Email</label><input class="form-control form-control-sm" type="email" name="email" required value="<?= $f('email') ?>"></div>
        <div class="mb-2"><label class="form-label small">Username (login)</label><input class="form-control form-control-sm" name="username" required value="<?= $f('username') ?>"></div>
        <div class="mb-2"><label class="form-label small">Licence no. (optional)</label><input class="form-control form-control-sm" name="license_number" value="<?= $f('license_number') ?>"></div>
        <div class="mb-3"><label class="form-label small">Password <?= $edit ? '(leave blank to keep)' : '' ?></label><input class="form-control form-control-sm" type="text" name="password" <?= $edit ? '' : 'required' ?> minlength="8" autocomplete="new-password"></div>
        <button class="btn btn-primary btn-sm"><?= $edit ? 'Save changes' : 'Create driver' ?></button>
        <?php if ($edit): ?><a class="btn btn-link btn-sm" href="<?= e(url('admin/drivers.php')) ?>">Cancel</a><?php endif; ?>
      </form>
    </div></div>
  </div>
</div>
<?php page_footer();
