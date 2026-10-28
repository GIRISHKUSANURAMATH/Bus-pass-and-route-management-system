/* Admin: all buses on one map, refreshed periodically. */
(function () {
  'use strict';
  const cfg = App.data('liveConfig');
  if (!cfg) return;
  const map = MapKit.create('liveMap');
  const colors = ['#1d4ed8', '#16a34a', '#9333ea', '#f59e0b', '#0891b2', '#db2777'];
  let bounds = null;
  cfg.routes.forEach((stops, i) => {
    const g = MapKit.drawRoute(map, stops, { color: colors[i % colors.length], fit: false });
    if (g.getLayers().length) bounds = bounds ? bounds.extend(g.getBounds()) : g.getBounds();
  });
  if (bounds) map.fitBounds(bounds, { padding: [30, 30] });

  const markers = {};
  const list = document.getElementById('lmList');

  function render(buses) {
    list.innerHTML = '';
    if (!buses.length) list.innerHTML = '<li class="list-group-item text-muted">No buses.</li>';
    buses.forEach((d) => {
      const info = MapKit.statusInfo(d.status, d.simulated);
      const li = document.createElement('li');
      li.className = 'list-group-item d-flex justify-content-between align-items-center gap-2';
      li.innerHTML = '<div><a href="' + App.esc(cfg.busUrl + d.bus.id) + '"><strong>' + App.esc(d.bus.code) + '</strong></a> · ' + App.esc(d.bus.registration) +
        '<br><span class="text-muted">' + App.esc(d.bus.route_label || 'No route') + ' · ' + App.esc(d.bus.driver || 'no driver') + '</span>' +
        (d.location ? '<br><span class="text-muted">Last fix ' + App.esc(d.location.updated_label) + '</span>' : '') + '</div>' +
        '<span class="badge ' + info.cls + '">' + App.esc(info.label) + '</span>';
      list.appendChild(li);

      const live = d.status !== 'no_trip';
      if (d.location && live) {
        const ll = [d.location.lat, d.location.lng];
        const state = d.simulated ? 'sim' : (d.status === 'live' ? '' : 'stale');
        if (!markers[d.bus.id]) markers[d.bus.id] = L.marker(ll, { icon: MapKit.busIcon(state), zIndexOffset: 1000 }).addTo(map);
        markers[d.bus.id].setLatLng(ll).setIcon(MapKit.busIcon(state))
          .bindPopup('<strong>' + App.esc(d.bus.code) + '</strong><br>' + App.esc(info.label) + '<br>' + App.esc(d.location.updated_label));
      } else if (markers[d.bus.id]) {
        map.removeLayer(markers[d.bus.id]); delete markers[d.bus.id];
      }
    });
  }

  async function poll() {
    const res = await App.api('api/admin/live-buses.php').catch(() => ({ ok: false }));
    if (res.httpStatus === 401) { location.reload(); return; }
    if (res.ok) { render(res.buses); document.getElementById('lmUpdated').textContent = 'updated ' + new Date().toLocaleTimeString(); }
    setTimeout(poll, (document.hidden ? 30 : cfg.poll) * 1000);
  }
  poll();
})();
