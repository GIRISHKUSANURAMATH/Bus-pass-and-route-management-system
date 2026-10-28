/*
 * Coordinate picker: click on #pickerMap to fill #latitude / #longitude.
 * Optional button #useMyLocation fills them from the device GPS.
 * Data: <script type="application/json" id="pickerData">{stops:[...], lat, lng}</script>
 */
(function () {
  'use strict';
  const el = document.getElementById('pickerMap');
  if (!el) return;
  const data = App.data('pickerData') || {};
  const latIn = document.getElementById('latitude');
  const lngIn = document.getElementById('longitude');
  const map = MapKit.create('pickerMap');
  if (data.stops && data.stops.length) MapKit.drawRoute(map, data.stops);
  let marker = null;

  function place(lat, lng, pan) {
    latIn.value = Number(lat).toFixed(7);
    lngIn.value = Number(lng).toFixed(7);
    if (!marker) marker = L.marker([lat, lng], { draggable: true }).addTo(map)
      .on('dragend', (e) => { const p = e.target.getLatLng(); place(p.lat, p.lng, false); });
    else marker.setLatLng([lat, lng]);
    if (pan) map.setView([lat, lng], Math.max(map.getZoom(), 14));
  }
  map.on('click', (e) => place(e.latlng.lat, e.latlng.lng, false));
  [latIn, lngIn].forEach((inp) => inp.addEventListener('change', () => {
    if (latIn.value && lngIn.value) place(latIn.value, lngIn.value, true);
  }));
  if (latIn.value && lngIn.value) place(latIn.value, lngIn.value, true);

  const btn = document.getElementById('useMyLocation');
  if (btn) btn.addEventListener('click', () => {
    if (!navigator.geolocation) return alert('GPS not supported in this browser.');
    btn.disabled = true;
    navigator.geolocation.getCurrentPosition((p) => { place(p.coords.latitude, p.coords.longitude, true); btn.disabled = false; },
      (err) => { alert('Could not get location: ' + err.message); btn.disabled = false; },
      { enableHighAccuracy: true, timeout: 15000 });
  });
})();
