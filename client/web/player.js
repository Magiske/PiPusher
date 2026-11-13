/*
 * PiPusher player – renders layouts (zones with content) full screen and runs playlists
 * (a queue of layouts with durations and weekday / time / date rules, checked on this clock).
 * Shared by the Raspberry Pi client (mode "device": polls the local agent for state),
 * the online web player (mode "web": play.php, polls the server, no offline cache)
 * and the server admin preview (mode "static": state is embedded in the page).
 */
(function () {
  'use strict';

  var ROTATION = { landscape: 0, portrait: 90, 'landscape-flipped': 180, 'portrait-flipped': 270 };
  var opts, root;
  var ctx = { online: true, version: null, playing: '', muted: false };
  var sequencer = null;
  var renderedKey = null;

  function el(tag, cls, parent) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (parent) parent.appendChild(e);
    return e;
  }

  function mediaUrl(m) {
    if (m.type === 'url') return m.url;
    return opts.mediaBase + m.id + '/' + String(m.entry).split('/').map(encodeURIComponent).join('/');
  }

  function start(o) {
    opts = o;
    root = document.getElementById('pp-root');
    if (o.mode === 'static') {
      apply(o.state);
      if (o.watchUrl) watch(o.state.version || null);
      return;
    }
    setInterval(applyMute, 2000); // also catches media that HTML content adds later
    setInterval(dailyReload, 20000);
    if (o.mode === 'web') {
      apply(o.state);
      setTimeout(pollWeb, 5000);
      return;
    }
    poll();
  }

  // Settings "reload the player every day at HH:MM" (config.reload_at, on this screen's clock): a fresh page once a
  // day in case content hangs. Checked every 20 s, so the minute is never missed; a page younger than 90 s does not
  // reload again. Only reloads when the page can be fetched, so an offline web screen keeps playing.
  var reloading = false;
  function dailyReload() {
    if (!ctx.reloadAt || reloading || (window.performance && performance.now() < 90000)) return;
    var now = new Date();
    var hm = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
    if (hm !== ctx.reloadAt) return;
    reloading = true;
    fetch(location.href, { method: 'HEAD', cache: 'no-store' })
      .then(function (r) { if (r.ok) location.reload(); else reloading = false; })
      .catch(function () { reloading = false; });
  }

  // Sound on/off for this screen (set in the admin panel). Covers the player's videos and media inside
  // same-origin HTML content; pages from other sites (URL media) cannot be reached. Only media this
  // function muted is unmuted again: videos muted because the browser blocked autoplay with sound stay muted.
  function setMuted(m) {
    m = !!m;
    if (m === ctx.muted) return;
    ctx.muted = m;
    applyMute();
  }

  function applyMute() {
    if (!root) return;
    muteIn(root);
    var frames = root.getElementsByTagName('iframe');
    for (var i = 0; i < frames.length; i++) {
      try {
        if (frames[i].contentDocument) muteIn(frames[i].contentDocument);
      } catch (e) { /* other site */ }
    }
  }

  function muteIn(doc) {
    var list = doc.querySelectorAll('video, audio');
    for (var i = 0; i < list.length; i++) {
      var m = list[i];
      if (ctx.muted && !m.muted) {
        m.muted = true;
        m.ppMuted = true;
      } else if (!ctx.muted && m.ppMuted) {
        m.muted = false;
        m.ppMuted = false;
      }
    }
  }

  function poll() {
    fetch(opts.stateUrl, { cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(apply)
      .catch(function () {
        if (renderedKey === null) showMessage({ state: 'starting' });
      })
      .then(function () { setTimeout(poll, opts.pollMs || 5000); });
  }

  // Web mode: report to the server and pick up new content and commands (state carries config only when it changed).
  function pollWeb() {
    fetch(opts.stateUrl, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        version: ctx.version,
        info: { screen: screen.width + 'x' + screen.height, playing_layout: ctx.playing,
          player_age_s: window.performance ? Math.round(performance.now() / 1000) : null }
      })
    })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        if (s.poll_interval) opts.pollMs = s.poll_interval * 1000;
        setMuted(s.muted);
        if ((s.commands || []).indexOf('reload') >= 0) {
          location.reload();
          return;
        }
        if (s.state === 'ready' && !s.config) return; // unchanged
        apply(s);
      })
      .catch(function () {}) // server unreachable: keep showing what is loaded
      .then(function () { setTimeout(pollWeb, opts.pollMs || 60000); });
  }

  // Static (preview) mode: reload when the server reports a different content version.
  function watch(version) {
    setTimeout(function () {
      fetch(opts.watchUrl, { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.version !== version) location.reload();
          else watch(version);
        })
        .catch(function () { watch(version); });
    }, opts.pollMs || 5000);
  }

  function apply(s) {
    if (opts.mode !== 'static') setMuted(s.muted);
    ctx.online = !!s.online;
    ctx.snapshots = s.snapshots || {};
    ctx.version = s.state === 'ready' ? s.version || null : null;
    var key = s.state + '|' + (s.version || '') + '|' + (s.reload || 0);
    if (s.state !== 'ready') key += '|' + [s.code, s.name, s.message, s.ip].join('|');
    if (key === renderedKey) return;
    // Leaving rendered content: reload the page for a clean slate (frees video/iframe memory).
    if (renderedKey !== null && renderedKey.indexOf('ready|') === 0 && opts.mode !== 'static') {
      location.reload();
      return;
    }
    renderedKey = key;
    if (sequencer) sequencer.stop();
    sequencer = null;
    root.innerHTML = '';
    if (s.state !== 'ready' || !s.config) {
      setFrame(null);
      showMessage(s);
      return;
    }
    setFrame(s.config.screen);
    ctx.reloadAt = s.config.reload_at || null;
    sequencer = new Sequencer(normalise(s.config));
  }

  // Screen alignment (admin panel): the player's area on the display, x / y / w / h in %. Layouts fill this area.
  function setFrame(f) {
    var st = root.style;
    if (!f) {
      ['left', 'top', 'right', 'bottom', 'width', 'height', '--pp-w', '--pp-h'].forEach(function (p) { st.removeProperty(p); });
      return;
    }
    st.left = f.x + '%';
    st.top = f.y + '%';
    st.right = 'auto';
    st.bottom = 'auto';
    st.width = f.w + '%';
    st.height = f.h + '%';
    st.setProperty('--pp-w', 'calc(100vw * ' + f.w / 100 + ')');
    st.setProperty('--pp-h', 'calc(100vh * ' + f.h / 100 + ')');
  }

  // Older configurations contained a single "layout"; turn them into a one-entry sequence.
  function normalise(cfg) {
    if (cfg.layouts) return cfg;
    return { layouts: [cfg.layout], sequence: [{ layout: cfg.layout.id, duration: 0 }], fallback: null, media: cfg.media };
  }

  // ------------------------------------------------------------------ playlist rules

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function allowed(e, now) {
    if (e.days && e.days.length) {
      var iso = now.getDay() === 0 ? 7 : now.getDay(); // 1 = Monday … 7 = Sunday
      if (e.days.indexOf(iso) < 0) return false;
    }
    if (e.time_from || e.time_to) {
      var t = pad(now.getHours()) + ':' + pad(now.getMinutes());
      var from = e.time_from || '00:00';
      var to = e.time_to || '24:00';
      if (from <= to ? !(t >= from && t < to) : !(t >= from || t < to)) return false; // may cross midnight
    }
    if (e.date_from || e.date_to) {
      var ymd = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
      if (e.yearly) {
        var md = ymd.slice(5);
        var a = e.date_from ? e.date_from.slice(5) : '01-01';
        var b = e.date_to ? e.date_to.slice(5) : '12-31';
        if (a <= b ? !(md >= a && md <= b) : !(md >= a || md <= b)) return false; // may cross new year
      } else {
        if (e.date_from && ymd < e.date_from) return false;
        if (e.date_to && ymd > e.date_to) return false;
      }
    }
    return true;
  }

  // ------------------------------------------------------------------ sequencer (layout queue)

  function Sequencer(cfg) {
    var self = this;
    this.cfg = cfg;
    this.layouts = {};
    cfg.layouts.forEach(function (l) { self.layouts[l.id] = l; });
    this.seq = (cfg.sequence || []).filter(function (e) { return self.layouts[e.layout]; });
    this.idx = -1;         // sequence index on screen, -1 = fallback / nothing
    this.shown = null;     // { layoutId, stage, zones }
    this.timer = null;
    this.auto = false;     // entry without a duration: move on once every zone has played its content once
    this.base = [];        // zone pass counts when the entry started
    // Per entry, for its frequency: turns it had, its share of turns built up, when it last played.
    this.turns = this.seq.map(function () { return { count: 0, credit: 0, last: Date.now() }; });
    this.stopped = false;
    this.next();
    // Re-check the rules regularly so time windows start and end on time.
    this.ticker = setInterval(function () { self.check(); }, 15000);
  }

  Sequencer.prototype.stop = function () {
    this.stopped = true;
    clearTimeout(this.timer);
    clearInterval(this.ticker);
    if (this.shown) this.shown.zones.forEach(function (z) { z.stop(); });
  };

  Sequencer.prototype.eligible = function () {
    var now = new Date();
    var out = [];
    this.seq.forEach(function (e, i) { if (allowed(e, now)) out.push(i); });
    return out;
  };

  Sequencer.prototype.check = function () {
    var self = this;
    var ok = this.eligible();
    var currentOk = this.idx >= 0 && ok.indexOf(this.idx) >= 0;
    // Switch when the current entry's window ended, or something became eligible while idle/fallback.
    if (!currentOk && (this.idx >= 0 || ok.length)) {
      this.next();
    } else if (currentOk && ok.length > 1 && !this.timer && !this.auto && this.shown && this.shown.visible) {
      // Was the only eligible entry; others are allowed now, so start rotating again.
      var d = this.seq[this.idx].duration;
      if (d > 0) this.timer = setTimeout(function () { self.next(); }, d * 1000);
      else this.startAuto();
    }
  };

  Sequencer.prototype.startAuto = function () {
    var self = this;
    this.auto = true;
    this.base = this.shown ? this.shown.zones.map(function (z) { return z.passes; }) : [];
    // Every zone already stopped (an item set to fade out, exit or hold): its content has ended.
    var ended = this.shown && this.shown.zones.some(function (z) { return z.ended; });
    if (ended && this.allDone()) this.timer = setTimeout(function () { self.next(); }, 1000);
  };

  // Each zone with content has played it once since the entry started, or has stopped for good.
  Sequencer.prototype.allDone = function () {
    var zones = this.shown.zones;
    for (var i = 0; i < zones.length; i++) {
      if (zones[i].items.length && !zones[i].ended && zones[i].passes <= (this.base[i] || 0)) return false;
    }
    return true;
  };

  // A zone finished a pass through its content. Returns true when the sequencer moved on to another
  // layout, so the zone keeps its last item on screen while it fades out.
  Sequencer.prototype.zoneDone = function (zone) {
    if (!this.auto || this.stopped || !this.shown) return false;
    if (this.shown.zones.indexOf(zone) < 0 || !this.allDone()) return false;
    var before = this.shown;
    this.next();
    return this.shown !== before;
  };

  Sequencer.prototype.next = function () {
    var self = this;
    clearTimeout(this.timer);
    this.timer = null;
    this.auto = false;
    if (this.stopped) return;
    if (this.idx >= 0) this.turns[this.idx].last = Date.now();
    var el = this.eligible();
    // The next allowed entry after the current one (wrapping round) whose frequency gives it this turn.
    var order = el.filter(function (i) { return i > self.idx; }).concat(el.filter(function (i) { return i <= self.idx; }));
    var pick = -1;
    for (var k = 0; k < order.length && pick < 0; k++) {
      if (this.due(order[k])) pick = order[k];
    }
    if (pick < 0) {
      this.idx = -1;
      if (this.cfg.fallback && this.layouts[this.cfg.fallback]) this.show(this.cfg.fallback);
      else this.show(null);
      // Nothing has its turn yet: try again as soon as a timed entry is due (the ticker covers the rest).
      var wait = Infinity;
      el.forEach(function (i) {
        var f = self.seq[i].frequency;
        if (f && f.t) wait = Math.min(wait, self.turns[i].last + f.t * 1000 - Date.now());
      });
      if (wait < 15000) this.timer = setTimeout(function () { self.next(); }, Math.max(wait, 0) + 50);
      return;
    }
    this.idx = pick;
    var e = this.seq[pick];
    this.show(e.layout);
    if (el.length < 2 && !e.frequency) return;
    // The entry's time starts once its layout is on screen, not while it loads.
    var shown = this.shown;
    whenVisible(shown, function () {
      if (self.stopped || self.shown !== shown) return;
      if (e.duration > 0) self.timer = setTimeout(function () { self.next(); }, e.duration * 1000);
      else self.startAuto();
    });
  };

  // Whether an entry plays on this turn. Frequency { t: seconds } = once that long has passed since it last played
  // (or since the start); { n: 10 } = every 10th turn; { p: 50 } = that share of its turns, spread evenly.
  Sequencer.prototype.due = function (i) {
    var f = this.seq[i].frequency, s = this.turns[i];
    if (!f) return true;
    if (f.t) return Date.now() - s.last >= f.t * 1000;
    s.count++;
    if (f.n) return s.count % f.n === 0;
    if (f.p) {
      s.credit += f.p / 100;
      if (s.credit < 0.999999) return false;
      s.credit -= 1;
    }
    return true;
  };

  function whenVisible(shown, fn) {
    if (shown.visible) fn();
    else shown.onVisible = fn;
  }

  Sequencer.prototype.show = function (layoutId) {
    if (this.shown && this.shown.layoutId === layoutId) return; // already on screen
    var old = this.shown;
    if (old && !old.visible) {
      // Replaced while still loading: drop it, the layout before it is still on screen.
      clearTimeout(old.limit);
      old.zones.forEach(function (z) { z.stop(); });
      discard(old.stage);
      old = old.prev;
    }
    var layoutReady = null;
    if (layoutId === null) {
      var blank = el('div', 'pp-stage pp-layout', root);
      blank.style.inset = '0';
      this.shown = { layoutId: null, stage: blank, zones: [] };
    } else {
      var self = this;
      this.shown = buildLayout(this.layouts[layoutId], this.cfg.media || {}, function (z) { return self.zoneDone(z); },
        function () { if (layoutReady) layoutReady(); });
      this.shown.layoutId = layoutId;
      report(this.layouts[layoutId]);
    }
    var shown = this.shown;
    shown.prev = old;
    shown.visible = false;
    // The new layout fades in over the old one once every zone shows its first item (or after the load limit).
    function swap() {
      if (shown.visible) return;
      shown.visible = true;
      shown.prev = null;
      clearTimeout(shown.limit);
      void shown.stage.offsetWidth;
      shown.stage.classList.add('pp-in');
      if (shown.onVisible) shown.onVisible();
      if (old) {
        old.stage.classList.add('pp-out');
        setTimeout(function () {
          old.zones.forEach(function (z) { z.stop(); });
          discard(old.stage);
        }, 900);
      }
    }
    layoutReady = function () {
      if (shown.zones.every(function (z) { return z.ready; })) swap();
    };
    shown.limit = setTimeout(swap, LOAD_LIMIT);
    layoutReady();
  };

  // Tell the local agent which layout is on screen (status + upright screenshots).
  function report(layout) {
    ctx.playing = layout.name;
    if (opts.mode !== 'device') return;
    fetch('/api/playing', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: layout.id, name: layout.name, orientation: layout.orientation })
    }).catch(function () {});
  }

  function buildLayout(L, media, onPass, onReady) {
    var rot = ROTATION[L.orientation] || 0;
    var stage = el('div', 'pp-stage pp-layout', root);
    stage.style.background = L.background || '#000';
    stage.style.width = rot % 180 ? 'var(--pp-h, 100vh)' : 'var(--pp-w, 100vw)';
    stage.style.height = rot % 180 ? 'var(--pp-w, 100vw)' : 'var(--pp-h, 100vh)';
    stage.style.transform = 'translate(-50%, -50%) rotate(' + rot + 'deg)';
    var zones = [];
    L.zones.slice().sort(function (a, b) { return a.z - b.z; }).forEach(function (zc) {
      var ze = el('div', 'pp-zone', stage);
      ze.style.left = zc.x + '%';
      ze.style.top = zc.y + '%';
      ze.style.width = zc.w + '%';
      ze.style.height = zc.h + '%';
      ze.style.zIndex = zc.z;
      // Rounded corners: radius in pixels of a 1080p screen, scaled to this screen (the shorter side of the area).
      if (zc.radius > 0) ze.style.borderRadius = 'calc(min(var(--pp-w, 100vw), var(--pp-h, 100vh)) * ' + zc.radius / 1080 + ')';
      zones.push(new Zone(ze, zc, media, onPass, onReady));
    });
    return { stage: stage, zones: zones };
  }

  function showMessage(s) {
    root.innerHTML = '';
    var box = el('div', 'pp-message', root);
    el('div', 'pp-logo', box).textContent = 'PiPusher';
    var title = el('h1', null, box);
    var text = el('p', null, box);
    switch (s.state) {
      case 'pending':
        title.textContent = 'Waiting for approval';
        text.textContent = 'Approve this screen in the PiPusher admin panel. Device code:';
        el('div', 'pp-code', box).textContent = s.code || '------';
        break;
      case 'nolayout':
        title.textContent = 'Nothing to show';
        text.textContent = 'Assign a layout or playlist to this screen in the PiPusher admin panel.';
        break;
      case 'removed':
        title.textContent = 'Screen removed';
        text.textContent = 'This web screen was removed in the PiPusher admin panel. Ask your administrator for a new link.';
        break;
      case 'waiting':
        title.textContent = 'Connecting to server…';
        text.textContent = s.message || 'No content has been downloaded yet.';
        break;
      default:
        title.textContent = 'Starting…';
        text.textContent = '';
    }
    var details = [];
    if (s.name) details.push('Name: ' + s.name);
    if (s.code && s.state !== 'pending') details.push('Code: ' + s.code);
    if (s.server) details.push('Server: ' + s.server);
    if (s.ip) details.push('IP: ' + s.ip);
    if (s.agent_version) details.push('Client v' + s.agent_version);
    if (details.length) el('div', 'pp-details', box).textContent = details.join('   ·   ');
  }

  // ------------------------------------------------------------------ zones (content rotation)

  // Picture with its own size ("50%" of its own size, "800x600" / "800x" / "x600" pixels), centred in the zone.
  function sizedImage(m, zc, size) {
    var box = el('div');
    box.style.display = 'flex';
    box.style.alignItems = 'center';
    box.style.justifyContent = 'center';
    var img = el('img', null, box);
    img.alt = '';
    img.style.flex = 'none';
    img.style.maxWidth = 'none';
    var pct = /^(\d+)%$/.exec(size);
    var px = /^(\d*)x(\d*)$/.exec(size);
    if (pct) {
      img.style.visibility = 'hidden';
      img.addEventListener('load', function () {
        img.style.width = Math.round(img.naturalWidth * pct[1] / 100) + 'px';
        img.style.visibility = '';
      });
    } else if (px) {
      img.style.width = px[1] ? px[1] + 'px' : 'auto';
      img.style.height = px[2] ? px[2] + 'px' : 'auto';
      img.style.objectFit = zc.fit || 'contain';
    }
    img.src = mediaUrl(m);
    box.ppMedia = img; // load / error events come from the picture
    return box;
  }

  // Offline picture of an external page (URL media), taken by the local agent while online. Device mode only.
  function hasSnapshot(m) {
    return opts.mode === 'device' && m.type === 'url' && !!(ctx.snapshots || {})[m.id];
  }

  // Asks the agent to photograph the zone showing an external page once it has loaded (the agent keeps at most
  // one per page per 10 minutes). The zone must still show the page, uncovered by a transition.
  function requestSnapshot(zone, node, m, delay) {
    if (opts.mode !== 'device' || !ctx.online) return;
    setTimeout(function () {
      if (zone.stopped || zone.cur !== node || !ctx.online || !node.isConnected) return;
      var r = node.getBoundingClientRect();
      if (r.width < 10 || r.height < 10) return;
      fetch('/api/snapshot', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ media: m.id, area: [r.left, r.top, r.width, r.height] })
      }).catch(function () {});
    }, delay);
  }

  // Removes an item or layout and frees what it holds at once. A removed video keeps its hardware decoder until
  // garbage collection, and TVs (and the Pi) have only a few: the next videos then cannot start.
  function discard(node) {
    if (!node) return;
    var held = /^(VIDEO|IFRAME)$/.test(node.tagName) ? [node] : [];
    held = held.concat(Array.prototype.slice.call(node.querySelectorAll('video, iframe')));
    held.forEach(function (m) {
      try {
        if (m.tagName === 'VIDEO') {
          m.pause();
          m.removeAttribute('src');
          m.load();
        } else {
          m.src = 'about:blank';
        }
      } catch (e) { /* already gone */ }
    });
    node.remove();
  }

  // A video without a picture yet shows nothing instead of the browser's play symbol.
  var NO_POSTER = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

  function makeNode(m, zc, it) {
    var node;
    if (m.type === 'url' && !ctx.online && hasSnapshot(m)) {
      node = el('img');
      node.alt = '';
      node.style.objectFit = 'contain';
      node.ppSnapshot = true;
      node.src = '/snapshot/' + m.id + '.jpg?t=' + ctx.snapshots[m.id];
      return node;
    }
    if (m.type === 'image' && it.size) {
      return sizedImage(m, zc, it.size);
    } else if (m.type === 'image') {
      node = el('img');
      node.alt = '';
      node.style.objectFit = zc.fit || 'contain';
    } else if (m.type === 'video') {
      node = el('video');
      node.preload = 'auto'; // loaded in full before it is shown, then started by playVideo()
      node.poster = NO_POSTER;
      node.playsInline = true;
      node.muted = opts.mode === 'static' || ctx.muted;
      node.ppMuted = opts.mode !== 'static' && ctx.muted;
      node.style.objectFit = zc.fit || 'contain';
    } else {
      node = el('iframe');
      node.setAttribute('allow', 'autoplay; fullscreen; encrypted-media');
      node.setAttribute('scrolling', 'no');
      node.addEventListener('load', applyMute);
    }
    node.src = mediaUrl(m);
    return node;
  }

  function playVideo(node) {
    var p = node.play && node.play();
    if (p && p.catch) {
      // Autoplay with sound blocked by the browser (web screens without a click): play muted instead.
      p.catch(function (e) {
        if (e && e.name === 'NotAllowedError' && !node.muted) {
          node.muted = true;
          node.play().catch(function () {});
        }
      });
    }
  }

  // How long an item (or a layout's first items) may take to load before it is shown anyway.
  var LOAD_LIMIT = 15000;

  function Zone(elem, cfg, media, onPass, onReady) {
    this.el = elem;
    this.cfg = cfg;
    this.media = media;
    this.items = (cfg.items || []).filter(function (it) { return media[it.media]; });
    this.idx = -1;
    this.cur = null;
    this.timer = null;
    this.stopped = false;
    this.passes = 0;       // times the zone has played all its content
    this.ended = false;    // stopped after an item set to fade out, exit or hold
    this.onPass = onPass;
    this.onReady = onReady;
    this.ready = false;    // shows its first item (or has nothing to show)
    // Optional progress bar along the bottom of the zone, filling up over each item's duration.
    this.bar = cfg.timer ? el('div', 'pp-timer', elem) : null;
    this.next();
  }

  // Count a finished pass. True when the layout is moving on, so the zone should stay as it is.
  Zone.prototype.pass = function () {
    this.passes++;
    return !!(this.onPass && this.onPass(this));
  };

  Zone.prototype.markReady = function () {
    if (this.ready) return;
    this.ready = true;
    if (this.onReady) this.onReady();
  };

  Zone.prototype.stop = function () {
    this.stopped = true;
    clearTimeout(this.timer);
    this.runBar(0);
    if (this.cur && this.cur.pause) this.cur.pause();
  };

  Zone.prototype.playable = function (it) {
    // External URLs need the network; while offline they show their snapshot, or are skipped without one.
    var m = this.media[it.media];
    return m.type !== 'url' || ctx.online || hasSnapshot(m);
  };

  Zone.prototype.playableCount = function () {
    var self = this;
    return this.items.filter(function (it) { return self.playable(it); }).length;
  };

  Zone.prototype.next = function () {
    var self = this;
    clearTimeout(this.timer);
    if (this.stopped) return;
    var n = this.items.length;
    var pick = -1;
    for (var k = 1; k <= n; k++) {
      var j = (this.idx + k + n) % n;
      if (this.playable(this.items[j])) { pick = j; break; }
    }
    if (pick < 0) {
      if (this.cur) { discard(this.cur); this.cur = null; }
      this.idx = -1;
      this.markReady();
      if (this.pass()) return; // nothing playable counts as done
      this.timer = setTimeout(function () { self.next(); }, 5000);
      return;
    }
    // Back at (or before) the item just shown: all content has played once.
    if (this.idx >= 0 && pick <= this.idx && this.pass()) return;
    var it = this.items[pick];
    var m = this.media[it.media];
    var same = pick === this.idx && this.cur;
    this.idx = pick;
    // A page shown live while the network went away (or its snapshot shown while it came back) is replaced.
    var swap = m.type === 'url' && !!(this.cur && this.cur.ppSnapshot) === ctx.online;
    if (same && m.type !== 'video' && !swap) {
      this.schedule(it, m, this.cur); // single item: keep it on screen, no reload
      return;
    }
    this.show(it, m);
  };

  Zone.prototype.show = function (it, m) {
    var self = this;
    var node = makeNode(m, this.cfg, it);
    var media = node.ppMedia || node;
    node.className = 'pp-item pp-' + (this.cfg.transition || 'fade');
    this.el.appendChild(node);
    var prev = this.cur;
    this.cur = node;
    var shown = false;
    // The previous item stays on screen until this one has loaded (a picture decoded, a video able to play
    // through, a page loaded), so nothing appears half-drawn. Its duration and timer bar start when it is shown.
    function reveal() {
      if (shown || self.stopped || self.cur !== node) return;
      shown = true;
      clearTimeout(limit);
      // Force a style flush so the transition runs from the initial state.
      void node.offsetWidth;
      node.classList.add('pp-in');
      if (prev) {
        prev.classList.add('pp-out');
        if (prev.pause) prev.pause();
        setTimeout(function () { discard(prev); }, 900);
      }
      if (m.type === 'video') playVideo(node);
      self.schedule(it, m, node);
      self.markReady();
    }
    var limit = setTimeout(reveal, LOAD_LIMIT);
    if (m.type === 'video') {
      node.addEventListener('canplaythrough', reveal);
      node.addEventListener('error', function () {
        reveal();
        clearTimeout(self.timer);
        self.timer = setTimeout(function () { self.next(); }, 2000);
      });
    } else if (media.tagName === 'IMG') {
      media.addEventListener('load', function () {
        if (media.decode) media.decode().then(reveal, reveal);
        else reveal();
      });
      media.addEventListener('error', reveal);
    } else {
      media.addEventListener('load', reveal);
      media.addEventListener('error', reveal);
    }
  };

  // The item's duration ran out: go on (Repeat), or stop the zone with the item faded out, cut away or held.
  Zone.prototype.finish = function (it) {
    var after = it.after || 'repeat';
    if (after === 'repeat' || this.stopped) return this.next();
    clearTimeout(this.timer);
    this.runBar(0);
    this.ended = true;
    var node = this.cur;
    if (node && node.pause) node.pause(); // a held video stops on its frame
    if (node && after !== 'hold') {
      this.cur = null;
      if (after === 'fadeout') {
        node.classList.add('pp-fadeout');
        setTimeout(function () { discard(node); }, 1200);
      } else {
        discard(node);
      }
    }
    this.pass();
  };

  Zone.prototype.schedule = function (it, m, node) {
    var self = this;
    // Items with an automatic duration (a video played to the end) have no timer bar.
    this.runBar(it.duration > 0 ? it.duration : 0);
    var single = this.playableCount() === 1 && this.items.length === 1 && (it.after || 'repeat') === 'repeat';
    if (m.type === 'url' && !node.ppSnapshot) {
      // Photograph the live page shortly before it moves on (at most 10 s after it appeared).
      requestSnapshot(this, node, m, Math.min(10000, Math.max(3000, (it.duration || 10) * 1000 - 2000)));
    }
    if (m.type === 'video' && !it.duration) {
      if (single) {
        node.loop = true;
        // A looping video fires no "ended"; a jump back to the start is the end of a pass.
        var last = 0;
        node.addEventListener('timeupdate', function () {
          if (node.currentTime + 0.5 < last && self.pass()) node.pause();
          last = node.currentTime;
        });
        return;
      }
      node.addEventListener('ended', function () { if (self.cur === node) self.finish(it); });
      this.timer = setTimeout(function () { self.finish(it); }, 4 * 3600 * 1000); // safety net
      return;
    }
    this.timer = setTimeout(function () { self.finish(it); }, Math.max(1, it.duration || 10) * 1000);
  };

  // Restarts the timer bar to fill over the given seconds; 0 hides it.
  Zone.prototype.runBar = function (seconds) {
    var bar = this.bar;
    if (!bar) return;
    bar.style.transition = 'none';
    bar.style.transform = 'scaleX(0)';
    bar.style.visibility = seconds > 0 ? 'visible' : 'hidden';
    if (!(seconds > 0)) return;
    void bar.offsetWidth; // start from empty
    bar.style.transition = 'transform ' + seconds + 's linear';
    bar.style.transform = 'scaleX(1)';
  };

  window.PiPusher = { start: start };
})();
