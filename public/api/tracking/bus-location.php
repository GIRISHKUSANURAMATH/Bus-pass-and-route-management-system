<?php
/**
 * GET live location of a bus.
 *  - student: ALWAYS the bus on their own active pass (any bus_id parameter is ignored)
 *  - admin:   ?bus_id=<id>
 */
declare(strict_types=1);
const API_REQUEST = true;
require __DIR__ . '/../../../src/bootstrap.php';

$user = require_role('student', 'admin');
if (!rate_limit('track:' . session_id(), 40, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Slow down.'], 429);
}

if ($user['role'] === 'student') {
    $pass = student_current_pass((int) current_student()['id']);
    if (!$pass) {
        json_response(['ok' => false, 'error' => 'No active bus pass.'], 403);
    }
    $busId = $pass['bus_id'] ? (int) $pass['bus_id'] : null;
    if ($busId === null) {
        // Pass is valid on any bus of the route: prefer one that is currently on a trip.
        $busId = (int) (q_val("SELECT b.id FROM buses b LEFT JOIN trips t ON t.bus_id = b.id AND t.status = 'active'
                               WHERE b.route_id = ? ORDER BY t.id IS NULL, b.id LIMIT 1", [$pass['route_id']]) ?? 0);
    }
} else {
    $busId = input_int('bus_id') ?? 0;
}
if (!$busId) {
    json_response(['ok' => false, 'error' => 'No bus is assigned to this route yet.'], 404);
}
$data = bus_live_payload($busId);
json_response($data, $data['ok'] ? 200 : 404);
