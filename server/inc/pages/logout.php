<?php
log_info('Logged out');
$_SESSION = [];
session_destroy();
redirect(url(['p' => 'login']));
