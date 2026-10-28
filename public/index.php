<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if ($u = current_user()) {
    redirect(dashboard_url($u['role']));
}
guest_header('Welcome');
?>
<div class="container hero" style="max-width:980px">
  <div class="text-center mb-4">
    <div class="brand-mark mb-3" style="width:64px;height:64px;font-size:2rem"><i class="bi bi-bus-front-fill"></i></div>
    <h1 class="display-6"><?= e(college_name()) ?> Bus Pass &amp; Route Management</h1>
    <p class="lead mb-0"><?= e((string) setting('college_full_name')) ?></p>
    <p class="small"><?= e((string) setting('college_address')) ?></p>
  </div>
  <div class="row g-3">
    <div class="col-md-4">
      <a class="role-tile" href="<?= e(url('login.php?role=student')) ?>">
        <i class="bi bi-mortarboard-fill"></i>
        <h2 class="h5 mt-2">Student</h2>
        <p class="text-muted small mb-2">Apply for a bus pass, pay with the college UPI QR, download your digital pass and track your bus live.</p>
        <span class="btn btn-primary btn-sm">Student login</span>
      </a>
    </div>
    <div class="col-md-4">
      <a class="role-tile" href="<?= e(url('login.php?role=driver')) ?>">
        <i class="bi bi-person-vcard-fill"></i>
        <h2 class="h5 mt-2">Driver</h2>
        <p class="text-muted small mb-2">See your assigned bus and route, start a trip and share live GPS from your phone.</p>
        <span class="btn btn-primary btn-sm">Driver login</span>
      </a>
    </div>
    <div class="col-md-4">
      <a class="role-tile" href="<?= e(url('login.php?role=admin')) ?>">
        <i class="bi bi-shield-lock-fill"></i>
        <h2 class="h5 mt-2">Transport Office</h2>
        <p class="text-muted small mb-2">Manage buses, routes, stops and fees, verify payments, approve passes and watch buses live.</p>
        <span class="btn btn-primary btn-sm">Admin login</span>
      </a>
    </div>
  </div>
  <p class="text-center mt-4 mb-0">
    <a class="btn btn-warning fw-semibold" href="<?= e(url('register.php')) ?>"><i class="bi bi-person-plus"></i> New student? Register here</a>
  </p>
  <p class="text-center small mt-3" style="color:#cbd5e1">Conductors: scan the QR code on a student's pass with any phone camera to verify it.</p>
</div>
<?php guest_footer();
