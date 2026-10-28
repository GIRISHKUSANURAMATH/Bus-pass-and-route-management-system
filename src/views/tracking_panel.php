<?php
/**
 * Live-tracking panel markup (student track page + admin single-bus view).
 * $trackConfig must be set by the including page.
 */
declare(strict_types=1);
?>
<div class="alert sim-banner text-center d-none" id="tSim">🟣 DEMO SIMULATION – this bus position is simulated for demonstration, not real GPS.</div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6 text-uppercase text-muted mb-3">Bus live tracking</h2>
      <div class="mb-3"><span class="badge live-badge text-bg-secondary" id="tStatus">Loading…</span></div>
      <dl class="row small mb-2">
        <dt class="col-5">Bus</dt><dd class="col-7" id="tBus">—</dd>
        <dt class="col-5">Route</dt><dd class="col-7" id="tRoute">—</dd>
        <dt class="col-5">Driver</dt><dd class="col-7" id="tDriver">—</dd>
        <dt class="col-5">Last updated</dt><dd class="col-7 fw-bold" id="tUpdated">—</dd>
        <dt class="col-5">Speed</dt><dd class="col-7" id="tSpeed">—</dd>
        <dt class="col-5">GPS accuracy</dt><dd class="col-7" id="tAccuracy">—</dd>
      </dl>
      <div class="alert alert-danger small py-1 d-none" id="tError"></div>
      <button class="btn btn-sm btn-outline-primary" id="tRefresh" type="button"><i class="bi bi-arrow-clockwise"></i> Refresh now</button>
      <hr>
      <div class="small text-muted">
        <div><span class="stop-marker board d-inline-block align-middle"></span> Boarding stop</div>
        <div><span class="stop-marker dest d-inline-block align-middle"></span> Destination stop</div>
        <div class="mt-2">Updates every <?= (int) $trackConfig['poll'] ?> s. Live tracking needs the driver's phone to have GPS, internet and the tracking page open.</div>
      </div>
    </div></div>
  </div>
  <div class="col-lg-8"><div id="trackMap" class="map-lg"></div></div>
</div>
<script type="application/json" id="trackConfig"><?= json_for_html($trackConfig) ?></script>
