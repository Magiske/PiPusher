<?php
declare(strict_types=1);

/*
 * Media scheduling and tags.
 * - media.starts_on / ends_on (YYYY-MM-DD, '' = open): the item is shown only on those days, by the server's date.
 *   Screens get content without it from the first sync after the date changes, so every client version obeys.
 * - media_tags: free tags such as Christmas or Food (case-insensitive). A zone item can be a tag instead of one
 *   media item: it plays every media item with that tag that is not outside its dates, by name (layout_data).
 */

function tag_clean(string $t): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace(',', ' ', $t)) ?? ''), 0, 40);
}

/** Tags from a comma-separated list, without duplicates (ignoring case). */
function tags_parse(string $list): array
{
    $out = [];
    foreach (explode(',', $list) as $t) {
        $t = tag_clean($t);
        if ($t !== '' && !isset($out[mb_strtolower($t)])) {
            $out[mb_strtolower($t)] = $t;
        }
    }
    return array_values($out);
}

/** media id => [tags], for some ids (or all media). */
function media_tags_map(?array $ids = null): array
{
    $out = [];
    $rows = $ids === null ? q('SELECT media_id, tag FROM media_tags ORDER BY tag')
        : ($ids ? q('SELECT media_id, tag FROM media_tags WHERE media_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY tag') : []);
    foreach ($rows as $r) {
        $out[(int)$r['media_id']][] = $r['tag'];
    }
    return $out;
}

function media_tags(int $id): array
{
    return media_tags_map([$id])[$id] ?? [];
}

function set_media_tags(int $id, array $tags): void
{
    x('DELETE FROM media_tags WHERE media_id = ?', [$id]);
    foreach ($tags as $t) {
        x('INSERT OR IGNORE INTO media_tags (media_id, tag) VALUES (?, ?)', [$id, $t]);
    }
}

function add_media_tag(int $id, string $tag): void
{
    x('INSERT OR IGNORE INTO media_tags (media_id, tag) VALUES (?, ?)', [$id, $tag]);
}

function remove_media_tag(int $id, string $tag): void
{
    x('DELETE FROM media_tags WHERE media_id = ? AND tag = ?', [$id, $tag]);
}

/** Every tag in use => number of media items with it, by name. */
function all_tags(): array
{
    $out = [];
    foreach (q('SELECT MIN(tag) AS tag, COUNT(*) AS n FROM media_tags GROUP BY tag ORDER BY tag COLLATE NOCASE') as $r) {
        $out[$r['tag']] = (int)$r['n'];
    }
    return $out;
}

function today(): string
{
    return date('Y-m-d');
}

/** 'active', 'scheduled' (starts later) or 'expired' (ended), by the server's date. */
function media_schedule(array $m, ?string $day = null): string
{
    $day ??= today();
    if (($m['ends_on'] ?? '') !== '' && $m['ends_on'] < $day) {
        return 'expired';
    }
    if (($m['starts_on'] ?? '') !== '' && $m['starts_on'] > $day) {
        return 'scheduled';
    }
    return 'active';
}

/** SQL condition for media shown today (alias m). */
function media_active_sql(): string
{
    $d = today();
    return "(m.starts_on = '' OR m.starts_on <= '$d') AND (m.ends_on = '' OR m.ends_on >= '$d')";
}

/** "1 Dec – 26 Dec 2026", "from 1 Dec 2026", "until 26 Dec 2026", or ''. */
function media_dates_label(array $m): string
{
    $s = (string)($m['starts_on'] ?? '');
    $e = (string)($m['ends_on'] ?? '');
    if ($s !== '' && $e !== '') {
        return format_date($s, substr($s, 0, 4) !== substr($e, 0, 4)) . ' – ' . format_date($e);
    }
    return $s !== '' ? 'from ' . format_date($s) : ($e !== '' ? 'until ' . format_date($e) : '');
}

/** A small badge with the item's dates, coloured by whether it shows today. */
function media_schedule_badge(array $m): string
{
    $label = media_dates_label($m);
    if ($label === '') {
        return '';
    }
    $state = media_schedule($m);
    $text = match ($state) {
        'scheduled' => '⏳ Starts ' . format_date((string)$m['starts_on']),
        'expired' => '⌛ Expired ' . format_date((string)$m['ends_on']),
        default => '📅 ' . $label,
    };
    return '<span class="badge badge-sched-' . $state . '" title="Shown ' . h($label) . '">' . h($text) . '</span>';
}

/** Media with a tag that show today, by name. */
function tag_media(string $tag): array
{
    return q('SELECT m.* FROM media m JOIN media_tags t ON t.media_id = m.id WHERE t.tag = ? AND ' . media_active_sql()
        . ' ORDER BY m.name COLLATE NOCASE, m.id', [$tag]);
}

function tag_chip(string $tag, ?string $href = null): string
{
    return $href ? '<a class="tag" href="' . h($href) . '">🏷 ' . h($tag) . '</a>' : '<span class="tag">🏷 ' . h($tag) . '</span>';
}
