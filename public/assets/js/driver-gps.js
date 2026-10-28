/*
 * Driver live GPS.
 *  REAL GPS:   navigator.geolocation.watchPosition -> POST api/driver/update-location.php
 *  SIMULATION: moves a point along the route stops and posts it the same way;
 *              the SERVER marks those points as simulated (decided by the trip mode).
 */
(function () {
  'use strict';
  const cfg = App.data('driverConfig');
  if (!cfg) return;
  const $ = (id) => document.getElementById(id);
  const INTERVAL = Math.max(3, cfg.interval) * 1000;

  const map = MapKit.create('driverMap');
  MapKit.drawRoute(map, cfg.stops);
  let marker = null;

  let mode = null, watchId = null, sendTimer = null, simTimer = null, wakeLock = null;
  let latestFix = null, lastSentAt = 0, sentCount = 0, sending = false;

  if (!window.isSecureContext) $('secureWarn').classList.remove('d-none');

  function setStatus(text, cls) { const b = $('gpsStatus'); b.textContent = text; b.className = 'badge text-bg-' + cls; }
  function setError(msg) { $('rError').textContent = msg || ''; }

  function showFix(fix) {
    $('rLat').textContent = fix.lat.toFixed(6);
    $('rLng').textContent = fix.lng.toFixed(6);
    $('rAcc').textContent = fix.accuracy != null ? '±' + Math.round(fix.accuracy) + ' m' : '—';
    const ll = [fix.lat, fix.lng];
    if (!marker) { marker = L.marker(ll, { icon: MapKit.busIcon(mode === 'simulation' ? 'sim' : '') }).addTo(map); map.setView(ll, 13); }
    else marker.setLatLng(ll);
  }

  function setRunning(running) {
    $('btnStart').disabled = running;
    $('btnStop').disabled = !running;
    document.querySelectorAll('input[name=gpsMode]').forEach((r) => { r.disabled = running; });
    $('simBanner').classList.toggle('d-none', !(running && mode === 'simulation'));
  }

  async function keepAwake() {
    try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch (e) { /* optional */ }
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && mode) keepAwake(); });

  async function send(fix) {
    if (sending) return;
    sending = true;
    try {
      const res = await App.api('api/driver/update-location.php', fix);
      if (res.ok) {
        lastSentAt = Date.now(); sentCount++;
        $('rSent').textContent = new Date().toLocaleTimeString();
        $('rCount').textContent = sentCount;
        setStatus(mode === 'simulation' ? 'SIMULATING – SENT' : 'LIVE – SENT', mode === 'simulation' ? 'primary' : 'success');
        setError('');
      } else if (res.httpStatus === 409 || res.httpStatus === 401 || res.httpStatus === 403) {
        setError(res.error || 'Trip is no longer active.');
        teardown(); setStatus('STOPPED', 'secondary'); setRunning(false); mode = null;
      } else if (res.code !== 'rate_limited') {
        setError(res.error || 'Server rejected the location.');
        setStatus('SEND ERROR', 'warning');
      }
    } catch (e) {
      setStatus('OFFLINE – RETRYING', 'danger');
      setError('No internet connection. Location will be sent when the connection returns.');
    } finally { sending = false; }
  }

  // ---------------- REAL GPS ----------------
  function startRealGps() {
    if (!('geolocation' in navigator)) { setError('This browser does not support GPS.'); return false; }
    setStatus('REQUESTING GPS…', 'info');
    watchId = navigator.geolocation.watchPosition((pos) => {
      latestFix = {
        lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy,
        speed: pos.coords.speed, heading: pos.coords.heading, timestamp: pos.timestamp,
      };
      showFix(latestFix);
      if (Date.now() - lastSentAt >= INTERVAL) send(latestFix);
    }, (err) => {
      const msg = { 1: 'Location permission denied. Allow location for this site in browser settings.', 2: 'GPS signal unavailable. Move to open sky / enable GPS.', 3: 'GPS timed out. Retrying…' }[err.code] || err.message;
      setError(msg);
      setStatus(err.code === 1 ? 'PERMISSION DENIED' : 'GPS ERROR', 'danger');
    }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
    // Heartbeat: if the bus is stationary watchPosition may stay quiet; resend the latest
    // *fresh* fix (its own timestamp) or request a new one.
    sendTimer = setInterval(() => {
      if (Date.now() - lastSentAt < INTERVAL) return;
      if (latestFix && Date.now() - latestFix.timestamp < 30000) send(latestFix);
      else navigator.geolocation.getCurrentPosition((p) => {
        latestFix = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy, speed: p.coords.speed, heading: p.coords.heading, timestamp: p.timestamp };
        showFix(latestFix); send(latestFix);
      }, () => {}, { enableHighAccuracy: true, maximumAge: 10000, timeout: 15000 });
    }, INTERVAL);
    return true;
  }

  // ---------------- DEMO SIMULATION ----------------
  function buildPath(stops, stepKm) {
    const pts = [];
    const R = 6371;
    const dist = (a, b) => {
      const dLat = (b.lat - a.lat) * Math.PI / 180, dLng = (b.lng - a.lng) * Math.PI / 180;
      const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
      return 2 * R * Math.asin(Math.sqrt(h));
    };
    for (let i = 0; i < stops.length - 1; i++) {
      const a = stops[i], b = stops[i + 1];
      const n = Math.max(1, Math.round(dist(a, b) / stepKm));
      for (let k = 0; k < n; k++) pts.push({ lat: a.lat + (b.lat - a.lat) * k / n, lng: a.lng + (b.lng - a.lng) * k / n });
    }
    if (stops.length) pts.push({ lat: stops[stops.length - 1].lat, lng: stops[stops.length - 1].lng });
    return pts;
  }

  function startSimulation() {
    if (!cfg.stops || cfg.stops.length < 2) { setError('Route needs at least 2 stops with coordinates for simulation.'); return false; }
    const stepKm = 0.4;
    const forward = buildPath(cfg.stops, stepKm);
    const path = forward.concat(forward.slice(0, -1).reverse());   // round trip, then loop
    let i = 0;
    setStatus('SIMULATING', 'primary');
    const tick = () => {
      const p = path[i % path.length];
      i++;
      latestFix = { lat: p.lat, lng: p.lng, accuracy: 5, speed: null, heading: null, timestamp: Date.now() };  // time-lapse: no fake speed
      showFix(latestFix);
      send(latestFix);
    };
    tick();
    simTimer = setInterval(tick, INTERVAL);
    return true;
  }

  function teardown() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    clearInterval(sendTimer); clearInterval(simTimer);
    watchId = null; sendTimer = null; simTimer = null;
    if (wakeLock) { wakeLock.release().catch(() => {}); wakeLock = null; }
  }

  async function start(requestedMode) {
    setError('');
    $('btnStart').disabled = true;
    const res = await App.api('api/driver/start-trip.php', { mode: requestedMode });
    if (!res.ok) { setError(res.error || 'Could not start trip.'); $('btnStart').disabled = false; return; }
    mode = res.trip.mode;     // server decides (a resumed trip keeps its original mode)
    document.getElementById(mode === 'simulation' ? 'modeSim' : 'modeReal').checked = true;
    $('rTrip').textContent = '#' + res.trip.id + ' since ' + res.trip.started_at + (res.resumed ? ' (resumed)' : '');
    setRunning(true);
    keepAwake();
    const ok = mode === 'simulation' ? startSimulation() : startRealGps();
    if (!ok) setStatus('NOT SENDING', 'danger');
  }

  async function stop() {
    $('btnStop').disabled = true;
    teardown();
    const res = await App.api('api/driver/stop-trip.php', {});
    if (!res.ok && res.httpStatus !== 409) { setError(res.error || 'Could not stop trip.'); $('btnStop').disabled = false; return; }
    mode = null;
    setRunning(false);
    setStatus('TRIP STOPPED', 'secondary');
    $('rTrip').textContent = 'Not started';
  }

  $('btnStart').addEventListener('click', () => start(document.querySelector('input[name=gpsMode]:checked').value));
  $('btnStop').addEventListener('click', () => { if (confirm('Stop the trip and stop sharing location?')) stop(); });

  // An active trip exists (page reloaded / phone restarted): offer to resume.
  if (cfg.activeTrip) {
    document.getElementById(cfg.activeTrip.mode === 'simulation' ? 'modeSim' : 'modeReal').checked = true;
    $('rTrip').textContent = '#' + cfg.activeTrip.id + ' since ' + cfg.activeTrip.started_at;
    $('btnStart').innerHTML = '<i class="bi bi-arrow-repeat"></i> RESUME SHARING';
    $('btnStop').disabled = false;
    setStatus('TRIP ACTIVE – NOT SENDING', 'warning');
    setError('A trip is active but this page is not sending location. Tap RESUME SHARING.');
  }
})();
