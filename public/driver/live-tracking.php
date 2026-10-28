<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
$user = require_role('driver');
$driver = current_driver();
$asg = driver_assignment((int) $driver['id']);
$trip = active_trip_for_driver((int) $driver['id']);

page_header('Live Tracking', 'live-tracking.php', ['vendor/leaflet/leaflet.css']);
if (!$asg || !$asg['route_id']) {
    echo '<div class="alert alert-warning">You need an assigned bus with a route before you can start a trip. Contact the transport office.</div>';
    page_footer();
    exit;
}
$cfg = [
    'activeTrip' => $trip ? ['id' => (int) $trip['id'], 'mode' => $trip['gps_mode'], 'started_at' => fmt_datetime($trip['started_at'])] : null,
    'interval'   => int_setting('gps_send_interval'),
    'stops'      => stops_for_map((int) $asg['route_id']),
];
?>
<div class="alert sim-banner text-center d-none" id="simBanner">🟣 DEMO GPS SIMULATION ACTIVE – positions are simulated along the route, NOT real GPS.</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3"><div class="card-body">
      <dl class="row small mb-0">
        <dt class="col-5">Driver</dt><dd class="col-7"><?= e($user['full_name']) ?> (<?= e($driver['driver_code']) ?>)</dd>
        <dt class="col-5">Assigned bus</dt><dd class="col-7"><?= e($asg['bus_code'] . ' · ' . $asg['registration_number']) ?> <?= status_badge($asg['bus_status']) ?></dd>
        <dt class="col-5">Assigned route</dt><dd class="col-7"><?= e($asg['route_name']) ?></dd>
      </dl>
    </div></div>

    <div class="card mb-3"><div class="card-body">
      <div class="fw-semibold mb-2">GPS MODE</div>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="gpsMode" id="modeReal" value="real" checked>
        <label class="form-check-label" for="modeReal"><strong>REAL GPS</strong> – this phone's location</label>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="radio" name="gpsMode" id="modeSim" value="simulation">
        <label class="form-check-label" for="modeSim"><strong>DEMO SIMULATION</strong> – moves the bus along the route (for presentations)</label>
      </div>
      <div class="d-grid gap-2">
        <button class="btn btn-success btn-xl" id="btnStart" type="button"><i class="bi bi-play-circle"></i> START TRIP</button>
        <button class="btn btn-danger btn-xl" id="btnStop" type="button" disabled><i class="bi bi-stop-circle"></i> STOP TRIP</button>
      </div>
      <div class="alert alert-warning small mt-3 mb-0 d-none" id="secureWarn">
        This page is not on HTTPS or localhost, so the browser will <strong>block real GPS</strong>. Use HTTPS for real tracking, or use DEMO SIMULATION.
      </div>
    </div></div>

    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="fw-semibold">GPS status</span><span class="badge text-bg-secondary" id="gpsStatus">Idle</span>
      </div>
      <dl class="row small gps-readout mb-2">
        <dt class="col-5">Trip</dt><dd class="col-7" id="rTrip">Not started</dd>
        <dt class="col-5">Latitude</dt><dd class="col-7" id="rLat">—</dd>
        <dt class="col-5">Longitude</dt><dd class="col-7" id="rLng">—</dd>
        <dt class="col-5">Accuracy</dt><dd class="col-7" id="rAcc">—</dd>
        <dt class="col-5">Last sent</dt><dd class="col-7" id="rSent">—</dd>
        <dt class="col-5">Updates sent</dt><dd class="col-7" id="rCount">0</dd>
      </dl>
      <div class="small text-danger" id="rError"></div>
      <p class="small text-muted mb-0 mt-2">Keep this page open with the screen on while driving. Most phones pause GPS in the browser when the screen is locked or the browser is closed.</p>
    </div></div>
  </div>
  <div class="col-lg-7"><div id="driverMap" class="map-lg"></div></div>
</div>
<script type="application/json" id="driverConfig"><?= json_for_html($cfg) ?></script>
<?php page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/driver-gps.js']);
