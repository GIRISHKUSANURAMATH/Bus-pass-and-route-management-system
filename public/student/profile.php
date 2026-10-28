<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('student');
$student = current_student();

$errors = [];
if (is_post()) {
    require_csrf();
    if (input('action') === 'password') {
        $err = change_password((int) $user['id'], (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_new'] ?? ''));
        $err ? flash('danger', $err) : flash('success', 'Password updated.');
        redirect('student/profile.php');
    }
    // Profile update (USN, name, email, branch are controlled by the transport office)
    $phone = input('phone');
    $address = input('address');
    $emergency = input('emergency_contact');
    $year = input_int('year_of_study');
    if (!valid_phone($phone)) { $errors[] = 'Enter a valid 10-digit mobile number.'; }
    if (!valid_phone($emergency)) { $errors[] = 'Enter a valid emergency contact number.'; }
    if (mb_strlen($address) < 5 || mb_strlen($address) > 255) { $errors[] = 'Address must be 5–255 characters.'; }
    if ($year === null || $year < 1 || $year > 4) { $errors[] = 'Choose your year.'; }
    $photo = null;
    if (!$errors) {
        $up = handle_image_upload($_FILES['profile_photo'] ?? null, UPLOAD_PATH . '/profiles', UPLOAD_PROFILE_MAX_BYTES);
        if ($up['ok']) {
            $photo = $up['file'];
        } else {
            $errors[] = 'Photo: ' . $up['error'];
        }
    }
    if (!$errors) {
        q('UPDATE users SET phone = ? WHERE id = ?', [$phone, $user['id']]);
        q('UPDATE students SET address = ?, emergency_contact = ?, year_of_study = ?, profile_photo = COALESCE(?, profile_photo) WHERE id = ?',
            [$address, $emergency, $year, $photo, $student['id']]);
        if ($photo && $student['profile_photo']) {
            delete_upload(UPLOAD_PATH . '/profiles', $student['profile_photo']);
        }
        audit_log('profile_updated', 'student', (int) $student['id']);
        flash('success', 'Profile updated.');
        redirect('student/profile.php');
    }
}

page_header('My Profile', 'profile.php');
?>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card"><div class="card-header">Profile</div><div class="card-body">
      <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="d-flex gap-3 align-items-center mb-3">
        <?php if ($student['profile_photo']): ?><img class="bp-photo" src="<?= e(url('file.php?type=profile&id=' . $student['id'])) ?>" alt="Profile photo"><?php endif; ?>
        <div>
          <div class="fs-5 fw-bold"><?= e($user['full_name']) ?></div>
          <div class="text-muted small"><?= e($student['usn']) ?> · <?= e($student['branch']) ?> · <?= e($user['email']) ?></div>
          <div class="text-muted small">Academic year <?= e($student['academic_year']) ?></div>
        </div>
      </div>
      <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="profile">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label" for="phone">Mobile</label>
            <input class="form-control" id="phone" name="phone" required pattern="[6-9][0-9]{9}" value="<?= e($user['phone']) ?>"></div>
          <div class="col-md-6"><label class="form-label" for="emergency_contact">Emergency contact</label>
            <input class="form-control" id="emergency_contact" name="emergency_contact" required pattern="[6-9][0-9]{9}" value="<?= e($student['emergency_contact']) ?>"></div>
          <div class="col-md-8"><label class="form-label" for="address">Address</label>
            <textarea class="form-control" id="address" name="address" rows="2" required><?= e($student['address']) ?></textarea></div>
          <div class="col-md-4"><label class="form-label" for="year_of_study">Year</label>
            <select class="form-select" id="year_of_study" name="year_of_study"><?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"<?= (int) $student['year_of_study'] === $y ? ' selected' : '' ?>><?= e(year_label($y)) ?></option><?php endfor; ?></select></div>
          <div class="col-12"><label class="form-label" for="profile_photo">Change photo (JPG/PNG/WEBP, max 1 MB)</label>
            <input class="form-control" type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp"></div>
        </div>
        <button class="btn btn-primary mt-3">Save profile</button>
        <p class="small text-muted mt-2 mb-0">To change your name, USN, email or branch, contact the transport office.</p>
      </form>
    </div></div>
  </div>
  <div class="col-lg-5"><?php render_password_card(); ?></div>
</div>
<?php page_footer();
