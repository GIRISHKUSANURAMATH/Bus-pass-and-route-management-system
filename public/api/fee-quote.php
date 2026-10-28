<?php
/** POST {route_id, boarding_stop_id, destination_stop_id, duration} -> fee quote. Students & admins only. */
declare(strict_types=1);
const API_REQUEST = true;
require __DIR__ . '/../../src/bootstrap.php';

require_role('student', 'admin');
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}
require_csrf();
if (!rate_limit('fee:' . session_id(), 60, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}
$in = json_input();
$q = fee_quote((int) ($in['route_id'] ?? 0), (int) ($in['boarding_stop_id'] ?? 0),
    (int) ($in['destination_stop_id'] ?? 0), (string) ($in['duration'] ?? ''));
json_response($q, $q['ok'] ? 200 : 422);
