<?php
declare(strict_types=1);

/*
 * Clock media: the time, the date and the weather, set up in a form. Like text and ticker media, the design is kept
 * as JSON in media.design and rendered to index.html, so every screen (and older clients) plays it like any HTML page.
 *
 * - The clock runs on the screen's own time and language (or a language chosen in the form).
 * - The weather comes from open-meteo.com (free, no API key), is fetched every 15 minutes and kept in the page's
 *   local storage, so a screen that goes offline keeps showing the last weather for up to a day.
 * - Everything is scaled to fill the zone, so the same item fits a small corner, a sidebar or a whole screen.
 */

const PP_CLOCK_DATES = ['long' => 'Sunday 15 November', 'medium' => 'Sun 15 Nov', 'numeric' => '15.11.2026 (in the screen\'s format)'];
const PP_CLOCK_ARRANGE = ['stack' => 'Stacked (corners, sidebars, full screen)', 'row' => 'In a row (wide, low zones)'];
const PP_CLOCK_SIZES = ['0.6' => 'Small', '0.78' => 'Medium', '0.92' => 'Large (fill the zone)'];
const PP_CLOCK_FORECAST = ['0' => 'No forecast', '3' => 'Next 3 days', '5' => 'Next 5 days'];

function clock_defaults(): array
{
    return [
        'time' => true, 'seconds' => false, 'hour12' => false,
        'date' => true, 'date_style' => 'long',
        'weather' => true, 'place' => 'Oslo', 'lat' => 59.91, 'lon' => 10.75, 'units' => 'c', 'forecast' => 0,
        'arrange' => 'stack', 'align' => 'center', 'size' => 0.78, 'locale' => '',
        'font' => 'sans', 'color' => '#ffffff', 'accent' => '#fcd34d', 'background' => '#0f172a', 'transparent' => false,
    ];
}

/** A complete, valid design from (possibly partial or posted) data. */
function clock_design(array $d): array
{
    $def = clock_defaults();
    $font = (string)($d['font'] ?? '');
    if (!isset(PP_TEXT_FONTS[$font]) && !preg_match('/^f\d+$/', $font)) {
        $font = 'sans';
    }
    $coord = function ($v, float $max, float $default): float {
        $v = str_replace(',', '.', trim((string)$v));
        return is_numeric($v) && abs((float)$v) <= $max ? round((float)$v, 4) : $default;
    };
    $locale = trim((string)($d['locale'] ?? ''));
    $bool = fn(string $k) => array_key_exists($k, $d) ? !empty($d[$k]) : $def[$k];
    return [
        'time' => $bool('time'),
        'seconds' => $bool('seconds'),
        'hour12' => $bool('hour12'),
        'date' => $bool('date'),
        'date_style' => text_pick($d['date_style'] ?? '', PP_CLOCK_DATES, $def['date_style']),
        'weather' => $bool('weather'),
        'place' => mb_substr(trim((string)($d['place'] ?? $def['place'])), 0, 60),
        'lat' => $coord($d['lat'] ?? '', 90, $def['lat']),
        'lon' => $coord($d['lon'] ?? '', 180, $def['lon']),
        'units' => text_pick($d['units'] ?? '', ['c' => 1, 'f' => 1], 'c'),
        'forecast' => (int)text_pick((string)($d['forecast'] ?? ''), PP_CLOCK_FORECAST, '0'),
        'arrange' => text_pick($d['arrange'] ?? '', PP_CLOCK_ARRANGE, 'stack'),
        'align' => text_pick($d['align'] ?? '', ['left' => 1, 'center' => 1, 'right' => 1], 'center'),
        'size' => isset(PP_CLOCK_SIZES[(string)(float)($d['size'] ?? 0)]) ? (float)$d['size'] : $def['size'],
        'locale' => preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/i', $locale) ? $locale : '',
        'font' => $font,
        'color' => text_color($d['color'] ?? '', $def['color']),
        'accent' => text_color($d['accent'] ?? '', $def['accent']),
        'background' => text_color($d['background'] ?? '', $def['background']),
        'transparent' => !empty($d['transparent']),
    ];
}

/** The design posted by the editor form (unticked boxes are missing from a post). */
function clock_design_from_post(array $p): array
{
    $flags = [];
    foreach (['time', 'seconds', 'hour12', 'date', 'weather', 'transparent'] as $k) {
        $flags[$k] = !empty($p[$k]);
    }
    return clock_design($flags + $p);
}

function clock_media_design(array $m): array
{
    return clock_design((array)json_decode((string)($m['design'] ?? ''), true));
}

