<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');

$errors = [];
if (is_post()) {
    require_csrf();
    $id = input_int('id') ?? 0;
    $st = q_one('SELECT s.*, u.email, u.full_name, u.status FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$id]);
    if (!$st) {
        flash('danger', 'Student not found.');
        redirect('admin/students.php');
    }
    $action = input('action');
    if ($action === 'toggle') {
        $new = $st['status'] === 'active' ? 'inactive' : 'active';
        q('UPDATE users SET status = ? WHERE id = ?', [$new, $st['user_id']]);
        audit_log('student_status_changed', 'student', $id, ['status' => $new]);
        flash('success', "Account {$new}.");
        redirect('admin/students.php?id=' . $id);
    }
    if ($action === 'reset_password') {
        $pw = (string) ($_POST['new_password'] ?? '');
        if ($p = password_problem($pw)) {
            flash('danger', $p);
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $st['user_id']]);
            audit_log('student_password_reset', 'student', $id);
            flash('success', 'Password reset. Share it with the student securely and ask them to change it.');
        }
        redirect('admin/students.php?id=' . $id);
    }
    if ($action === 'update') {
        $name = preg_replace('/\s+/', ' ', input('full_name'));
        $email = mb_strtolower(input('email'));
        $usn = normalize_usn(input('usn'));
        $branch = input('branch');
        $year = input_int('year_of_study');
        $ay = input('academic_year');
        $phone = input('phone');
        if (!preg_match("/^[\\p{L} .'-]{3,120}$/u", $name)) { $errors[] = 'Invalid name.'; }
        if (!valid_email($email)) { $errors[] = 'Invalid email.'; }
        if (!valid_usn($usn)) { $errors[] = 'Invalid USN.'; }
        if (!in_array($branch, BRANCHES, true)) { $errors[] = 'Invalid branch.'; }
        if ($year === null || $year < 1 || $year > 4) { $errors[] = 'Invalid year.'; }
        if (!valid_academic_year($ay)) { $errors[] = 'Academic year must look like 2026-2027.'; }
        if (!valid_phone($phone)) { $errors[] = 'Invalid phone.'; }
        if (!$errors && q_val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $st['user_id']])) { $errors[] = 'Email already used.'; }
        if (!$errors && q_val('SELECT 1 FROM students WHERE usn = ? AND id <> ?', [$usn, $id])) { $errors[] = 'USN already used.'; }
        if (!$errors) {
            q('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?', [$name, $email, $phone, $st['user_id']]);
            q('UPDATE students SET usn = ?, branch = ?, year_of_study = ?, academic_year = ? WHERE id = ?', [$usn, $branch, $year, $ay, $id]);
            audit_log('student_updated', 'student', $id, ['usn' => $usn]);
            flash('success', 'Student updated.');
            redirect('admin/students.php?id=' . $id);
        }
    }
}

page_header('Students', 'students.php');

