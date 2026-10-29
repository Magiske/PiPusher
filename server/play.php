<?php
/**
 * Online web player: shows a screen's content in any browser straight from the server, without
 * installing the client and without logging in. It needs the network, because there is no offline cache.
 *
 *   play.php?screen=<token>                  the player for one web screen (link on the screen's page)
 *   play.php?key=<web player key>[&name=..]  registers this browser as a new web screen (Settings → Web player);
 *                                            a cookie remembers it, so opening the key link again reuses that screen
 *   POST play.php?screen=<token>&action=state  JSON {version, info} -> {state, version, config?, commands[], muted, poll_interval}
 *   play.php/<token>/<media id>/<path>        media files (path-style, so relative links in HTML bundles work)
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

const PP_WEB_COOKIE = 'pipusher_web';

function web_screen(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    return q1("SELECT * FROM devices WHERE kind = 'web' AND web_token = ?", [$token]);
}

function message_page(string $title, string $text, int $status = 200): never
{
    http_response_code($status);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PiPusher</title>'
        . '<link rel="stylesheet" href="assets/player/player.css?v=' . PP_VERSION . '"><div id="pp-root"><div class="pp-message">'
        . '<div class="pp-logo">PiPusher</div><h1>' . h($title) . '</h1><p>' . h($text) . '</p></div></div>';
    exit;
}

// Media files: play.php/<token>/<media id>/<path>
if (preg_match('#^/([a-f0-9]{32})/(\d+)/(.+)$#', $_SERVER['PATH_INFO'] ?? '', $m)) {
    $d = web_screen($m[1]);
    $path = safe_rel_path(rawurldecode($m[3]));
    $row = $d && $d['status'] === 'approved' && $path !== null
        ? q1('SELECT id FROM media_files WHERE media_id = ? AND path = ?', [(int)$m[2], $path]) : null;
    $abs = $row ? media_dir((int)$m[2]) . '/' . $path : null;
    if (!$abs || !is_file($abs)) {
        http_response_code(404);
        exit('Not found');
    }
    send_file($abs);
}

// Registration with the web player key.
if (isset($_GET['key'])) {
    $webKey = (string)setting('web_key', '');
    if ($webKey === '' || !hash_equals($webKey, (string)$_GET['key'])) {
        message_page('Invalid link', 'This web player link is not valid. Ask your PiPusher administrator for a new one.', 403);
    }
    $d = web_screen((string)($_COOKIE[PP_WEB_COOKIE] ?? '')) ?? create_web_screen((string)($_GET['name'] ?? ''));
    setcookie(PP_WEB_COOKIE, $d['web_token'], [
        'expires' => time() + 10 * 365 * 86400,
        'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => str_starts_with(server_base_url(), 'https://'),
    ]);
    redirect('play.php?screen=' . $d['web_token']);
}

$d = web_screen((string)($_GET['screen'] ?? ''));

if (($_GET['action'] ?? '') === 'state') {
    if (!$d) {
        json_out(['state' => 'removed', 'online' => true, 'poll_interval' => poll_interval()], 404);
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];
    $sent = is_array($body['info'] ?? null) ? $body['info'] : [];
    $info = [
        'client_version' => 'web',
        'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'screen' => mb_substr((string)($sent['screen'] ?? ''), 0, 20),
        'playing_layout' => mb_substr((string)($sent['playing_layout'] ?? ''), 0, 80),
    ];
    x('UPDATE devices SET last_seen = ?, ip = ?, info = ?, command = NULL WHERE id = ?',
        [now(), $_SERVER['REMOTE_ADDR'] ?? '', json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $d['id']]);
    $resp = [
        'state' => 'pending',
        'online' => true,
        'code' => $d['code'],
        'name' => $d['name'],
        'poll_interval' => poll_interval(),
        'commands' => array_values(array_filter(explode(',', (string)$d['command']))),
        'muted' => (bool)$d['muted'],
    ];
    if ($d['status'] === 'approved') {
        $payload = screen_payload($d);
        $resp['state'] = $payload ? 'ready' : 'nolayout';
        if ($payload) {
            $resp['version'] = payload_version($payload);
            if (($body['version'] ?? '') !== $resp['version']) {
                $resp['config'] = $payload;
            }
        }
    }
    json_out($resp);
}

if (!$d) {
    message_page('Screen not found', 'This web screen does not exist (any more). Ask your PiPusher administrator for a new link.', 404);
}
$state = ['state' => 'pending', 'online' => true, 'code' => $d['code'], 'name' => $d['name']];
if ($d['status'] === 'approved') {
    $payload = screen_payload($d);
    $state = $payload
        ? ['state' => 'ready', 'online' => true, 'version' => payload_version($payload), 'config' => $payload]
        : ['state' => 'nolayout', 'online' => true, 'name' => $d['name']];
}
$state['muted'] = (bool)$d['muted'];
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($d['name']) ?> · PiPusher</title>
<link rel="stylesheet" href="assets/player/player.css?v=<?= PP_VERSION ?>">
</head>
<body>
<div id="pp-root"></div>
<script src="assets/player/player.js?v=<?= PP_VERSION ?>"></script>
<script>
PiPusher.start({
  mode: 'web',
  state: <?= json_encode($state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  stateUrl: <?= json_encode('play.php?screen=' . $d['web_token'] . '&action=state') ?>,
  mediaBase: <?= json_encode($base . 'play.php/' . $d['web_token'] . '/') ?>,
  pollMs: <?= poll_interval() * 1000 ?>
});
</script>
</body>
</html>
