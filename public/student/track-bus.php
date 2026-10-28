<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require_role('student');
$student = current_student();
$pass = student_current_pass((int) $student['id']);

page_header('Bus Live Tracking', 'track-bus.php', ['vendor/leaflet/leaflet.css']);
if (!$pass) {
    echo '<div class="alert alert-info">Live tracking is available to students with an <strong>active bus pass</strong>. <a href="'
        . e(url('student/pass.php')) . '">Check your pass</a>.</div>';
    page_footer();
    exit;
}
$trackConfig = [
    'endpoint' => 'api/tracking/bus-location.php',      // bus is resolved from the student's pass on the server
    'poll'     => int_setting('tracking_poll_seconds'),
    'stops'    => stops_for_map((int) $pass['route_id']),
    'boardId'  => (int) $pass['boarding_stop_id'],
    'destId'   => (int) $pass['destination_stop_id'],
];
require __DIR__ . '/../../src/views/tracking_panel.php';
page_footer(['vendor/leaflet/leaflet.js', 'js/maps.js', 'js/track-bus.js']);
