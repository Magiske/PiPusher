<?php
declare(strict_types=1);

/*
 * Ticker media: messages that scroll across a zone, set up in a form. Like text media, the design is kept as JSON
 * in media.design and rendered to index.html, so every screen (and older clients) plays it like any HTML page.
 *
 * The page is built to scroll smoothly on slow screens (Raspberry Pi, TVs):
 * - the moving strip is one GPU layer moved by a CSS transform animation, which the browser runs on its
 *   compositor thread: busy JavaScript or other zones cannot make it stutter, and the text is drawn only once;
 * - the strip moves a whole number of pixels per display frame (the page measures the refresh rate, 50 or 60 Hz),
 *   so every frame moves the same distance instead of alternating 2 and 3 pixels;
 * - the messages are repeated just enough to fill the zone, and the loop length is a whole number of frames, so
 *   it restarts without a jump or a gap, and the strip stays as short as possible.
 */

const PP_TICKER_SEPARATORS = ['•' => '•  dot', '★' => '★  star', '◆' => '◆  diamond', '|' => '|  bar', '—' => '—  dash', '' => 'Space only'];
// Text height as a share of the zone height.
const PP_TICKER_SIZES = ['0.4' => 'Small', '0.55' => 'Medium', '0.7' => 'Large', '0.85' => 'Extra large'];
// Speed in text heights per second (the scroll speed follows the text size, so it looks the same on every screen).
const PP_TICKER_SPEEDS = ['1.2' => 'Very slow', '2' => 'Slow', '3' => 'Normal', '4.5' => 'Fast', '6.5' => 'Very fast'];

function ticker_defaults(): array
{
    return [
        'messages' => ['Welcome! Open Monday–Friday 07–18', 'Free Wi-Fi in the whole building'],
        'separator' => '•', 'font' => 'sans', 'bold' => true, 'size' => 0.55, 'speed' => 3.0, 'direction' => 'left',
        'color' => '#ffffff', 'background' => '#111827', 'transparent' => false, 'accent' => '#f59e0b', 'label' => '',
    ];
}

/** A complete, valid design from (possibly partial or posted) data. */
function ticker_design(array $d): array
{
    $def = ticker_defaults();
    $msgs = $d['messages'] ?? $def['messages'];
    if (is_string($msgs)) {
        $msgs = explode("\n", str_replace("\r\n", "\n", $msgs));
    }
    $messages = [];
    foreach ((array)$msgs as $m) {
        $m = trim(preg_replace('/\s+/u', ' ', (string)$m) ?? '');
        if ($m !== '') {
            $messages[] = mb_substr($m, 0, 1000);
        }
    }
    $font = (string)($d['font'] ?? '');
    if (!isset(PP_TEXT_FONTS[$font]) && !preg_match('/^f\d+$/', $font)) {
        $font = 'sans';
    }
    $num = fn($v, array $allowed, float $default) => isset($allowed[(string)(float)$v]) ? (float)$v : $default;
    return [
        'messages' => array_slice($messages, 0, 100),
        'separator' => text_pick($d['separator'] ?? '•', PP_TICKER_SEPARATORS, '•'),
        'font' => $font,
        'bold' => !empty($d['bold']),
        'size' => $num($d['size'] ?? 0, PP_TICKER_SIZES, $def['size']),
        'speed' => $num($d['speed'] ?? 0, PP_TICKER_SPEEDS, $def['speed']),
        'direction' => text_pick($d['direction'] ?? '', ['left' => 1, 'right' => 1], 'left'),
        'color' => text_color($d['color'] ?? '', $def['color']),
        'background' => text_color($d['background'] ?? '', $def['background']),
        'transparent' => !empty($d['transparent']),
        'accent' => text_color($d['accent'] ?? '', $def['accent']),
        'label' => mb_substr(trim((string)($d['label'] ?? '')), 0, 40),
    ];
}

/** The design posted by the editor form. */
function ticker_design_from_post(array $p): array
{
    return ticker_design(['bold' => !empty($p['bold']), 'transparent' => !empty($p['transparent'])] + $p);
}

function ticker_media_design(array $m): array
{
    return ticker_design((array)json_decode((string)($m['design'] ?? ''), true));
}

