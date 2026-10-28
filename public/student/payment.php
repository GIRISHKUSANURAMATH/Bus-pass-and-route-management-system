<?php
/**
 * Bus-pass payment: shows the OFFICIAL college UPI QR (from settings, via
 * payment_qr_url()) and collects the UTR + optional screenshot.
 * Submitting does NOT mark anything as paid - an admin must verify it.
 */
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('student');
$student = current_student();

$appId = input_int('application') ?? 0;
// Ownership check: a student can only ever load their own application.
$app = q_one('SELECT a.*, r.name AS route_name, bs.name AS boarding, ds.name AS destination,
                     p.id AS payment_id, p.status AS payment_status, p.transaction_reference, p.payment_date,
                     p.payment_screenshot, p.rejection_reason, p.amount
              FROM bus_pass_applications a
              JOIN routes r ON r.id = a.route_id
              JOIN bus_stops bs ON bs.id = a.boarding_stop_id
              JOIN bus_stops ds ON ds.id = a.destination_stop_id
              JOIN payments p ON p.application_id = a.id
              WHERE a.id = ? AND a.student_id = ?', [$appId, $student['id']]);
if (!$app) {
    flash('warning', 'Application not found.');
    redirect('student/applications.php');
}
$canSubmit = $app['status'] === 'payment_pending' && in_array($app['payment_status'], ['pending', 'rejected'], true);

$errors = [];
if (is_post() && $canSubmit) {
    require_csrf();
    $utr  = strtoupper(preg_replace('/\s+/', '', input('transaction_reference')));
    $date = input('payment_date');

    if (!preg_match('/^[A-Z0-9]{8,30}$/', $utr)) {
        $errors[] = 'Enter the UTR / transaction reference exactly as shown in your UPI app (8–30 letters/digits; UPI UTRs are usually 12 digits).';
    }
    if (!valid_date($date)) {
        $errors[] = 'Enter the payment date.';
    } elseif ($date > date('Y-m-d')) {
        $errors[] = 'Payment date cannot be in the future.';
    } elseif ($date < date('Y-m-d', strtotime($app['created_at'] . ' -1 day'))) {
        $errors[] = 'Payment date cannot be before the application date.';
    }
    if (!$errors && q_val('SELECT 1 FROM payments WHERE transaction_reference = ? AND id <> ?', [$utr, $app['payment_id']])) {
        $errors[] = 'This UTR has already been submitted for another payment. Each UPI transaction can be used only once.';
        audit_log('duplicate_utr_attempt', 'payment', (int) $app['payment_id'], ['utr' => $utr]);
    }
    $shot = null;
    if (!$errors) {
        $up = handle_image_upload($_FILES['payment_screenshot'] ?? null, UPLOAD_PATH . '/payment', UPLOAD_PAYMENT_MAX_BYTES);
        if (!$up['ok']) {
            $errors[] = 'Screenshot: ' . $up['error'];
        } else {
            $shot = $up['file'];
        }
    }
    if (!$errors) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            q('UPDATE payments SET transaction_reference = ?, payment_date = ?, payment_method = "UPI",
                   payment_screenshot = COALESCE(?, payment_screenshot), status = "submitted", rejection_reason = NULL,
                   submitted_at = ?, verified_by = NULL, verified_at = NULL
               WHERE id = ? AND student_id = ? AND status IN ("pending","rejected")',
                [$utr, $date, $shot, date('Y-m-d H:i:s'), $app['payment_id'], $student['id']]);
            q('UPDATE bus_pass_applications SET status = "payment_submitted" WHERE id = ? AND status = "payment_pending"', [$app['id']]);
            $pdo->commit();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            delete_upload(UPLOAD_PATH . '/payment', $shot);
            if ($ex->getCode() !== '23000') {
                throw $ex;
            }
            $errors[] = 'This UTR has already been submitted for another payment.';
        }
        if (!$errors) {
            if ($shot && $app['payment_screenshot'] && $shot !== $app['payment_screenshot']) {
                delete_upload(UPLOAD_PATH . '/payment', $app['payment_screenshot']);
            }
            audit_log('payment_submitted', 'payment', (int) $app['payment_id'], ['utr' => $utr, 'amount' => $app['amount']]);
            notify_admins('Payment submitted', "{$user['full_name']} ({$student['usn']}) submitted UTR {$utr} for " . money($app['amount']) . '.', 'admin/payments.php');
            flash('success', 'Payment details submitted. Status: Pending Verification. The transport office will verify it against the college account.');
            redirect('student/applications.php');
        }
    }
}

