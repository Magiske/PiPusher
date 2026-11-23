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
$size = null; // [width, height] in pixels to show the preview at, or null = the browser window
if (isset($_GET['device'])) {
    $d = q1('SELECT * FROM devices WHERE id = ?', [(int)$_GET['device']]);
    [$layoutId, $playlistId] = $d ? effective_content($d) : [null, null];
    $size = $d ? preview_size($d) : null;
}
// ?size=WxH (or 1080p …) shows any preview at that size; ?size=window fills the window.
if (isset($_GET['size'])) {
    $res = parse_resolution((string)$_GET['size']);
    $size = $res === null ? null : array_map('intval', explode('x', $res));
}

// At a fixed size, the player runs in a frame of exactly that many pixels (so vw/vh, text and zones match the
// screen), scaled down or up to fit the window.
if ($size && !isset($_GET['inner']) && !isset($_GET['version'])) {
    [$pw, $ph] = $size;
    $inner = 'preview.php?' . http_build_query(array_merge($_GET, ['inner' => 1]));
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Preview <?= $pw ?> × <?= $ph ?> · PiPusher</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<style>
html, body { margin: 0; height: 100%; overflow: hidden; background: #111; }
#pp-frame { position: absolute; left: 50%; top: 50%; border: 0; background: #000; transform-origin: 0 0; box-shadow: 0 0 0 1px #333; }
#pp-size { position: fixed; right: 10px; bottom: 8px; font: 12px system-ui, sans-serif; color: #bbb; background: #000a;
  padding: 3px 8px; border-radius: 4px; transition: opacity .6s; pointer-events: none; }
</style>
</head>
<body>
<iframe id="pp-frame" src="<?= h($inner) ?>" width="<?= $pw ?>" height="<?= $ph ?>" allow="autoplay; fullscreen"></iframe>
<div id="pp-size"></div>
<script>
(function () {
  var f = document.getElementById('pp-frame'), label = document.getElementById('pp-size'), W = <?= $pw ?>, H = <?= $ph ?>, hide;
  function fit() {
    var s = Math.min(innerWidth / W, innerHeight / H);
    f.style.transform = 'scale(' + s + ') translate(-50%, -50%)';
    label.textContent = W + ' × ' + H + ' px, shown at ' + Math.round(s * 100) + ' %';
    label.style.opacity = 1;
    clearTimeout(hide);
    hide = setTimeout(function () { label.style.opacity = 0; }, 4000);
  }
  addEventListener('resize', fit);
  fit();
})();
</script>
</body>
</html>
<?php
    exit;
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
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
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
