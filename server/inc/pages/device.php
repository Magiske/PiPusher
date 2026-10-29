<?php
$id = (int)($_GET['id'] ?? 0);
$device = q1('SELECT * FROM devices WHERE id = ?', [$id]);
if (!$device) {
    flash('Screen not found.', 'error');
    redirect(url());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        [$layout, $playlist] = parse_content_ref($_POST['content'] ?? '');
        $status = ($_POST['status'] ?? '') === 'approved' ? 'approved' : 'pending';
        $group = (int)($_POST['group_id'] ?? 0);
        $group = isset(screen_groups()[$group]) ? $group : null;
        $useGroup = $group && ($_POST['source'] ?? 'group') === 'group' ? 1 : 0;
        x('UPDATE devices SET name = ?, layout_id = ?, playlist_id = ?, group_id = ?, use_group_content = ?, status = ? WHERE id = ?',
            [trim((string)$_POST['name']) ?: $device['name'], $layout, $playlist, $group, $useGroup, $status, $id]);
        flash('Screen saved. It picks up the change on its next sync.');
    } elseif ($do === 'command' && isset(PP_COMMANDS[$_POST['command'] ?? '']) && (!is_web_screen($device) || $_POST['command'] === 'reload')) {
        queue_command($id, $_POST['command']);
        if ($_POST['command'] !== 'screenshot') {
            flash('"' . PP_COMMANDS[$_POST['command']] . '" queued; it runs on the next sync.');
        }
    } elseif ($do === 'frame') {
        $num = fn($k, $def, $min, $max) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? $def : max($min, min($max, round((float)str_replace(',', '.', $v), 2)));
        $frame = ($_POST['reset'] ?? '') === '1' ? [0, 0, 100, 100]
            : [$num('frame_x', 0, -100, 100), $num('frame_y', 0, -100, 100), $num('frame_w', 100, 10, 200), $num('frame_h', 100, 10, 200)];
        x('UPDATE devices SET frame_x = ?, frame_y = ?, frame_w = ?, frame_h = ? WHERE id = ?', [...$frame, $id]);
        flash('Screen alignment saved; the screen picks it up on its next sync.');
    } elseif ($do === 'mute') {
        $muted = ($_POST['muted'] ?? '') === '1' ? 1 : 0;
        x('UPDATE devices SET muted = ? WHERE id = ?', [$muted, $id]);
        flash($muted ? 'Sound muted; the screen goes quiet on its next sync.' : 'Sound on; the screen plays sound again from its next sync.');
    } elseif ($do === 'new_link' && is_web_screen($device)) {
        x('UPDATE devices SET web_token = ? WHERE id = ?', [random_token(16), $id]);
        flash('New link created. The old link no longer works; open the new one on the screen.');
    } elseif ($do === 'delete') {
        x('DELETE FROM devices WHERE id = ?', [$id]);
        @unlink(screenshot_path($id));
        flash(is_web_screen($device) ? 'Web screen removed. Its link no longer works.'
            : 'Screen removed. If it is still running it registers again as a new pending screen.');
        redirect(url());
    }
    redirect(url(['p' => 'device', 'id' => $id]));
}

$info = json_decode((string)$device['info'], true) ?: [];
$pending = array_filter(explode(',', (string)$device['command']));
$hasShot = $device['screenshot_at'] && is_file(screenshot_path($id));
$web = is_web_screen($device);
// Browsers can only be told to reload; reboot, screenshots and sync are Pi agent features.
$commands = $web ? ['reload' => PP_COMMANDS['reload']] : PP_COMMANDS;
page_header($device['name'], 'devices');
?>
<div class="page-head" data-device="<?= $id ?>" data-status="<?= h($device['status']) ?>">
  <h1><span class="dot <?= device_online($device) ? 'dot-on' : 'dot-off' ?>"></span> <?= h($device['name']) ?></h1>
  <?php [$effLayout, $effPlaylist, $effSource] = effective_content($device); if ($effLayout || $effPlaylist): ?>
    <a class="btn" target="_blank" href="preview.php?device=<?= $id ?>">Preview</a>
  <?php endif; ?>
