<?php
declare(strict_types=1);

/**
 * Creates the sample pages and a demo layout: the café screen shown on pipusher.com.
 * Returns the layout id.
 */
function install_samples(): int
{
    x('INSERT INTO media_folders (name, created_at) VALUES (?, ?)', ['Samples', now()]);
    $folder = last_id();

    // The three slides are text items (see inc/text.php), so they can be changed in the text editor.
    $line = fn(string $text, string $style, float $size = 1, string $color = '#ffffff', string $bg = '#ffffff')
        => ['text' => $text, 'style' => $style, 'size' => $size, 'color' => $color, 'bg' => $bg];
    $photo = fn(string $file) => __DIR__ . '/samples/' . $file;

    $welcome = create_text_media('Sample – Welcome', [
        'bg1' => '#172f6e', 'bg2' => '#1e3a8a', 'photo_mode' => 'cover', 'shade' => 92, 'shade_fade' => true, 'shadow' => true,
        'lines' => [$line('Welcome to', 'label'), $line('Harbour Café', 'title'), $line('Fresh coffee · open 07–18', 'text')],
    ], $folder, $photo('cafe.jpg'));

    $special = create_text_media('Sample – Today\'s special', [
        'bg1' => '#c2410c', 'bg2' => '#9f1239', 'angle' => 135, 'photo_mode' => 'right', 'photo_x' => 40, 'photo_y' => 55,
        'shadow' => true,
        'lines' => [$line('Today\'s special', 'label'), $line("Cinnamon bun\n+ coffee", 'title', 0.8),
            $line('€ 6.50', 'badge', 1, '#9a3412', '#ffffff')],
    ], $folder, $photo('special.jpg'));

    $music = create_text_media('Sample – Live music', [
        'bg1' => '#2e1065', 'bg2' => '#4c1d95', 'photo_mode' => 'cover', 'photo_x' => 60, 'photo_y' => 70, 'shade' => 85,
        'shade_fade' => true, 'shadow' => true,
        'lines' => [$line('Friday from 20:00', 'label'), $line('Live music', 'title'), $line('Free entry · all welcome', 'text')],
    ], $folder, $photo('music.jpg'));

    $side = create_html_media('Sample – Clock & weather (online data, cached offline)', <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><style>
  html,body{margin:0;height:100%;overflow:hidden;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#e6ecf5;
    background:linear-gradient(180deg,#111c30,#0b1424)}
  body{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
  #t{font-size:min(24vw,16vh);font-weight:700;letter-spacing:-.03em;font-variant-numeric:tabular-nums;line-height:1.1}
  #d{font-size:min(7vw,4.4vh);color:#94a3b8;text-transform:capitalize}
  #w{display:flex;align-items:center;gap:2vh;margin-top:8vh;color:#fcd34d;font-weight:700;font-size:min(14vw,8vh)}
  #w svg{width:min(18vw,10vh);height:auto;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  #upd{font-size:min(4vw,2.2vh);color:#94a3b8;opacity:.6;margin-top:2vh;min-height:1em}
</style></head><body>
  <div id="t"></div><div id="d"></div>
  <div id="w"><svg viewBox="0 0 24 24"><circle cx="9" cy="10" r="4"/><path d="M7 19h10a4 4 0 0 0 0-8 5 5 0 0 0-1 .1"/></svg><span id="temp">–</span></div>
  <div id="upd"></div>
<script>
  // Edit these to your location. Data from open-meteo.com (no API key needed).
  var LAT = 59.91, LON = 10.75;

  function tick(){var n=new Date();
    document.getElementById('t').textContent=n.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',hour12:false});
    document.getElementById('d').textContent=n.toLocaleDateString([], {weekday:'long',day:'numeric',month:'short'});}
  tick(); setInterval(tick, 1000);

  function show(d, cached){
    document.getElementById('temp').textContent = Math.round(d.temperature_2m) + '°';
    document.getElementById('upd').textContent = cached ? 'Offline – weather from ' + new Date(d.fetched).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '';
  }
  function load(){
    fetch('https://api.open-meteo.com/v1/forecast?latitude='+LAT+'&longitude='+LON+'&current=temperature_2m')
      .then(function(r){return r.json();})
      .then(function(j){var d=j.current; d.fetched=Date.now(); try{localStorage.setItem('pp-weather',JSON.stringify(d));}catch(e){} show(d,false);})
      .catch(function(){try{var d=JSON.parse(localStorage.getItem('pp-weather')); if(d) show(d,true);}catch(e){}});
  }
  load(); setInterval(load, 15*60*1000);
</script></body></html>
HTML, $folder);

    $ticker = create_html_media('Sample – Ticker', <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><style>
  html,body{margin:0;height:100%;overflow:hidden;background:#f8fafc;color:#0f172a;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  .wrap{display:flex;align-items:center;height:100%;white-space:nowrap}
  .msg{display:inline-block;padding-left:100%;font-size:45vh;font-weight:600;animation:scroll 30s linear infinite}
  @keyframes scroll{to{transform:translateX(-100%)}}
</style></head><body>
  <div class="wrap"><span class="msg">Opening hours: Mon–Fri 07–18 · Sat–Sun 09–16 &nbsp;★&nbsp; Free Wi-Fi: HarbourGuest &nbsp;★&nbsp; Ask about our loyalty card</span></div>
<script>
  // Scroll speed in pixels per second: short and long messages move at the same speed.
  var SPEED = 150;

  var msg = document.querySelector('.msg');
  function setSpeed() {
    // The text travels its own width plus the screen width (the padding), so time = distance / speed.
    msg.style.animationDuration = (msg.offsetWidth / SPEED) + 's';
  }
  setSpeed();
  if (document.fonts) document.fonts.ready.then(setSpeed);
  window.addEventListener('resize', setSpeed);
</script>
</body></html>
HTML, $folder);

    $layoutId = create_layout('Demo layout', 'landscape', 'sidebar_ticker');
    $zones = q('SELECT id, name FROM zones WHERE layout_id = ? ORDER BY z', [$layoutId]);
    // The main zone shows a progress bar under each slide, like the screen on pipusher.com.
    x('UPDATE zones SET timer = 1 WHERE id = ?', [(int)$zones[0]['id']]);
    $add = function (int $zoneId, int $mediaId, int $duration, int $pos) {
        x('INSERT INTO playlist_items (zone_id, media_id, duration, position) VALUES (?, ?, ?, ?)', [$zoneId, $mediaId, $duration, $pos]);
    };
    $add((int)$zones[0]['id'], $welcome, 10, 0);
    $add((int)$zones[0]['id'], $special, 10, 1);
    $add((int)$zones[0]['id'], $music, 10, 2);
    $add((int)$zones[1]['id'], $side, 60, 0);
    $add((int)$zones[2]['id'], $ticker, 60, 0);
    return $layoutId;
}
