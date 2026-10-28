<?php
/**
 * Common guard for driver GPS/trip endpoints.
 * Resolves driver -> active assignment -> bus -> route from the DATABASE.
 * Any bus_id / driver_id sent by the browser is ignored.
 */
declare(strict_types=1);

function driver_api_context(bool $requireActiveTrip = false): array
{
    require_role('driver');
    if (!is_post()) {
        json_response(['ok' => false, 'error' => 'POST required.'], 405);
    }
    require_csrf();
    $driver = current_driver();
    if (!$driver) {
        json_response(['ok' => false, 'error' => 'Driver profile missing. Contact the administrator.'], 403);
    }
    $asg = driver_assignment((int) $driver['id']);
    if (!$asg) {
        json_response(['ok' => false, 'error' => 'No bus is assigned to you.'], 403);
    }
    if (!$asg['route_id']) {
        json_response(['ok' => false, 'error' => 'Your bus has no route assigned.'], 403);
    }
    $trip = active_trip_for_driver((int) $driver['id']);
    if ($requireActiveTrip && (!$trip || (int) $trip['bus_id'] !== (int) $asg['bus_id'])) {
        json_response(['ok' => false, 'error' => 'No active trip. Press START TRIP first.', 'code' => 'no_trip'], 409);
    }
    return ['driver' => $driver, 'assignment' => $asg, 'trip' => $trip];
}
