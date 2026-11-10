<?php
declare(strict_types=1);

/*
 * Text media: lines of text designed in the admin form instead of written as HTML. The page is transparent, so a
 * text zone placed over another zone shows the text on top of it. The design is kept as JSON in media.design and
 * rendered to index.html, so screens play it like any HTML page. An uploaded font (table fonts, files in
 * data/fonts) is copied into the item as font.<ext>, so screens cache it with the page.
 */

const PP_TEXT_STYLES = ['label' => 'Small label', 'title' => 'Heading', 'text' => 'Text', 'badge' => 'Price tag'];
// Base font sizes in % of the zone height, before the line's size factor.
const PP_TEXT_BASE = ['label' => 3.2, 'title' => 11, 'text' => 3.8, 'badge' => 4.6];
const PP_TEXT_SIZES = ['0.6' => 'XS', '0.8' => 'S', '1' => 'M', '1.4' => 'L', '1.9' => 'XL', '2.6' => 'XXL', '3.5' => 'XXXL'];
const PP_TEXT_FONTS = [
    'sans' => ['Sans-serif', 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif'],
    'serif' => ['Serif', 'Georgia,"Times New Roman",serif'],
    'rounded' => ['Rounded', 'ui-rounded,"Nunito","Arial Rounded MT Bold",system-ui,sans-serif'],
    'condensed' => ['Condensed', '"Roboto Condensed","Arial Narrow",system-ui,sans-serif'],
    'mono' => ['Monospace', 'ui-monospace,"Cascadia Code",Consolas,monospace'],
];
const PP_FONT_EXT = ['woff2', 'woff', 'ttf', 'otf'];

function text_defaults(): array
{
    return [
        'align' => 'left', 'valign' => 'middle', 'font' => 'sans', 'shadow' => true,
        'lines' => [
            ['text' => 'Small label', 'style' => 'label', 'size' => 1, 'color' => '#ffffff', 'bg' => '#ffffff'],
            ['text' => 'Heading', 'style' => 'title', 'size' => 1, 'color' => '#ffffff', 'bg' => '#ffffff'],
            ['text' => 'A line of text', 'style' => 'text', 'size' => 1, 'color' => '#ffffff', 'bg' => '#ffffff'],
        ],
    ];
}

/** Uploaded fonts by id: ['name' => ..., 'file' => '<id>.<ext>']. */
function text_fonts(): array
{
    $out = [];
    foreach (q('SELECT * FROM fonts ORDER BY name COLLATE NOCASE') as $f) {
        $out[(int)$f['id']] = $f;
    }
    return $out;
}

/** Font choices for the editor: the built-in families, then uploaded fonts as 'f<id>'. */
function text_font_choices(): array
{
    $out = [];
    foreach (PP_TEXT_FONTS as $k => [$label]) {
        $out[$k] = $label;
    }
    foreach (text_fonts() as $id => $f) {
        $out['f' . $id] = $f['name'];
    }
    return $out;
}

/** Stores an uploaded font file. Returns an error message, or null. */
function add_font(string $tmp, string $origName, string $name): ?string
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, PP_FONT_EXT, true)) {
        return 'Fonts must be WOFF2, WOFF, TTF or OTF files.';
    }
    $name = mb_substr(trim($name) ?: pathinfo($origName, PATHINFO_FILENAME), 0, 80);
    x('INSERT INTO fonts (name, file, created_at) VALUES (?, ?, ?)', [$name, '', now()]);
    $id = last_id();
    $file = $id . '.' . $ext;
    if (!is_dir(PP_FONTS)) {
        mkdir(PP_FONTS, 0775, true);
    }
    if (!move_uploaded_file($tmp, PP_FONTS . '/' . $file) && !copy($tmp, PP_FONTS . '/' . $file)) {
        x('DELETE FROM fonts WHERE id = ?', [$id]);
        return 'Saving the font failed.';
    }
    x('UPDATE fonts SET file = ? WHERE id = ?', [$file, $id]);
    return null;
}

