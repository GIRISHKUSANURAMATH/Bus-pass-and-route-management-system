/* Leaflet + OpenStreetMap helpers shared by all map pages. */
(function () {
  'use strict';
  const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  const ATTRIB = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';

  window.MapKit = {
    create(elId, opts) {
      const map = L.map(elId, Object.assign({ zoomControl: true }, opts || {}));
      L.tileLayer(TILE_URL, { maxZoom: 19, attribution: ATTRIB }).addTo(map);
      map.setView([15.2, 75.4], 10);   // Laxmeshwar / Gadag / Hubballi area
      return map;
    },

    stopIcon(kind) {
      return L.divIcon({ className: '', html: '<div class="stop-marker ' + (kind || '') + '"></div>', iconSize: [22, 22], iconAnchor: [11, 11] });
    },

    busIcon(state) {
      return L.divIcon({ className: '', html: '<div class="bus-marker ' + (state || '') + '">🚌</div>', iconSize: [38, 38], iconAnchor: [19, 19] });
    },

    /** Draw stops (ordered) + route polyline. Returns the layer group. */
    drawRoute(map, stops, opts) {
      opts = opts || {};
      const group = L.featureGroup().addTo(map);
      if (!stops || !stops.length) return group;
      const pts = stops.map((s) => [s.lat, s.lng]);
      L.polyline(pts, { color: opts.color || '#1d4ed8', weight: 5, opacity: 0.6 }).addTo(group);
      stops.forEach((s) => {
        let kind = '';
        if (opts.boardId && s.id === opts.boardId) kind = 'board';
        if (opts.destId && s.id === opts.destId) kind = 'dest';
        const label = (kind === 'board' ? '🟢 Boarding: ' : kind === 'dest' ? '🔴 Destination: ' : '') +
          App.esc(s.name) + (s.eta ? '<br><small>Approx. ' + App.esc(s.eta) + '</small>' : '');
        L.marker([s.lat, s.lng], { icon: MapKit.stopIcon(kind), title: s.name }).bindPopup(label).addTo(group);
      });
      if (opts.fit !== false) map.fitBounds(group.getBounds(), { padding: [30, 30] });
      return group;
    },

    statusInfo(status, simulated) {
      const map = {
        live:    ['🟢 LIVE', 'text-bg-success'],
        delayed: ['🟡 LOCATION DELAYED', 'text-bg-warning'],
        offline: ['🔴 BUS LOCATION OFFLINE', 'text-bg-danger'],
        waiting: ['⏳ TRIP STARTED – WAITING FOR GPS', 'text-bg-info'],
        no_trip: ['⚪ NO ACTIVE TRIP', 'text-bg-secondary'],
      };
      const [label, cls] = map[status] || ['UNKNOWN', 'text-bg-secondary'];
      return { label: (simulated && status === 'live') ? '🟣 LIVE (DEMO SIMULATION)' : label, cls: simulated && status === 'live' ? 'text-bg-primary' : cls };
    },
  };
})();
