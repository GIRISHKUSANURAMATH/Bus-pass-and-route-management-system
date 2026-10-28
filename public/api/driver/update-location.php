<?php
/**
 * POST {lat, lng, accuracy, speed?, heading?, timestamp(ms)} - store one GPS fix.
 *
 * Verified on EVERY request:
 *   authenticated + role driver + CSRF  ->  assigned bus + route (from DB)
 *   ->  active trip belonging to this driver AND this bus
 *   ->  valid lat/lng/accuracy/timestamp  ->  per-driver rate limit
 *   ->  (real GPS) no physically impossible jump.
 * bus_id / driver_id / trip_id are never accepted from the client.
 */
declare(strict_types=1);
const API_REQUEST = true;
require __DIR__ . '/../../../src/bootstrap.php';

['driver' => $driver, 'assignment' => $asg, 'trip' => $trip] = driver_api_context(true);
$in = json_input();

$lat = $in['lat'] ?? null;
$lng = $in['lng'] ?? null;
$acc = $in['accuracy'] ?? null;
$ts  = $in['timestamp'] ?? null;
if (!valid_lat($lat) || !valid_lng($lng) || ((float) $lat === 0.0 && (float) $lng === 0.0)) {
    json_response(['ok' => false, 'error' => 'Invalid coordinates.'], 422);
}
if ($acc !== null && (!is_numeric($acc) || $acc < 0 || $acc > 100000)) {
    json_response(['ok' => false, 'error' => 'Invalid accuracy.'], 422);
}
if (!is_numeric($ts)) {
    json_response(['ok' => false, 'error' => 'Missing timestamp.'], 422);
}
$deviceTime = (int) floor((float) $ts / 1000);
if (abs($deviceTime - time()) > GPS_MAX_CLOCK_SKEW_SEC) {
    json_response(['ok' => false, 'error' => 'Device clock is wrong or the fix is too old. Check the phone\'s date & time.'], 422);
}
$speed = isset($in['speed']) && is_numeric($in['speed']) && $in['speed'] >= 0 && $in['speed'] < 100 ? (float) $in['speed'] : null;
$heading = isset($in['heading']) && is_numeric($in['heading']) && $in['heading'] >= 0 && $in['heading'] <= 360 ? (float) $in['heading'] : null;

$prev = q_one('SELECT latitude, longitude, recorded_at, received_at FROM bus_locations WHERE trip_id = ? ORDER BY id DESC LIMIT 1', [$trip['id']]);
if ($prev && time() - strtotime($prev['received_at']) < GPS_MIN_INTERVAL_SECONDS) {
    json_response(['ok' => false, 'error' => 'Too frequent. Slow down.', 'code' => 'rate_limited'], 429);
}
$simulated = $trip['gps_mode'] === 'simulation';   // decided by the trip, not by the client
if ($prev && !$simulated) {
    $dt = max(1, $deviceTime - strtotime($prev['recorded_at']));
    $km = haversine_km((float) $prev['latitude'], (float) $prev['longitude'], (float) $lat, (float) $lng);
    if ($km > 0.5 && ($km / ($dt / 3600)) > GPS_MAX_SPEED_KMPH) {
        audit_log('gps_jump_rejected', 'trip', (int) $trip['id'], ['km' => round($km, 2), 'seconds' => $dt]);
        json_response(['ok' => false, 'error' => 'Location jump rejected (implausible speed).', 'code' => 'jump'], 422);
    }
}

$now = date('Y-m-d H:i:s');
q('INSERT INTO bus_locations (trip_id, bus_id, driver_id, latitude, longitude, accuracy_m, speed_mps, heading, is_simulated, recorded_at, received_at)
   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
    $trip['id'], $asg['bus_id'], $driver['id'], round((float) $lat, 7), round((float) $lng, 7),
    $acc !== null ? round((float) $acc, 2) : null, $speed, $heading, $simulated ? 1 : 0,
    date('Y-m-d H:i:s', $deviceTime), $now,
]);
q('UPDATE trips SET last_location_at = ?, start_latitude = COALESCE(start_latitude, ?), start_longitude = COALESCE(start_longitude, ?) WHERE id = ?',
    [$now, $lat, $lng, $trip['id']]);
json_response(['ok' => true, 'received_at' => $now, 'simulated' => $simulated]);