if ($id = input_int('id')) {
    $s = q_one('SELECT s.*, u.email, u.full_name, u.phone, u.status, u.last_login_at, u.created_at AS registered
                FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$id]);
    if (!$s) {
        echo '<div class="alert alert-warning">Not found.</div>';
        page_footer();
        exit;
    }
    $apps = q_all('SELECT a.id, a.application_no, a.status, a.fee_amount, a.created_at, r.name AS route FROM bus_pass_applications a JOIN routes r ON r.id = a.route_id WHERE a.student_id = ? ORDER BY a.id DESC', [$id]);
    $passes = q_all('SELECT id, pass_number, valid_from, valid_until, status FROM bus_passes WHERE student_id = ? ORDER BY id DESC', [$id]);
    ?>
    <a href="<?= e(url('admin/students.php')) ?>" class="small">&larr; All students</a>
    <?php if ($errors): ?><div class="alert alert-danger mt-2"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="row g-3 mt-1">
      <div class="col-lg-7"><div class="card"><div class="card-header d-flex justify-content-between"><span><?= e($s['full_name']) ?> · <?= e($s['usn']) ?></span><?= status_badge($s['status']) ?></div><div class="card-body">
        <div class="d-flex gap-3 mb-3">
          <?php if ($s['profile_photo']): ?><img class="bp-photo" src="<?= e(url('file.php?type=profile&id=' . $s['id'])) ?>" alt="Photo"><?php endif; ?>
          <div class="small text-muted">Registered <?= e(fmt_datetime($s['registered'])) ?><br>Last login <?= e(fmt_datetime($s['last_login_at'])) ?><br>Address: <?= e($s['address']) ?><br>Emergency: <?= e($s['emergency_contact']) ?></div>
        </div>
        <form method="post" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="update">
          <div class="col-md-6"><label class="form-label small">Full name</label><input class="form-control form-control-sm" name="full_name" value="<?= e($s['full_name']) ?>" required></div>
          <div class="col-md-6"><label class="form-label small">USN</label><input class="form-control form-control-sm" name="usn" value="<?= e($s['usn']) ?>" required></div>
          <div class="col-md-6"><label class="form-label small">Email</label><input class="form-control form-control-sm" type="email" name="email" value="<?= e($s['email']) ?>" required></div>
          <div class="col-md-6"><label class="form-label small">Phone</label><input class="form-control form-control-sm" name="phone" value="<?= e($s['phone']) ?>" required></div>
          <div class="col-md-4"><label class="form-label small">Branch</label><select class="form-select form-select-sm" name="branch"><?php foreach (BRANCHES as $b): ?><option<?= $b === $s['branch'] ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label small">Year</label><select class="form-select form-select-sm" name="year_of_study"><?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"<?= (int) $s['year_of_study'] === $y ? ' selected' : '' ?>><?= e(year_label($y)) ?></option><?php endfor; ?></select></div>
          <div class="col-md-4"><label class="form-label small">Academic year</label><input class="form-control form-control-sm" name="academic_year" value="<?= e($s['academic_year']) ?>" pattern="\d{4}-\d{4}"></div>
          <div class="col-12"><button class="btn btn-sm btn-primary">Save changes</button></div>
        </form>
      </div></div></div>
      <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header">Account</div><div class="card-body">
          <form method="post" class="mb-3" data-confirm="Change account status?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="toggle">
            <button class="btn btn-sm <?= $s['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"><?= $s['status'] === 'active' ? 'Deactivate account' : 'Activate account' ?></button></form>
          <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reset_password">
            <div class="col"><input class="form-control form-control-sm" type="text" name="new_password" required minlength="8" placeholder="New temporary password" autocomplete="off"></div>
            <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Reset password</button></div></form>
        </div></div>
        <div class="card mb-3"><div class="card-header">Applications</div><ul class="list-group list-group-flush small">
          <?php foreach ($apps as $a): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('admin/applications.php?id=' . $a['id'])) ?>"><?= e($a['application_no']) ?></a><span><?= e(money($a['fee_amount'])) ?> <?= status_badge($a['status']) ?></span></li><?php endforeach; ?>
          <?php if (!$apps): ?><li class="list-group-item text-muted">None</li><?php endif; ?></ul></div>
        <div class="card"><div class="card-header">Passes</div><ul class="list-group list-group-flush small">
          <?php foreach ($passes as $p): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('admin/passes.php?view=' . $p['id'])) ?>"><?= e($p['pass_number']) ?></a><span><?= e(fmt_date($p['valid_until'])) ?> <?= status_badge($p['status']) ?></span></li><?php endforeach; ?>
          <?php if (!$passes): ?><li class="list-group-item text-muted">None</li><?php endif; ?></ul></div>
      </div>
    </div>
    <?php
    page_footer();
    exit;
}

$q = input('q');
$branch = input('branch');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(s.usn LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if (in_array($branch, BRANCHES, true)) {
    $where[] = 's.branch = ?';
    $params[] = $branch;
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) q_val('SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id' . $w, $params);
$pg = paginate($total, 25);
$today = date('Y-m-d');
$rows = q_all('SELECT s.id, s.usn, s.branch, s.year_of_study, s.academic_year, u.full_name, u.email, u.phone, u.status,
                 (SELECT p.pass_number FROM bus_passes p WHERE p.student_id = s.id AND p.status = "active" AND p.valid_until >= ? ORDER BY p.id DESC LIMIT 1) AS pass_number
               FROM students s JOIN users u ON u.id = s.user_id' . $w . ' ORDER BY s.id DESC LIMIT ' . $pg['limit'] . ' OFFSET ' . $pg['offset'], array_merge([$today], $params));
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-sm-5 col-lg-4"><input class="form-control" name="q" placeholder="USN, name or email" value="<?= e($q) ?>"></div>
  <div class="col-sm-3 col-lg-2"><select class="form-select" name="branch" data-autosubmit><option value="">All branches</option><?php foreach (BRANCHES as $b): ?><option<?= $b === $branch ? ' selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-outline-primary">Search</button></div>
  <div class="col-auto ms-auto small text-muted align-self-center"><?= $total ?> student(s)</div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>USN</th><th>Name</th><th>Branch / Year</th><th>Contact</th><th>Active pass</th><th>Account</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= e($r['usn']) ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['branch']) ?> · <?= e(year_label((int) $r['year_of_study'])) ?></td>
    <td class="small"><?= e($r['email']) ?><br><?= e($r['phone']) ?></td><td><?= e($r['pass_number'] ?? '—') ?></td><td><?= status_badge($r['status']) ?></td>
    <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/students.php?id=' . $r['id'])) ?>">Manage</a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No students.</td></tr><?php endif; ?>
  </tbody>
</table></div></div>
<div class="mt-3"><?= pagination_links($pg) ?></div>
<?php page_footer();
