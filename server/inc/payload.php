<?php
declare(strict_types=1);

/**
 * Builds the configuration a player needs:
 *   layouts:  every layout that may be shown (orientation, background, zones with their content)
 *   sequence: the queue of layouts to rotate through, each with a duration and optional rules
 *             (days 1=Mon..7=Sun, time_from/time_to "HH:MM", date_from/date_to "YYYY-MM-DD", yearly)
 *   fallback: layout id shown when no sequence entry is allowed right now (or null)
 *   media:    every media item referenced by a zone, with the files a device must cache
 * A screen with a single layout gets a one-entry sequence.
 */
function content_payload(?int $layoutId, ?int $playlistId): ?array
{
    $sequence = [];
    $fallback = null;
    if ($playlistId) {
        $pl = q1('SELECT * FROM playlists WHERE id = ?', [$playlistId]);
        if (!$pl) {
            return null;
        }
        foreach (q('SELECT * FROM playlist_entries WHERE playlist_id = ? ORDER BY position, id', [$playlistId]) as $e) {
            $sequence[] = [
                'layout' => (int)$e['layout_id'],
                'duration' => (int)$e['duration'],
                'days' => array_map('intval', array_filter(explode(',', $e['days']))),
                'time_from' => $e['time_from'],
                'time_to' => $e['time_to'],
                'date_from' => $e['date_from'],
                'date_to' => $e['date_to'],
                'yearly' => (bool)$e['yearly'],
            ];
        }
        $fallback = $pl['fallback_layout_id'] ? (int)$pl['fallback_layout_id'] : null;
        if (!$sequence && !$fallback) {
            return null;
        }
    } elseif ($layoutId) {
        $sequence[] = ['layout' => $layoutId, 'duration' => 0];
    } else {
        return null;
    }

    $layoutIds = array_unique(array_merge(array_column($sequence, 'layout'), $fallback ? [$fallback] : []));
    $layouts = [];
    $mediaIds = [];
    foreach ($layoutIds as $lid) {
        $l = layout_data((int)$lid, $mediaIds);
        if ($l) {
            $layouts[] = $l;
        }
    }
    if (!$layouts) {
        return null;
    }
    return [
        'layouts' => $layouts,
        'sequence' => $sequence,
        'fallback' => $fallback,
        'media' => (object)media_data(array_keys($mediaIds)),
    ];
}

function layout_data(int $layoutId, array &$mediaIds): ?array
{
    $layout = q1('SELECT * FROM layouts WHERE id = ?', [$layoutId]);
    if (!$layout) {
        return null;
    }
    $zones = [];
    foreach (q('SELECT * FROM zones WHERE layout_id = ? ORDER BY z, id', [$layoutId]) as $z) {
        $items = [];
        foreach (q('SELECT media_id, duration, size FROM playlist_items WHERE zone_id = ? ORDER BY position, id', [$z['id']]) as $it) {
            $item = ['media' => (int)$it['media_id'], 'duration' => (int)$it['duration']];
            if ($it['size'] !== '') {
                $item['size'] = $it['size'];
            }
            $items[] = $item;
            $mediaIds[(int)$it['media_id']] = true;
        }
        $zones[] = [
            'id' => (int)$z['id'],
            'name' => $z['name'],
            'x' => (float)$z['x'], 'y' => (float)$z['y'], 'w' => (float)$z['w'], 'h' => (float)$z['h'],
            'z' => (int)$z['z'],
            'fit' => $z['fit'],
            'transition' => $z['transition'],
            'items' => $items,
        ];
    }
    return [
        'id' => (int)$layout['id'],
        'name' => $layout['name'],
        'orientation' => $layout['orientation'],
        'background' => $layout['background'],
        'zones' => $zones,
    ];
}

function media_data(array $ids): array
{
    $media = [];
    foreach ($ids as $mid) {
        $m = q1('SELECT * FROM media WHERE id = ?', [$mid]);
        if (!$m) {
            continue;
        }
        $files = [];
        foreach (q('SELECT path, size, sha256 FROM media_files WHERE media_id = ? ORDER BY path', [$mid]) as $f) {
            $files[] = ['path' => $f['path'], 'size' => (int)$f['size'], 'sha256' => $f['sha256']];
        }
        $media[(string)$mid] = [
            'id' => (int)$m['id'],
            'name' => $m['name'],
            'type' => $m['type'],
            'entry' => $m['entry'],
            'url' => $m['url'],
            'files' => $files,
        ];
    }
    return $media;
}

