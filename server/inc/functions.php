<?php
declare(strict_types=1);

const PP_ORIENTATIONS = [
    'landscape' => 'Landscape (0°)',
    'portrait' => 'Portrait (90°)',
    'landscape-flipped' => 'Landscape flipped (180°)',
    'portrait-flipped' => 'Portrait flipped (270°)',
];
const PP_FITS = ['contain' => 'Contain', 'cover' => 'Cover (crop)', 'fill' => 'Stretch'];
const PP_TRANSITIONS = ['fade' => 'Fade', 'none' => 'None', 'slide' => 'Slide'];
const PP_COMMANDS = ['reload' => 'Reload player', 'sync' => 'Sync now', 'reboot' => 'Reboot device', 'screenshot' => 'Take screenshot'];

const PP_MIME = [
    'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
    'json' => 'application/json', 'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml', 'csv' => 'text/csv',
    'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime',
    'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    'pdf' => 'application/pdf',
];
const PP_IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp', 'svg'];
const PP_VIDEO_EXT = ['mp4', 'm4v', 'webm', 'ogv', 'mov'];
const PP_HTML_EXT = ['html', 'htm'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function url(array $params = []): string
{
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function now(): int
{
    return time();
}

function random_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function random_code(int $len = 6): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Public URL of the PiPusher server directory, e.g. https://example.com/pipusher/ */
function server_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

function poll_interval(): int
{
    return max(10, (int)setting('poll_interval', '60'));
}

/** Adds a remote command to a device's queue (delivered and cleared on its next sync). */
function queue_command(int $deviceId, string $cmd): void
{
    $row = q1('SELECT command FROM devices WHERE id = ?', [$deviceId]);
    $cmds = array_filter(explode(',', (string)($row['command'] ?? '')));
    if (!in_array($cmd, $cmds, true)) {
        $cmds[] = $cmd;
    }
    x('UPDATE devices SET command = ? WHERE id = ?', [implode(',', $cmds), $deviceId]);
}

function screenshot_path(int $deviceId): string
{
    return PP_SCREENS . '/' . $deviceId . '.jpg';
}

/** Screenshot interval in seconds (0 = only on demand). Stored in minutes. */
function screenshot_interval(): int
{
    return max(0, (int)setting('screenshot_interval', '10')) * 60;
}

function device_online(array $device): bool
{
    return $device['last_seen'] && (now() - (int)$device['last_seen']) <= poll_interval() * 3 + 15;
}

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($i ? number_format($v, 1) : (string)$bytes) . ' ' . $units[$i];
}

function time_ago(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $d = now() - $ts;
    if ($d < 60) return $d . 's ago';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return floor($d / 86400) . 'd ago';
}

function mime_for(string $path): string
{
    return PP_MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** Streams a file with HTTP Range support (needed for video seeking in browsers). */
function send_file(string $abs): never
{
    $size = filesize($abs);
    $start = 0;
    $end = $size - 1;
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . mime_for($abs));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-cache');
    header('X-Content-Type-Options: nosniff');
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') {
                $end = min((int)$m[2], $size - 1);
            }
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }
    $fh = fopen($abs, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, (int)min(1 << 16, $left));
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

// ---------------------------------------------------------------- session / auth

function start_session(): void
{
    global $ppConfig;
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name($ppConfig['session_name']);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

const PP_ROLES = [
    'admin' => 'Administrator – full control',
    'playlist' => 'Playlist editor – add, remove and edit layouts in playlists',
];

/** The logged-in user (re-read every request, so role changes and deletions apply at once). */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $id = (int)($_SESSION['user_id'] ?? 0);
        $user = $id ? q1('SELECT * FROM users WHERE id = ?', [$id]) : null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    x('UPDATE users SET last_login = ? WHERE id = ?', [now(), $user['id']]);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect(url(['p' => 'login']));
    }
}

/** Ids of the playlists the current user may edit, or null for "all". */
function allowed_playlist_ids(): ?array
{
    $user = current_user();
    if (!$user || $user['role'] === 'admin' || (int)$user['all_playlists'] === 1) {
        return null;
    }
    return array_map('intval', array_column(q('SELECT playlist_id FROM user_playlists WHERE user_id = ?', [(int)$user['id']]), 'playlist_id'));
}

function can_edit_playlist(int $playlistId): bool
{
    if (!is_logged_in()) {
        return false;
    }
    $ids = allowed_playlist_ids();
    return $ids === null || in_array($playlistId, $ids, true);
}

