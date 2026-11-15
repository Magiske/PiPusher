<?php
declare(strict_types=1);

/*
 * Colour media: a zone filled with one colour, set up in a form. Put it in a low layer as a coloured background
 * (under text, a clock or a ticker), or in a high layer as a see-through tint or a fade over a photo or video.
 * Like text, ticker and clock media, the design is kept as JSON in media.design and rendered to index.html, so
 * every screen (and older clients) plays it like any HTML page.
 *
 * - opacity: how solid the colour is, in % (100 = solid, 40 = a light tint).
 * - fade:    the colour fades out to nothing towards an edge (or the centre), over `reach` % of the zone, so a photo
 *            under it shows more and more; '' = the same colour all over.
 */

const PP_COLOR_FADES = [
    '' => 'No fade (even colour)',
    'left' => 'Fades out towards the left',
    'right' => 'Fades out towards the right',
    'top' => 'Fades out towards the top',
    'bottom' => 'Fades out towards the bottom',
    'center' => 'Fades out towards the centre (coloured edges)',
    'edges' => 'Fades out towards the edges (coloured centre)',
];

function color_defaults(): array
{
    return ['color' => '#0f172a', 'opacity' => 100, 'fade' => '', 'reach' => 100];
}

/** A complete, valid design from (possibly partial or posted) data. */
function color_design(array $d): array
{
    $def = color_defaults();
    $pct = function ($v, int $min, int $default): int {
        return is_numeric($v) ? max($min, min(100, (int)round((float)$v))) : $default;
    };
    return [
        'color' => text_color($d['color'] ?? '', $def['color']),
        'opacity' => $pct($d['opacity'] ?? '', 0, $def['opacity']),
        'fade' => text_pick((string)($d['fade'] ?? ''), PP_COLOR_FADES, ''),
        'reach' => $pct($d['reach'] ?? '', 5, $def['reach']),
    ];
}

function color_media_design(array $m): array
{
    return color_design((array)json_decode((string)($m['design'] ?? ''), true));
}

/** The CSS background for a design (without its opacity). */
function color_fill(array $d): string
{
    $c = $d['color'];
    $solid = 100 - $d['reach']; // the share that keeps the full colour before the fade starts
    if (in_array($d['fade'], ['left', 'right', 'top', 'bottom'], true)) {
        return 'linear-gradient(to ' . $d['fade'] . ',' . $c . ' ' . $solid . '%,transparent 100%)';
    }
    if ($d['fade'] === 'center') {
        return 'radial-gradient(ellipse farthest-corner at center,transparent ' . $solid . '%,' . $c . ' 100%)';
    }
    if ($d['fade'] === 'edges') {
        return 'radial-gradient(ellipse farthest-corner at center,' . $c . ' ' . $solid . '%,transparent 100%)';
    }
    return $c;
}

/** The page for a design. */
function color_media_html(array $d): string
{
    $d = color_design($d);
    $css = 'html,body{margin:0;height:100%;overflow:hidden;background:transparent}'
        . '#c{position:fixed;top:0;left:0;right:0;bottom:0;background:' . color_fill($d) . ';opacity:' . ($d['opacity'] / 100) . '}';
    return "<!doctype html>\n<html><head><meta charset=\"utf-8\">\n<!-- Made with the PiPusher colour editor: edit it there, changes made here are overwritten. -->\n"
        . "<style>$css</style></head><body><div id=\"c\"></div></body></html>\n";
}

function save_color_media(int $id, array $design): void
{
    $design = color_design($design);
    if (!is_dir(media_dir($id))) {
        mkdir(media_dir($id), 0775, true);
    }
    file_put_contents(media_dir($id) . '/index.html', color_media_html($design));
    x('UPDATE media SET design = ? WHERE id = ?', [json_encode($design, JSON_UNESCAPED_SLASHES), $id]);
    reindex_media($id);
}

function create_color_media(string $name, array $design, ?int $folderId = null): int
{
    $id = create_media($name, 'color', 'index.html', null, true, $folderId);
    save_color_media($id, $design);
    return $id;
}