page_header('Bus Pass Payment', 'applications.php');
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body text-center">
        <div class="fw-bold fs-4 text-primary"><?= e(college_name()) ?></div>
        <div class="text-uppercase small text-muted" style="letter-spacing:.2em">Bus Pass Payment</div>
        <div class="my-2">Amount</div>
        <div class="amount-pill mb-2"><?= e(money($app['amount'])) ?></div>
        <div class="fw-semibold text-info mb-2">Scan &amp; Pay</div>
        <div class="qr-frame mb-2">
          <img src="<?= e(payment_qr_url()) ?>" alt="Official <?= e(college_name()) ?> UPI payment QR code">
        </div>
        <dl class="row small text-start mb-0">
          <dt class="col-4">Merchant</dt><dd class="col-8"><?= e((string) setting('payment_merchant_name')) ?></dd>
          <dt class="col-4">UPI ID</dt><dd class="col-8"><code><?= e((string) setting('payment_upi_id')) ?></code></dd>
          <dt class="col-4">Pay for</dt><dd class="col-8"><?= e($app['application_no']) ?> · <?= e($app['route_name']) ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body small">
      <div class="row">
        <div class="col-6 col-md-4"><div class="text-muted">Application</div><?= e($app['application_no']) ?></div>
        <div class="col-6 col-md-4"><div class="text-muted">Stops</div><?= e($app['boarding'] . ' → ' . $app['destination']) ?></div>
        <div class="col-6 col-md-4"><div class="text-muted">Duration</div><?= e(duration_label($app['duration'])) ?></div>
        <div class="col-6 col-md-4 mt-2"><div class="text-muted">Payment status</div><?= status_badge($app['payment_status']) ?></div>
        <div class="col-6 col-md-4 mt-2"><div class="text-muted">Application status</div><?= status_badge($app['status']) ?></div>
      </div>
    </div></div>

    <?php if ($app['payment_status'] === 'rejected'): ?>
      <div class="alert alert-danger"><strong>Your previous payment submission was rejected.</strong>
        <?= $app['rejection_reason'] ? 'Reason: ' . e($app['rejection_reason']) : '' ?> Please check and submit again.</div>
    <?php endif; ?>

    <?php if ($canSubmit): ?>
    <div class="card"><div class="card-header">After payment, submit the details</div><div class="card-body">
      <ol class="small text-muted">
        <li><?= e((string) setting('payment_instructions')) ?></li>
        <li>Pay exactly <strong><?= e(money($app['amount'])) ?></strong>. Add your USN <strong><?= e($student['usn']) ?></strong> in the UPI note if your app allows it.</li>
        <li>Never share your UPI PIN, OTP or bank password with anyone – this website will never ask for them.</li>
      </ol>
      <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="transaction_reference">UTR / Transaction reference</label>
          <input class="form-control form-control-lg text-uppercase" id="transaction_reference" name="transaction_reference" required pattern="[A-Za-z0-9 ]{8,30}" maxlength="34" inputmode="text" autocomplete="off" placeholder="12-digit UTR, e.g. 412345678901" value="<?= e(input('transaction_reference', (string) $app['transaction_reference'])) ?>">
          <div class="form-text">Find it in your UPI app under the transaction details (UTR / UPI Ref No.).</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="payment_date">Payment date</label>
          <input class="form-control" type="date" id="payment_date" name="payment_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(input('payment_date', (string) ($app['payment_date'] ?: date('Y-m-d')))) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="payment_screenshot">Payment screenshot <span class="text-muted">(optional, JPG/PNG/WEBP, max 2 MB)</span></label>
          <input class="form-control" type="file" id="payment_screenshot" name="payment_screenshot" accept="image/jpeg,image/png,image/webp" data-preview="shotPreview">
          <img id="shotPreview" class="img-thumbnail mt-2 d-none" style="max-height:200px" alt="Screenshot preview">
        </div>
        <button class="btn btn-success btn-lg w-100" type="submit"><i class="bi bi-check2-circle"></i> Submit payment</button>
      </form>
    </div></div>
    <?php else: ?>
    <div class="alert alert-info mb-0">
      <?php if ($app['payment_status'] === 'submitted'): ?>
        Payment details submitted (UTR <strong><?= e($app['transaction_reference']) ?></strong>). Status: <strong>Pending Verification</strong>. You will be notified when the transport office verifies it.
      <?php elseif ($app['payment_status'] === 'verified'): ?>
        Payment verified. <?= $app['status'] === 'approved' ? 'Your pass is ready – <a class="alert-link" href="' . e(url('student/pass.php')) . '">view it</a>.' : 'Waiting for pass approval.' ?>
      <?php else: ?>
        Payment cannot be submitted for this application (status: <?= e(STATUS_LABELS[$app['status']][0] ?? $app['status']) ?>).
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