/** Removes a font from the list. Text items using it keep their copy until they are saved with another font. */
function delete_font(int $id): void
{
    $f = q1('SELECT * FROM fonts WHERE id = ?', [$id]);
    if ($f) {
        @unlink(PP_FONTS . '/' . $f['file']);
        x('DELETE FROM fonts WHERE id = ?', [$id]);
    }
}

function text_color($v, string $default): string
{
    return is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $default;
}

function text_pick($v, array $allowed, string $default): string
{
    return is_string($v) && array_key_exists($v, $allowed) ? $v : $default;
}

/** A complete, valid design from (possibly partial or posted) data. */
function text_design(array $d): array
{
    $def = text_defaults();
    $size = fn($v) => isset(PP_TEXT_SIZES[(string)(float)$v]) ? (float)$v : 1.0;
    $lines = [];
    foreach ((array)($d['lines'] ?? $def['lines']) as $l) {
        $lines[] = [
            'text' => mb_substr(str_replace("\r\n", "\n", (string)($l['text'] ?? '')), 0, 2000),
            'style' => text_pick($l['style'] ?? '', PP_TEXT_STYLES, 'text'),
            'size' => $size($l['size'] ?? 1),
            'color' => text_color($l['color'] ?? '', '#ffffff'),
            'bg' => text_color($l['bg'] ?? '', '#ffffff'),
        ];
    }
    $font = (string)($d['font'] ?? '');
    if (!isset(PP_TEXT_FONTS[$font]) && !preg_match('/^f\d+$/', $font)) {
        $font = 'sans';
    }
    return [
        'align' => text_pick($d['align'] ?? '', ['left' => 1, 'center' => 1, 'right' => 1], 'left'),
        'valign' => text_pick($d['valign'] ?? '', ['top' => 1, 'middle' => 1, 'bottom' => 1], 'middle'),
        'font' => $font,
        'shadow' => !empty($d['shadow']),
        'lines' => $lines,
    ];
}

/** The design posted by the editor form (lines come as parallel arrays, in screen order). */
function text_design_from_post(array $p): array
{
    $lines = [];
    foreach ((array)($p['line_text'] ?? []) as $i => $t) {
        $lines[] = ['text' => $t, 'style' => $p['line_style'][$i] ?? 'text', 'size' => $p['line_size'][$i] ?? 1,
            'color' => $p['line_color'][$i] ?? '', 'bg' => $p['line_bg'][$i] ?? ''];
    }
    return text_design(['lines' => $lines, 'shadow' => !empty($p['shadow'])] + $p);
}

/** Font size that scales with the zone: a share of its height, but never wider than a narrow zone allows. */
function text_size(float $vh): string
{
    return 'min(' . round($vh, 2) . 'vh,' . round($vh * .75, 2) . 'vw)';
}

/**
 * The page for a design. $fontUrl is where an uploaded font is loaded from: the item's own copy (font.<ext>)
 * on screens, the shared file in the editor preview.
 */
