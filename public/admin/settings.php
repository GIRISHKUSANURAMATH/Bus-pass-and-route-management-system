<?php
/**
 * Settings, including the payment QR. The QR path is stored ONLY in the
 * `settings` table (key payment_qr_path); every page resolves it through
 * payment_qr_url().
 */
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');

$DEFAULT_QR = SETTING_DEFAULTS['payment_qr_path'];

if (is_post()) {
    require_csrf();
    $section = input('section');

    if ($section === 'general') {
        $vals = [
            'college_name' => input('college_name'),
            'college_full_name' => input('college_full_name'),
            'college_address' => input('college_address'),
            'transport_office_phone' => input('transport_office_phone'),
            'current_academic_year' => input('current_academic_year'),
        ];
        if (mb_strlen($vals['college_name']) < 2 || mb_strlen($vals['college_name']) > 40) {
            flash('danger', 'College short name must be 2–40 characters.');
        } elseif ($vals['current_academic_year'] !== '' && !valid_academic_year($vals['current_academic_year'])) {
            flash('danger', 'Academic year must look like 2026-2027.');
        } else {
            foreach ($vals as $k => $v) { set_setting($k, mb_substr($v, 0, 255)); }
            audit_log('settings_updated', 'settings', null, ['section' => 'general']);
            flash('success', 'General settings saved.');
        }
    } elseif ($section === 'payment') {
        $merchant = input('payment_merchant_name');
        $upi = input('payment_upi_id');
        $instr = input('payment_instructions');
        if (mb_strlen($merchant) > 120 || mb_strlen($instr) > 500) {
            flash('danger', 'Text too long.');
        } elseif ($upi !== '' && !preg_match('/^[A-Za-z0-9._-]{2,64}@[A-Za-z0-9.-]{2,64}$/', $upi)) {
            flash('danger', 'UPI ID should look like name@bank.');
        } else {
            set_setting('payment_merchant_name', $merchant);
            set_setting('payment_upi_id', $upi);
            set_setting('payment_instructions', $instr);
            $up = handle_image_upload($_FILES['payment_qr'] ?? null, PUBLIC_PATH . '/assets/qr', UPLOAD_QR_MAX_BYTES);
            if (!$up['ok']) {
                flash('danger', 'QR image: ' . $up['error']);
            } else {
                if ($up['file']) {
                    $old = (string) setting('payment_qr_path');
                    set_setting('payment_qr_path', 'assets/qr/' . $up['file']);
                    // remove the previous admin-uploaded QR (never the official default file)
                    if ($old !== $DEFAULT_QR) {
                        delete_upload(PUBLIC_PATH . '/assets/qr', basename($old));
                    }
                    audit_log('payment_qr_replaced', 'settings', null, ['new' => $up['file']]);
                }
                audit_log('settings_updated', 'settings', null, ['section' => 'payment']);
                flash('success', 'Payment settings saved.' . ($up['file'] ? ' New QR is now live – scan it once with a UPI app to confirm.' : ''));
            }
        }
    } elseif ($section === 'restore_qr') {
        $old = (string) setting('payment_qr_path');
        set_setting('payment_qr_path', $DEFAULT_QR);
        if ($old !== $DEFAULT_QR) {
            delete_upload(PUBLIC_PATH . '/assets/qr', basename($old));
        }
        audit_log('payment_qr_restored', 'settings');
        flash('success', 'Official default QR restored.');
    } elseif ($section === 'gps') {
        $live = input_int('gps_live_seconds');
        $off = input_int('gps_offline_seconds');
        $send = input_int('gps_send_interval');
        $poll = input_int('tracking_poll_seconds');
        if (!$live || !$off || !$send || !$poll || $live < 10 || $off <= $live || $off > 3600 || $send < 3 || $send > 60 || $poll < 5 || $poll > 60) {
            flash('danger', 'Check the values: live ≥ 10 s, offline > live, send interval 3–60 s, poll 5–60 s.');
        } else {
            foreach (compact('live', 'off', 'send', 'poll') as $k => $v) {
                set_setting(['live' => 'gps_live_seconds', 'off' => 'gps_offline_seconds', 'send' => 'gps_send_interval', 'poll' => 'tracking_poll_seconds'][$k], (string) $v);
            }
            audit_log('settings_updated', 'settings', null, ['section' => 'gps']);
            flash('success', 'GPS settings saved.');
        }
    }
    redirect('admin/settings.php');
}

