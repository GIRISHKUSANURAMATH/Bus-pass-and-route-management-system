<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('admin');

page_header('Live Buses', 'live-map.php', ['vendor/leaflet/leaflet.css']);

if ($busId = input_int('bus')) {   // single-bus view re-uses the student tracking panel
    $bus = q_one('SELECT id, route_id, bus_code FROM buses WHERE id = ?', [$busId]);
    if ($bus) {
        echo '<a class="small" href="' . e(url('admin/live-map.php')) . '">&larr; All buses</a>';
        $trackConfig = [
            'endpoint' => 'api/tracking/bus-location.php?bus_id=' . (int) $bus['id'],
            'poll' => int_setting('tracking_poll_seconds'),
            'stops' => $bus['route_id'] ? stops_for_map((int) $bus['route_id']) : [],
            'boardId' => null, 'destId' => null,
        ];
        require __DIR__ . '/../../src/views/tracking_panel.php';
        page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/track-bus.js']);
        exit;
    }
}
$routes = q_all("SELECT id FROM routes WHERE status = 'active'");
$routeStops = [];
foreach ($routes as $r) {
    $routeStops[] = stops_for_map((int) $r['id']);
}
?>
<div class="row g-3">
  <div class="col-xl-4"><div class="card"><div class="card-header d-flex justify-content-between"><span>Buses</span><small class="text-muted" id="lmUpdated"></small></div>
    <ul class="list-group list-group-flush small" id="lmList"><li class="list-group-item text-muted">Loading…</li></ul></div>
    <p class="small text-muted mt-2">LIVE = fix within <?= int_setting('gps_live_seconds') ?> s · DELAYED = within <?= int_setting('gps_offline_seconds') ?> s · otherwise OFFLINE. Change in Settings.</p></div>
  <div class="col-xl-8"><div id="liveMap" class="map-lg"></div></div>
</div>
<script type="application/json" id="liveConfig"><?= json_for_html(['poll' => int_setting('tracking_poll_seconds'), 'routes' => $routeStops, 'busUrl' => url('admin/live-map.php?bus=')]) ?></script>
<?php page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/live-map.js']);
