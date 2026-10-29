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
    if (o.mode === 'web') {
      apply(o.state);
      setTimeout(pollWeb, 5000);
      return;
    }
    poll();
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
        info: { screen: screen.width + 'x' + screen.height, playing_layout: ctx.playing }
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
    } else if (currentOk && ok.length > 1 && !this.timer && !this.auto) {
      // Was the only eligible entry; others are allowed now, so start rotating again.
      var d = this.seq[this.idx].duration;
      if (d > 0) this.timer = setTimeout(function () { self.next(); }, d * 1000);
      else this.startAuto();
    }
  };

  Sequencer.prototype.startAuto = function () {
    this.auto = true;
    this.base = this.shown ? this.shown.zones.map(function (z) { return z.passes; }) : [];
  };

  // A zone finished a pass through its content. Returns true when the sequencer moved on to another
  // layout, so the zone keeps its last item on screen while it fades out.
  Sequencer.prototype.zoneDone = function (zone) {
    if (!this.auto || this.stopped || !this.shown) return false;
    var zones = this.shown.zones;
    if (zones.indexOf(zone) < 0) return false;
    for (var i = 0; i < zones.length; i++) {
      if (zones[i].items.length && zones[i].passes <= (this.base[i] || 0)) return false;
    }
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
    var el = this.eligible();
    if (!el.length) {
      this.idx = -1;
      if (this.cfg.fallback && this.layouts[this.cfg.fallback]) this.show(this.cfg.fallback);
      else this.show(null);
      return;
    }
    var pick = el[0];
    for (var k = 0; k < el.length; k++) {
      if (el[k] > this.idx) { pick = el[k]; break; }
    }
    this.idx = pick;
    var e = this.seq[pick];
    this.show(e.layout);
    if (el.length > 1 && e.duration > 0) {
      this.timer = setTimeout(function () { self.next(); }, e.duration * 1000);
    } else if (el.length > 1) {
      this.startAuto();
    }
  };

  Sequencer.prototype.show = function (layoutId) {
    if (this.shown && this.shown.layoutId === layoutId) return; // already on screen
    var old = this.shown;
    if (layoutId === null) {
      var blank = el('div', 'pp-stage pp-layout pp-in', root);
      blank.style.inset = '0';
      this.shown = { layoutId: null, stage: blank, zones: [] };
    } else {
      var self = this;
      this.shown = buildLayout(this.layouts[layoutId], this.cfg.media || {}, function (z) { return self.zoneDone(z); });
      this.shown.layoutId = layoutId;
      report(this.layouts[layoutId]);
    }
    var stage = this.shown.stage;
    void stage.offsetWidth;
    stage.classList.add('pp-in');
    if (old) {
      old.stage.classList.add('pp-out');
      setTimeout(function () {
        old.zones.forEach(function (z) { z.stop(); });
        old.stage.remove();
      }, 900);
    }
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

  function buildLayout(L, media, onPass) {
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
      zones.push(new Zone(ze, zc, media, onPass));
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

  function makeNode(m, zc, it) {
    var node;
    if (m.type === 'image' && it.size) {
      return sizedImage(m, zc, it.size);
    } else if (m.type === 'image') {
      node = el('img');
      node.alt = '';
      node.style.objectFit = zc.fit || 'contain';
    } else if (m.type === 'video') {
      node = el('video');
      node.autoplay = true;
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
    if (m.type === 'video') {
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
    return node;
  }

  function Zone(elem, cfg, media, onPass) {
    this.el = elem;
    this.cfg = cfg;
    this.media = media;
    this.items = (cfg.items || []).filter(function (it) { return media[it.media]; });
    this.idx = -1;
    this.cur = null;
    this.timer = null;
    this.stopped = false;
    this.passes = 0;       // times the zone has played all its content
    this.onPass = onPass;
    this.next();
  }

  // Count a finished pass. True when the layout is moving on, so the zone should stay as it is.
  Zone.prototype.pass = function () {
    this.passes++;
    return !!(this.onPass && this.onPass(this));
  };

  Zone.prototype.stop = function () {
    this.stopped = true;
    clearTimeout(this.timer);
    if (this.cur && this.cur.pause) this.cur.pause();
  };

  Zone.prototype.playable = function (it) {
    // External URLs need the network; skip them while the device is offline.
    return this.media[it.media].type !== 'url' || ctx.online;
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
      if (this.cur) { this.cur.remove(); this.cur = null; }
      this.idx = -1;
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
    if (same && m.type !== 'video') {
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
    function reveal() {
      if (shown || self.stopped) return;
      shown = true;
      // Force a style flush so the transition runs from the initial state.
      void node.offsetWidth;
      node.classList.add('pp-in');
      if (prev) {
        prev.classList.add('pp-out');
        setTimeout(function () { prev.remove(); }, 900);
      }
    }
    if (m.type === 'video') {
      node.addEventListener('loadeddata', reveal);
      node.addEventListener('error', function () {
        reveal();
        clearTimeout(self.timer);
        self.timer = setTimeout(function () { self.next(); }, 2000);
      });
    } else {
      media.addEventListener('load', reveal);
      media.addEventListener('error', reveal);
    }
    setTimeout(reveal, 2500);
    this.schedule(it, m, node);
  };

  Zone.prototype.schedule = function (it, m, node) {
    var self = this;
    var single = this.playableCount() === 1 && this.items.length === 1;
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
      node.addEventListener('ended', function () { self.next(); });
      this.timer = setTimeout(function () { self.next(); }, 4 * 3600 * 1000); // safety net
      return;
    }
    this.timer = setTimeout(function () { self.next(); }, Math.max(1, it.duration || 10) * 1000);
  };

  window.PiPusher = { start: start };
})();
