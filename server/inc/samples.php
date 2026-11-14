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

    // An HTML page with a photo from inc/samples next to it.
    $page = function (string $name, string $html, string $photo) use ($folder): int {
        $id = create_html_media($name, $html, $folder);
        copy(__DIR__ . '/samples/' . $photo, media_dir($id) . '/' . $photo);
        reindex_media($id);
        return $id;
    };

    // Shared look of the three slides in the main zone.
    $slide = <<<'CSS'
  html,body{margin:0;height:100%;overflow:hidden;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#fff}
  body{box-sizing:border-box;padding:0 8vw;display:flex;flex-direction:column;justify-content:center;background-size:cover;background-position:center}
  small{font-size:3.2vh;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.75);font-weight:600}
  b{font-size:11vh;line-height:1.05;margin:1.6vh 0 2.8vh;letter-spacing:-.02em}
  span{font-size:3.8vh;color:rgba(255,255,255,.85)}
  small,b,span{text-shadow:0 2px 12px rgba(0,0,0,.45)}
CSS;

    $welcome = $page('Sample – Welcome', <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><style>
$slide
  body{background-image:linear-gradient(90deg,rgba(23,47,110,.92) 0%,rgba(30,58,138,.6) 45%,rgba(30,58,138,.05) 80%),url(cafe.jpg)}
</style></head><body>
  <small>Welcome to</small>
  <b>Harbour Café</b>
  <span>Fresh coffee · open 07–18</span>
</body></html>
HTML, 'cafe.jpg');

    $special = $page('Sample – Today\'s special', <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><style>
$slide
  body{padding:0;flex-direction:row;align-items:stretch;background:linear-gradient(135deg,#c2410c,#9f1239)}
  .text{position:relative;z-index:1;flex:0 0 50%;box-sizing:border-box;padding:0 0 0 8vw;display:flex;flex-direction:column;justify-content:center}
  .text b{font-size:9vh}
  .price{display:inline-block;align-self:flex-start;background:#fff;color:#9a3412;font-weight:800;padding:1vh 2.4vh;border-radius:1.4vh;font-size:4.6vh;text-shadow:none}
  .photo{flex:1;margin-left:-14%;background:url(special.jpg) 40% 55%/cover no-repeat;
    -webkit-mask-image:linear-gradient(90deg,transparent,#000 30%);mask-image:linear-gradient(90deg,transparent,#000 30%)}
</style></head><body>
  <div class="text">
    <small>Today's special</small>
    <b>Cinnamon bun<br>+ coffee</b>
    <span class="price">€ 6.50</span>
  </div>
  <div class="photo"></div>
</body></html>
HTML, 'special.jpg');

    $music = $page('Sample – Live music', <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><style>
$slide
  body{background-image:linear-gradient(90deg,rgba(46,16,101,.85) 0%,rgba(76,29,149,.35) 50%,transparent 80%),url(music.jpg);background-position:60% 70%}
</style></head><body>
  <small>Friday from 20:00</small>
  <b>Live music</b>
  <span>Free entry · all welcome</span>
</body></html>
HTML, 'music.jpg');

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

    $ticker = create_ticker_media('Sample – Ticker', [
        'messages' => ['Opening hours: Mon–Fri 07–18 · Sat–Sun 09–16', 'Free Wi-Fi: HarbourGuest', 'Ask about our loyalty card'],
        'separator' => '★', 'font' => 'sans', 'bold' => true, 'size' => 0.55, 'speed' => 3, 'direction' => 'left',
        'color' => '#0f172a', 'background' => '#f8fafc', 'accent' => '#d97706',
    ], $folder);

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
