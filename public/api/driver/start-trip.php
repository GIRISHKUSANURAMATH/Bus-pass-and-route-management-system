<?php
/** POST {mode: "real"|"simulation"} - start (or resume) the driver's trip on their assigned bus. */
declare(strict_types=1);
const API_REQUEST = true;
require __DIR__ . '/../../../src/bootstrap.php';

['driver' => $driver, 'assignment' => $asg, 'trip' => $trip] = driver_api_context();
$in = json_input();

if ($trip) {   // resume - the mode of an existing trip can't be changed
    json_response(['ok' => true, 'resumed' => true, 'trip' => ['id' => (int) $trip['id'], 'mode' => $trip['gps_mode'], 'started_at' => fmt_datetime($trip['started_at'])]]);
}
$mode = ($in['mode'] ?? '') === 'simulation' ? 'simulation' : 'real';
if ($asg['bus_status'] !== 'active') {
    json_response(['ok' => false, 'error' => 'Your bus is marked "' . $asg['bus_status'] . '" and cannot start a trip.'], 403);
}
if ($asg['route_status'] !== 'active') {
    json_response(['ok' => false, 'error' => 'Your route is inactive.'], 403);
}
try {
    q('INSERT INTO trips (bus_id, driver_id, route_id, gps_mode, status, started_at) VALUES (?, ?, ?, ?, "active", ?)',
        [$asg['bus_id'], $driver['id'], $asg['route_id'], $mode, date('Y-m-d H:i:s')]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {   // unique "one active trip per bus"
        json_response(['ok' => false, 'error' => 'This bus already has an active trip (another driver?). Contact the office.'], 409);
    }
    throw $e;
}
$tripId = (int) db()->lastInsertId();
audit_log('trip_started', 'trip', $tripId, ['bus' => $asg['bus_code'], 'mode' => $mode]);
json_response(['ok' => true, 'resumed' => false, 'trip' => ['id' => $tripId, 'mode' => $mode, 'started_at' => fmt_datetime(date('Y-m-d H:i:s'))]]);
