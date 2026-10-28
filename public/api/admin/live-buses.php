<?php
/** GET - live status of every bus (admin only). */
declare(strict_types=1);
const API_REQUEST = true;
require __DIR__ . '/../../../src/bootstrap.php';
require_role('admin');
if (!rate_limit('livebuses:' . session_id(), 30, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}
$out = [];
foreach (q_all("SELECT id FROM buses WHERE status <> 'inactive' ORDER BY bus_code") as $b) {
    $p = bus_live_payload((int) $b['id']);
    if ($p['ok']) {
        unset($p['ok'], $p['server_time']);
        $out[] = $p;
    }
}
json_response(['ok' => true, 'buses' => $out, 'server_time' => date('c')]);
