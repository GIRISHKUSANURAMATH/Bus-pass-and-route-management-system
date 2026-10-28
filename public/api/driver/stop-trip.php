<?php
/** POST - stop the driver's active trip. */
declare(strict_types=1);
const API_REQUEST = true;
require __DIR__ . '/../../../src/bootstrap.php';

['trip' => $trip] = driver_api_context(true);
$last = q_one('SELECT latitude, longitude FROM bus_locations WHERE trip_id = ? ORDER BY id DESC LIMIT 1', [$trip['id']]);
q('UPDATE trips SET status = "completed", ended_at = ?, end_latitude = ?, end_longitude = ? WHERE id = ? AND status = "active"',
    [date('Y-m-d H:i:s'), $last['latitude'] ?? null, $last['longitude'] ?? null, $trip['id']]);
audit_log('trip_stopped', 'trip', (int) $trip['id'], ['mode' => $trip['gps_mode']]);
json_response(['ok' => true]);
