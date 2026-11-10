<?php
declare(strict_types=1);

/*
 * Text media: a page designed in the admin form (background, optional photo, lines of text) instead of written
 * as HTML. The design is kept as JSON in media.design and rendered to index.html, so screens play it like any
 * HTML page.
 */

const PP_TEXT_STYLES = ['label' => 'Small label', 'title' => 'Heading', 'text' => 'Text', 'badge' => 'Price tag'];
// Base font sizes in % of the zone height, before the line's size factor.
const PP_TEXT_BASE = ['label' => 3.2, 'title' => 11, 'text' => 3.8, 'badge' => 4.6];
const PP_TEXT_SIZES = ['0.6' => 'XS', '0.8' => 'S', '1' => 'M', '1.4' => 'L', '1.9' => 'XL', '2.6' => 'XXL'];
const PP_TEXT_FONTS = [
    'sans' => ['Sans-serif', 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif'],
    'serif' => ['Serif', 'Georgia,"Times New Roman",serif'],
    'rounded' => ['Rounded', 'ui-rounded,"Nunito","Arial Rounded MT Bold",system-ui,sans-serif'],
    'condensed' => ['Condensed', '"Roboto Condensed","Arial Narrow",system-ui,sans-serif'],
    'mono' => ['Monospace', 'ui-monospace,"Cascadia Code",Consolas,monospace'],
];
const PP_TEXT_PHOTO_MODES = ['cover' => 'Behind the text', 'right' => 'On the right', 'left' => 'On the left'];

function text_defaults(): array
{
    return [
        'bg1' => '#1e3a8a', 'bg2' => '#0f172a', 'angle' => 135,
        'photo' => '', 'photo_mode' => 'cover', 'photo_x' => 50, 'photo_y' => 50, 'shade' => 60, 'shade_fade' => true,
        'align' => 'left', 'valign' => 'middle', 'font' => 'sans', 'shadow' => true,
        'lines' => [
            ['text' => 'Small label', 'style' => 'label', 'size' => 1, 'color' => '#ffffff', 'bg' => '#ffffff'],
            ['text' => 'Heading', 'style' => 'title', 'size' => 1, 'color' => '#ffffff', 'bg' => '#ffffff'],
            ['text' => 'A line of text', 'style' => 'text', 'size' => 1, 'color' => '#ffffff', 'bg' => '#ffffff'],
        ],
    ];
}

function text_color($v, string $default): string
{
    return is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $default;
}

function text_pick($v, array $allowed, string $default): string
{
    return is_string($v) && array_key_exists($v, $allowed) ? $v : $default;
}

function text_int($v, int $min, int $max, int $default): int
{
    return is_numeric($v) ? max($min, min($max, (int)$v)) : $default;
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
    return [
        'bg1' => text_color($d['bg1'] ?? '', $def['bg1']),
        'bg2' => text_color($d['bg2'] ?? '', $def['bg2']),
        'angle' => text_int($d['angle'] ?? null, 0, 360, $def['angle']),
        'photo' => is_string($d['photo'] ?? null) && preg_match('/^photo\.[a-z0-9]+$/', $d['photo']) ? $d['photo'] : '',
        'photo_mode' => text_pick($d['photo_mode'] ?? '', PP_TEXT_PHOTO_MODES, 'cover'),
        'photo_x' => text_int($d['photo_x'] ?? null, 0, 100, 50),
        'photo_y' => text_int($d['photo_y'] ?? null, 0, 100, 50),
        'shade' => text_int($d['shade'] ?? null, 0, 100, $def['shade']),
        'shade_fade' => !empty($d['shade_fade']),
        'align' => text_pick($d['align'] ?? '', ['left' => 1, 'center' => 1, 'right' => 1], 'left'),
        'valign' => text_pick($d['valign'] ?? '', ['top' => 1, 'middle' => 1, 'bottom' => 1], 'middle'),
        'font' => text_pick($d['font'] ?? '', PP_TEXT_FONTS, 'sans'),
        'shadow' => !empty($d['shadow']),
        'lines' => $lines,
    ];
}

/** The design posted by the editor form (lines come as parallel arrays, in screen order). */
function text_design_from_post(array $p, string $photo): array
{
    $lines = [];
    foreach ((array)($p['line_text'] ?? []) as $i => $t) {
        $lines[] = ['text' => $t, 'style' => $p['line_style'][$i] ?? 'text', 'size' => $p['line_size'][$i] ?? 1,
            'color' => $p['line_color'][$i] ?? '', 'bg' => $p['line_bg'][$i] ?? ''];
    }
    return text_design(['lines' => $lines, 'photo' => $photo, 'shade_fade' => !empty($p['shade_fade']),
        'shadow' => !empty($p['shadow'])] + $p);
}

function text_rgba(string $hex, float $alpha): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return "rgba($r,$g,$b," . round($alpha, 3) . ')';
}

/** Font size that scales with the zone: a share of its height, but never wider than a narrow zone allows. */
function text_size(float $vh): string
{
    return 'min(' . round($vh, 2) . 'vh,' . round($vh * .75, 2) . 'vw)';
}