function payload_version(array $payload): string
{
    return sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function screen_groups(): array
{
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        foreach (q('SELECT * FROM screen_groups ORDER BY name COLLATE NOCASE') as $g) {
            $groups[(int)$g['id']] = $g;
        }
    }
    return $groups;
}

/**
 * What a screen shows: its group's content when it is in a group and follows it, otherwise its own.
 * Returns [layout id|null, playlist id|null, 'group'|'own'].
 */
function effective_content(array $d): array
{
    $g = $d['group_id'] ? (screen_groups()[(int)$d['group_id']] ?? null) : null;
    if ($g && (int)$d['use_group_content'] === 1) {
        return [$g['layout_id'] ? (int)$g['layout_id'] : null, $g['playlist_id'] ? (int)$g['playlist_id'] : null, 'group'];
    }
    return [$d['layout_id'] ? (int)$d['layout_id'] : null, $d['playlist_id'] ? (int)$d['playlist_id'] : null, 'own'];
}

/**
 * The screen's alignment: where the player sits on the display, as x, y, w, h in % of the display.
 * Null when it is the default 0, 0, 100, 100. Layout zones are placed inside this area.
 */
function screen_frame(array $d): ?array
{
    $f = ['x' => (float)$d['frame_x'], 'y' => (float)$d['frame_y'], 'w' => (float)$d['frame_w'], 'h' => (float)$d['frame_h']];
    return $f === ['x' => 0.0, 'y' => 0.0, 'w' => 100.0, 'h' => 100.0] ? null : $f;
}

/** The content payload for a screen: its effective content plus its alignment ("screen"), or null when it shows nothing. */
function screen_payload(array $d): ?array
{
    [$layout, $playlist] = effective_content($d);
    $payload = content_payload($layout, $playlist);
    if ($payload !== null && ($frame = screen_frame($d))) {
        $payload['screen'] = $frame;
    }
    return $payload;
}

/** What a newly registered screen starts with (Settings): [layout id|null, playlist id|null, group id|null]. */
function new_screen_defaults(): array
{
    [$layout, $playlist] = parse_content_ref(setting('default_layout'));
    if ($layout && !q1('SELECT id FROM layouts WHERE id = ?', [$layout])) {
        $layout = null;
    }
    if ($playlist && !q1('SELECT id FROM playlists WHERE id = ?', [$playlist])) {
        $playlist = null;
    }
    $group = (int)setting('default_group', '0');
    return [$layout, $playlist, $group && isset(screen_groups()[$group]) ? $group : null];
}

/** Creates an approved web screen (played in a browser via play.php) and returns its row. */
function create_web_screen(string $name): array
{
    [$layout, $playlist, $group] = new_screen_defaults();
    $code = random_code();
    x("INSERT INTO devices (uuid, secret_hash, code, name, status, kind, web_token, layout_id, playlist_id, group_id, use_group_content, created_at)
       VALUES (?, ?, ?, ?, 'approved', 'web', ?, ?, ?, ?, ?, ?)",
        ['web-' . random_token(16), hash('sha256', random_token()), $code, mb_substr(trim($name) ?: 'Web screen ' . $code, 0, 80),
         random_token(16), $layout, $playlist, $group, $group ? 1 : 0, now()]);
    return q1('SELECT * FROM devices WHERE id = ?', [last_id()]);
}

function is_web_screen(array $d): bool
{
    return ($d['kind'] ?? 'pi') === 'web';
}

/** The secret link a web screen plays from (anyone with the link sees that screen's content). */
function web_screen_url(array $d): string
{
    return server_base_url() . 'play.php?screen=' . $d['web_token'];
}

/** Screens currently showing a given playlist, or a given layout directly (not via a playlist). */
function devices_showing(?int $layoutId, ?int $playlistId): array
{
    return array_values(array_filter(q('SELECT * FROM devices ORDER BY name COLLATE NOCASE'), function ($d) use ($layoutId, $playlistId) {
        [$l, $p] = effective_content($d);
        return $playlistId ? $p === $playlistId : ($layoutId && !$p && $l === $layoutId);
    }));
}

