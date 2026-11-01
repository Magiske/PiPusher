<?php
/**
 * Downloads a backup zip of the database, media and layout previews (administrators, POST from Settings).
 * Restoring is done on the Settings page.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/backup.php';
start_session();
if (!is_admin()) {
    http_response_code(403);
    exit('Administrators only');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(url(['p' => 'settings']));
}
csrf_check();
session_write_close();

try {
    $zip = backup_create();
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Backup failed: ' . $e->getMessage() . "\n");
}
register_shutdown_function(fn() => @unlink($zip));
ignore_user_abort(true); // so the temporary zip is still deleted when the download is cancelled
header('Content-Disposition: attachment; filename="pipusher-backup-' . date('Y-m-d-His') . '.zip"');
send_file($zip);
