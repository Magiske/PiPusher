(function () {
  'use strict';

  // Confirmation for destructive forms.
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // QR codes (two-factor setup), drawn by assets/qrcode.js when the page loads it.
  document.querySelectorAll('[data-qr]').forEach(function (box) {
    if (!window.qrcode) return;
    var qr = window.qrcode(0, 'M');
    qr.addData(box.getAttribute('data-qr'));
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
  });

  // Times of day in the viewer's own format: 12-hour with AM/PM (e.g. US) or 24-hour, as the browser's locale uses,
  // unless Settings -> Time format forces one (<html data-time-format="12|24">).
  var forced = document.documentElement.getAttribute('data-time-format');
  var hour12 = forced === '12' || (forced !== '24' && (function () {
    try {
      var o = new Intl.DateTimeFormat(undefined, { hour: 'numeric' }).resolvedOptions();
      return o.hourCycle ? o.hourCycle === 'h12' || o.hourCycle === 'h11' : !!o.hour12;
    } catch (e) { return false; }
  })());
  function dayPeriod(h) {
    try {
      var part = new Intl.DateTimeFormat(undefined, { hour: 'numeric', hour12: true }).formatToParts(new Date(2000, 0, 1, h))
        .filter(function (p) { return p.type === 'dayPeriod'; })[0];
      if (part) return part.value;
    } catch (e) { /* older browsers */ }
    return h < 12 ? 'AM' : 'PM';
  }
  function localTime(hhmm) {
    var hm = hhmm.split(':');
    try {
      var opts = { hour: 'numeric', minute: '2-digit' };
      if (forced) { opts.hour12 = hour12; if (!hour12) opts.hour = '2-digit'; }
      return new Date(2000, 0, 1, +hm[0], +hm[1]).toLocaleTimeString(undefined, opts);
    } catch (e) { return hhmm; }
  }
  document.querySelectorAll('time[data-hm]').forEach(function (t) { t.textContent = localTime(t.getAttribute('data-hm')); });
  // Times inside the text of [data-local-times] elements (select options, playlist rules); 24:00 = end of the day.
  var hhmmRe = /\b(([01]\d|2[0-3]):[0-5]\d|24:00)\b/g;
  var relabel = function (s) { return s.replace(hhmmRe, function (t) { return t === '24:00' && !hour12 ? t : localTime(t === '24:00' ? '00:00' : t); }); };
  document.querySelectorAll('[data-local-times]').forEach(function (box) {
    var walk = document.createTreeWalker(box, NodeFilter.SHOW_TEXT);
    for (var n = walk.nextNode(); n; n = walk.nextNode()) n.nodeValue = relabel(n.nodeValue);
  });
  document.querySelectorAll('option[data-hm]').forEach(function (o) {
    o.text = o.text.replace(o.getAttribute('data-hm'), localTime(o.getAttribute('data-hm')));
  });
  // Screen page: the time pickers only apply to "At this screen's own time".
  document.querySelectorAll('[data-reload-mode]').forEach(function (sel) {
    var own = sel.form.querySelector('[data-reload-own]');
    sel.addEventListener('change', function () { own.hidden = sel.value !== 'own'; });
  });

  // Hour + minute selects from time_select(): the posted hour stays 0-23 (or a special value such as "Off").
  document.querySelectorAll('[data-time-select]').forEach(function (box) {
    var hour = box.querySelector('select[name$="_h"]');
    var minute = box.querySelector('select[name$="_m"]');
    var sep = box.querySelector('.time-sep');
    var special = function () { return !hour.options[hour.selectedIndex].hasAttribute('data-hour'); };
    if (!hour12) {
      var toggle = function () { minute.hidden = sep.hidden = special(); };
      hour.addEventListener('change', toggle);
      toggle();
      return;
    }
    // 12-hour clock: shown as hour (1-12), minutes and AM/PM; the real 0-23 select is kept hidden in sync.
    var h12 = document.createElement('select');
    var ap = document.createElement('select');
    h12.setAttribute('aria-label', 'Hour');
    ap.setAttribute('aria-label', 'AM or PM');
    [].forEach.call(hour.options, function (o) {
      if (!o.hasAttribute('data-hour')) h12.add(new Option(o.text, 's:' + o.value));
    });
    for (var i = 1; i <= 12; i++) h12.add(new Option(String(i), String(i % 12)));
    ap.add(new Option(dayPeriod(1), '0'));
    ap.add(new Option(dayPeriod(13), '12'));
    function show() {
      var o = hour.options[hour.selectedIndex];
      if (special()) {
        h12.value = 's:' + o.value;
      } else {
        h12.value = String(+o.value % 12);
        ap.value = +o.value >= 12 ? '12' : '0';
      }
      minute.hidden = sep.hidden = ap.hidden = special();
    }
    function sync() {
      hour.value = h12.value.indexOf('s:') === 0 ? h12.value.slice(2) : String(+h12.value + +ap.value);
      show();
    }
    h12.addEventListener('change', sync);
    ap.addEventListener('change', sync);
    hour.hidden = true;
    box.insertBefore(h12, hour);
    box.appendChild(ap);
    show();
  });

  // Day / month / year selects from date_select(), in the order of Settings -> Date format or the browser's locale.
  var dateFormat = document.documentElement.getAttribute('data-date-format') || (function () {
    try {
      return new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).formatToParts(new Date(2000, 11, 24))
        .filter(function (p) { return p.type === 'day' || p.type === 'month' || p.type === 'year'; })
        .map(function (p) { return p.type.charAt(0); }).join('');
    } catch (e) { return 'dmy'; }
  })();
  if (!/^(?=.*d)(?=.*m)(?=.*y)[dmy]{3}$/.test(dateFormat)) dateFormat = 'dmy';
  document.querySelectorAll('[data-date-select]').forEach(function (box) {
    var month = box.querySelector('select[name$="_m"]');
    [].forEach.call(month.options, function (o) {
      if (!o.value) return;
      if (dateFormat === 'ymd') { o.text = ('0' + o.value).slice(-2); return; }
      try { o.text = new Date(2000, +o.value - 1, 1).toLocaleDateString(undefined, { month: 'short' }); } catch (e) { /* keep */ }
    });
    dateFormat.split('').forEach(function (c) { box.appendChild(box.querySelector('select[name$="_' + c + '"]')); });
  });

  // Inputs that save their form when changed (playlist durations).
  document.querySelectorAll('[data-autosubmit]').forEach(function (inp) {
    inp.addEventListener('change', function () { inp.form.submit(); });
  });

  // User form: playlist access only applies to playlist editors.
  document.querySelectorAll('[data-role-select]').forEach(function (sel) {
    var box = sel.form.querySelector('[data-playlist-access]');
    if (!box) return;
    sel.addEventListener('change', function () { box.hidden = sel.value === 'admin'; });
  });

  // Screen form: "follow group / own content" only applies when a group is chosen.
  document.querySelectorAll('[data-group-select]').forEach(function (sel) {
    var box = sel.form.querySelector('[data-source-box]');
    var label = sel.form.querySelector('[data-own-label]');
    sel.addEventListener('change', function () {
      box.hidden = !sel.value;
      label.textContent = sel.value ? 'Own content' : 'Shows';
    });
  });

  // Media library: select items to move them to another folder.
  var selForm = document.getElementById('media-select');
  if (selForm) {
    var boxes = document.querySelectorAll('input[name="ids[]"][form="media-select"]');
    var all = selForm.querySelector('[data-select-all]');
    var update = function () {
      var n = Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
      selForm.querySelector('[data-selected-count]').textContent = n + ' selected';
      selForm.querySelectorAll('[data-needs-selection]').forEach(function (b) { b.disabled = n === 0; });
      all.checked = n > 0 && n === boxes.length;
    };
    boxes.forEach(function (b) { b.addEventListener('change', update); });
    all.addEventListener('change', function () {
      boxes.forEach(function (b) { b.checked = all.checked; });
      update();
    });
  }

  // Drag & drop highlight on the upload box.
  var dz = document.querySelector('.dropzone');
  if (dz) {
    ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function () { dz.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function () { dz.classList.remove('drag'); }); });
  }

  // HTML editor: live preview, Tab inserts spaces, Ctrl+S saves.
  var code = document.getElementById('html-content');
  var preview = document.getElementById('html-preview');
  if (code) {
    var t = null;
    var refresh = function () {
      if (!preview) return;
      var base = '<base href="' + code.getAttribute('data-base') + '">';
      preview.removeAttribute('src');
      preview.srcdoc = base + code.value;
    };
    code.addEventListener('input', function () { clearTimeout(t); t = setTimeout(refresh, 400); });
    code.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') {
        e.preventDefault();
        var s = code.selectionStart;
        code.setRangeText('  ', s, code.selectionEnd, 'end');
      } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        code.form.submit();
      }
    });
  }

  // Text editor: lines can be added, moved and removed; the server renders the preview as you edit.
  var textForm = document.querySelector('[data-text-editor]');
  if (textForm) {
    var lines = document.getElementById('text-lines');
    var textPreview = document.getElementById('html-preview');
    var shape = document.getElementById('text-shape');
    var box = textPreview.parentNode;
    var textBase = new URL(textPreview.getAttribute('src').replace(/[^\/]*$/, ''), location.href).href;
    var textTimer = null;
    var render = function () {
      var fd = new FormData(textForm);
      fd.set('do', 'render');
      fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          textPreview.removeAttribute('src');
          textPreview.srcdoc = html.replace('<head>', '<head><base href="' + textBase + '">');
        });
    };
    var later = function () { clearTimeout(textTimer); textTimer = setTimeout(render, 250); };
    var sync = function () {
      if (!lines) return; // ticker editor: no line rows
      lines.querySelectorAll('.text-line-row').forEach(function (row) {
        row.setAttribute('data-style', row.querySelector('select[name="line_style[]"]').value);
      });
    };
    // The text is transparent: show it over a colour of your choice (preview only, not saved).
    var backdrop = document.getElementById('text-backdrop');
    var paint = function () { box.style.background = backdrop.value; };
    backdrop.addEventListener('input', paint);
    paint();
    var grow = function (ta) { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 2 + 'px'; };
    textForm.addEventListener('input', function (e) {
      if (e.target.tagName === 'TEXTAREA' && lines) grow(e.target);
      sync(); later();
    });
    textForm.addEventListener('change', function () { sync(); later(); });
    textForm.addEventListener('click', function (e) {
      var b = e.target.closest('[data-line]');
      if (!b) return;
      var act = b.getAttribute('data-line');
      var row = b.closest('.text-line-row');
      if (act === 'add') {
        lines.appendChild(document.getElementById('text-line-template').content.cloneNode(true));
        lines.lastElementChild.querySelector('textarea').focus();
      } else if (act === 'up' && row.previousElementSibling) {
        lines.insertBefore(row, row.previousElementSibling);
      } else if (act === 'down' && row.nextElementSibling) {
        lines.insertBefore(row.nextElementSibling, row);
      } else if (act === 'remove') {
        row.remove();
      }
      sync(); later();
    });
    textForm.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); textForm.submit(); }
    });
    var reshape = function () {
      box.style.aspectRatio = shape.value;
      box.style.width = 'min(100%, calc(70vh * ' + shape.value + '))'; // tall shapes stay on screen
    };
    shape.addEventListener('change', reshape);
    if (lines) lines.querySelectorAll('textarea').forEach(grow);
    sync(); reshape();

    // Clock editor: find a place's position (open-meteo geocoding, no key needed).
    var find = document.getElementById('clock-find');
    if (find) {
      var place = document.getElementById('clock-place'), found = document.getElementById('clock-found');
      var lookup = function () {
        if (!place.value.trim()) return;
        find.disabled = true;
        fetch('https://geocoding-api.open-meteo.com/v1/search?count=1&name=' + encodeURIComponent(place.value.trim()))
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var g = j.results && j.results[0];
            if (!g) { found.textContent = 'No place called "' + place.value + '" found. Try another spelling, or enter the position.'; return; }
            document.getElementById('clock-lat').value = Math.round(g.latitude * 1e4) / 1e4;
            document.getElementById('clock-lon').value = Math.round(g.longitude * 1e4) / 1e4;
            place.value = g.name;
            found.textContent = 'Found ' + [g.name, g.admin1, g.country].filter(Boolean).join(', ') + '.';
            later();
          })
          .catch(function () { found.textContent = 'Could not reach the place search. Enter the position yourself.'; })
          .then(function () { find.disabled = false; });
      };
      find.addEventListener('click', lookup);
      place.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });
    }
  }

  // Live screen status (online dot, last seen, thumbnails) on the Screens list and screen page.
  var liveList = document.querySelector('[data-live-list]');
  var liveRows = document.querySelectorAll('[data-device]');
  if (liveList || liveRows.length) {
    var refreshStatus = function () {
      fetch('status.php', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var devices = j.devices || {};
          // New screens or changed approval state: reload the list (unless the user is typing).
          var known = {};
          liveRows.forEach(function (row) { known[row.getAttribute('data-device')] = row.getAttribute('data-status'); });
          var structural = liveList && Object.keys(devices).some(function (id) { return known[id] !== devices[id].status; });
          if (structural && !(document.activeElement && /INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName))) {
            location.reload();
            return;
          }
          liveRows.forEach(function (row) {
            var d = devices[row.getAttribute('data-device')];
            if (!d) return;
            var dot = row.querySelector('.dot');
            if (dot) dot.className = 'dot ' + (d.online ? 'dot-on' : 'dot-off');
            var thumb = row.querySelector('[data-live="thumb"]');
            if (thumb && d.screenshot_at) {
              var src = 'screenshot.php?id=' + row.getAttribute('data-device') + '&t=' + d.screenshot_at;
              if (thumb.getAttribute('src') !== src) thumb.setAttribute('src', src);
            }
          });
          document.querySelectorAll('[data-live][data-device-ref], [data-device] [data-live="last_seen"]').forEach(function (el) {
            var ref = el.getAttribute('data-device-ref') || el.closest('[data-device]').getAttribute('data-device');
            var d = devices[ref];
            if (!d) return;
            if (el.getAttribute('data-live') === 'last_seen') el.textContent = d.last_seen;
            if (el.getAttribute('data-live') === 'state') el.textContent = d.online ? 'Online' : 'Offline';
          });
        })
        .catch(function () {})
        .then(function () { setTimeout(refreshStatus, 5000); });
    };
    setTimeout(refreshStatus, 5000);
  }

  // Requested screenshot: poll until a newer one arrives (up to 5 minutes), then reload.
  var shot = document.getElementById('screenshot');
  if (shot && shot.getAttribute('data-waiting') === '1') {
    var since = Number(shot.getAttribute('data-at'));
    var tries = 0;
    var check = function () {
      fetch('screenshot.php?meta=1&id=' + shot.getAttribute('data-id'), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.at && j.at > since) location.reload();
          else if (++tries < 60) setTimeout(check, 5000);
        })
        .catch(function () { if (++tries < 60) setTimeout(check, 5000); });
    };
    setTimeout(check, 5000);
  }

  // ------------------------------------------------------------------ zone editor
  var cfg = window.PP_ZONE_EDITOR;
  var stage = document.getElementById('zone-stage');
  if (!cfg || !stage) return;

  var zones = cfg.zones.map(function (z) { return Object.assign({}, z); });
  var rows = document.getElementById('zone-rows');
  var selected = 0;
  var dirty = false;

  function round(v) { return Math.round(v * 2) / 2; } // snap to 0.5 %
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function options(map, value) {
    return Object.keys(map).map(function (k) {
      return '<option value="' + k + '"' + (k === value ? ' selected' : '') + '>' + map[k] + '</option>';
    }).join('');
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  function renderStage() {
    stage.innerHTML = '';
    zones.forEach(function (z, i) {
      var b = document.createElement('div');
      b.className = 'zone-box' + (i === selected ? ' selected' : '');
      b.style.left = z.x + '%';
      b.style.top = z.y + '%';
      b.style.width = z.w + '%';
      b.style.height = z.h + '%';
      b.style.zIndex = 10 + (z.z || 0);
      // Same scale as the player: the radius is in pixels of a 1080-pixel-high screen.
      if (z.radius > 0) b.style.borderRadius = Math.min(stage.clientWidth, stage.clientHeight) * z.radius / 1080 + 'px';
      b.textContent = z.name;
      var hdl = document.createElement('div');
      hdl.className = 'handle';
      b.appendChild(hdl);
      b.addEventListener('pointerdown', function (e) { startDrag(e, i, e.target === hdl ? 'resize' : 'move'); });
      stage.appendChild(b);
    });
  }

  // Zone indexes from the bottom layer to the top one.
  function stackOrder() {
    return zones.map(function (z, i) { return i; })
      .sort(function (a, b) { return (zones[a].z || 0) - (zones[b].z || 0) || a - b; });
  }

  // Moves a zone one layer up (dir 1) or down (dir -1) and renumbers the layers 0..n-1.
  function moveLayer(i, dir) {
    var order = stackOrder();
    var pos = order.indexOf(i);
    if (pos + dir < 0 || pos + dir >= order.length) return;
    order.splice(pos, 1);
    order.splice(pos + dir, 0, i);
    order.forEach(function (j, k) { zones[j].z = k; });
    dirty = true;
    renderAll();
  }

  // Rows are listed by layer, top layer first (layer 1), so ▲ moves a zone up the list and on top of the one above.
  function renderRows() {
    rows.innerHTML = '';
    var order = stackOrder();
    order.slice().reverse().forEach(function (i) {
      var z = zones[i];
      var pos = order.indexOf(i);
      var tr = document.createElement('tr');
      tr.setAttribute('data-zone', i);
      if (i === selected) tr.className = 'selected';
      tr.innerHTML =
        '<td><input data-k="name" value="' + esc(z.name) + '"></td>' +
        ['x', 'y', 'w', 'h'].map(function (k) {
          return '<td><input data-k="' + k + '" type="number" step="0.5" min="0" max="100" value="' + z[k] + '"></td>';
        }).join('') +
        '<td class="zone-layer"><button type="button" class="btn btn-sm" data-layer="1" title="Move up: on top of the zone above"' +
          (pos === order.length - 1 ? ' disabled' : '') + '>▲</button>' +
        '<button type="button" class="btn btn-sm" data-layer="-1" title="Move down: under the zone below"' +
          (pos === 0 ? ' disabled' : '') + '>▼</button>' +
        '<span class="muted small" title="1 = top, ' + order.length + ' = bottom">' + (order.length - pos) + '</span></td>' +
        '<td><select data-k="fit">' + options(cfg.fits, z.fit || 'contain') + '</select></td>' +
        '<td><select data-k="transition">' + options(cfg.transitions, z.transition || 'fade') + '</select></td>' +
        '<td class="zone-timer"><input data-k="timer" type="checkbox" title="Progress bar at the bottom of the zone"' +
          (z.timer ? ' checked' : '') + '></td>' +
        '<td class="zone-radius"><input data-k="radius" type="number" min="0" max="540" step="1" value="' + (z.radius || 0) + '"' +
          ' title="Rounded corners: radius in pixels on a 1080p screen, 0 = square"></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger zone-remove" title="Remove zone">✕</button></td>';
      tr.addEventListener('focusin', function () { select(i, false); });
      tr.querySelectorAll('[data-k]').forEach(function (inp) {
        inp.addEventListener('input', function () {
          var k = inp.getAttribute('data-k');
          if (k === 'timer') z[k] = inp.checked;
          else if (k === 'radius') z[k] = Math.max(0, Math.min(540, Math.round(Number(inp.value) || 0)));
          else z[k] = (k === 'name' || k === 'fit' || k === 'transition') ? inp.value : Number(inp.value);
          if ('xywh'.indexOf(k) >= 0) normalise(z);
          dirty = true;
          renderStage();
        });
      });
      tr.querySelectorAll('[data-layer]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          selected = i;
          moveLayer(i, Number(btn.getAttribute('data-layer')));
        });
      });
      tr.querySelector('.zone-remove').addEventListener('click', function () {
        if (zones.length === 1) { alert('A layout needs at least one zone.'); return; }
        zones.splice(i, 1);
        selected = 0;
        dirty = true;
        renderAll();
      });
      rows.appendChild(tr);
    });
  }

  function normalise(z) {
    z.x = clamp(z.x, 0, 99);
    z.y = clamp(z.y, 0, 99);
    z.w = clamp(z.w, 1, 100 - z.x);
    z.h = clamp(z.h, 1, 100 - z.y);
  }

  function renderAll() { renderStage(); renderRows(); }

  function select(i, rerenderRows) {
    if (selected === i) return;
    selected = i;
    renderStage();
    if (rerenderRows !== false) renderRows();
    else rows.querySelectorAll('tr').forEach(function (tr) { tr.className = +tr.getAttribute('data-zone') === i ? 'selected' : ''; });
  }

  function startDrag(e, i, mode) {
    e.preventDefault();
    select(i);
    var rect = stage.getBoundingClientRect();
    var z = zones[i];
    var start = { px: e.clientX, py: e.clientY, x: z.x, y: z.y, w: z.w, h: z.h };
    function move(ev) {
      var dx = (ev.clientX - start.px) / rect.width * 100;
      var dy = (ev.clientY - start.py) / rect.height * 100;
      if (mode === 'move') {
        z.x = round(clamp(start.x + dx, 0, 100 - z.w));
        z.y = round(clamp(start.y + dy, 0, 100 - z.h));
      } else {
        z.w = round(clamp(start.w + dx, 1, 100 - z.x));
        z.h = round(clamp(start.h + dy, 1, 100 - z.y));
      }
      dirty = true;
      var box = stage.children[i];
      box.style.left = z.x + '%'; box.style.top = z.y + '%';
      box.style.width = z.w + '%'; box.style.height = z.h + '%';
    }
    function up() {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
      renderRows();
    }
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  }

  document.getElementById('zone-add').addEventListener('click', function () {
    var maxZ = zones.reduce(function (m, z) { return Math.max(m, z.z || 0); }, 0);
    zones.push({ id: null, name: 'Zone ' + (zones.length + 1), x: 25, y: 25, w: 50, h: 50, z: maxZ + 1, fit: 'contain', transition: 'fade', timer: false, radius: 0 });
    selected = zones.length - 1;
    dirty = true;
    renderAll();
  });

  var tplBox = document.getElementById('zone-templates');
  Object.keys(cfg.templates).forEach(function (key) {
    var t = cfg.templates[key];
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm';
    btn.textContent = t.label;
    btn.title = 'Apply template (keeps existing zones/playlists where possible)';
    btn.addEventListener('click', function () {
      // Re-use existing zones (and their playlists) in order; extra zones are removed on save.
      zones = t.zones.map(function (tz, i) {
        var old = zones[i] || {};
        return { id: old.id || null, name: old.id ? old.name : tz.name, x: tz.x, y: tz.y, w: tz.w, h: tz.h, z: i, fit: old.fit || 'contain', transition: old.transition || 'fade', timer: !!old.timer, radius: old.radius || 0 };
      });
      selected = 0;
      dirty = true;
      renderAll();
    });
    tplBox.appendChild(btn);
  });

  document.getElementById('zone-form').addEventListener('submit', function () {
    document.getElementById('zones_json').value = JSON.stringify(zones);
    dirty = false;
  });
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  renderAll();
})();

