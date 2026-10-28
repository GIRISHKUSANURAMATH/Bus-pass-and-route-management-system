<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('student');
$student = current_student();
$sid = (int) $student['id'];

$open = q_one("SELECT id, application_no, status FROM bus_pass_applications
               WHERE student_id = ? AND status IN ('payment_pending','payment_submitted','pending_approval')
               ORDER BY id DESC LIMIT 1", [$sid]);
$latestPass = student_latest_pass($sid);
$latestStatus = $latestPass ? pass_effective_status($latestPass) : null;
$daysLeft = $latestPass ? (int) floor((strtotime($latestPass['valid_until']) - strtotime(date('Y-m-d'))) / 86400) : null;
$isRenewal = $latestPass !== null;
$blockedByActive = $latestPass && in_array($latestStatus, ['active', 'scheduled'], true) && $daysLeft > 30;

$routes = q_all("SELECT id, name, origin, destination FROM routes WHERE status = 'active' ORDER BY name");
$routeData = [];
foreach ($routes as $r) {
    $routeData[] = [
        'id' => (int) $r['id'],
        'name' => $r['name'],
        'stops' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'km' => (float) $s['distance_km']],
            route_stops((int) $r['id'])),
    ];
}

$errors = [];
if (is_post() && !$open && !$blockedByActive) {
    require_csrf();
    $routeId  = input_int('route_id') ?? 0;
    $boardId  = input_int('boarding_stop_id') ?? 0;
    $destId   = input_int('destination_stop_id') ?? 0;
    $duration = input('duration');
    $acadYear = input('academic_year');

    if (!in_array($acadYear, academic_year_options(), true)) {
        $errors[] = 'Choose a valid academic year.';
    }
    // Fee is ALWAYS recalculated on the server - the browser value is display only.
    $quote = fee_quote($routeId, $boardId, $destId, $duration);
    if (!$quote['ok']) {
        $errors[] = $quote['error'];
    }
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Re-check inside the transaction (double-submit protection)
            $dup = q_val("SELECT COUNT(*) FROM bus_pass_applications WHERE student_id = ?
                          AND status IN ('payment_pending','payment_submitted','pending_approval') FOR UPDATE", [$sid]);
            if ($dup) {
                throw new RuntimeException('You already have an application in progress.');
            }
            $appNo = next_application_no();
            q('INSERT INTO bus_pass_applications (application_no, student_id, route_id, boarding_stop_id, destination_stop_id,
                   academic_year, duration, distance_km, fee_amount, application_type, previous_pass_id, status, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "payment_pending", ?)', [
                $appNo, $sid, $routeId, $boardId, $destId, $acadYear, $duration, $quote['distance_km'], $quote['amount'],
                $isRenewal ? 'renewal' : 'new', $isRenewal ? $latestPass['id'] : null, date('Y-m-d H:i:s'),
            ]);
            $appId = (int) $pdo->lastInsertId();
            q('INSERT INTO payments (student_id, application_id, amount, status) VALUES (?, ?, ?, "pending")',
                [$sid, $appId, $quote['amount']]);
            $pdo->commit();
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            $errors[] = $ex->getMessage();
        }
        if (!$errors) {
            audit_log('application_created', 'application', $appId, ['no' => $appNo, 'amount' => $quote['amount'], 'type' => $isRenewal ? 'renewal' : 'new']);
            notify_admins('New bus-pass application', "{$user['full_name']} ({$student['usn']}) applied: {$appNo}, " . money($quote['amount']) . '.', 'admin/applications.php');
            flash('success', "Application {$appNo} created. Please complete the payment of " . money($quote['amount']) . '.');
            redirect('student/payment.php?application=' . $appId);
        }
    }
}

$pre = [
    'route' => input_int('route_id') ?? ($latestPass ? (int) $latestPass['route_id'] : null),
    'board' => input_int('boarding_stop_id') ?? ($latestPass ? (int) $latestPass['boarding_stop_id'] : null),
    'dest'  => input_int('destination_stop_id') ?? ($latestPass ? (int) $latestPass['destination_stop_id'] : null),
];