$s = fn (string $k) => e((string) setting($k));
page_header('Settings & Payment QR', 'settings.php');
?>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card mb-3"><div class="card-header">Payment QR (shown to students on the payment page)</div><div class="card-body">
      <div class="row g-3">
        <div class="col-md-5 text-center">
          <div class="qr-frame"><img src="<?= e(payment_qr_url()) ?>" alt="Current payment QR" style="max-height:300px"></div>
          <div class="small text-muted mt-1"><?= setting('payment_qr_path') === $DEFAULT_QR ? 'Official SKSVMACET QR (default)' : 'Custom QR uploaded by admin' ?></div>
          <?php if (setting('payment_qr_path') !== $DEFAULT_QR): ?>
          <form method="post" class="mt-2" data-confirm="Restore the official default QR?"><?= csrf_field() ?><input type="hidden" name="section" value="restore_qr"><button class="btn btn-sm btn-outline-secondary">Restore default</button></form>
          <?php endif; ?>
        </div>
        <div class="col-md-7">
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="section" value="payment">
            <div class="mb-2"><label class="form-label small">Merchant name</label><input class="form-control form-control-sm" name="payment_merchant_name" value="<?= $s('payment_merchant_name') ?>"></div>
            <div class="mb-2"><label class="form-label small">UPI ID (display only)</label><input class="form-control form-control-sm" name="payment_upi_id" value="<?= $s('payment_upi_id') ?>"></div>
            <div class="mb-2"><label class="form-label small">Instructions to students</label><textarea class="form-control form-control-sm" name="payment_instructions" rows="3"><?= $s('payment_instructions') ?></textarea></div>
            <div class="mb-2"><label class="form-label small">Replace QR image (JPG/PNG/WEBP, max 3 MB)</label>
              <input class="form-control form-control-sm" type="file" name="payment_qr" accept="image/jpeg,image/png,image/webp" data-preview="qrPreview">
              <img id="qrPreview" class="img-thumbnail mt-2 d-none" style="max-height:160px" alt="New QR preview">
              <div class="form-text">Upload only the college's official merchant QR. The image is stored unmodified.</div></div>
            <button class="btn btn-primary btn-sm">Save payment settings</button>
          </form>
        </div>
      </div>
    </div></div>
  </div>
  <div class="col-xl-6">
    <div class="card mb-3"><div class="card-header">College</div><div class="card-body">
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="section" value="general">
        <div class="col-md-4"><label class="form-label small">Short name</label><input class="form-control form-control-sm" name="college_name" required value="<?= $s('college_name') ?>"></div>
        <div class="col-md-8"><label class="form-label small">Full name</label><input class="form-control form-control-sm" name="college_full_name" value="<?= $s('college_full_name') ?>"></div>
        <div class="col-md-8"><label class="form-label small">Address</label><input class="form-control form-control-sm" name="college_address" value="<?= $s('college_address') ?>"></div>
        <div class="col-md-4"><label class="form-label small">Transport office phone</label><input class="form-control form-control-sm" name="transport_office_phone" value="<?= $s('transport_office_phone') ?>"></div>
        <div class="col-md-4"><label class="form-label small">Current academic year</label><input class="form-control form-control-sm" name="current_academic_year" placeholder="2026-2027" value="<?= $s('current_academic_year') ?>"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
    </div></div>
    <div class="card"><div class="card-header">GPS &amp; live tracking</div><div class="card-body">
      <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="section" value="gps">
        <div class="col-6"><label class="form-label small">LIVE if last fix within (s)</label><input class="form-control form-control-sm" type="number" name="gps_live_seconds" value="<?= $s('gps_live_seconds') ?>"></div>
        <div class="col-6"><label class="form-label small">OFFLINE after (s)</label><input class="form-control form-control-sm" type="number" name="gps_offline_seconds" value="<?= $s('gps_offline_seconds') ?>"></div>
        <div class="col-6"><label class="form-label small">Driver phone sends every (s)</label><input class="form-control form-control-sm" type="number" name="gps_send_interval" value="<?= $s('gps_send_interval') ?>"></div>
        <div class="col-6"><label class="form-label small">Student map refresh (s)</label><input class="form-control form-control-sm" type="number" name="tracking_poll_seconds" value="<?= $s('tracking_poll_seconds') ?>"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
    </div></div>
  </div>
</div>
<?php page_footer();
