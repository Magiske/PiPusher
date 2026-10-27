<?php
declare(strict_types=1);

/** Creates sample HTML pages and a demo layout. Returns the layout id. */
function install_samples(): int
{
    x('INSERT INTO media_folders (name, created_at) VALUES (?, ?)', ['Samples', now()]);
    $folder = last_id();
    $welcome = create_html_media('Sample – Welcome', <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><style>
  html,body{margin:0;height:100%;font-family:system-ui,sans-serif;color:#fff;overflow:hidden}
  body{display:flex;flex-direction:column;justify-content:center;padding:0 8vw;box-sizing:border-box;
       background:linear-gradient(135deg,#0f2027,#203a43 50%,#2c5364)}
  h1{font-size:9vh;margin:0 0 2vh} p{font-size:3.5vh;max-width:60vw;opacity:.85;line-height:1.4}
  .tag{display:inline-block;background:#ff7a59;padding:.6vh 1.6vh;border-radius:1vh;font-size:2.4vh;margin-bottom:3vh;width:max-content}
</style></head><body>
  <span class="tag">PiPusher</span>
  <h1>Welcome!</h1>
  <p>This is a sample HTML page served by your PiPusher server. Edit it under <b>Media</b>, or upload your own HTML pages, ZIP bundles, images and videos.</p>
</body></html>
HTML, $folder);

    $clock = create_html_media('Sample – Clock', <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><style>
  html,body{margin:0;height:100%;font-family:system-ui,sans-serif;color:#fff;background:#111827;overflow:hidden}
  body{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
  #t{font-size:min(22vw,20vh);font-weight:700;font-variant-numeric:tabular-nums}
  #d{font-size:min(6vw,5vh);opacity:.7;margin-top:1vh}
</style></head><body>
  <div id="t"></div><div id="d"></div>
<script>
  function tick(){var n=new Date();
    document.getElementById('t').textContent=n.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
    document.getElementById('d').textContent=n.toLocaleDateString([], {weekday:'long',day:'numeric',month:'long'});}
  tick(); setInterval(tick, 1000);
</script></body></html>
HTML, $folder);

    $weather = create_html_media('Sample – Weather (online data, cached offline)', <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"><style>
  html,body{margin:0;height:100%;font-family:system-ui,sans-serif;color:#fff;overflow:hidden;
    background:linear-gradient(180deg,#1e3c72,#2a5298)}
  body{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
  #city{font-size:min(7vw,5vh);opacity:.8} #temp{font-size:min(28vw,20vh);font-weight:700}
  #desc{font-size:min(6vw,4vh)} #upd{font-size:min(4vw,2.2vh);opacity:.5;margin-top:2vh}
</style></head><body>
  <div id="city"></div><div id="temp">–</div><div id="desc"></div><div id="upd"></div>
<script>
  // Edit these to your location. Data from open-meteo.com (no API key needed).
  var CITY = 'Oslo', LAT = 59.91, LON = 10.75;
  var CODES = {0:'Clear sky',1:'Mainly clear',2:'Partly cloudy',3:'Overcast',45:'Fog',48:'Fog',51:'Drizzle',53:'Drizzle',
    55:'Drizzle',61:'Rain',63:'Rain',65:'Heavy rain',71:'Snow',73:'Snow',75:'Heavy snow',80:'Showers',81:'Showers',
    82:'Heavy showers',95:'Thunderstorm'};
  function show(d, cached){
    document.getElementById('city').textContent = CITY;
    document.getElementById('temp').textContent = Math.round(d.temperature_2m) + '°';
    document.getElementById('desc').textContent = CODES[d.weather_code] || '';
    document.getElementById('upd').textContent = (cached ? 'Offline – last update ' : 'Updated ') + new Date(d.fetched).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
  }
  function load(){
    fetch('https://api.open-meteo.com/v1/forecast?latitude='+LAT+'&longitude='+LON+'&current=temperature_2m,weather_code')
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
  html,body{margin:0;height:100%;overflow:hidden;background:#ff7a59;color:#fff;font-family:system-ui,sans-serif}
  .wrap{display:flex;align-items:center;height:100%;white-space:nowrap}
  .msg{display:inline-block;padding-left:100%;font-size:55vh;font-weight:600;animation:scroll 30s linear infinite}
  @keyframes scroll{to{transform:translateX(-100%)}}
</style></head><body>
  <div class="wrap"><span class="msg">Welcome to PiPusher digital signage &nbsp;•&nbsp; Edit this ticker in the Media library &nbsp;•&nbsp; Content keeps playing even when the server is offline</span></div>
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
    $add = function (int $zoneId, int $mediaId, int $duration, int $pos) {
        x('INSERT INTO playlist_items (zone_id, media_id, duration, position) VALUES (?, ?, ?, ?)', [$zoneId, $mediaId, $duration, $pos]);
    };
    $add((int)$zones[0]['id'], $welcome, 30, 0);
    $add((int)$zones[1]['id'], $clock, 15, 0);
    $add((int)$zones[1]['id'], $weather, 15, 1);
    $add((int)$zones[2]['id'], $ticker, 60, 0);
    return $layoutId;
}
