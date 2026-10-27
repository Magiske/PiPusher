<?php
/** PiPusher admin panel – front controller. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ui.php';

$problems = [];
if (PHP_VERSION_ID < 80100) {
    $problems[] = 'PHP 8.1 or newer is required (this server runs ' . PHP_VERSION . '). Select a newer PHP version in your hosting control panel.';
}
if (!extension_loaded('pdo_sqlite')) {
    $problems[] = 'The PHP extension pdo_sqlite is not enabled.';
}
if (!is_dir(PP_DATA) ? !is_writable(dirname(PP_DATA)) : !is_writable(PP_DATA)) {
    $problems[] = 'The data folder ' . PP_DATA . ' is not writable by PHP. Give it write permission (e.g. 755/775) in your file manager or FTP client.';
}
if ($problems) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>PiPusher</title><body style="font-family:system-ui;max-width:640px;margin:10vh auto;padding:0 16px">'
        . '<h1>PiPusher cannot start</h1><ul><li>' . implode('</li><li>', array_map('h', $problems)) . '</li></ul></body>';
    exit;
}

start_session();

$pages = ['devices', 'device', 'group', 'playlists', 'playlist', 'layouts', 'layout', 'media', 'media_edit', 'settings',
    'users', 'account', 'login', 'logout', 'setup'];
// Pages a playlist editor may open; everything else is administrators only.
$editorPages = ['playlists', 'playlist', 'account', 'logout'];
$p = $_GET['p'] ?? '';
if (!in_array($p, $pages, true)) {
    $p = '';
}

if (!q1('SELECT id FROM users LIMIT 1')) {
    $p = 'setup';
} elseif ($p === 'setup') {
    redirect(url());
} elseif ($p !== 'login') {
    require_login();
    if ($p === '') {
        $p = is_admin() ? 'devices' : 'playlists';
    }
    // Settings can require two-factor authentication: until it is set up, only the account page is open.
    if (mfa_required(current_user()) && !mfa_enabled(current_user()) && !in_array($p, ['account', 'logout'], true)) {
        redirect(url(['p' => 'account']) . '#mfa');
    }
    if (!is_admin() && !in_array($p, $editorPages, true)) {
        deny('This page is for administrators. Your account can edit playlists.');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

require __DIR__ . '/inc/pages/' . $p . '.php';
