<?php
/**
 * Serves media files to logged-in admins (previews):  media.php/<media id>/<relative path>
 * Path-style URLs keep relative links inside HTML/ZIP bundles working.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
start_session();
if (!is_logged_in()) {
    http_response_code(403);
    exit('Not logged in');
}
session_write_close();

$info = $_SERVER['PATH_INFO'] ?? '';
if (!preg_match('#^/(\d+)/(.+)$#', $info, $m)) {
    http_response_code(404);
    exit('Not found');
}
$path = safe_rel_path(rawurldecode($m[2]));
$abs = $path === null ? null : media_dir((int)$m[1]) . '/' . $path;
if (!$abs || !is_file($abs)) {
    http_response_code(404);
    exit('Not found');
}
send_file($abs);