/** The page for a design. $fontUrl: where an uploaded font is loaded from (see text_media_html). */
function ticker_media_html(array $d, ?string $fontUrl = null): string
{
    $d = ticker_design($d);
    $family = PP_TEXT_FONTS[$d['font']][1] ?? PP_TEXT_FONTS['sans'][1];
    $css = '';
    if ($fontUrl !== null && !isset(PP_TEXT_FONTS[$d['font']])) {
        $css .= '@font-face{font-family:"pp-font";src:url("' . $fontUrl . '");font-display:block}';
        $family = '"pp-font",' . PP_TEXT_FONTS['sans'][1];
    }
    $css .= 'html,body{margin:0;height:100%;overflow:hidden;background:' . ($d['transparent'] ? 'transparent' : $d['background']) . '}'
        . 'body{font-family:' . $family . ';font-weight:' . ($d['bold'] ? 700 : 400) . ';color:' . $d['color'] . ';line-height:1}'
        // The visible window and the moving strip. contain: the strip's movement never makes the browser lay out
        // or repaint anything else; will-change: the strip gets its own GPU layer, drawn once and then only moved.
        . '#v{position:absolute;top:0;bottom:0;left:0;right:0;overflow:hidden;contain:strict}'
        . '#t{position:absolute;top:0;left:0;height:100%;display:flex;align-items:center;white-space:nowrap;'
        . 'will-change:transform;-webkit-backface-visibility:hidden;backface-visibility:hidden}'
        . '.c{display:flex;align-items:center;flex:none;height:100%}'
        . '.m,.s{flex:none}.s{color:' . $d['accent'] . ';padding:0 .7em}.g{flex:none;width:2em}'
        . '#src{display:none}'
        . '#l{position:absolute;top:0;bottom:0;left:0;z-index:1;display:flex;align-items:center;padding:0 .6em;'
        . 'background:' . $d['accent'] . ';font-weight:700;white-space:nowrap}';
    $copy = '';
    foreach ($d['messages'] as $m) {
        $copy .= '<span class="m">' . h($m) . '</span>'
            . ($d['separator'] === '' ? '<span class="g"></span>' : '<span class="s">' . h($d['separator']) . '</span>');
    }
    $label = $d['label'] !== '' ? '<div id="l">' . h($d['label']) . '</div>' : '';
    $cfg = json_encode(['size' => $d['size'], 'speed' => $d['speed'], 'dir' => $d['direction'] === 'right' ? -1 : 1]);
    return "<!doctype html>\n<html><head><meta charset=\"utf-8\">\n<!-- Made with the PiPusher ticker editor: edit it there, changes made here are overwritten. -->\n"
        . "<style>$css</style></head><body>\n<div id=\"v\"><div id=\"t\"></div></div>$label\n<div id=\"src\">$copy</div>\n"
        . "<script>var PP_TICKER = $cfg;\n" . PP_TICKER_JS . "</script>\n</body></html>\n";
}

// The scrolling engine (plain ES5, for old browsers on TVs and Windows 7).
const PP_TICKER_JS = <<<'JS'
(function () {
  var C = PP_TICKER, v = document.getElementById('v'), t = document.getElementById('t');
  var src = document.getElementById('src'), label = document.getElementById('l');
  var style = document.createElement('style');
  document.head.appendChild(style);
  var hz = 60, width = 0, pps = 0, gen = 0, timer = null;
  if (!src.firstChild) return;

  // How far along the loop the strip is now, 0..1, to keep the position when the strip is rebuilt.
  function progress() {
    if (!width) return 0;
    var m = /matrix(?:3d)?\(([^)]+)\)/.exec(getComputedStyle(t).transform || '');
    if (!m) return 0;
    var p = m[1].split(','), x = parseFloat(p.length === 16 ? p[12] : p[4]) || 0;
    var f = (C.dir > 0 ? -x : x + width) / width;
    return f - Math.floor(f);
  }

  function build() {
    var at = progress();
    var h = v.clientHeight || window.innerHeight;
    if (!h) return;
    // Whole pixels everywhere: the font size, the distance per frame and the loop length.
    var font = Math.max(8, Math.round(h * C.size));
    document.body.style.fontSize = font + 'px';
    v.style.left = label ? label.offsetWidth + 'px' : '0';
    var step = Math.max(1, Math.round(font * C.speed / hz));
    pps = step * hz;
    t.style.animation = 'none';
    t.innerHTML = '';
    var one = document.createElement('div');
    one.className = 'c';
    one.innerHTML = src.innerHTML;
    t.appendChild(one);
    width = Math.ceil(Math.ceil(one.getBoundingClientRect().width) / step) * step;
    one.style.width = width + 'px';
    // Enough copies to cover the window while one copy scrolls out of it.
    for (var i = Math.ceil(v.clientWidth / width); i > 0; i--) t.appendChild(one.cloneNode(true));
    var name = 'pp-tick' + (++gen), a = C.dir > 0 ? 0 : -width, b = C.dir > 0 ? -width : 0;
    style.textContent = '@keyframes ' + name + '{from{transform:translate3d(' + a + 'px,0,0)}to{transform:translate3d(' + b + 'px,0,0)}}';
    void t.offsetWidth;
    var dur = width / pps;
    t.style.animation = name + ' ' + dur + 's linear ' + (-at * dur).toFixed(3) + 's infinite';
  }

  function later() { clearTimeout(timer); timer = setTimeout(build, 250); }

  // The display's refresh rate from the time between frames (median, so a slow frame does not count).
  function measure() {
    var times = [], done = false;
    function finish() {
      if (done) return;
      done = true;
      var d = [];
      for (var i = 1; i < times.length; i++) d.push(times[i] - times[i - 1]);
      if (d.length < 10) return;
      d.sort(function (x, y) { return x - y; });
      var r = Math.round(1000 / d[d.length >> 1]);
      if (r >= 24 && r <= 240 && Math.abs(r - hz) > 2) { hz = r; build(); }
    }
    function frame(ts) {
      times.push(ts);
      if (times.length < 40) requestAnimationFrame(frame); else finish();
    }
    requestAnimationFrame(frame);
    setTimeout(finish, 3000);
  }

  build();
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(build);
  window.addEventListener('resize', later);
  setTimeout(measure, 500);
})();
JS;

/** Writes the design and its index.html, with a copy of its uploaded font. */
function save_ticker_media(int $id, array $design): void
{
    $design = ticker_design($design);
    $fontUrl = design_media_font($id, $design);
    file_put_contents(media_dir($id) . '/index.html', ticker_media_html($design, $fontUrl));
    x('UPDATE media SET design = ? WHERE id = ?', [json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    reindex_media($id);
}

function create_ticker_media(string $name, array $design, ?int $folderId = null): int
{
    $id = create_media($name, 'ticker', 'index.html', null, true, $folderId);
    save_ticker_media($id, $design);
    return $id;
}
