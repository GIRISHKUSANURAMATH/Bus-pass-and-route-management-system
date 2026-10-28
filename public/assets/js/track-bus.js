/*
 * Live bus tracking (student + admin single-bus view).
 * Polls the server every N seconds. Status (LIVE / DELAYED / OFFLINE) is
 * computed by the SERVER from the age of the last GPS fix, so a stale
 * location is never shown as live.
 */
(function () {
  'use strict';
  const cfg = App.data('trackConfig');
  if (!cfg) return;
  const map = MapKit.create('trackMap');
  MapKit.drawRoute(map, cfg.stops, { boardId: cfg.boardId, destId: cfg.destId });

  const $ = (id) => document.getElementById(id);
  let marker = null, accuracyCircle = null, firstFix = true, timer = null, failures = 0;

  function render(d) {
    const info = MapKit.statusInfo(d.status, d.simulated);
    $('tStatus').className = 'badge live-badge ' + info.cls;
    $('tStatus').textContent = info.label;
    $('tSim').classList.toggle('d-none', !d.simulated);
    $('tBus').textContent = d.bus.number + ' · ' + d.bus.registration;
    $('tRoute').textContent = d.bus.route_label || '—';
    $('tDriver').textContent = d.bus.driver || 'Not assigned';
    $('tUpdated').textContent = d.location ? d.location.updated_label : 'No location yet';
    $('tSpeed').textContent = d.location && d.location.speed_kmph != null ? d.location.speed_kmph + ' km/h' : '—';
    $('tAccuracy').textContent = d.location && d.location.accuracy != null ? '±' + Math.round(d.location.accuracy) + ' m' : '—';

    if (d.location) {
      const ll = [d.location.lat, d.location.lng];
      const state = d.simulated ? 'sim' : (d.status === 'live' ? '' : 'stale');
      if (!marker) {
        marker = L.marker(ll, { icon: MapKit.busIcon(state), zIndexOffset: 1000 }).addTo(map);
      } else {
        marker.setLatLng(ll);
        marker.setIcon(MapKit.busIcon(state));
      }
      marker.bindPopup('<strong>' + App.esc(d.bus.number) + '</strong><br>' + App.esc(info.label) + '<br>' + App.esc(d.location.updated_label));
      if (d.location.accuracy && d.location.accuracy < 2000) {
        if (!accuracyCircle) accuracyCircle = L.circle(ll, { radius: d.location.accuracy, weight: 1, fillOpacity: 0.08 }).addTo(map);
        else accuracyCircle.setLatLng(ll).setRadius(d.location.accuracy);
      }
      if (firstFix) { map.setView(ll, Math.max(map.getZoom(), 12)); firstFix = false; }
    }
  }

  async function poll() {
    try {
      const d = await App.api(cfg.endpoint);
      if (d.httpStatus === 401) { window.location.reload(); return; }
      if (!d.ok) throw new Error(d.error || 'error');
      failures = 0;
      $('tError').classList.add('d-none');
      render(d);
    } catch (e) {
      failures++;
      $('tError').classList.remove('d-none');
      $('tError').textContent = 'Could not reach the server (' + failures + '). Retrying…';
    }
    // back off when the tab is hidden or the server is failing - don't overload it
    const delay = (document.hidden ? 30 : cfg.poll) * 1000 * Math.min(4, 1 + failures);
    timer = setTimeout(poll, delay);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { clearTimeout(timer); poll(); } });
  $('tRefresh') && $('tRefresh').addEventListener('click', () => { clearTimeout(timer); poll(); });
  poll();
})();