function text_media_html(array $d): string
{
    $d = text_design($d);
    $flex = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];
    $just = ['top' => 'flex-start', 'middle' => 'center', 'bottom' => 'flex-end'];
    $gradient = 'linear-gradient(' . $d['angle'] . 'deg,' . $d['bg1'] . ',' . $d['bg2'] . ')';
    $photo = $d['photo'] !== '' ? 'url(' . $d['photo'] . ')' : '';
    $pos = $d['photo_x'] . '% ' . $d['photo_y'] . '%';

    $css = 'html,body{margin:0;height:100%;overflow:hidden}'
        . 'body{font-family:' . PP_TEXT_FONTS[$d['font']][1] . ';display:flex;background:' . $gradient . '}'
        . '.text{box-sizing:border-box;padding:6vh 8vw;display:flex;flex-direction:column;justify-content:' . $just[$d['valign']]
        . ';align-items:' . $flex[$d['align']] . ';text-align:' . $d['align'] . ';flex:1;min-width:0;position:relative;z-index:1}'
        . '.text>div{max-width:100%;overflow-wrap:break-word}'
        . '.label{text-transform:uppercase;letter-spacing:.14em;font-weight:600;opacity:.8;margin-bottom:.5em}'
        . '.title{font-weight:700;line-height:1.05;letter-spacing:-.02em;margin-bottom:.25em}'
        . '.text>.text-line{line-height:1.35;margin-bottom:.4em}'
        . '.badge{display:inline-block;font-weight:800;padding:.2em .5em;border-radius:.3em;margin:.2em 0 .4em}'
        . '.text>div:last-child{margin-bottom:0}';
    if ($d['shadow']) {
        $css .= '.label,.title,.text-line{text-shadow:0 2px 12px rgba(0,0,0,.45)}';
    }
    if ($photo && $d['photo_mode'] === 'cover') {
        $a = $d['shade'] / 100;
        if ($d['shade_fade'] && $d['align'] !== 'center') {
            $dir = $d['align'] === 'left' ? 90 : 270;
            $shade = "linear-gradient({$dir}deg," . text_rgba($d['bg1'], $a) . ' 0%,' . text_rgba($d['bg1'], $a * .65) . ' 45%,'
                . text_rgba($d['bg1'], $a * .05) . ' 80%)';
        } else {
            $shade = 'linear-gradient(' . text_rgba($d['bg1'], $a) . ',' . text_rgba($d['bg1'], $a) . ')';
        }
        $css .= "body{background:$shade,$photo $pos/cover no-repeat,{$d['bg1']}}";
    } elseif ($photo) {
        // Text on the background colour, the photo beside it fading in under the text.
        $right = $d['photo_mode'] === 'right';
        $css .= '.text{flex:0 0 50%;padding-' . ($right ? 'right' : 'left') . ':0}'
            . ".photo{flex:1;background:$photo $pos/cover no-repeat;margin-" . ($right ? 'left' : 'right') . ':-14%;'
            . '-webkit-mask-image:linear-gradient(' . ($right ? 90 : 270) . 'deg,transparent,#000 30%);'
            . 'mask-image:linear-gradient(' . ($right ? 90 : 270) . 'deg,transparent,#000 30%)}';
        if (!$right) {
            $css .= 'body{flex-direction:row-reverse}';
        }
    }

    $lines = '';
    foreach ($d['lines'] as $l) {
        if (trim($l['text']) === '') {
            continue;
        }
        $style = 'font-size:' . text_size(PP_TEXT_BASE[$l['style']] * $l['size']) . ';color:' . $l['color']
            . ($l['style'] === 'badge' ? ';background:' . $l['bg'] : '');
        $class = $l['style'] === 'text' ? 'text-line' : $l['style'];
        $lines .= '  <div class="' . $class . '" style="' . $style . '">' . nl2br(h($l['text']), false) . "</div>\n";
    }
    $side = $photo && $d['photo_mode'] !== 'cover' ? "<div class=\"photo\"></div>\n" : '';
    return "<!doctype html>\n<html><head><meta charset=\"utf-8\">\n<!-- Made with the PiPusher text editor: edit it there, changes made here are overwritten. -->\n"
        . "<style>$css</style></head><body>\n<div class=\"text\">\n$lines</div>\n$side</body></html>\n";
}

/** Writes the design and its index.html. */
function save_text_media(int $id, array $design): void
{
    $design = text_design($design);
    if (!is_dir(media_dir($id))) {
        mkdir(media_dir($id), 0775, true);
    }
    file_put_contents(media_dir($id) . '/index.html', text_media_html($design));
    x('UPDATE media SET design = ? WHERE id = ?', [json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    reindex_media($id);
}

/** A new text item. $photoFile (optional) is copied in as its photo. */
function create_text_media(string $name, array $design, ?int $folderId = null, ?string $photoFile = null): int
{
    $id = create_media($name, 'text', 'index.html', null, true, $folderId);
    mkdir(media_dir($id), 0775, true);
    if ($photoFile) {
        $design['photo'] = 'photo.' . strtolower(pathinfo($photoFile, PATHINFO_EXTENSION));
        copy($photoFile, media_dir($id) . '/' . $design['photo']);
    }
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