page_header($isRenewal ? 'Renew Bus Pass' : 'Apply for Bus Pass', 'apply.php');
?>
<?php if ($open): ?>
  <div class="alert alert-info">
    You already have application <strong><?= e($open['application_no']) ?></strong> in progress (<?= status_badge($open['status']) ?>).
    <?php if ($open['status'] === 'payment_pending'): ?>
      <a class="alert-link" href="<?= e(url('student/payment.php?application=' . $open['id'])) ?>">Complete the payment</a>.
    <?php else: ?>
      <a class="alert-link" href="<?= e(url('student/applications.php')) ?>">View its status</a>.
    <?php endif; ?>
  </div>
<?php elseif ($blockedByActive): ?>
  <div class="alert alert-success">
    Your pass <strong><?= e($latestPass['pass_number']) ?></strong> is valid until <strong><?= e(fmt_date($latestPass['valid_until'])) ?></strong>.
    Renewal opens 30 days before expiry.
  </div>
<?php elseif (!$routeData): ?>
  <div class="alert alert-warning">No routes are open for applications yet. Please check back later.</div>
<?php else: ?>
  <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($isRenewal): ?><div class="alert alert-light border"><i class="bi bi-arrow-repeat"></i> This will be a <strong>renewal</strong> of pass <?= e($latestPass['pass_number']) ?> (<?= e(strtoupper((string) $latestStatus)) ?>, valid until <?= e(fmt_date($latestPass['valid_until'])) ?>). Your previous route is pre-selected.</div><?php endif; ?>
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card"><div class="card-body">
        <form method="post" id="applyForm" class="needs-validation" novalidate>
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="route_id">1. Route</label>
            <select class="form-select" id="route_id" name="route_id" required>
              <option value="">Choose a route…</option>
              <?php foreach ($routeData as $r): ?><option value="<?= $r['id'] ?>"<?= $pre['route'] === $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label" for="boarding_stop_id">2. Boarding stop</label>
              <select class="form-select" id="boarding_stop_id" name="boarding_stop_id" required data-selected="<?= e((string) $pre['board']) ?>"><option value="">Select route first</option></select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="destination_stop_id">3. Destination stop</label>
              <select class="form-select" id="destination_stop_id" name="destination_stop_id" required data-selected="<?= e((string) $pre['dest']) ?>"><option value="">Select route first</option></select>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label" for="academic_year">4. Academic year</label>
              <select class="form-select" id="academic_year" name="academic_year" required>
                <?php foreach (academic_year_options() as $ay): ?><option<?= $ay === (setting('current_academic_year') ?: current_academic_year()) ? ' selected' : '' ?>><?= e($ay) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="duration">5. Pass duration</label>
              <select class="form-select" id="duration" name="duration" required>
                <option value="monthly" selected><?= e(duration_label('monthly')) ?><?= ($mf = monthly_pass_fee()) !== null ? ' — ₹' . e(rtrim(rtrim(number_format($mf, 2, '.', ''), '0'), '.')) : '' ?></option>
              </select>
            </div>
          </div>
          <button class="btn btn-primary btn-lg w-100" type="submit" id="applySubmit"><i class="bi bi-send"></i> Submit application &amp; continue to payment</button>
        </form>
      </div></div>
    </div>
    <div class="col-lg-5">
      <div class="card"><div class="card-header">Fee calculation</div><div class="card-body">
        <div id="feeBox" class="text-muted">Choose route, stops and duration to see the fee.</div>
        <hr>
        <p class="small text-muted mb-0">Monthly fee set by the transport office. The final amount is always calculated by the server.</p>
      </div></div>
    </div>
  </div>
  <script type="application/json" id="routeData"><?= json_for_html($routeData) ?></script>
<?php endif; ?>
<?php page_footer(['js/apply.js']);
