<?php
/** Renders a layout in the browser using the same player code that runs on the devices. */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
start_session();
require_login();
session_write_close();

$layoutId = (int)($_GET['layout'] ?? 0) ?: null;
$playlistId = (int)($_GET['playlist'] ?? 0) ?: null;
// Playlist editors may preview layouts and the playlists they can edit, not screens or groups.
if (((isset($_GET['device']) || isset($_GET['group'])) && !is_admin()) || ($playlistId && !can_edit_playlist($playlistId))) {
    http_response_code(403);
    exit('No access');
}
if (isset($_GET['device'])) {
    $d = q1('SELECT * FROM devices WHERE id = ?', [(int)$_GET['device']]);
    [$layoutId, $playlistId] = $d ? effective_content($d) : [null, null];
}
if (isset($_GET['group'])) {
    $g = screen_groups()[(int)$_GET['group']] ?? null;
    $layoutId = $g && $g['layout_id'] ? (int)$g['layout_id'] : null;
    $playlistId = $g && $g['playlist_id'] ? (int)$g['playlist_id'] : null;
}
$payload = content_payload($layoutId, $playlistId);
if (isset($_GET['version'])) {
    // Polled by the open preview so it reloads when the layout or its content changes.
    json_out(['version' => $payload ? payload_version($payload) : null]);
}
$state = $payload
    ? ['state' => 'ready', 'online' => true, 'version' => payload_version($payload), 'config' => $payload]
    : ['state' => 'nolayout', 'online' => true, 'message' => 'Nothing to show'];
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Preview · PiPusher</title>
<link rel="stylesheet" href="assets/player/player.css?v=<?= PP_VERSION ?>">
<style>body.pp-preview #pp-root{cursor:default}</style>
</head>
<body class="pp-preview">
<div id="pp-root"></div>
<script src="assets/player/player.js?v=<?= PP_VERSION ?>"></script>
<script>
PiPusher.start({
  mode: 'static',
  state: <?= json_encode($state, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  mediaBase: <?= json_encode($base . 'media.php/') ?>,
  watchUrl: <?= json_encode('preview.php?' . http_build_query(array_merge($_GET, ['version' => 1]))) ?>
});
</script>
</body>
</html>
