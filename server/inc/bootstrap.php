<?php
declare(strict_types=1);

require __DIR__ . '/version.php'; // PP_VERSION, written by build.ps1
define('PP_ROOT', dirname(__DIR__));

$ppConfig = [
    // Where the SQLite database and uploaded media are stored. Should not be web accessible
    // (the bundled data/.htaccess denies access on Apache; move it outside the web root on nginx).
    'data_dir' => PP_ROOT . '/data',
    'session_name' => 'pipusher_admin',
];
if (is_file(PP_ROOT . '/config.php')) {
    $ppConfig = array_merge($ppConfig, (array)require PP_ROOT . '/config.php');
}

define('PP_DATA', rtrim((string)$ppConfig['data_dir'], '/\\'));
define('PP_MEDIA', PP_DATA . '/media');
define('PP_SCREENS', PP_DATA . '/screenshots');

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/payload.php';