// Logs page: "Auto refresh" loads the list again every 10 seconds while the tab is visible (remembered in this browser).
(function () {
  'use strict';
  var box = document.getElementById('log-auto');
  var list = document.getElementById('log-list');
  if (!box || !list) return;
  var KEY = 'pipusher-log-auto';
  try { box.checked = localStorage.getItem(KEY) === '1'; } catch (e) { /* storage blocked */ }
  var busy = false;

  function refresh() {
    if (!box.checked || busy || document.hidden) return;
    busy = true;
    fetch(location.href, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('log-list');
        if (!fresh) return; // logged out: the login page came back
        list.innerHTML = fresh.innerHTML;
        // Entry counts in the level filter.
        var sel = document.querySelector('.log-filter select[name=level]');
        var nsel = doc.querySelector('.log-filter select[name=level]');
        if (sel && nsel) {
          for (var i = 0; i < sel.options.length && i < nsel.options.length; i++) sel.options[i].text = nsel.options[i].text;
        }
      })
      .catch(function () { /* server unreachable: try again next time */ })
      .then(function () { busy = false; });
  }

  box.addEventListener('change', function () {
    try { localStorage.setItem(KEY, box.checked ? '1' : '0'); } catch (e) { /* storage blocked */ }
    refresh();
  });
  document.addEventListener('visibilitychange', refresh);
  setInterval(refresh, 10000);
})();

