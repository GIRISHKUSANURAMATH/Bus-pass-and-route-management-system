<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if ($u = current_user()) {
    redirect(dashboard_url($u['role']));
}

$errors = [];
if (is_post()) {
    require_csrf();
    remember_input($_POST);

    if (!rate_limit('register:' . client_ip(), 10, 3600)) {
        $errors[] = 'Too many registrations from this network. Please try again later.';
    }

    $name      = preg_replace('/\s+/', ' ', input('full_name'));
    $usn       = normalize_usn(input('usn'));
    $email     = mb_strtolower(input('email'));
    $mobile    = input('mobile');
    $password  = (string) ($_POST['password'] ?? '');
    $confirm   = (string) ($_POST['confirm_password'] ?? '');
    $branch    = input('branch');
    $year      = input_int('year_of_study');
    $acadYear  = input('academic_year');
    $address   = input('address');
    $emergency = input('emergency_contact');

    if (!preg_match("/^[\\p{L} .'-]{3,120}$/u", $name)) {
        $errors[] = 'Enter your full name (letters, spaces, . \' - only).';
    }
    if (!valid_usn($usn)) {
        $errors[] = 'Enter a valid USN (6–20 letters and digits, e.g. 2KA24CS001).';
    }
    if (!valid_email($email)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!valid_phone($mobile)) {
        $errors[] = 'Enter a valid 10-digit Indian mobile number.';
    }
    if ($p = password_problem($password)) {
        $errors[] = $p;
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!in_array($branch, BRANCHES, true)) {
        $errors[] = 'Choose your branch.';
    }
    if ($year === null || $year < 1 || $year > 4) {
        $errors[] = 'Choose your year of study.';
    }
    if (!in_array($acadYear, academic_year_options(), true)) {
        $errors[] = 'Choose a valid academic year.';
    }
    if (mb_strlen($address) < 5 || mb_strlen($address) > 255) {
        $errors[] = 'Enter your address (5–255 characters).';
    }
    if (!valid_phone($emergency)) {
        $errors[] = 'Enter a valid 10-digit emergency contact number.';
    }
    if (!$errors) {
        if (q_val('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with this email already exists. Try logging in.';
        }
        if (q_val('SELECT 1 FROM students WHERE usn = ?', [$usn])) {
            $errors[] = 'This USN is already registered. Try logging in.';
        }
    }

    $photo = null;
    if (!$errors) {
        $up = handle_image_upload($_FILES['profile_photo'] ?? null, UPLOAD_PATH . '/profiles', UPLOAD_PROFILE_MAX_BYTES);
        if (!$up['ok']) {
            $errors[] = 'Profile photo: ' . $up['error'];
        } else {
            $photo = $up['file'];
        }
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            q('INSERT INTO users (role, email, password_hash, full_name, phone, status) VALUES ("student", ?, ?, ?, ?, "active")',
                [$email, password_hash($password, PASSWORD_DEFAULT), $name, $mobile]);
            $userId = (int) $pdo->lastInsertId();
            q('INSERT INTO students (user_id, usn, branch, year_of_study, academic_year, address, emergency_contact, profile_photo)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$userId, $usn, $branch, $year, $acadYear, $address, $emergency, $photo]);
            $pdo->commit();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            delete_upload(UPLOAD_PATH . '/profiles', $photo);
            if ($ex->getCode() === '23000') {           // unique constraint race
                $errors[] = 'This email or USN is already registered.';
            } else {
                throw $ex;
            }
        }
        if (!$errors) {
            forget_input();
            audit_log('student_registered', 'user', $userId, ['usn' => $usn], $userId, 'student');
            notify($userId, 'Welcome to ' . college_name() . ' Bus Pass', 'Your account is ready. Apply for a bus pass to get started.', 'student/apply.php');
            login_user(['id' => $userId, 'role' => 'student']);
            flash('success', 'Registration successful. Welcome, ' . $name . '!');
            redirect('student/dashboard.php');
        }
    }
}

guest_header('Student Registration');
$sel = fn (string $k, string $v) => old($k) === $v ? ' selected' : '';
?>
<div class="card shadow guest-card wide">
  <div class="card-body p-4">
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="brand-mark"><i class="bi bi-bus-front-fill"></i></span>
      <div>
        <h1 class="h4 mb-0"><?= e(college_name()) ?> · Student Registration</h1>
        <div class="text-muted small">All fields except the photo are required.</div>
      </div>
    </div>
    <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="full_name">Full name</label>
          <input class="form-control" id="full_name" name="full_name" required minlength="3" maxlength="120" value="<?= e(old('full_name')) ?>" autocomplete="name">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="usn">USN</label>
          <input class="form-control text-uppercase" id="usn" name="usn" required pattern="[A-Za-z0-9 ]{6,20}" maxlength="20" placeholder="e.g. 2KA24CS001" value="<?= e(old('usn')) ?>">
          <div class="form-text">VTU format like 2KA24CS001; other college formats are accepted.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="email">Email</label>
          <input class="form-control" type="email" id="email" name="email" required maxlength="150" value="<?= e(old('email')) ?>" autocomplete="email">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="mobile">Mobile number</label>
          <input class="form-control" id="mobile" name="mobile" required pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" value="<?= e(old('mobile')) ?>" autocomplete="tel-national">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="password">Password</label>
          <div class="input-group">
            <input class="form-control" type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
          <div class="form-text">At least 8 characters with a letter and a number.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="confirm_password">Confirm password</label>
          <input class="form-control" type="password" id="confirm_password" name="confirm_password" required data-match="password" autocomplete="new-password">
          <div class="invalid-feedback">Passwords must match.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="branch">Branch</label>
          <select class="form-select" id="branch" name="branch" required>
            <option value="">Choose…</option>
            <?php foreach (BRANCHES as $b): ?><option value="<?= e($b) ?>"<?= $sel('branch', $b) ?>><?= e($b) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="year_of_study">Year</label>
          <select class="form-select" id="year_of_study" name="year_of_study" required>
            <option value="">Choose…</option>
            <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"<?= $sel('year_of_study', (string) $y) ?>><?= e(year_label($y)) ?></option><?php endfor; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="academic_year">Academic year</label>
          <select class="form-select" id="academic_year" name="academic_year" required>
            <?php foreach (academic_year_options() as $ay): ?><option value="<?= e($ay) ?>"<?= (old('academic_year', current_academic_year()) === $ay) ? ' selected' : '' ?>><?= e($ay) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label" for="address">Address</label>
          <textarea class="form-control" id="address" name="address" rows="2" required minlength="5" maxlength="255"><?= e(old('address')) ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="emergency_contact">Emergency contact</label>
          <input class="form-control" id="emergency_contact" name="emergency_contact" required pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" value="<?= e(old('emergency_contact')) ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label" for="profile_photo">Profile photo <span class="text-muted">(optional, JPG/PNG/WEBP, max 1 MB)</span></label>
          <input class="form-control" type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp" data-preview="photoPreview">
        </div>
        <div class="col-md-4 text-center"><img id="photoPreview" class="bp-photo d-none" alt="Preview"></div>
      </div>
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-4">
        <a href="<?= e(url('login.php?role=student')) ?>">Already registered? Log in</a>
        <button class="btn btn-primary px-4" type="submit"><i class="bi bi-person-plus"></i> Create account</button>
      </div>
    </form>
  </div>
</div>
<?php guest_footer();