</div>

<?php if ($web): ?>
<div class="card">
  <div class="page-head">
    <h2>🌐 Web player link</h2>
    <?= post_button(['do' => 'new_link'], 'New link', 'btn btn-sm', 'Create a new link? The current link stops working.') ?>
  </div>
  <p>Open this link in a browser on the screen (full screen: F11 or the browser's kiosk mode). No login needed.</p>
  <pre><a href="<?= h(web_screen_url($device)) ?>" target="_blank"><?= h(web_screen_url($device)) ?></a></pre>
  <p class="muted small">Keep the link private: anyone with it can see this screen's content. Plays straight from the server, so the
    browser needs the network all the time. Content changes and the reload command arrive within <?= poll_interval() ?> s.</p>
</div>
<?php else: ?>
<div class="card">
  <div class="page-head">
    <h2>Screenshot</h2>
    <?= post_button(['do' => 'command', 'command' => 'screenshot'], in_array('screenshot', $pending, true) ? 'Screenshot requested…' : 'Take screenshot now', 'btn btn-sm') ?>
  </div>
  <div class="screenshot" id="screenshot" data-id="<?= $id ?>" data-at="<?= (int)$device['screenshot_at'] ?>"
       data-waiting="<?= in_array('screenshot', $pending, true) ? '1' : '0' ?>">
    <?php if ($hasShot): ?>
      <a href="screenshot.php?id=<?= $id ?>&amp;t=<?= (int)$device['screenshot_at'] ?>" target="_blank"><img src="screenshot.php?id=<?= $id ?>&amp;t=<?= (int)$device['screenshot_at'] ?>" alt="Screenshot of <?= h($device['name']) ?>"></a>
    <?php else: ?>
      <div class="screenshot-empty muted">No screenshot yet</div>
    <?php endif; ?>
  </div>
  <p class="muted small" id="screenshot-info">
    <?= $hasShot ? 'Taken ' . h(date('Y-m-d H:i:s', (int)$device['screenshot_at'])) . ' (' . h(time_ago((int)$device['screenshot_at'])) . ')' : '' ?>
    <?= in_array('screenshot', $pending, true) ? ' · New screenshot requested; it arrives after the next sync (within ' . poll_interval() . ' s).' : '' ?>
    <?php if (!empty($info['screenshot_error'])): ?><br><span class="text-error">Last screenshot failed: <?= h($info['screenshot_error']) ?></span><?php endif; ?>
  </p>
</div>
<?php endif; ?>

<div class="grid-2">
  <div class="card">
    <h2>Settings</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="save">
      <label>Name <input name="name" value="<?= h($device['name']) ?>" maxlength="80" required></label>
      <label>Group <select name="group_id" data-group-select><?= select_options(['' => '— no group —'] + array_map(fn($g) => $g['name'], screen_groups()), $device['group_id'] ?? '') ?></select></label>
      <fieldset class="access" data-source-box <?= $device['group_id'] ? '' : 'hidden' ?>>
        <legend>Content</legend>
        <label class="check"><input type="radio" name="source" value="group" <?= (int)$device['use_group_content'] === 1 ? 'checked' : '' ?>> Follow the group
          <?php if ($device['group_id'] && isset(screen_groups()[(int)$device['group_id']])): $g = screen_groups()[(int)$device['group_id']]; ?>
            <span class="muted small">(<?= content_label($g['layout_id'] ? (int)$g['layout_id'] : null, $g['playlist_id'] ? (int)$g['playlist_id'] : null) ?>)</span>
          <?php endif; ?></label>
        <label class="check"><input type="radio" name="source" value="own" <?= (int)$device['use_group_content'] === 1 ? '' : 'checked' ?>> Use this screen's own content (below)</label>
      </fieldset>
      <label><span data-own-label><?= $device['group_id'] ? 'Own content' : 'Shows' ?></span> <select name="content"><?= content_options(
          $device['layout_id'] ? (int)$device['layout_id'] : null,
          $device['playlist_id'] ? (int)$device['playlist_id'] : null) ?></select>
        <span class="muted small">A playlist rotates layouts by its schedule; a single layout plays permanently.</span></label>
      <label>Status <select name="status"><?= select_options(['approved' => 'Approved', 'pending' => 'Pending (not allowed to play)'], $device['status']) ?></select></label>
      <button class="btn btn-primary">Save</button>
    </form>
  </div>

  <div class="card">
    <h2>Status</h2>
    <dl class="kv">
      <dt>Code</dt><dd><code><?= h($device['code']) ?></code></dd>
      <dt>State</dt><dd><span data-live="state" data-device-ref="<?= $id ?>"><?= device_online($device) ? 'Online' : 'Offline' ?></span>, last seen <span data-live="last_seen" data-device-ref="<?= $id ?>"><?= h(time_ago($device['last_seen'] ? (int)$device['last_seen'] : null)) ?></span></dd>
      <dt>Shows</dt><dd><?= content_label($effLayout, $effPlaylist) ?><?= $effSource === 'group'
          ? ' <span class="muted small">via group <a href="' . url(['p' => 'group', 'id' => $device['group_id']]) . '">' . h(screen_groups()[(int)$device['group_id']]['name']) . '</a></span>' : '' ?></dd>
      <?php if (!empty($info['playing_layout'])): ?><dt>Now showing</dt><dd><?= h($info['playing_layout']) ?></dd><?php endif; ?>
      <?php if ($web): ?>
      <dt>Type</dt><dd>Web screen (browser)</dd>
      <dt>IP</dt><dd><?= h($device['ip']) ?></dd>
      <?php if (!empty($info['screen'])): ?><dt>Resolution</dt><dd><?= h($info['screen']) ?></dd><?php endif; ?>
      <?php if (!empty($info['user_agent'])): ?><dt>Browser</dt><dd class="small"><?= h($info['user_agent']) ?></dd><?php endif; ?>
      <?php else: ?>
      <dt>Hostname</dt><dd><?= h($info['hostname'] ?? '') ?></dd>
      <dt>Local IP</dt><dd><?= h($info['local_ip'] ?? '') ?></dd>
      <dt>Public IP</dt><dd><?= h($device['ip']) ?></dd>
      <dt>Client</dt><dd><?= h(($info['client_version'] ?? '') . ' ' . ($info['os'] ?? '') . '/' . ($info['arch'] ?? '')) ?></dd>
      <?php endif; ?>
      <?php if (isset($info['model'])): ?><dt>Model</dt><dd><?= h($info['model']) ?></dd><?php endif; ?>
      <?php if (isset($info['uptime_s'])): ?><dt>Uptime</dt><dd><?= h(round($info['uptime_s'] / 3600, 1)) ?> h</dd><?php endif; ?>
      <?php if (isset($info['cpu_temp_c'])): ?><dt>CPU temp</dt><dd><?= h($info['cpu_temp_c']) ?> °C</dd><?php endif; ?>
      <?php if (isset($info['disk_free'])): ?><dt>Disk free</dt><dd><?= h(human_size((int)$info['disk_free'])) ?></dd><?php endif; ?>
      <?php if (isset($info['cache_size'])): ?><dt>Cache</dt><dd><?= h(human_size((int)$info['cache_size'])) ?></dd><?php endif; ?>
      <?php if (!empty($info['playing_version'])): ?><dt>Content</dt><dd><code><?= h(substr($info['playing_version'], 0, 10)) ?></code></dd><?php endif; ?>
      <?php if (!empty($info['last_error'])): ?><dt>Last error</dt><dd class="text-error"><?= h($info['last_error']) ?></dd><?php endif; ?>
      <dt>Registered</dt><dd><?= h(date('Y-m-d H:i', (int)$device['created_at'])) ?></dd>
    </dl>
  </div>

  <div class="card">
    <h2>Remote commands</h2>
    <p class="muted">Delivered on the screen's next sync (every <?= poll_interval() ?> s).</p>
    <div class="row">
      <?php foreach ($commands as $cmd => $label): if ($cmd === 'screenshot' || $cmd === 'update') continue; ?>
        <?= post_button(['do' => 'command', 'command' => $cmd], $label, 'btn', $cmd === 'reboot' ? 'Reboot this screen?' : null) ?>
      <?php endforeach; ?>
      <?= (int)$device['muted']
          ? post_button(['do' => 'mute', 'muted' => '0'], '🔊 Unmute sound', 'btn btn-primary')
          : post_button(['do' => 'mute', 'muted' => '1'], '🔇 Mute sound', 'btn') ?>
    </div>
    <p class="muted small">Sound is <strong><?= (int)$device['muted'] ? 'muted' : 'on' ?></strong>. Muting covers videos and uploaded
      HTML content; web pages from other sites (URL media) keep their own sound.</p>
    <?php if ($pending): ?><p class="muted">Pending: <strong><?= h(implode(', ', array_map(fn($c) => PP_COMMANDS[$c] ?? $c, $pending))) ?></strong></p><?php endif; ?>
    <?php if (!$web):
        $clientVersion = (string)($info['client_version'] ?? '');
        $package = client_downloads()[client_package_for((string)($info['os'] ?? ''), (string)($info['arch'] ?? ''), (string)($info['go_version'] ?? ''))] ?? null; ?>
    <h3>Client</h3>
    <?php if ($clientVersion === PP_VERSION): ?>
      <p class="muted small">Client <?= h($clientVersion) ?>: up to date with the server.</p>
    <?php elseif ($clientVersion !== '' && version_compare($clientVersion, '1.9.0', '<')): ?>
      <p class="muted small">Client <?= h($clientVersion) ?>, server <?= h(PP_VERSION) ?>. Clients older than 1.9.0 cannot update
        themselves: install the new client on the screen once (see Settings), later updates can then be sent from here.</p>
    <?php elseif (!$package || empty($package['path'])): ?>
      <p class="muted small">Client <?= h($clientVersion ?: '?') ?>, server <?= h(PP_VERSION) ?>. This server has no client package
        for this screen (<?= h(($info['os'] ?? '?') . '/' . ($info['arch'] ?? '?')) ?>).</p>
    <?php else: ?>
      <p class="muted small">Client <?= h($clientVersion ?: '?') ?>, server <?= h(PP_VERSION) ?>. The screen downloads
        <?= h($package['name']) ?> (<?= h(human_size((int)$package['size'])) ?>), replaces its client and restarts it; content keeps playing.</p>
      <?= post_button(['do' => 'command', 'command' => 'update'], 'Update client to ' . PP_VERSION, 'btn',
          'Update the client on this screen to ' . PP_VERSION . '?') ?>
    <?php endif; ?>
    <?php if (!empty($info['update_error'])): ?><p class="text-error small">Last update failed: <?= h($info['update_error']) ?></p><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Screen alignment</h2>
    <form method="post" class="row wrap align-end">
      <?= csrf_field() ?><input type="hidden" name="do" value="frame">
      <?php foreach (['frame_x' => 'X', 'frame_y' => 'Y', 'frame_w' => 'W', 'frame_h' => 'H'] as $k => $label): ?>
        <label><?= $label ?> (%) <input type="number" name="<?= $k ?>" step="0.1" value="<?= h((string)(float)$device[$k]) ?>" class="num"></label>
      <?php endforeach; ?>
      <button class="btn">Save alignment</button>
      <?php if (screen_frame($device)): ?><button class="btn" name="reset" value="1">Reset</button><?php endif; ?>
    </form>
    <p class="muted small">Moves and sizes the whole picture on this screen, in % of the display: X and Y move it (negative = left / up),
      W and H size it (default 100, above 100 overscans). Use it to line up a display that crops or shifts the picture.
      Layout zones are placed inside this area, so layouts need no changes. Currently
      <strong><?= screen_frame($device) ? h("{$device['frame_w']}% × {$device['frame_h']}% at {$device['frame_x']}%, {$device['frame_y']}%") : 'full screen (default)' ?></strong>.</p>
  </div>

  <div class="card">
    <h2>Danger zone</h2>
    <?= post_button(['do' => 'delete'], 'Remove screen', 'btn btn-danger', 'Remove this screen?') ?>
  </div>
</div>
<?php page_footer();
