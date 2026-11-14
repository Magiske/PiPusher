<?php
/**
 * Scheduled check for notifications: open cron.php?key=<key> every few minutes (cron job, uptime monitor).
 * Finds screens that went offline (also when none of them can reach the server) and sends queued emails.
 * The key is shown on the Notifications page.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$key = (string)setting('notify_key', '');
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "Wrong or missing key. Copy the link from the Notifications page.\n";
    exit;
}
@set_time_limit(90);
set_setting('notify_cron_at', (string)now());
$sent = notify_run(true);
$open = (int)(q1('SELECT COUNT(*) AS n FROM alerts WHERE active = 1')['n'] ?? 0);
echo "OK. Open problems: $open. Notifications emailed now: $sent.\n";
