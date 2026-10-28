<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'approve') {
        x("UPDATE devices SET status = 'approved' WHERE id = ?", [(int)$_POST['id']]);
        flash('Screen approved.');
    } elseif ($do === 'web_new') {
        $d = create_web_screen((string)($_POST['name'] ?? ''));
        flash('Web screen created. Open its link in a browser on the screen.');
        redirect(url(['p' => 'device', 'id' => $d['id']]));
    } elseif ($do === 'group_new') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        if ($name !== '') {
            x('INSERT INTO screen_groups (name, created_at) VALUES (?, ?)', [$name, now()]);
            redirect(url(['p' => 'group', 'id' => last_id()]));
        }
    }
    redirect(url());
}

$devices = q("SELECT * FROM devices ORDER BY status = 'pending' DESC, name COLLATE NOCASE");
$groups = screen_groups();
$sections = [];
foreach ($groups as $gid => $g) {
    $sections[$gid] = [];
}
$sections[0] = [];
foreach ($devices as $d) {
    $gid = $d['group_id'] && isset($groups[(int)$d['group_id']]) ? (int)$d['group_id'] : 0;
    $sections[$gid][] = $d;
}
$onlineCount = count(array_filter($devices, 'device_online'));

function device_row(array $d): void
{
    $info = json_decode((string)$d['info'], true) ?: [];
    [$l, $p, $src] = effective_content($d);
    ?>
    <tr class="<?= $d['status'] === 'pending' ? 'row-pending' : '' ?>" data-device="<?= $d['id'] ?>" data-status="<?= h($d['status']) ?>">
      <td><span class="dot <?= device_online($d) ? 'dot-on' : 'dot-off' ?>" title="<?= device_online($d) ? 'Online' : 'Offline' ?>"></span></td>
      <td class="thumb-cell"><a href="<?= url(['p' => 'device', 'id' => $d['id']]) ?>">
        <?php if ($d['screenshot_at'] && is_file(screenshot_path((int)$d['id']))): ?>
          <img data-live="thumb" src="screenshot.php?id=<?= $d['id'] ?>&amp;t=<?= (int)$d['screenshot_at'] ?>" alt="" loading="lazy" title="<?= h(time_ago((int)$d['screenshot_at'])) ?>">
        <?php else: ?><span class="thumb-none"></span><?php endif; ?>
      </a></td>
      <td><a href="<?= url(['p' => 'device', 'id' => $d['id']]) ?>"><strong><?= h($d['name']) ?></strong></a><?= (int)($d['muted'] ?? 0) ? ' <span title="Sound muted">🔇</span>' : '' ?>
        <?php if (is_web_screen($d)): ?><span class="badge badge-web" title="Web screen: plays in a browser from the server">🌐 WEB</span><?php endif; ?>
        <?php if ($d['status'] === 'pending'): ?><span class="badge badge-pending">PENDING</span><?php endif; ?></td>
      <td><code><?= h($d['code']) ?></code></td>
      <td><?= content_label($l, $p) ?>
        <?php if ($d['group_id']): ?><span class="badge <?= $src === 'group' ? 'badge-follow' : 'badge-own' ?>" title="<?= $src === 'group' ? 'Follows the group' : 'Runs its own content' ?>"><?= $src === 'group' ? 'GROUP' : 'OWN' ?></span><?php endif; ?>
        <?php if (!empty($info['playing_layout']) && $p): ?><br><span class="muted small">now: <?= h($info['playing_layout']) ?></span><?php endif; ?></td>
      <td data-live="last_seen"><?= h(time_ago($d['last_seen'] ? (int)$d['last_seen'] : null)) ?></td>
      <td><?= h($info['local_ip'] ?? $d['ip']) ?></td>
      <td class="muted"><?= h($info['client_version'] ?? '') ?></td>
      <td class="right">
        <?php if ($d['status'] === 'pending'): ?>
          <?= post_button(['do' => 'approve', 'id' => $d['id']], 'Approve', 'btn btn-primary btn-sm') ?>
        <?php endif; ?>
        <a class="btn btn-sm" href="<?= url(['p' => 'device', 'id' => $d['id']]) ?>">Manage</a>
      </td>
    </tr>
    <?php
}

page_header('Screens', 'devices');
?>
<div class="page-head" data-live-list>
  <h1>Screens</h1>
  <span class="muted"><?= count($devices) ?> registered · <?= $onlineCount ?> online · <?= count($groups) ?> group(s)</span>
</div>

<?php if (!$devices && !$groups): ?>
  <div class="card empty">
    <h2>No screens yet</h2>
    <p>Install the PiPusher client on a Raspberry Pi and point it at this server:</p>
    <pre>sudo bash install.sh --server <?= h(server_base_url()) ?> --key <?= h(setting('registration_key', '')) ?></pre>
    <p class="muted">With the registration key the screen is approved automatically. Without it, it shows a code here that you approve.</p>
    <p>No Raspberry Pi? Add a <strong>web screen</strong> below and open its link in any browser.</p>
  </div>
<?php endif; ?>

<?php foreach ($sections as $gid => $members):
    if ($gid === 0 && !$members) continue;
    $g = $groups[$gid] ?? null; ?>
<div class="card group-card">
  <div class="page-head">
    <?php if ($g): ?>
      <h2><a href="<?= url(['p' => 'group', 'id' => $gid]) ?>">▦ <?= h($g['name']) ?></a>
        <span class="muted small"><?= count($members) ?> screen(s) · <?= count(array_filter($members, 'device_online')) ?> online · shows
          <?= content_label($g['layout_id'] ? (int)$g['layout_id'] : null, $g['playlist_id'] ? (int)$g['playlist_id'] : null) ?></span></h2>
      <a class="btn btn-sm" href="<?= url(['p' => 'group', 'id' => $gid]) ?>">Manage group</a>
    <?php else: ?>
      <h2><?= $groups ? 'Not in a group' : 'All screens' ?> <span class="muted small"><?= count($members) ?> screen(s)</span></h2>
    <?php endif; ?>
  </div>
  <?php if ($members): ?>
  <table class="table">
    <thead><tr><th></th><th></th><th>Name</th><th>Code</th><th>Shows</th><th>Last seen</th><th>IP</th><th>Client</th><th></th></tr></thead>
    <tbody><?php foreach ($members as $d) device_row($d); ?></tbody>
  </table>
  <?php else: ?>
    <p class="muted">No screens in this group yet. Add screens on the <a href="<?= url(['p' => 'group', 'id' => $gid]) ?>">group page</a> or on a screen's page.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="grid-2">
<div class="card">
  <h2>Add web screen</h2>
  <form method="post" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="web_new">
    <label class="grow">Name <input name="name" maxlength="80" placeholder="Reception TV"></label>
    <button class="btn btn-primary">Add web screen</button>
  </form>
  <p class="muted small">A web screen plays in any browser (smart TV, PC, tablet) straight from this server: no client to install and
    no login, just a secret link. It needs the network all the time (no offline cache). Browsers can also add themselves with the
    <a href="<?= url(['p' => 'settings']) ?>">web player key</a>.</p>
</div>

<div class="card">
  <h2>New group</h2>
  <form method="post" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="group_new">
    <label class="grow">Name <input name="name" required maxlength="80" placeholder="Lobby screens"></label>
    <button class="btn btn-primary">Create group</button>
  </form>
  <p class="muted small">Screens in a group can all show the group's playlist (change it once, every screen follows), or each run their
    own playlist while still being managed together (reload, screenshot or reboot the whole group at once).</p>
</div>
</div>
<?php page_footer();
