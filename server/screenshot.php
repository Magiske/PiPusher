<?php
/**
 * Latest screenshot of a screen (admin session):  screenshot.php?id=<device id>
 * With &meta=1 returns {"at": <unix time or null>} so the admin page can poll for a new one.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
start_session();
if (!is_admin()) {
    http_response_code(403);
    exit('Administrators only');
}
session_write_close();

$device = q1('SELECT id, screenshot_at FROM devices WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
if (isset($_GET['meta'])) {
    json_out(['at' => $device && $device['screenshot_at'] ? (int)$device['screenshot_at'] : null]);
}
$path = $device ? screenshot_path((int)$device['id']) : null;
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('No screenshot');
}
send_file($path);