function deny(string $msg = 'You do not have access to this page.'): never
{
    http_response_code(403);
    page_header('No access');
    echo '<div class="card empty"><h2>No access</h2><p>' . h($msg) . '</p><p><a class="btn" href="' . url() . '">Back</a></p></div>';
    page_footer();
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token(16);
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid form token. Go back, reload the page and try again.');
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- media folders

/** All folders keyed by id, each with its full "path" (e.g. "Events / Christmas"). */
function media_folders(): array
{
    static $folders = null;
    if ($folders !== null) {
        return $folders;
    }
    $folders = [];
    foreach (q('SELECT * FROM media_folders ORDER BY name COLLATE NOCASE') as $f) {
        $folders[(int)$f['id']] = $f;
    }
    foreach ($folders as $id => $f) {
        $names = [];
        $seen = [];
        for ($cur = $f; $cur && !isset($seen[$cur['id']]); $cur = $folders[(int)$cur['parent_id']] ?? null) {
            $seen[$cur['id']] = true;
            array_unshift($names, $cur['name']);
        }
        $folders[$id]['path'] = implode(' / ', $names);
    }
    uasort($folders, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
    return $folders;
}

/** Ids of a folder and all folders below it. */
function folder_descendants(int $folderId): array
{
    $ids = [$folderId];
    for ($i = 0; $i < count($ids); $i++) {
        foreach (media_folders() as $f) {
            if ((int)$f['parent_id'] === $ids[$i]) {
                $ids[] = (int)$f['id'];
            }
        }
    }
    return $ids;
}

/** <option>s for picking a folder ("" = top level); $exclude hides a folder and its sub-folders. */
function folder_options(?int $selected, ?int $exclude = null): string
{
    $skip = $exclude ? folder_descendants($exclude) : [];
    $out = '<option value="">Media (top level)</option>';
    foreach (media_folders() as $id => $f) {
        if (!in_array($id, $skip, true)) {
            $out .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>📁 ' . h($f['path']) . '</option>';
        }
    }
    return $out;
}

function valid_folder_id($value): ?int
{
    $id = (int)$value;
    return $id && isset(media_folders()[$id]) ? $id : null;
}

// ---------------------------------------------------------------- media storage

function media_dir(int $mediaId): string
{
    return PP_MEDIA . '/' . $mediaId;
}

/** Normalises a relative path inside a media bundle; returns null for anything unsafe. */
function safe_rel_path(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..' || str_contains($seg, "\0") || preg_match('/^[a-zA-Z]:$/', $seg)) {
            return null;
        }
        $parts[] = $seg;
    }
    return $parts ? implode('/', $parts) : null;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** Re-scans a media directory and rewrites its media_files rows (paths, sizes, hashes). */
function reindex_media(int $mediaId): void
{
    $dir = media_dir($mediaId);
    $files = [];
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
                $files[$rel] = $f->getPathname();
            }
        }
    }
    ksort($files);
    $pdo = db();
    $pdo->beginTransaction();
    x('DELETE FROM media_files WHERE media_id = ?', [$mediaId]);
    $total = 0;
    foreach ($files as $rel => $abs) {
        $size = filesize($abs);
        $total += $size;
        x('INSERT INTO media_files (media_id, path, size, sha256) VALUES (?, ?, ?, ?)',
            [$mediaId, $rel, $size, hash_file('sha256', $abs)]);
    }
    x('UPDATE media SET size = ?, updated_at = ? WHERE id = ?', [$total, now(), $mediaId]);
    $pdo->commit();
}

function create_media(string $name, string $type, ?string $entry = null, ?string $url = null, bool $editable = false, ?int $folderId = null): int
{
    x('INSERT INTO media (name, type, entry, url, editable, folder_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$name, $type, $entry, $url, $editable ? 1 : 0, $folderId, now(), now()]);
    return last_id();
}

function create_html_media(string $name, string $html, ?int $folderId = null): int
{
    $id = create_media($name, 'html', 'index.html', null, true, $folderId);
    mkdir(media_dir($id), 0775, true);
    file_put_contents(media_dir($id) . '/index.html', $html);
    reindex_media($id);
    return $id;
}

function delete_media(int $id): void
{
    x('DELETE FROM media WHERE id = ?', [$id]);
    rrmdir(media_dir($id));
}

/**
 * Stores one uploaded file as a new media item. Returns [media id, error message].
 * ZIP files become HTML bundles (entry: index.html or the first .html file found).
 */
function import_upload(string $tmp, string $origName, ?int $folderId = null): array
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $base = pathinfo($origName, PATHINFO_FILENAME);
    if (in_array($ext, PP_IMAGE_EXT, true) || in_array($ext, PP_VIDEO_EXT, true) || in_array($ext, PP_HTML_EXT, true)) {
        $type = in_array($ext, PP_IMAGE_EXT, true) ? 'image' : (in_array($ext, PP_VIDEO_EXT, true) ? 'video' : 'html');
        $fileName = $type === 'html' ? 'index.html' : 'file.' . $ext;
        $id = create_media($base, $type, $fileName, null, $type === 'html', $folderId);
        mkdir(media_dir($id), 0775, true);
        if (!move_uploaded_file($tmp, media_dir($id) . '/' . $fileName) && !rename($tmp, media_dir($id) . '/' . $fileName)) {
            delete_media($id);
            return [null, "Could not store $origName"];
        }
        reindex_media($id);
        return [$id, null];
    }
    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) {
            return [null, 'ZIP upload requires the PHP zip extension'];
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            return [null, "$origName is not a valid ZIP file"];
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (str_ends_with($n, '/') || str_starts_with($n, '__MACOSX/')) {
                continue;
            }
            $safe = safe_rel_path($n);
            if ($safe === null) {
                $zip->close();
                return [null, "$origName contains an unsafe path: $n"];
            }
            $names[$i] = $safe;
        }
        // Strip a single common top-level folder ("site/index.html" -> "index.html").
        $tops = array_unique(array_map(fn($n) => explode('/', $n)[0], $names));
        if (count($tops) === 1 && count(array_filter($names, fn($n) => !str_contains($n, '/'))) === 0) {
            $prefix = reset($tops) . '/';
            $names = array_map(fn($n) => substr($n, strlen($prefix)), $names);
        }
        $entry = in_array('index.html', $names, true) ? 'index.html' : null;
        if ($entry === null) {
            foreach ($names as $n) {
                if (in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), PP_HTML_EXT, true)) {
                    $entry = $n;
                    break;
                }
            }
        }
        if ($entry === null) {
            $zip->close();
            return [null, "$origName does not contain an HTML page"];
        }
        $id = create_media($base, 'html', $entry, null, false, $folderId);
        $dir = media_dir($id);
        foreach ($names as $i => $n) {
            $dest = $dir . '/' . $n;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            file_put_contents($dest, $zip->getFromIndex($i));
        }
        $zip->close();
        reindex_media($id);
        return [$id, null];
    }
    return [null, "Unsupported file type: $origName"];
}