// Playlist entries: "Only from time to time" shows its fields only when ticked.
(function () {
  document.querySelectorAll('[data-freq]').forEach(function (box) {
    var t = box.querySelector('[data-freq-toggle]'), f = box.querySelector('.freq-fields');
    t.addEventListener('change', function () {
      f.hidden = !t.checked;
      if (t.checked) f.querySelector('input').focus();
    });
  });
})();

// Playlist schedule: week and month calendar of the entries' rules (same rules as entry_active() and the player).
(function () {
  var S = window.PP_SCHEDULE, root = document.querySelector('[data-cal]');
  if (!S || !root) return;
  var title = document.querySelector('[data-cal-title]');
  var forced = document.documentElement.getAttribute('data-time-format');
  var hour12 = forced === '12' || (forced !== '24' && (function () {
    try {
      var o = new Intl.DateTimeFormat(undefined, { hour: 'numeric' }).resolvedOptions();
      return o.hourCycle ? o.hourCycle === 'h12' || o.hourCycle === 'h11' : !!o.hour12;
    } catch (e) { return false; }
  })());
  var view = 'week';
  try { view = localStorage.getItem('pipusher-cal-view') === 'month' ? 'month' : 'week'; } catch (e) { /* no storage */ }
  var p = S.today.split('-');
  var today = new Date(+p[0], +p[1] - 1, +p[2]);
  var cursor = new Date(today);
  var COLORS = 8;

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function addDays(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function mins(hm) { var a = hm.split(':'); return +a[0] * 60 + +a[1]; }
  function hourLabel(h) { return hour12 ? ((h % 12) || 12) + (h < 12 ? 'a' : 'p') : pad(h % 24) + ':00'; }
  function timeLabel(m) {
    var h = Math.floor(m / 60), mm = m % 60;
    if (!hour12) return pad(h) + ':' + pad(mm);
    return ((h % 12) || 12) + (mm ? ':' + pad(mm) : '') + (h < 12 || h === 24 ? 'am' : 'pm');
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmtDate(d, opts) { try { return d.toLocaleDateString(undefined, opts); } catch (e) { return ymd(d); } }

  // Does the entry play on this date (weekday and date range)?
  function onDay(e, d) {
    var iso = d.getDay() || 7, day = ymd(d);
    if (e.days.length && e.days.indexOf(iso) < 0) return false;
    if (e.dateFrom || e.dateTo) {
      if (e.yearly) {
        var md = day.slice(5), a = e.dateFrom ? e.dateFrom.slice(5) : '01-01', b = e.dateTo ? e.dateTo.slice(5) : '12-31';
        if (a <= b ? !(md >= a && md <= b) : !(md >= a || md <= b)) return false;
      } else if ((e.dateFrom && day < e.dateFrom) || (e.dateTo && day > e.dateTo)) {
        return false;
      }
    }
    return true;
  }
  // Minutes of the day it plays: [[from, to], …]. A window crossing midnight plays at both ends of each matching day.
  function segments(e) {
    if (!e.from && !e.to) return [[0, 1440]];
    var f = mins(e.from || '00:00'), t = e.to ? mins(e.to) : 1440;
    return f <= t ? (f < t ? [[f, t]] : []) : [[0, t], [f, 1440]];
  }
  function block(e, cls, style, label) {
    return '<a href="#entry-' + e.id + '" data-entry="' + e.id + '" class="cal-entry c' + ((e.num - 1) % COLORS) + (e.freq ? ' freq' : '') + ' ' + cls + '"'
      + ' style="' + style + '" title="' + esc('#' + e.num + ' ' + e.name + ' · ' + e.when + (e.freq ? ' · ' + e.freq : '')) + '">' + label + '</a>';
  }

  function week() {
    var start = addDays(cursor, -((cursor.getDay() || 7) - 1)), end = addDays(start, 6);
    title.textContent = fmtDate(start, { day: 'numeric', month: 'short' }) + ' – ' + fmtDate(end, { day: 'numeric', month: 'short', year: 'numeric' });
    var html = '<div class="cal-week"><div class="cal-hours"><div class="cal-dayhead"></div><div class="cal-col-body">';
    for (var h = 0; h < 24; h += 3) html += '<span style="top:' + (h / 24 * 100) + '%">' + hourLabel(h) + '</span>';
    html += '</div></div>';
    for (var i = 0; i < 7; i++) {
      var d = addDays(start, i), isToday = ymd(d) === S.today;
      var active = S.entries.filter(function (e) { return onDay(e, d) && segments(e).length; });
      // Cover the day minute by minute to find where nothing plays (the fallback).
      var covered = new Uint8Array(1440);
      active.forEach(function (e) { segments(e).forEach(function (s) { for (var m = s[0]; m < s[1]; m++) covered[m] = 1; }); });
      html += '<div class="cal-day' + (isToday ? ' today' : '') + '"><div class="cal-dayhead">' + esc(fmtDate(d, { weekday: 'short' })) + ' <b>' + d.getDate() + '</b></div><div class="cal-col-body">';
      for (var h2 = 3; h2 < 24; h2 += 3) html += '<i class="cal-line" style="top:' + (h2 / 24 * 100) + '%"></i>';
      for (var m = 0; m < 1440;) {
        if (covered[m]) { m++; continue; }
        var g = m;
        while (g < 1440 && !covered[g]) g++;
        html += '<div class="cal-fallback" style="top:' + (m / 14.4) + '%;height:' + ((g - m) / 14.4) + '%" title="' + esc('Fallback: ' + (S.fallback || 'blank screen')
          + ' · ' + timeLabel(m) + '–' + timeLabel(g)) + '"></div>';
        m = g;
      }
      var lanes = active.length;
      active.forEach(function (e, lane) {
        segments(e).forEach(function (s) {
          var style = 'top:' + (s[0] / 14.4) + '%;height:' + ((s[1] - s[0]) / 14.4) + '%;left:' + (lane / lanes * 100) + '%;width:' + (100 / lanes) + '%';
          var tall = s[1] - s[0] >= 90;
          html += block(e, '', style, '<b>' + e.num + '</b>' + (tall ? ' ' + esc(e.name) + '<small>' + timeLabel(s[0]) + '–' + timeLabel(s[1]) + '</small>' : ''));
        });
      });
      if (isToday) html += '<i class="cal-now" style="top:' + (S.now / 14.4) + '%"></i>';
      html += '</div></div>';
    }
    root.innerHTML = html + '</div>';
  }

  function month() {
    var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    title.textContent = fmtDate(first, { month: 'long', year: 'numeric' });
    var start = addDays(first, -((first.getDay() || 7) - 1));
    var html = '<div class="cal-month">';
    for (var w = 0; w < 7; w++) html += '<div class="cal-mhead">' + esc(fmtDate(addDays(start, w), { weekday: 'short' })) + '</div>';
    for (var i = 0; i < 42; i++) {
      var d = addDays(start, i);
      if (i >= 35 && d.getMonth() !== first.getMonth()) break;
      var active = S.entries.filter(function (e) { return onDay(e, d) && segments(e).length; });
      var full = active.some(function (e) { var s = segments(e); return s.length === 1 && s[0][0] === 0 && s[0][1] === 1440; });
      html += '<div class="cal-mday' + (d.getMonth() !== first.getMonth() ? ' other' : '') + (ymd(d) === S.today ? ' today' : '') + '">'
        + '<div class="cal-mnum">' + d.getDate() + '</div>';
      active.slice(0, 4).forEach(function (e) {
        var s = segments(e), part = !(s.length === 1 && s[0][1] - s[0][0] === 1440);
        html += block(e, 'chip-entry', '', '<b>' + e.num + '</b> ' + esc(e.name) + (part ? ' <small>' + esc((e.from || '00:00') + '–' + (e.to || '24:00')) + '</small>' : ''));
      });
      if (active.length > 4) html += '<span class="muted small">+' + (active.length - 4) + ' more</span>';
      if (!active.length) html += '<span class="cal-fb-label">' + esc(S.fallback || 'blank') + '</span>';
      else if (!full) html += '<span class="cal-fb-note" title="Some hours show the fallback">+ fallback</span>';
      html += '</div>';
    }
    root.innerHTML = html + '</div>';
  }

  function render() {
    document.querySelectorAll('[data-cal-view]').forEach(function (b) { b.classList.toggle('btn-primary', b.getAttribute('data-cal-view') === view); });
    (view === 'week' ? week : month)();
  }
  document.querySelectorAll('[data-cal-view]').forEach(function (b) {
    b.addEventListener('click', function () {
      view = b.getAttribute('data-cal-view');
      try { localStorage.setItem('pipusher-cal-view', view); } catch (e) { /* no storage */ }
      render();
    });
  });
  document.querySelectorAll('[data-cal-go]').forEach(function (b) {
    b.addEventListener('click', function () {
      var n = +b.getAttribute('data-cal-go');
      if (!n) cursor = new Date(today);
      else if (view === 'week') cursor = addDays(cursor, 7 * n);
      else cursor = new Date(cursor.getFullYear(), cursor.getMonth() + n, 1);
      render();
    });
  });
  // Clicking an entry opens it in the queue for editing.
  root.addEventListener('click', function (ev) {
    var a = ev.target.closest('[data-entry]');
    if (!a) return;
    ev.preventDefault();
    var det = document.getElementById('entry-' + a.getAttribute('data-entry'));
    if (det) {
      det.open = true;
      det.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
  render();
})();
