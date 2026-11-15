<?php
declare(strict_types=1);

/**
 * Creates the samples: a café that shows off PiPusher's features with as little HTML as possible.
 *
 * - Media (folder Samples, sub-folders Photos and Texts): photos as image items, text items (headings, text and
 *   price tags made in the form), a ticker and clock & weather items. No HTML: everything is made in forms.
 * - Tags: Food, Music, Promotion, Christmas, Internal. Show dates: an opening offer that expires by itself after two
 *   weeks, and a summer offer that starts next summer.
 * - Layouts (folder Samples): Café – day (tag zones, rounded corners, timer bar, slide transition, ticker),
 *   Café – evening (text over a photo, Hold), Café – Christmas, and Staff room (portrait, Internal tag).
 * - Playlist Café (folder Samples): day 07–18, evening Thu–Sat 18–23, Christmas every 3rd turn 1–26 December
 *   every year, and the day layout as fallback. New screens get this playlist.
 *
 * Returns the content reference for new screens: "playlist:<id>".
 */
function install_samples(): string
{
    // ---------- Folders ----------
    x('INSERT INTO media_folders (name, created_at) VALUES (?, ?)', ['Samples', now()]);
    $folder = last_id();
    x('INSERT INTO media_folders (name, parent_id, created_at) VALUES (?, ?, ?)', ['Photos', $folder, now()]);
    $photos = last_id();
    x('INSERT INTO media_folders (name, parent_id, created_at) VALUES (?, ?, ?)', ['Texts', $folder, now()]);
    $texts = last_id();
    x('INSERT INTO layout_folders (name, created_at) VALUES (?, ?)', ['Samples', now()]);
    $layoutFolder = last_id();
    x('INSERT INTO playlist_folders (name, created_at) VALUES (?, ?)', ['Samples', now()]);
    $playlistFolder = last_id();

    // ---------- Media ----------
    $photo = function (string $name, string $file, array $tags) use ($photos): int {
        $id = create_media($name, 'image', 'file.jpg', null, false, $photos);
        if (!is_dir(media_dir($id))) {
            mkdir(media_dir($id), 0775, true);
        }
        copy(__DIR__ . '/samples/' . $file, media_dir($id) . '/file.jpg');
        reindex_media($id);
        set_media_tags($id, $tags);
        return $id;
    };
    $cafe = $photo('Café', 'cafe.jpg', ['Food']);
    $bun = $photo('Cinnamon bun', 'special.jpg', ['Food']);
    $band = $photo('Live band', 'music.jpg', ['Music']);

    // A text item: lines as [text, style, size, colour(, price tag colour)].
    $text = function (string $name, array $lines, array $tags, array $opts = []) use ($texts): int {
        $design = $opts + ['align' => 'left', 'valign' => 'middle', 'font' => 'sans', 'shadow' => true, 'lines' => []];
        foreach ($lines as $l) {
            $design['lines'][] = ['text' => $l[0], 'style' => $l[1], 'size' => $l[2] ?? 1, 'color' => $l[3] ?? '#ffffff', 'bg' => $l[4] ?? '#ffffff'];
        }
        $id = create_text_media($name, $design, $texts);
        set_media_tags($id, $tags);
        return $id;
    };
    $welcome = $text('Welcome', [['Welcome to', 'label'], ['Harbour Café', 'title', 1.4], ['Fresh coffee · open 07–18', 'text', 1.4]], []);
    $text('Today\'s special', [['Today\'s special', 'label', 1.4], ['Cinnamon bun + coffee', 'title'], ['€ 6.50', 'badge', 1.9, '#9a3412', '#ffffff']],
        ['Food', 'Promotion']);
    $text('Live music on Fridays', [['Every Friday from 20:00', 'label', 1.4], ['Live music', 'title', 1.4], ['Free entry · all welcome', 'text', 1.4]],
        ['Music', 'Promotion']);
    $opening = $text('Opening offer (ends by itself)', [['Opening weeks', 'label', 1.4], ['2 for 1 on all coffee', 'title'],
        ['Until ' . format_date(date('Y-m-d', strtotime('+13 days'))), 'badge', 1.4, '#14532d', '#bbf7d0']], ['Promotion']);
    $summer = $text('Summer terrace (starts next summer)', [['Summer', 'label', 1.4], ['The terrace is open', 'title'],
        ['Iced coffee € 4', 'badge', 1.4, '#1e3a8a', '#fde68a']], ['Promotion']);
    $liveMusic = $text('Live music tonight', [['Tonight from 20:00', 'label', 1.4], ['Live music', 'title', 1.9], ['Free entry · all welcome', 'text', 1.6]],
        ['Music']);
    $christmas = $text('Merry Christmas', [['December at Harbour Café', 'label', 1.4], ['Merry Christmas', 'title', 1.6],
        ['Mulled wine & ginger biscuits', 'text', 1.4], ['€ 5', 'badge', 1.9, '#ffffff', '#b91c1c']], ['Christmas']);
    $text('Staff meeting', [['Staff only', 'label', 1.4, '#fde68a'], ['Staff meeting', 'title'], ['Monday 08:30 in the back room', 'text', 1.6]], ['Internal'],
        ['valign' => 'top']);
    $text('Closing routine', [['Staff only', 'label', 1.4, '#fde68a'], ['Before closing', 'title'], ["Clean the coffee machine\nEmpty the bins\nLock the terrace door", 'text', 1.6]],
        ['Internal'], ['valign' => 'top']);

    // Show dates: the opening offer disappears by itself after two weeks; the summer offer appears next summer.
    x('UPDATE media SET starts_on = ?, ends_on = ? WHERE id = ?', [date('Y-m-d'), date('Y-m-d', strtotime('+13 days')), $opening]);
    $y = (int)date('n') > 8 ? (int)date('Y') + 1 : (int)date('Y');
    x('UPDATE media SET starts_on = ?, ends_on = ? WHERE id = ?', [max(date('Y-m-d'), "$y-06-01"), "$y-08-31", $summer]);

    $ticker = create_ticker_media('Café info', [
        'messages' => ['Opening hours: Mon–Fri 07–18 · Sat–Sun 09–16', 'Free Wi-Fi: HarbourGuest', 'Ask about our loyalty card', 'Live music every Friday from 20:00'],
        'separator' => '★', 'font' => 'sans', 'bold' => true, 'size' => 0.55, 'speed' => 3, 'direction' => 'left',
        'color' => '#0f172a', 'background' => '#f8fafc', 'accent' => '#d97706', 'label' => 'INFO',
    ], $folder);

    // Clock & weather: the same kind of item, stacked with a forecast for a sidebar and in a row for a corner or a strip.
    $clock = create_clock_media('Clock & weather', ['arrange' => 'stack', 'forecast' => 3, 'transparent' => true, 'date_style' => 'long'], $folder);
    $clockRow = create_clock_media('Clock & weather – small', ['arrange' => 'row', 'align' => 'right', 'date' => false, 'transparent' => true,
        'place' => '', 'size' => 0.92], $folder);

    // ---------- Layouts ----------
    // $zones: [name, x, y, w, h, layer (higher = on top), fit, transition, timer, corner radius]
    $layout = function (string $name, string $orientation, string $background, array $zones) use ($layoutFolder): array {
        x('INSERT INTO layouts (name, orientation, background, folder_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $orientation, $background, $layoutFolder, now(), now()]);
        $id = last_id();
        $ids = [];
        foreach ($zones as [$zn, $zx, $zy, $zw, $zh, $zz, $fit, $tr, $timer, $radius]) {
            x('INSERT INTO zones (layout_id, name, x, y, w, h, z, fit, transition, timer, radius) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $zn, $zx, $zy, $zw, $zh, $zz, $fit, $tr, $timer, $radius]);
            $ids[$zn] = last_id();
        }
        return [$id, $ids];
    };
    // A zone item: one media item (id) or a tag (string), with its duration and what happens after it.
    $item = function (int $zone, int|string $content, int $duration, string $after = 'repeat') {
        $pos = (int)(q1('SELECT MAX(position) AS m FROM playlist_items WHERE zone_id = ?', [$zone])['m'] ?? -1) + 1;
        x('INSERT INTO playlist_items (zone_id, media_id, tag, duration, after, position) VALUES (?, ?, ?, ?, ?, ?)',
            [$zone, is_int($content) ? $content : null, is_int($content) ? '' : $content, $duration, $after, $pos]);
    };

    // Day: messages (Welcome, then everything tagged Promotion that shows today) beside rounded food photos.
    [$day, $z] = $layout('Café – day', 'landscape', '#13294b', [
        ['Messages', 0, 0, 46, 88, 0, 'contain', 'slide', 1, 0],
        ['Photos', 47, 4, 29, 80, 1, 'cover', 'fade', 0, 40],
        ['Clock', 77, 0, 23, 88, 2, 'contain', 'fade', 0, 0],
        ['Info', 0, 88, 100, 12, 3, 'contain', 'none', 0, 0],
    ]);
    $item($z['Messages'], $welcome, 8);
    $item($z['Messages'], 'Promotion', 8);
    $item($z['Photos'], 'Food', 8);
    $item($z['Clock'], $clock, 60);
    $item($z['Info'], $ticker, 60);

    // Evening: a text zone on top of a full-screen photo; the text holds on screen.
    [$evening, $z] = $layout('Café – evening', 'landscape', '#000000', [
        ['Photo', 0, 0, 100, 88, 0, 'cover', 'fade', 0, 0],
        ['Text', 0, 0, 60, 88, 1, 'contain', 'fade', 0, 0],
        ['Info', 0, 88, 100, 12, 2, 'contain', 'none', 0, 0],
        ['Clock', 70, 2, 28, 12, 3, 'contain', 'none', 0, 0],
    ]);
    $item($z['Photo'], 'Music', 30);
    $item($z['Text'], $liveMusic, 30, 'hold');
    $item($z['Clock'], $clockRow, 60);
    $item($z['Info'], $ticker, 60);

    // Christmas: the Christmas tag over a rounded café photo.
    [$xmas, $z] = $layout('Café – Christmas', 'landscape', '#7f1d1d', [
        ['Photo', 50, 6, 46, 76, 0, 'cover', 'fade', 0, 60],
        ['Text', 3, 0, 50, 88, 1, 'contain', 'fade', 0, 0],
        ['Info', 0, 88, 100, 12, 2, 'contain', 'none', 0, 0],
    ]);
    $item($z['Photo'], $cafe, 20);
    $item($z['Text'], 'Christmas', 20);
    $item($z['Info'], $ticker, 60);

    // Staff room: a portrait screen with the notices tagged Internal (not in the café playlist).
    [, $z] = $layout('Staff room (portrait)', 'portrait', '#1e293b', [
        ['Clock', 0, 0, 100, 8, 0, 'contain', 'none', 0, 0],
        ['Notices', 0, 8, 100, 56, 1, 'contain', 'slide', 1, 0],
        ['Photo', 6, 66, 88, 30, 2, 'cover', 'fade', 0, 50],
    ]);
    $item($z['Clock'], $clockRow, 60);
    $item($z['Notices'], 'Internal', 12);
    $item($z['Photo'], $bun, 60);

    // ---------- Playlist ----------
    x('INSERT INTO playlists (name, fallback_layout_id, folder_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        ['Café', $day, $playlistFolder, now(), now()]);
    $playlist = last_id();
    $entry = function (int $layoutId, int $duration, string $days, string $from, string $to, string $dateFrom = '', string $dateTo = '',
                       int $yearly = 0, string $frequency = '') use ($playlist) {
        $pos = (int)(q1('SELECT MAX(position) AS m FROM playlist_entries WHERE playlist_id = ?', [$playlist])['m'] ?? -1) + 1;
        x('INSERT INTO playlist_entries (playlist_id, layout_id, position, duration, days, time_from, time_to, date_from, date_to, yearly, frequency)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$playlist, $layoutId, $pos, $duration, $days, $from, $to, $dateFrom, $dateTo, $yearly, $frequency]);
    };
    $year = date('Y');
    $entry($day, 60, '', '07:00', '18:00');
    $entry($evening, 60, '4,5,6', '18:00', '23:00');
    $entry($xmas, 20, '', '', '', "$year-12-01", "$year-12-26", 1, 'f3');
    return 'playlist:' . $playlist;
}
