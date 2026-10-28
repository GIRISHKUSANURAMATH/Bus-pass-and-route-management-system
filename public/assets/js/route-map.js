/* Static route map: <div id="routeMap"> + JSON #routeMapData {stops, boardId, destId, path?} */
(function () {
  'use strict';
  const el = document.getElementById('routeMap');
  const data = App.data('routeMapData');
  if (!el || !data) return;
  const map = MapKit.create('routeMap');
  const group = MapKit.drawRoute(map, data.stops, { boardId: data.boardId, destId: data.destId, fit: !(data.path && data.path.length) });
  if (data.path && data.path.length) {
    const line = L.polyline(data.path, { color: '#dc2626', weight: 4 }).addTo(map);
    L.circleMarker(data.path[0], { radius: 7, color: '#16a34a' }).bindPopup('Trip start').addTo(map);
    L.circleMarker(data.path[data.path.length - 1], { radius: 7, color: '#dc2626' }).bindPopup('Last position').addTo(map);
    const b = line.getBounds();
    if (group.getLayers().length) b.extend(group.getBounds());
    map.fitBounds(b, { padding: [30, 30] });
  }
})();