/** Short HTML description of content: "▶ Playlist" or a layout name, linked. */
function content_label(?int $layoutId, ?int $playlistId): string
{
    if ($playlistId && ($p = q1('SELECT name FROM playlists WHERE id = ?', [$playlistId]))) {
        return '▶ <a href="' . url(['p' => 'playlist', 'id' => $playlistId]) . '">' . h($p['name']) . '</a>';
    }
    if ($layoutId && ($l = q1('SELECT name FROM layouts WHERE id = ?', [$layoutId]))) {
        return '<a href="' . url(['p' => 'layout', 'id' => $layoutId]) . '">' . h($l['name']) . '</a>';
    }
    return '<span class="muted">nothing</span>';
}

/** "layout:3" / "playlist:2" (as used in content selects) -> [layout id|null, playlist id|null] */
function parse_content_ref(?string $ref): array
{
    if ($ref !== null && preg_match('/^(layout|playlist):(\d+)$/', $ref, $m)) {
        return $m[1] === 'layout' ? [(int)$m[2], null] : [null, (int)$m[2]];
    }
    if ($ref !== null && ctype_digit($ref) && (int)$ref > 0) {
        return [(int)$ref, null]; // older settings stored a bare layout id
    }
    return [null, null];
}

/** <option>s for choosing what a screen shows: a playlist or a single layout. */
function content_options(?int $layoutId, ?int $playlistId, string $noneLabel = '— nothing —'): string
{
    $out = '<option value="">' . h($noneLabel) . '</option>';
    $pls = q('SELECT id, name FROM playlists ORDER BY name COLLATE NOCASE');
    if ($pls) {
        $out .= '<optgroup label="Playlists">';
        foreach ($pls as $p) {
            $out .= '<option value="playlist:' . $p['id'] . '"' . ($playlistId === (int)$p['id'] ? ' selected' : '') . '>▶ ' . h($p['name']) . '</option>';
        }
        $out .= '</optgroup>';
    }
    foreach (layouts_by_folder() as $fid => $list) {
        $out .= '<optgroup label="' . h($fid ? 'Single layout · 📁 ' . layout_folders()[$fid]['name'] : 'Single layout') . '">';
        foreach ($list as $l) {
            $out .= '<option value="layout:' . $l['id'] . '"' . ($layoutId === (int)$l['id'] && !$playlistId ? ' selected' : '') . '>' . h($l['name']) . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out;
}

// ---------------------------------------------------------------- layout folders

/** All layout folders keyed by id, sorted by name. */
function layout_folders(): array
{
    static $folders = null;
    if ($folders === null) {
        $folders = [];
        foreach (q('SELECT * FROM layout_folders ORDER BY name COLLATE NOCASE') as $f) {
            $folders[(int)$f['id']] = $f;
        }
    }
    return $folders;
}

function valid_layout_folder_id($value): ?int
{
    $id = (int)$value;
    return $id && isset(layout_folders()[$id]) ? $id : null;
}

/** Layouts grouped by folder: [0 => top-level layouts, folder id => layouts, ...] in folder name order, empty groups left out. */
function layouts_by_folder(): array
{
    $groups = [0 => []] + array_fill_keys(array_keys(layout_folders()), []);
    foreach (q('SELECT id, name, orientation, folder_id FROM layouts ORDER BY name COLLATE NOCASE') as $l) {
        $groups[isset($groups[(int)$l['folder_id']]) ? (int)$l['folder_id'] : 0][] = $l;
    }
    return array_filter($groups);
}

/** <option>s for picking a layout, grouped by folder; $none adds an empty first option with that label. */
function layout_options($selected, ?string $none = null): string
{
    $out = $none !== null ? '<option value="">' . h($none) . '</option>' : '';
    foreach (layouts_by_folder() as $fid => $list) {
        $out .= '<optgroup label="' . h($fid ? '📁 ' . layout_folders()[$fid]['name'] : 'Layouts') . '">';
        foreach ($list as $l) {
            $label = $l['name'] . (str_starts_with($l['orientation'], 'portrait') ? ' (portrait)' : '');
            $out .= '<option value="' . $l['id'] . '"' . ((string)$l['id'] === (string)$selected ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        $out .= '</optgroup>';
    }
    return $out;
}

/** <option>s for picking a layout folder ("" = top level). */
function layout_folder_options(?int $selected): string
{
    return select_options(['' => 'Layouts (top level)'] + array_map(fn($f) => '📁 ' . $f['name'], layout_folders()), $selected ?? '');
}

/** Human summary of a playlist entry's rules, e.g. "Mon–Fri · 08:00–17:00 · 01 Dec – 31 Dec yearly". */
function describe_when(array $e): string
{
    $parts = [];
    $days = array_map('intval', array_filter(explode(',', (string)$e['days'])));
    if ($days && count($days) < 7) {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        sort($days);
        if ($days === [1, 2, 3, 4, 5]) {
            $parts[] = 'Mon–Fri';
        } elseif ($days === [6, 7]) {
            $parts[] = 'Weekends';
        } else {
            $parts[] = implode(', ', array_map(fn($d) => $names[$d], $days));
        }
    }
    if ($e['time_from'] !== '' || $e['time_to'] !== '') {
        $parts[] = ($e['time_from'] ?: '00:00') . '–' . ($e['time_to'] ?: '24:00');
    }
    if ($e['date_from'] !== '' || $e['date_to'] !== '') {
        $fmt = fn($d) => $d === '' ? '…' : date($e['yearly'] ? 'j M' : 'j M Y', strtotime($d));
        $parts[] = $fmt($e['date_from']) . ' – ' . $fmt($e['date_to']) . ($e['yearly'] ? ' (every year)' : '');
    }
    return $parts ? implode(' · ', $parts) : 'Always';
}

/** Whether a playlist entry's rules allow it at the given time (server clock; the screens use their own clock). */
function entry_active(array $e, ?int $ts = null): bool
{
    $ts ??= time();
    $days = array_filter(explode(',', (string)$e['days']));
    if ($days && !in_array(date('N', $ts), $days, true)) {
        return false;
    }
    if ($e['time_from'] !== '' || $e['time_to'] !== '') {
        $now = date('H:i', $ts);
        $from = $e['time_from'] ?: '00:00';
        $to = $e['time_to'] ?: '24:00';
        if ($from <= $to ? !($now >= $from && $now < $to) : !($now >= $from || $now < $to)) {
            return false;
        }
    }
    if ($e['date_from'] !== '' || $e['date_to'] !== '') {
        if ($e['yearly']) {
            $md = date('m-d', $ts);
            $a = $e['date_from'] !== '' ? substr($e['date_from'], 5) : '01-01';
            $b = $e['date_to'] !== '' ? substr($e['date_to'], 5) : '12-31';
            if ($a <= $b ? !($md >= $a && $md <= $b) : !($md >= $a || $md <= $b)) {
                return false;
            }
        } else {
            $d = date('Y-m-d', $ts);
            if (($e['date_from'] !== '' && $d < $e['date_from']) || ($e['date_to'] !== '' && $d > $e['date_to'])) {
                return false;
            }
        }
    }
    return true;
}

/** Layout presets offered when creating a layout or in the zone editor. Geometry in %. */
function layout_templates(): array
{
    return [
        'full' => ['label' => 'Full screen', 'zones' => [['Main', 0, 0, 100, 100]]],
        'sidebar' => ['label' => 'Main + sidebar', 'zones' => [['Main', 0, 0, 75, 100], ['Sidebar', 75, 0, 25, 100]]],
        'sidebar_ticker' => ['label' => 'Main + sidebar + footer', 'zones' => [['Main', 0, 0, 75, 88], ['Sidebar', 75, 0, 25, 88], ['Footer', 0, 88, 100, 12]]],
        'header_main' => ['label' => 'Header + main', 'zones' => [['Header', 0, 0, 100, 15], ['Main', 0, 15, 100, 85]]],
        'split' => ['label' => 'Two columns', 'zones' => [['Left', 0, 0, 50, 100], ['Right', 50, 0, 50, 100]]],
        'stack' => ['label' => 'Two rows', 'zones' => [['Top', 0, 0, 100, 50], ['Bottom', 0, 50, 100, 50]]],
        'grid' => ['label' => '2 × 2 grid', 'zones' => [['Top left', 0, 0, 50, 50], ['Top right', 50, 0, 50, 50], ['Bottom left', 0, 50, 50, 50], ['Bottom right', 50, 50, 50, 50]]],
    ];
}

function create_layout(string $name, string $orientation, string $template = 'full'): int
{
    $tpl = layout_templates()[$template] ?? layout_templates()['full'];
    x('INSERT INTO layouts (name, orientation, background, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$name, isset(PP_ORIENTATIONS[$orientation]) ? $orientation : 'landscape', '#000000', now(), now()]);
    $id = last_id();
    foreach ($tpl['zones'] as $i => [$zn, $zx, $zy, $zw, $zh]) {
        x('INSERT INTO zones (layout_id, name, x, y, w, h, z) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $zn, $zx, $zy, $zw, $zh, $i]);
    }
    return $id;
}