function text_media_html(array $d, ?string $fontUrl = null): string
{
    $d = text_design($d);
    $flex = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
    $just = ['top' => 'flex-start', 'middle' => 'center', 'bottom' => 'flex-end'];
    $family = PP_TEXT_FONTS[$d['font']][1] ?? PP_TEXT_FONTS['sans'][1];
    $css = '';
    if ($fontUrl !== null && !isset(PP_TEXT_FONTS[$d['font']])) {
        $css .= '@font-face{font-family:"pp-font";src:url("' . $fontUrl . '");font-display:block}';
        $family = '"pp-font",' . PP_TEXT_FONTS['sans'][1];
    }
    $css .= 'html,body{margin:0;height:100%;overflow:hidden;background:transparent}'
        . 'body{font-family:' . $family . ';box-sizing:border-box;padding:6vh 8vw;display:flex;flex-direction:column;'
        . 'justify-content:' . $just[$d['valign']] . ';align-items:' . $flex[$d['align']] . ';text-align:' . $d['align'] . '}'
        . 'body>div{max-width:100%;overflow-wrap:break-word}'
        . '.label{text-transform:uppercase;letter-spacing:.14em;font-weight:600;opacity:.8;margin-bottom:.5em}'
        . '.title{font-weight:700;line-height:1.05;letter-spacing:-.02em;margin-bottom:.25em}'
        . '.text{line-height:1.35;margin-bottom:.4em}'
        . '.badge{display:inline-block;font-weight:800;padding:.2em .5em;border-radius:.3em;margin:.2em 0 .4em}'
        . 'body>div:last-child{margin-bottom:0}';
    if ($d['shadow']) {
        $css .= '.label,.title,.text{text-shadow:0 2px 12px rgba(0,0,0,.45)}';
    }
    $lines = '';
    foreach ($d['lines'] as $l) {
        if (trim($l['text']) === '') {
            continue;
        }
        $style = 'font-size:' . text_size(PP_TEXT_BASE[$l['style']] * $l['size']) . ';color:' . $l['color']
            . ($l['style'] === 'badge' ? ';background:' . $l['bg'] : '');
        $lines .= '<div class="' . $l['style'] . '" style="' . $style . '">' . nl2br(h($l['text']), false) . "</div>\n";
    }
    return "<!doctype html>\n<html><head><meta charset=\"utf-8\">\n<!-- Made with the PiPusher text editor: edit it there, changes made here are overwritten. -->\n"
        . "<style>$css</style></head><body>\n$lines</body></html>\n";
}

/** The uploaded font a design uses, or null. */
function text_design_font(array $d): ?array
{
    return preg_match('/^f(\d+)$/', $d['font'], $m) ? q1('SELECT * FROM fonts WHERE id = ?', [(int)$m[1]]) : null;
}

/** Writes the design and its index.html, with a copy of its uploaded font. */
function save_text_media(int $id, array $design): void
{
    $design = text_design($design);
    $dir = media_dir($id);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $font = text_design_font($design);
    $fontUrl = $font && is_file(PP_FONTS . '/' . $font['file']) ? 'font.' . pathinfo($font['file'], PATHINFO_EXTENSION) : null;
    // A font removed from the list stays in use through the item's own copy.
    $kept = !$fontUrl && !isset(PP_TEXT_FONTS[$design['font']]) ? (glob($dir . '/font.*') ?: [null])[0] : null;
    if ($kept) {
        $fontUrl = basename($kept);
    }
    // Only index.html and the font belong to a text item (1.32.0 also kept a photo here).
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && basename($f) !== 'index.html' && $f !== $kept) {
            unlink($f);
        }
    }
    if ($font && !$kept && $fontUrl) {
        copy(PP_FONTS . '/' . $font['file'], $dir . '/' . $fontUrl);
    }
    file_put_contents($dir . '/index.html', text_media_html($design, $fontUrl));
    x('UPDATE media SET design = ? WHERE id = ?', [json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    reindex_media($id);
}

function create_text_media(string $name, array $design, ?int $folderId = null): int
{
    $id = create_media($name, 'text', 'index.html', null, true, $folderId);
    save_text_media($id, $design);
    return $id;
}

function text_media_design(array $m): array
{
    return text_design((array)json_decode((string)($m['design'] ?? ''), true));
}

/** <option>s for the editor preview's shape: the zones the item plays in, then common screen shapes. */
function text_shape_options(array $uses): string
{
    $out = '';
    foreach ($uses as $u) {
        [$sw, $sh] = str_starts_with((string)$u['orientation'], 'portrait') ? [9, 16] : [16, 9];
        $w = max(1, (float)$u['w'] * $sw);
        $h = max(1, (float)$u['h'] * $sh);
        $out .= '<option value="' . round($w, 2) . '/' . round($h, 2) . '">' . h($u['layout'] . ' → ' . $u['zone']) . '</option>';
    }
    foreach (['16/9' => 'Landscape 16:9', '9/16' => 'Portrait 9:16', '4/3' => '4:3', '1/1' => 'Square', '32/9' => 'Wide strip 32:9'] as $v => $label) {
        $out .= '<option value="' . $v . '">' . $label . '</option>';
    }
    return $out;
}