/** The page for a design. $fontUrl: where an uploaded font is loaded from (see text_media_html). */
function clock_media_html(array $d, ?string $fontUrl = null): string
{
    $d = clock_design($d);
    $family = PP_TEXT_FONTS[$d['font']][1] ?? PP_TEXT_FONTS['sans'][1];
    $css = '';
    if ($fontUrl !== null && !isset(PP_TEXT_FONTS[$d['font']])) {
        $css .= '@font-face{font-family:"pp-font";src:url("' . $fontUrl . '");font-display:block}';
        $family = '"pp-font",' . PP_TEXT_FONTS['sans'][1];
    }
    $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$d['align']];
    $css .= 'html,body{margin:0;height:100%;overflow:hidden;background:' . ($d['transparent'] ? 'transparent' : $d['background']) . '}'
        . 'body{display:flex;align-items:center;justify-content:' . $justify . ';font-family:' . $family . ';color:' . $d['color']
        . ';line-height:1.1;font-size:100px;padding:0 4vw;box-sizing:border-box' . ($d['transparent'] ? ';text-shadow:0 .03em .12em rgba(0,0,0,.55)' : '') . '}'
        . '#c{display:inline-flex;flex-direction:' . ($d['arrange'] === 'row' ? 'row;align-items:center;gap:.9em' : 'column;gap:.25em')
        . ';align-items:' . ($d['arrange'] === 'row' ? 'center' : $justify) . ';text-align:' . $d['align'] . ';white-space:nowrap}'
        . '#tm{font-size:3em;font-weight:700;letter-spacing:-.03em;font-variant-numeric:tabular-nums}'
        . '#tm small{font-size:.45em;opacity:.65;letter-spacing:0;margin-left:.08em}'
        . '#dt{opacity:.75;text-transform:capitalize}'
        . '#wx{display:flex;align-items:center;gap:.25em;font-size:1.9em;font-weight:700;color:' . $d['accent'] . '}'
        . '#wx small{font-size:.38em;font-weight:400;opacity:.8;color:' . $d['color'] . '}'
        . '#fc{display:flex;gap:.9em;font-size:.8em}#fc div{display:flex;flex-direction:column;align-items:center;gap:.15em}'
        . '#fc b{color:' . $d['accent'] . '}#fc span{opacity:.7}'
        . 'svg{width:1.15em;height:1.15em;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}'
        . '#fc svg{color:' . $d['accent'] . '}.hide{display:none!important}';
    $cfg = json_encode([
        'time' => $d['time'], 'seconds' => $d['seconds'], 'hour12' => $d['hour12'], 'date' => $d['date'], 'dateStyle' => $d['date_style'],
        'weather' => $d['weather'], 'place' => $d['place'], 'lat' => $d['lat'], 'lon' => $d['lon'], 'units' => $d['units'],
        'forecast' => $d['forecast'], 'size' => $d['size'], 'locale' => $d['locale'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return "<!doctype html>\n<html><head><meta charset=\"utf-8\">\n<!-- Made with the PiPusher clock editor: edit it there, changes made here are overwritten. -->\n"
        . "<style>$css</style></head><body>\n<div id=\"c\"><div id=\"tm\"></div><div id=\"dt\"></div><div id=\"wx\" class=\"hide\"></div><div id=\"fc\" class=\"hide\"></div></div>\n"
        . "<script>var PP_CLOCK = $cfg;\n" . PP_CLOCK_JS . "</script>\n</body></html>\n";
}

// The clock (plain ES5, for old browsers on TVs and Windows 7).
const PP_CLOCK_JS = <<<'JS'
(function () {
  var C = PP_CLOCK, L = C.locale || undefined, $ = function (id) { return document.getElementById(id); };
  var c = $('c'), tm = $('tm'), dt = $('dt'), wx = $('wx'), fc = $('fc'), shape = '';
  if (!C.time) tm.className = 'hide';
  if (!C.date) dt.className = 'hide';

  // Weather icons (WMO weather codes from open-meteo).
  var CLOUD = '<path d="M7 18h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6.1 11 3.5 3.5 0 0 0 7 18z"/>';
  var ICONS = {
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon: '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
    partly: '<path d="M8 2v1.5M3.3 4.3l1 1M2 9h1.5M12.7 4.3l-1 1"/><path d="M5.2 10.5a3 3 0 1 1 5.6-2"/><path d="M9 20h8a3.5 3.5 0 0 0 .4-7 5 5 0 0 0-9.2.7A3.2 3.2 0 0 0 9 20z"/>',
    cloud: CLOUD,
    fog: '<path d="M4 9h16M3 13h18M5 17h14M8 21h8"/>',
    rain: '<path d="M7 15h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6.1 8 3.5 3.5 0 0 0 7 15z"/><path d="M8 18l-1 3M12 18l-1 3M16 18l-1 3"/>',
    snow: '<path d="M7 15h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6.1 8 3.5 3.5 0 0 0 7 15z"/><path d="M8 19h.01M12 18h.01M16 19h.01M10 21.5h.01M14 21.5h.01"/>',
    storm: '<path d="M7 15h10a4 4 0 0 0 .5-7.97A6 6 0 0 0 6.1 8 3.5 3.5 0 0 0 7 15z"/><path d="M12.5 15l-2 3.5h3l-2 3.5"/>'
  };
  function icon(code, day) {
    var k = code === 0 ? (day ? 'sun' : 'moon') : code <= 2 ? (day ? 'partly' : 'moon') : code === 3 ? 'cloud' : code <= 48 ? 'fog'
      : code >= 95 ? 'storm' : (code >= 71 && code <= 77) || code === 85 || code === 86 ? 'snow' : 'rain';
    return '<svg viewBox="0 0 24 24">' + ICONS[k] + '</svg>';
  }

  // Scales everything to fill the zone; only needed when the shape of the content changes.
  function fit() {
    document.body.style.fontSize = '100px';
    var r = c.getBoundingClientRect(), w = window.innerWidth * 0.92, h = window.innerHeight;
    if (!r.width || !r.height || !w || !h) return;
    document.body.style.fontSize = Math.max(6, Math.floor(100 * Math.min(w / r.width, h / r.height) * C.size)) + 'px';
  }
  function refit() {
    var s = tm.textContent.length + '|' + dt.textContent + '|' + wx.textContent + '|' + fc.textContent.length;
    if (s !== shape) { shape = s; fit(); }
  }

  function two(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    var n = new Date();
    if (C.time) {
      var t;
      try { t = n.toLocaleTimeString(L, { hour: '2-digit', minute: '2-digit', hour12: C.hour12 }); }
      catch (e) { t = two(n.getHours()) + ':' + two(n.getMinutes()); }
      if (C.hour12) t = t.replace(/^0(?=\d\D)/, '');
      // Seconds go small right after the minutes (before AM/PM).
      tm.innerHTML = C.seconds ? t.replace(/(\d+\D\d\d)/, '$1<small>' + two(n.getSeconds()) + '</small>') : t;
    }
    if (C.date) {
      var o = C.dateStyle === 'long' ? { weekday: 'long', day: 'numeric', month: 'long' }
        : C.dateStyle === 'medium' ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: '2-digit', month: '2-digit', year: 'numeric' };
      try { dt.textContent = n.toLocaleDateString(L, o); } catch (e) { dt.textContent = n.toDateString(); }
    }
    refit();
    setTimeout(tick, 1000 - n.getMilliseconds() + 5);
  }

  var KEY = 'pp-clock-weather|' + C.lat + '|' + C.lon + '|' + C.units + '|' + C.forecast;
  function show(d) {
    var u = '°', cur = d.current, days = d.daily;
    wx.innerHTML = icon(cur.weather_code, cur.is_day) + Math.round(cur.temperature_2m) + u + (C.place ? ' <small>' + C.place.replace(/[<&]/g, '') + '</small>' : '');
    wx.className = '';
    if (C.forecast && days && days.time) {
      var html = '';
      for (var i = 1; i <= C.forecast && i < days.time.length; i++) {
        var p = days.time[i].split('-'), day = new Date(+p[0], p[1] - 1, +p[2]), name;
        try { name = day.toLocaleDateString(L, { weekday: 'short' }); } catch (e) { name = days.time[i].slice(5); }
        html += '<div><span>' + name + '</span>' + icon(days.weather_code[i], 1) + '<b>' + Math.round(days.temperature_2m_max[i]) + u
          + '</b><span>' + Math.round(days.temperature_2m_min[i]) + u + '</span></div>';
      }
      fc.innerHTML = html;
      fc.className = '';
    }
    shape = '';
    refit();
  }
  function cached() { try { return JSON.parse(localStorage.getItem(KEY)); } catch (e) { return null; } }
  function load() {
    var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + C.lat + '&longitude=' + C.lon + '&timezone=auto'
      + '&current=temperature_2m,weather_code,is_day' + (C.units === 'f' ? '&temperature_unit=fahrenheit' : '')
      + (C.forecast ? '&daily=weather_code,temperature_2m_max,temperature_2m_min&forecast_days=' + (C.forecast + 1) : '');
    var x = new XMLHttpRequest();
    x.open('GET', url);
    x.timeout = 20000;
    x.onload = function () {
      try { var d = JSON.parse(x.responseText); if (!d.current) throw 0; d.at = Date.now(); show(d); localStorage.setItem(KEY, JSON.stringify(d)); }
      catch (e) { x.onerror(); }
    };
    x.onerror = x.ontimeout = function () {
      var d = cached();
      if (d && Date.now() - d.at < 86400000) show(d); else { wx.className = 'hide'; fc.className = 'hide'; shape = ''; refit(); }
    };
    x.send();
  }

  tick();
  if (C.weather) {
    var d = cached();
    if (d && Date.now() - d.at < 86400000) show(d);
    load();
    setInterval(load, 15 * 60 * 1000);
  }
  window.addEventListener('resize', function () { shape = ''; refit(); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { shape = ''; refit(); });
})();
JS;

/** Writes the design and its index.html, with a copy of its uploaded font. */
function save_clock_media(int $id, array $design): void
{
    $design = clock_design($design);
    $fontUrl = design_media_font($id, $design);
    file_put_contents(media_dir($id) . '/index.html', clock_media_html($design, $fontUrl));
    x('UPDATE media SET design = ? WHERE id = ?', [json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    reindex_media($id);
}

function create_clock_media(string $name, array $design, ?int $folderId = null): int
{
    $id = create_media($name, 'clock', 'index.html', null, true, $folderId);
    save_clock_media($id, $design);
    return $id;
}
