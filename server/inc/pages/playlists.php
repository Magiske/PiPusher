<?php
$folderId = valid_playlist_folder_id($_GET['folder'] ?? null);
$here = fn(?int $fid = null) => url(array_filter(['p' => 'playlists', 'folder' => $fid]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        deny('Only administrators can create, duplicate, move or delete playlists and folders.');
    }
    $do = $_POST['do'] ?? '';
    if ($do === 'create') {
        x('INSERT INTO playlists (name, folder_id, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [trim((string)($_POST['name'] ?? '')) ?: 'New playlist', $folderId, now(), now()]);
        $id = last_id();
        flash('Playlist created. Add layouts to it below.');
        redirect(url(['p' => 'playlist', 'id' => $id]));
    }
    if ($do === 'duplicate' && ($src = q1('SELECT * FROM playlists WHERE id = ?', [(int)$_POST['id']]))) {
        $pdo = db();
        $pdo->beginTransaction();
        x('INSERT INTO playlists (name, fallback_layout_id, folder_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$src['name'] . ' (copy)', $src['fallback_layout_id'], $src['folder_id'], now(), now()]);
        $newId = last_id();
        x('INSERT INTO playlist_entries (playlist_id, layout_id, duration, position, days, time_from, time_to, date_from, date_to, yearly, frequency)
           SELECT ?, layout_id, duration, position, days, time_from, time_to, date_from, date_to, yearly, frequency
           FROM playlist_entries WHERE playlist_id = ?', [$newId, $src['id']]);
        $pdo->commit();
        flash('Playlist duplicated.');
    } elseif ($do === 'delete') {
        x('DELETE FROM playlists WHERE id = ?', [(int)$_POST['id']]);
        flash('Playlist deleted.');
    } elseif ($do === 'move') {
        $target = valid_playlist_folder_id($_POST['folder_id'] ?? null);
        x('UPDATE playlists SET folder_id = ? WHERE id = ?', [$target, (int)$_POST['id']]);
        flash('Playlist moved to ' . ($target ? playlist_folders()[$target]['name'] : 'the top level') . '.');
    } elseif ($do === 'folder_new') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        if ($name !== '') {
            x('INSERT INTO playlist_folders (name, created_at) VALUES (?, ?)', [$name, now()]);
            redirect($here(last_id()));
        }
    } elseif ($do === 'folder_save' && $folderId) {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        x('UPDATE playlist_folders SET name = ? WHERE id = ?', [$name ?: playlist_folders()[$folderId]['name'], $folderId]);
        flash('Folder saved.');
    } elseif ($do === 'folder_delete' && $folderId) {
        // Keep the playlists: move them to the top level, then remove the folder.
        x('UPDATE playlists SET folder_id = NULL WHERE folder_id = ?', [$folderId]);
        x('DELETE FROM playlist_folders WHERE id = ?', [$folderId]);
        flash('Folder deleted; its playlists moved to the top level.');
        redirect($here());
    }
    redirect($here($folderId));
}

$allowed = allowed_playlist_ids();
$visible = fn(array $p) => $allowed === null || in_array((int)$p['id'], $allowed, true);
$current = $folderId ? playlist_folders()[$folderId] : null;
$playlists = array_filter(q('SELECT p.*, f.name AS fallback_name,
    (SELECT COUNT(*) FROM playlist_entries e WHERE e.playlist_id = p.id) AS entry_count
    FROM playlists p LEFT JOIN layouts f ON f.id = p.fallback_layout_id
    WHERE ' . ($folderId ? 'p.folder_id = ?' : 'p.folder_id IS NULL') . ' ORDER BY p.name COLLATE NOCASE',
    $folderId ? [$folderId] : []), $visible);
// Playlist editors only see folders holding playlists they may edit.
$counts = [];
foreach (array_filter(q('SELECT id, folder_id FROM playlists WHERE folder_id IS NOT NULL'), $visible) as $p) {
    $counts[(int)$p['folder_id']] = ($counts[(int)$p['folder_id']] ?? 0) + 1;
}
$folders = array_filter(playlist_folders(), fn($f) => is_admin() || isset($counts[(int)$f['id']]));
page_header($current ? $current['name'] : 'Playlists', 'playlists');
?>
<div class="page-head">
  <h1 class="crumbs">
    <a href="<?= $here() ?>">Playlists</a>
    <?php if ($current): ?><span class="muted">/</span> 📁 <?= h($current['name']) ?><?php endif; ?>
  </h1>
</div>
<?php if (!$current): ?>
<p class="muted">A playlist is a queue of layouts. Each layout plays for its duration, and rules (weekdays, time of day, dates)
  decide when it may play, so one set of layouts can be reused for opening hours, weekends, events and seasons.</p>

<?php if ($folders || is_admin()): ?>
<div class="folder-grid">
  <?php foreach ($folders as $fid => $f): ?>
    <a class="folder" href="<?= $here($fid) ?>"><span class="folder-icon">📁</span><span class="folder-name"><?= h($f['name']) ?></span>
      <span class="muted small"><?= $counts[$fid] ?? 0 ?> playlist(s)</span></a>
  <?php endforeach; ?>
  <?php if (is_admin()): ?>
  <details class="folder folder-new">
    <summary><span class="folder-icon">＋</span><span class="folder-name">New folder</span></summary>
    <form method="post" class="row">
      <?= csrf_field() ?><input type="hidden" name="do" value="folder_new">
      <input name="name" required maxlength="80" placeholder="Folder name"><button class="btn btn-sm btn-primary">Create</button>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="layout-grid">
<?php foreach ($playlists as $p):
    $entries = q('SELECT e.*, l.name AS layout_name FROM playlist_entries e JOIN layouts l ON l.id = e.layout_id
                  WHERE e.playlist_id = ? ORDER BY e.position, e.id', [$p['id']]); ?>
  <div class="card layout-card">
    <h3><a href="<?= url(['p' => 'playlist', 'id' => $p['id']]) ?>">▶ <?= h($p['name']) ?></a></h3>
    <p class="muted"><?= (int)$p['entry_count'] ?> layout(s) · <?= count(devices_showing(null, (int)$p['id'])) ?> screen(s)</p>
    <ol class="entry-mini">
      <?php foreach (array_slice($entries, 0, 6) as $e): ?>
        <li class="<?= entry_active($e) ? 'active' : '' ?>"><?= h($e['layout_name']) ?> <span class="muted small" data-local-times>· <?= h(describe_when($e)) ?></span></li>
      <?php endforeach; ?>
      <?php if (count($entries) > 6): ?><li class="muted">… <?= count($entries) - 6 ?> more</li><?php endif; ?>
    </ol>
    <?php if ($p['fallback_name']): ?><p class="muted small">Otherwise: <?= h($p['fallback_name']) ?></p><?php endif; ?>
    <div class="row">
      <a class="btn btn-sm" href="<?= url(['p' => 'playlist', 'id' => $p['id']]) ?>">Edit</a>
      <a class="btn btn-sm" target="_blank" href="preview.php?playlist=<?= $p['id'] ?>">Preview</a>
      <?php if (is_admin()): ?>
      <?= post_button(['do' => 'duplicate', 'id' => $p['id']], 'Duplicate', 'btn btn-sm') ?>
      <?= post_button(['do' => 'delete', 'id' => $p['id']], 'Delete', 'btn btn-sm btn-danger', 'Delete this playlist? Screens using it get nothing to show.') ?>
      <?php endif; ?>
    </div>
    <?php if (is_admin() && playlist_folders()): ?>
    <form method="post" class="row">
      <?= csrf_field() ?><input type="hidden" name="do" value="move"><input type="hidden" name="id" value="<?= $p['id'] ?>">
      <label class="row small">Folder <select name="folder_id" data-autosubmit><?= playlist_folder_options($p['folder_id'] ? (int)$p['folder_id'] : null) ?></select></label>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if (!is_admin() && !$playlists && !$folders): ?>
  <div class="card empty"><p>You have not been given access to any playlists yet. Ask an administrator.</p></div>
<?php endif; ?>

<?php if (is_admin()): ?>
  <div class="card">
    <h2>New playlist<?= $current ? ' in “' . h($current['name']) . '”' : '' ?></h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="create">
      <label>Name <input name="name" required placeholder="Reception – weekly"></label>
      <button class="btn btn-primary">Create playlist</button>
    </form>
  </div>
<?php endif; ?>
</div>

<?php if ($current && is_admin()): ?>
<div class="card">
  <h2>Folder settings</h2>
  <form method="post" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="folder_save">
    <label>Name <input name="name" value="<?= h($current['name']) ?>" required maxlength="80"></label>
    <button class="btn">Save folder</button>
  </form>
  <p></p>
  <?= post_button(['do' => 'folder_delete'], 'Delete folder', 'btn btn-sm btn-danger', 'Delete this folder? Its playlists are moved to the top level, nothing is deleted.') ?>
</div>
<?php endif;
page_footer();
