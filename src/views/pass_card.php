<?php
/**
 * Digital bus-pass card. Expects $pass (PASS_SELECT row), $status, $verifyUrl.
 * The QR encodes ONLY the verification URL with a random token.
 */
declare(strict_types=1);
?>
<div class="print-area">
  <div class="bus-pass">
    <div class="bp-head">
      <div class="college"><?= e(college_name()) ?></div>
      <div class="title">BUS PASS</div>
      <div class="small opacity-75"><?= e((string) setting('college_address')) ?></div>
    </div>
    <div class="bp-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="fs-5 fw-bold"><?= e($pass['full_name']) ?></div>
          <div class="text-muted small"><?= e($pass['usn']) ?></div>
        </div>
        <?php if ($pass['profile_photo']): ?>
          <img class="bp-photo" src="<?= e(url('file.php?type=profile&id=' . $pass['student_id'])) ?>" alt="Photo">
        <?php endif; ?>
      </div>
      <dl>
        <dt>College</dt><dd><?= e(college_name()) ?></dd>
        <dt>Branch</dt><dd><?= e($pass['branch']) ?></dd>
        <dt>Year</dt><dd><?= e(year_label((int) $pass['year_of_study'])) ?></dd>
        <dt>Academic Year</dt><dd><?= e($pass['academic_year']) ?></dd>
        <dt>Bus Pass ID</dt><dd><?= e($pass['pass_number']) ?></dd>
        <dt>Route</dt><dd><?= e($pass['origin'] . ' → ' . $pass['destination']) ?></dd>
        <dt>Bus</dt><dd><?= e($pass['bus_code'] ? $pass['bus_code'] . ' · ' . $pass['registration_number'] : 'Any bus on route') ?></dd>
        <dt>Boarding</dt><dd><?= e($pass['boarding_stop']) ?></dd>
        <dt>Destination</dt><dd><?= e($pass['destination_stop']) ?></dd>
        <dt>Valid From</dt><dd><?= e(fmt_date($pass['valid_from'])) ?></dd>
        <dt>Valid Until</dt><dd><?= e(fmt_date($pass['valid_until'])) ?></dd>
        <dt>Payment</dt><dd><?= e(STATUS_LABELS[$pass['payment_status']][0] ?? '—') ?></dd>
        <dt>Status</dt><dd><span class="badge text-bg-<?= $status === 'active' ? 'success' : 'danger' ?> fs-6"><?= e(strtoupper($status)) ?></span></dd>
      </dl>
    </div>
    <div class="bp-qr">
      <div id="passQr" data-qr="<?= e($verifyUrl) ?>"></div>
      <div class="small text-muted">Scan QR to verify</div>
    </div>
  </div>
</div>
