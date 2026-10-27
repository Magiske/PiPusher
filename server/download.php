<?php
/**
 * Client packages bundled with the server:  download.php?file=pi|windows|windows7[&key=<registration key>]
 * For logged-in administrators, or with the registration key so a screen can download it directly
 * (e.g. with curl on the Pi). Without a registration key only administrators can download.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
start_session();
$regKey = (string)setting('registration_key', '');
$keyOk = $regKey !== '' && hash_equals($regKey, (string)($_GET['key'] ?? ''));
if (!$keyOk && !is_admin()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Log in as an administrator, or add the registration key: download.php?file=...&key=...\n");
}
session_write_close();

$d = client_downloads()[(string)($_GET['file'] ?? '')] ?? null;
if (!$d || empty($d['path'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This client package is not included with this server.\n");
}
header('Content-Disposition: attachment; filename="' . $d['name'] . '"');
send_file($d['path']);
