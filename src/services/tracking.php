<?php
/**
 * Bus assignment + live tracking helpers.
 */
declare(strict_types=1);

/** Driver's active assignment (bus + route), resolved from the DB, never from the client. */
function driver_assignment(int $driverId): ?array
{
    return q_one('
        SELECT a.id AS assignment_id, b.id AS bus_id, b.bus_code, b.bus_number, b.registration_number, b.bus_name,
               b.capacity, b.status AS bus_status, r.id AS route_id, r.name AS route_name, r.route_code,
               r.origin, r.destination, r.status AS route_status
        FROM driver_bus_assignments a
        JOIN buses b ON b.id = a.bus_id
        LEFT JOIN routes r ON r.id = b.route_id
        WHERE a.driver_id = ? AND a.is_active = 1
        LIMIT 1', [$driverId]);
}

function active_trip_for_driver(int $driverId): ?array
{
    return q_one("SELECT * FROM trips WHERE driver_id = ? AND status = 'active' LIMIT 1", [$driverId]);
}

function route_stops(int $routeId, bool $activeOnly = true): array
{
    return q_all('SELECT id, stop_code, name, latitude, longitude, stop_order, direction, distance_km, est_arrival_time, status
                  FROM bus_stops WHERE route_id = ?' . ($activeOnly ? " AND status = 'active'" : '') . '
                  ORDER BY stop_order, id', [$routeId]);
}

/** Stops in a JS-friendly shape for Leaflet. */
function stops_for_map(int $routeId): array
{
    return array_map(fn ($s) => [
        'id' => (int) $s['id'],
        'name' => $s['name'],
        'lat' => (float) $s['latitude'],
        'lng' => (float) $s['longitude'],
        'order' => (int) $s['stop_order'],
        'eta' => $s['est_arrival_time'] ? substr($s['est_arrival_time'], 0, 5) : null,
    ], route_stops($routeId));
}

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/**
 * Live status of a bus, computed on the SERVER from the age of the last fix:
 *   live      -> updated within gps_live_seconds
 *   delayed   -> within gps_offline_seconds
 *   offline   -> older, or an active trip with no fix yet
 *   no_trip   -> no active trip (last known position may still be returned)
 */
function bus_live_payload(int $busId): array
{
    $bus = q_one('SELECT b.id, b.bus_code, b.bus_number, b.registration_number, b.status, b.route_id,
                         r.name AS route_name, r.origin, r.destination
                  FROM buses b LEFT JOIN routes r ON r.id = b.route_id WHERE b.id = ?', [$busId]);
    if (!$bus) {
        return ['ok' => false, 'error' => 'Bus not found.'];
    }
    $driver = q_one('SELECT u.full_name FROM driver_bus_assignments a JOIN drivers d ON d.id = a.driver_id
                     JOIN users u ON u.id = d.user_id WHERE a.bus_id = ? AND a.is_active = 1', [$busId]);
    $trip = q_one("SELECT id, gps_mode, started_at FROM trips WHERE bus_id = ? AND status = 'active' LIMIT 1", [$busId]);

    $loc = null;
    if ($trip) {
        $loc = q_one('SELECT latitude, longitude, accuracy_m, speed_mps, heading, is_simulated, received_at
                      FROM bus_locations WHERE trip_id = ? ORDER BY id DESC LIMIT 1', [$trip['id']]);
    }
    $last = $loc ?: q_one('SELECT latitude, longitude, accuracy_m, speed_mps, heading, is_simulated, received_at
                           FROM bus_locations WHERE bus_id = ? ORDER BY id DESC LIMIT 1', [$busId]);

    $age = $last ? max(0, time() - strtotime($last['received_at'])) : null;
    if (!$trip) {
        $status = 'no_trip';
    } elseif (!$loc) {
        $status = 'waiting';
    } elseif ($age <= int_setting('gps_live_seconds')) {
        $status = 'live';
    } elseif ($age <= int_setting('gps_offline_seconds')) {
        $status = 'delayed';
    } else {
        $status = 'offline';
    }

    return [
        'ok' => true,
        'bus' => [
            'id' => (int) $bus['id'],
            'code' => $bus['bus_code'],
            'number' => $bus['bus_number'],
            'registration' => $bus['registration_number'],
            'route' => $bus['route_name'],
            'route_label' => $bus['origin'] ? $bus['origin'] . ' → ' . $bus['destination'] : null,
            'driver' => $driver['full_name'] ?? null,
        ],
        'trip' => $trip ? ['mode' => $trip['gps_mode'], 'started_at' => fmt_datetime($trip['started_at'])] : null,
        'status' => $status,
        'simulated' => $trip ? $trip['gps_mode'] === 'simulation' : (bool) ($last['is_simulated'] ?? false),
        'location' => $last ? [
            'lat' => (float) $last['latitude'],
            'lng' => (float) $last['longitude'],
            'accuracy' => $last['accuracy_m'] !== null ? (float) $last['accuracy_m'] : null,
            'speed_kmph' => $last['speed_mps'] !== null ? round((float) $last['speed_mps'] * 3.6, 1) : null,
            'heading' => $last['heading'] !== null ? (float) $last['heading'] : null,
            'age_seconds' => $age,
            'updated_label' => time_ago($last['received_at']),
            'updated_at' => fmt_datetime($last['received_at']),
        ] : null,
        'server_time' => date('c'),
    ];
}

/** Human label for a server-computed tracking status. */
function gps_status_text(string $status): string
{
    return [
        'live' => 'LIVE', 'delayed' => 'DELAYED', 'offline' => 'OFFLINE',
        'waiting' => 'WAITING FOR GPS', 'no_trip' => 'NOT SHARING',
    ][$status] ?? strtoupper($status);
}

/**
 * (Re)assign a driver to a bus, or unassign when $busId is null.
 * Ends the previous assignment of BOTH the driver and the bus (history is kept).
 * @return string|null error
 */
function assign_driver_bus(int $driverId, ?int $busId, int $adminUserId): ?string
{
    if (active_trip_for_driver($driverId)) {
        return 'This driver has an active trip. Stop the trip first.';
    }
    if ($busId !== null && q_val("SELECT 1 FROM trips WHERE bus_id = ? AND status = 'active'", [$busId])) {
        return 'That bus is currently on an active trip.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');
    q('UPDATE driver_bus_assignments SET is_active = 0, unassigned_at = ? WHERE is_active = 1 AND driver_id = ?', [$now, $driverId]);
    if ($busId !== null) {
        q('UPDATE driver_bus_assignments SET is_active = 0, unassigned_at = ? WHERE is_active = 1 AND bus_id = ?', [$now, $busId]);
        q('INSERT INTO driver_bus_assignments (driver_id, bus_id, is_active, assigned_by, assigned_at) VALUES (?, ?, 1, ?, ?)',
            [$driverId, $busId, $adminUserId, $now]);
    }
    $pdo->commit();
    audit_log($busId ? 'driver_assigned' : 'driver_unassigned', 'driver', $driverId, ['bus_id' => $busId]);
    return null;
}
