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
      selForm.querySelector('[data-needs-selection]').disabled = n === 0;
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
      b.textContent = z.name;
      var hdl = document.createElement('div');
      hdl.className = 'handle';
      b.appendChild(hdl);
      b.addEventListener('pointerdown', function (e) { startDrag(e, i, e.target === hdl ? 'resize' : 'move'); });
      stage.appendChild(b);
    });
  }

  function renderRows() {
    rows.innerHTML = '';
    zones.forEach(function (z, i) {
      var tr = document.createElement('tr');
      if (i === selected) tr.className = 'selected';
      tr.innerHTML =
        '<td><input data-k="name" value="' + esc(z.name) + '"></td>' +
        ['x', 'y', 'w', 'h'].map(function (k) {
          return '<td><input data-k="' + k + '" type="number" step="0.5" min="0" max="100" value="' + z[k] + '"></td>';
        }).join('') +
        '<td><input data-k="z" type="number" step="1" value="' + (z.z || 0) + '"></td>' +
        '<td><select data-k="fit">' + options(cfg.fits, z.fit || 'contain') + '</select></td>' +
        '<td><select data-k="transition">' + options(cfg.transitions, z.transition || 'fade') + '</select></td>' +
        '<td><button type="button" class="btn btn-sm btn-danger" title="Remove zone">✕</button></td>';
      tr.addEventListener('focusin', function () { select(i, false); });
      tr.querySelectorAll('[data-k]').forEach(function (inp) {
        inp.addEventListener('input', function () {
          var k = inp.getAttribute('data-k');
          z[k] = (k === 'name' || k === 'fit' || k === 'transition') ? inp.value : Number(inp.value);
          if ('xywh'.indexOf(k) >= 0) normalise(z);
          dirty = true;
          renderStage();
        });
      });
      tr.querySelector('button').addEventListener('click', function () {
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
    else rows.querySelectorAll('tr').forEach(function (tr, j) { tr.className = j === i ? 'selected' : ''; });
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
    zones.push({ id: null, name: 'Zone ' + (zones.length + 1), x: 25, y: 25, w: 50, h: 50, z: maxZ + 1, fit: 'contain', transition: 'fade' });
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
        return { id: old.id || null, name: old.id ? old.name : tz.name, x: tz.x, y: tz.y, w: tz.w, h: tz.h, z: i, fit: old.fit || 'contain', transition: old.transition || 'fade' };
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
