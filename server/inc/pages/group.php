<?php
$id = (int)($_GET['id'] ?? 0);
$group = screen_groups()[$id] ?? null;
if (!$group) {
    flash('Group not found.', 'error');
    redirect(url());
}
$back = url(['p' => 'group', 'id' => $id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    $members = array_map('intval', array_column(q('SELECT id FROM devices WHERE group_id = ?', [$id]), 'id'));
    if ($do === 'save') {
        [$layout, $playlist] = parse_content_ref($_POST['content'] ?? '');
        x('UPDATE screen_groups SET name = ?, layout_id = ?, playlist_id = ? WHERE id = ?',
            [mb_substr(trim((string)$_POST['name']), 0, 80) ?: $group['name'], $layout, $playlist, $id]);
        flash('Group saved. Screens that follow the group pick up the change on their next sync.');
    } elseif ($do === 'add') {
        $n = 0;
        foreach ((array)($_POST['devices'] ?? []) as $did) {
            $n += x('UPDATE devices SET group_id = ?, use_group_content = ? WHERE id = ?', [$id, ($_POST['source'] ?? 'group') === 'group' ? 1 : 0, (int)$did]);
        }
        flash("$n screen(s) added to the group.");
    } elseif ($do === 'source' && in_array((int)$_POST['device'], $members, true)) {
        x('UPDATE devices SET use_group_content = ? WHERE id = ?', [($_POST['source'] ?? '') === 'group' ? 1 : 0, (int)$_POST['device']]);
    } elseif ($do === 'all_follow') {
        x('UPDATE devices SET use_group_content = 1 WHERE group_id = ?', [$id]);
        flash('All screens in the group now follow the group content.');
    } elseif ($do === 'remove' && in_array((int)$_POST['device'], $members, true)) {
        x('UPDATE devices SET group_id = NULL, use_group_content = 0 WHERE id = ?', [(int)$_POST['device']]);
        flash('Screen removed from the group; it now shows its own content.');
    } elseif ($do === 'command' && isset(PP_COMMANDS[$_POST['command'] ?? ''])) {
        foreach ($members as $did) {
            queue_command($did, $_POST['command']);
        }
        flash('"' . PP_COMMANDS[$_POST['command']] . '" sent to ' . count($members) . ' screen(s); it runs on their next sync.');
    } elseif ($do === 'delete') {
        // Screens that followed the group keep showing what it showed.
        x('UPDATE devices SET layout_id = ?, playlist_id = ? WHERE group_id = ? AND use_group_content = 1', [$group['layout_id'], $group['playlist_id'], $id]);
        x('UPDATE devices SET group_id = NULL, use_group_content = 0 WHERE group_id = ?', [$id]);
        x('DELETE FROM screen_groups WHERE id = ?', [$id]);
        flash('Group deleted. Its screens keep their current content.');
        redirect(url());
    }
    redirect($back);
}

$members = q('SELECT * FROM devices WHERE group_id = ? ORDER BY name COLLATE NOCASE', [$id]);
$others = q('SELECT d.*, g.name AS group_name FROM devices d LEFT JOIN screen_groups g ON g.id = d.group_id
             WHERE d.group_id IS NULL OR d.group_id != ? ORDER BY d.name COLLATE NOCASE', [$id]);
$following = count(array_filter($members, fn($d) => (int)$d['use_group_content'] === 1));
page_header($group['name'], 'devices');
?>
<div class="page-head">
  <h1>▦ <?= h($group['name']) ?></h1>
  <div class="row">
    <?php if ($group['layout_id'] || $group['playlist_id']): ?><a class="btn" target="_blank" href="preview.php?group=<?= $id ?>">Preview group content ↗</a><?php endif; ?>
    <a class="btn" href="<?= url() ?>">← Screens</a>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Group content</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="save">
      <label>Name <input name="name" value="<?= h($group['name']) ?>" required maxlength="80"></label>
      <label>Screens that follow the group show
        <select name="content"><?= content_options($group['layout_id'] ? (int)$group['layout_id'] : null, $group['playlist_id'] ? (int)$group['playlist_id'] : null) ?></select></label>
      <button class="btn btn-primary">Save</button>
    </form>
    <p class="muted small"><?= $following ?> of <?= count($members) ?> screen(s) follow the group; the others run their own content.</p>
  </div>

  <div class="card">
    <h2>Control the whole group</h2>
    <p class="muted">Sent to all <?= count($members) ?> screen(s); runs on their next sync (within <?= poll_interval() ?> s).</p>
    <div class="row wrap">
      <?php foreach (PP_COMMANDS as $cmd => $label): ?>
        <?= post_button(['do' => 'command', 'command' => $cmd], $label, 'btn', $cmd === 'reboot' ? 'Reboot all screens in this group?' : null) ?>
      <?php endforeach; ?>
    </div>
    <?php if ($following < count($members)): ?>
      <p></p><?= post_button(['do' => 'all_follow'], 'Make all screens follow the group', 'btn btn-sm', 'Switch every screen in this group to the group content?') ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Screens in this group</h2>
  <?php if (!$members): ?>
    <p class="muted">No screens yet – add some below.</p>
  <?php else: ?>
  <div class="wall">
    <?php foreach ($members as $d):
        [$l, $p, $src] = effective_content($d);
        $info = json_decode((string)$d['info'], true) ?: []; ?>
    <div class="wall-item" data-device="<?= $d['id'] ?>" data-status="<?= h($d['status']) ?>">
      <a class="wall-shot" href="<?= url(['p' => 'device', 'id' => $d['id']]) ?>">
        <?php if ($d['screenshot_at'] && is_file(screenshot_path((int)$d['id']))): ?>
          <img data-live="thumb" src="screenshot.php?id=<?= $d['id'] ?>&amp;t=<?= (int)$d['screenshot_at'] ?>" alt="">
        <?php else: ?><span class="muted small">no screenshot</span><?php endif; ?>
      </a>
      <div class="wall-meta">
        <span class="dot <?= device_online($d) ? 'dot-on' : 'dot-off' ?>"></span>
        <a href="<?= url(['p' => 'device', 'id' => $d['id']]) ?>"><strong><?= h($d['name']) ?></strong></a>
        <span class="muted small" data-live="last_seen"><?= h(time_ago($d['last_seen'] ? (int)$d['last_seen'] : null)) ?></span>
      </div>
      <div class="small"><?= content_label($l, $p) ?><?= !empty($info['playing_layout']) && $p ? ' <span class="muted">· now: ' . h($info['playing_layout']) . '</span>' : '' ?></div>
      <div class="row wrap">
        <form method="post" class="inline">
          <?= csrf_field() ?><input type="hidden" name="do" value="source"><input type="hidden" name="device" value="<?= $d['id'] ?>">
          <select name="source" data-autosubmit class="small">
            <?= select_options(['group' => 'Follows group', 'own' => 'Own content'], $src === 'group' ? 'group' : 'own') ?>
          </select>
        </form>
        <?= post_button(['do' => 'remove', 'device' => $d['id']], 'Remove', 'btn btn-sm btn-danger') ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Screens set to <em>Own content</em> show what is selected on their own page.</p>
  <?php endif; ?>
</div>

<?php if ($others): ?>
<div class="card">
  <h2>Add screens</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <div class="access-list" style="padding-left:0;max-height:260px">
      <?php foreach ($others as $d): ?>
        <label class="check"><input type="checkbox" name="devices[]" value="<?= $d['id'] ?>">
          <span class="dot <?= device_online($d) ? 'dot-on' : 'dot-off' ?>"></span> <?= h($d['name']) ?>
          <?php if ($d['group_name']): ?><span class="muted small">(moves from “<?= h($d['group_name']) ?>”)</span><?php endif; ?></label>
      <?php endforeach; ?>
    </div>
    <div class="row wrap">
      <label class="row">Added screens <select name="source"><?= select_options(['group' => 'follow the group content', 'own' => 'keep their own content'], 'group') ?></select></label>
      <button class="btn btn-primary">Add to group</button>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <?= post_button(['do' => 'delete'], 'Delete group', 'btn btn-danger', 'Delete this group? Its screens are kept and continue showing their current content.') ?>
</div>
<?php page_footer();
