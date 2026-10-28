<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('driver');
$driver = current_driver();
$asg = driver_assignment((int) $driver['id']);

if (is_post()) {
    require_csrf();
    if (input('action') === 'password') {
        $err = change_password((int) $user['id'], (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_new'] ?? ''));
        $err ? flash('danger', $err) : flash('success', 'Password updated.');
    } else {
        $phone = input('phone');
        if (!valid_phone($phone)) {
            flash('danger', 'Enter a valid 10-digit mobile number.');
        } else {
            // Contact information only - the phone number plays NO role in GPS tracking.
            q('UPDATE users SET phone = ? WHERE id = ?', [$phone, $user['id']]);
            audit_log('profile_updated', 'driver', (int) $driver['id']);
            flash('success', 'Phone number updated.');
        }
    }
    redirect('driver/profile.php');
}
page_header('Driver Profile', 'profile.php');
?>
<div class="row g-4">
  <div class="col-lg-6"><div class="card"><div class="card-header">Profile</div><div class="card-body">
    <dl class="row small">
      <dt class="col-5">Driver ID</dt><dd class="col-7"><?= e($driver['driver_code']) ?></dd>
      <dt class="col-5">Name</dt><dd class="col-7"><?= e($user['full_name']) ?></dd>
      <dt class="col-5">Username</dt><dd class="col-7"><?= e($user['username']) ?></dd>
      <dt class="col-5">Email</dt><dd class="col-7"><?= e($user['email']) ?></dd>
      <dt class="col-5">Licence</dt><dd class="col-7"><?= e($driver['license_number'] ?: '—') ?></dd>
      <dt class="col-5">Assigned bus</dt><dd class="col-7"><?= e($asg ? $asg['bus_code'] . ' · ' . $asg['registration_number'] : '—') ?></dd>
      <dt class="col-5">Assigned route</dt><dd class="col-7"><?= e($asg['route_name'] ?? '—') ?></dd>
      <dt class="col-5">Status</dt><dd class="col-7"><?= status_badge($user['status']) ?></dd>
    </dl>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="phone">
      <div class="col"><label class="form-label" for="phone">Contact phone</label><input class="form-control" id="phone" name="phone" required pattern="[6-9][0-9]{9}" value="<?= e($user['phone']) ?>"></div>
      <div class="col-auto"><button class="btn btn-primary">Save</button></div>
    </form>
    <p class="small text-muted mt-2 mb-0">Your phone number is contact information only. Live tracking uses the GPS of the phone on which you open the Live Tracking page.</p>
  </div></div></div>
  <div class="col-lg-6"><?php render_password_card(); ?></div>
</div>
<?php page_footer();
