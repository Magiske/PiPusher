<?php
/** Live screen status for the admin pages (polled by assets/admin.js). Admin session required. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
start_session();
if (!is_admin()) {
    json_out(['error' => 'administrators only'], 403);
}
session_write_close();

$out = [];
foreach (q('SELECT id, status, last_seen, screenshot_at FROM devices') as $d) {
    $out[$d['id']] = [
        'online' => device_online($d),
        'status' => $d['status'],
        'last_seen' => time_ago($d['last_seen'] ? (int)$d['last_seen'] : null),
        'screenshot_at' => $d['screenshot_at'] ? (int)$d['screenshot_at'] : null,
    ];
}
json_out(['devices' => (object)$out, 'poll_interval' => poll_interval()]);
