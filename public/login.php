<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$role = input('role', 'student');
if (!in_array($role, ROLES, true)) {
    $role = 'student';
}
if ($u = current_user()) {
    redirect(dashboard_url($u['role']));
}

$error = null;
if (is_post()) {
    require_csrf();
    $identifier = input('identifier');
    $result = attempt_login($identifier, (string) ($_POST['password'] ?? ''), $role);
    if ($result['ok']) {
        $target = dashboard_url($role);
        $intended = $_SESSION['intended_url'] ?? null;
        unset($_SESSION['intended_url']);
        // Only follow same-site paths inside this role's area (no open redirects).
        if (is_string($intended) && str_starts_with($intended, base_path() . '/' . $role . '/') && !str_contains($intended, '//')) {
            $target = $intended;
        }
        redirect($target);
    }
    $error = $result['error'];
}

$expired = !empty($_SESSION['session_expired']);
unset($_SESSION['session_expired']);

$labels = [
    'student' => ['Student Login', 'USN or email', 'e.g. 2KA24CS001'],
    'driver'  => ['Driver Login', 'Username or email', 'e.g. driver1'],
    'admin'   => ['Admin Login', 'Username or email', 'admin username'],
];
[$title, $idLabel, $idHint] = $labels[$role];
guest_header($title);
?>
<div class="card shadow guest-card">
  <div class="card-body p-4">
    <div class="text-center mb-3">
      <a href="<?= e(url('index.php')) ?>" class="text-decoration-none"><span class="brand-mark"><i class="bi bi-bus-front-fill"></i></span></a>
      <h1 class="h4 mt-2 mb-0"><?= e(college_name()) ?></h1>
      <div class="text-muted small">Bus Pass &amp; Route Management</div>
    </div>
    <ul class="nav nav-pills nav-fill mb-3 small">
      <?php foreach ($labels as $r => $l): ?>
      <li class="nav-item"><a class="nav-link<?= $r === $role ? ' active' : '' ?>" href="<?= e(url('login.php?role=' . $r)) ?>"><?= e(ucfirst($r)) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <h2 class="h5"><?= e($title) ?></h2>
    <?php if ($expired): ?><div class="alert alert-warning py-2">Your session expired. Please log in again.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <?php render_flashes(); ?>
    <form method="post" class="needs-validation" novalidate autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="role" value="<?= e($role) ?>">
      <div class="mb-3">
        <label class="form-label" for="identifier"><?= e($idLabel) ?></label>
        <input class="form-control" id="identifier" name="identifier" required maxlength="150" autocomplete="username" placeholder="<?= e($idHint) ?>" value="<?= e(input('identifier')) ?>">
        <div class="invalid-feedback">Required.</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <div class="input-group">
          <input class="form-control" type="password" id="password" name="password" required maxlength="72" autocomplete="current-password">
          <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right"></i> Log in</button>
    </form>
    <?php if ($role === 'student'): ?>
    <p class="text-center small mt-3 mb-0">No account? <a href="<?= e(url('register.php')) ?>">Register as a student</a></p>
    <?php else: ?>
    <p class="text-center small text-muted mt-3 mb-0">Driver and admin accounts are created by the transport office.</p>
    <?php endif; ?>
  </div>
</div>
<?php guest_footer();
