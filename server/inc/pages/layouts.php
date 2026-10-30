<?php
$folderId = valid_layout_folder_id($_GET['folder'] ?? null);
$here = fn(?int $fid = null) => url(array_filter(['p' => 'layouts', 'folder' => $fid]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'create') {
        $name = trim((string)($_POST['name'] ?? '')) ?: 'New layout';
        $id = create_layout($name, (string)($_POST['orientation'] ?? 'landscape'), (string)($_POST['template'] ?? 'full'));
        x('UPDATE layouts SET folder_id = ? WHERE id = ?', [$folderId, $id]);
        flash('Layout created. Add content to its zones below.');
        redirect(url(['p' => 'layout', 'id' => $id]));
    }
    if ($do === 'duplicate') {
        $src = q1('SELECT * FROM layouts WHERE id = ?', [(int)$_POST['id']]);
        if ($src) {
            $pdo = db();
            $pdo->beginTransaction();
            x('INSERT INTO layouts (name, orientation, background, folder_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$src['name'] . ' (copy)', $src['orientation'], $src['background'], $src['folder_id'], now(), now()]);
            $newId = last_id();
            foreach (q('SELECT * FROM zones WHERE layout_id = ?', [$src['id']]) as $z) {
                x('INSERT INTO zones (layout_id, name, x, y, w, h, z, fit, transition) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$newId, $z['name'], $z['x'], $z['y'], $z['w'], $z['h'], $z['z'], $z['fit'], $z['transition']]);
                $newZone = last_id();
                x('INSERT INTO playlist_items (zone_id, media_id, duration, size, position)
                   SELECT ?, media_id, duration, size, position FROM playlist_items WHERE zone_id = ?', [$newZone, $z['id']]);
            }
            $pdo->commit();
            flash('Layout duplicated.');
        }
    } elseif ($do === 'delete') {
        x('DELETE FROM layouts WHERE id = ?', [(int)$_POST['id']]);
        flash('Layout deleted.');
    } elseif ($do === 'move') {
        $target = valid_layout_folder_id($_POST['folder_id'] ?? null);
        x('UPDATE layouts SET folder_id = ? WHERE id = ?', [$target, (int)$_POST['id']]);
        flash('Layout moved to ' . ($target ? layout_folders()[$target]['name'] : 'the top level') . '.');
    } elseif ($do === 'folder_new') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        if ($name !== '') {
            x('INSERT INTO layout_folders (name, created_at) VALUES (?, ?)', [$name, now()]);
            redirect($here(last_id()));
        }
    } elseif ($do === 'folder_save' && $folderId) {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        x('UPDATE layout_folders SET name = ? WHERE id = ?', [$name ?: layout_folders()[$folderId]['name'], $folderId]);
        flash('Folder saved.');
    } elseif ($do === 'folder_delete' && $folderId) {
        // Keep the layouts: move them to the top level, then remove the folder.
        x('UPDATE layouts SET folder_id = NULL WHERE folder_id = ?', [$folderId]);
        x('DELETE FROM layout_folders WHERE id = ?', [$folderId]);
        flash('Folder deleted; its layouts moved to the top level.');
        redirect($here());
    }
    redirect($here($folderId));
}

$current = $folderId ? layout_folders()[$folderId] : null;
$layouts = q('SELECT l.*,
    (SELECT COUNT(*) FROM zones z WHERE z.layout_id = l.id) AS zone_count,
    (SELECT COUNT(DISTINCT e.playlist_id) FROM playlist_entries e WHERE e.layout_id = l.id) AS playlist_count
    FROM layouts l WHERE ' . ($folderId ? 'l.folder_id = ?' : 'l.folder_id IS NULL') . ' ORDER BY l.name COLLATE NOCASE',
    $folderId ? [$folderId] : []);
$counts = [];
foreach (q('SELECT folder_id, COUNT(*) AS n FROM layouts WHERE folder_id IS NOT NULL GROUP BY folder_id') as $r) {
    $counts[(int)$r['folder_id']] = (int)$r['n'];
}
$templates = array_map(fn($t) => $t['label'], layout_templates());
page_header($current ? $current['name'] : 'Layouts', 'layouts');
?>
<div class="page-head">
  <h1 class="crumbs">
    <a href="<?= $here() ?>">Layouts</a>
    <?php if ($current): ?><span class="muted">/</span> 📁 <?= h($current['name']) ?><?php endif; ?>
  </h1>
</div>

<?php if (!$current): ?>
<div class="folder-grid">
  <?php foreach (layout_folders() as $fid => $f): ?>
    <a class="folder" href="<?= $here($fid) ?>"><span class="folder-icon">📁</span><span class="folder-name"><?= h($f['name']) ?></span>
      <span class="muted small"><?= $counts[$fid] ?? 0 ?> layout(s)</span></a>
  <?php endforeach; ?>
  <details class="folder folder-new">
    <summary><span class="folder-icon">＋</span><span class="folder-name">New folder</span></summary>
    <form method="post" class="row">
      <?= csrf_field() ?><input type="hidden" name="do" value="folder_new">
      <input name="name" required maxlength="80" placeholder="Folder name"><button class="btn btn-sm btn-primary">Create</button>
    </form>
  </details>
</div>
<?php endif; ?>

<div class="layout-grid">
<?php foreach ($layouts as $l):
    $zones = q('SELECT x, y, w, h FROM zones WHERE layout_id = ? ORDER BY z', [$l['id']]);
    $portrait = str_starts_with($l['orientation'], 'portrait'); ?>
  <div class="card layout-card">
    <a href="<?= url(['p' => 'layout', 'id' => $l['id']]) ?>" class="mini-stage <?= $portrait ? 'portrait' : '' ?>" style="background:<?= h($l['background']) ?>">
      <?php foreach ($zones as $z): ?>
        <span style="left:<?= $z['x'] ?>%;top:<?= $z['y'] ?>%;width:<?= $z['w'] ?>%;height:<?= $z['h'] ?>%"></span>
      <?php endforeach; ?>
    </a>
    <h3><a href="<?= url(['p' => 'layout', 'id' => $l['id']]) ?>"><?= h($l['name']) ?></a></h3>
    <p class="muted"><?= h(PP_ORIENTATIONS[$l['orientation']] ?? $l['orientation']) ?> · <?= (int)$l['zone_count'] ?> zone(s) · <?= count(devices_showing((int)$l['id'], null)) ?> screen(s) · <?= (int)$l['playlist_count'] ?> playlist(s)</p>
    <div class="row">
      <a class="btn btn-sm" href="<?= url(['p' => 'layout', 'id' => $l['id']]) ?>">Edit</a>
      <a class="btn btn-sm" target="_blank" href="preview.php?layout=<?= $l['id'] ?>">Preview</a>
      <?= post_button(['do' => 'duplicate', 'id' => $l['id']], 'Duplicate', 'btn btn-sm') ?>
      <?= post_button(['do' => 'delete', 'id' => $l['id']], 'Delete', 'btn btn-sm btn-danger', 'Delete this layout? It is also removed from playlists, and screens showing it directly get nothing to show.') ?>
    </div>
    <?php if (layout_folders()): ?>
    <form method="post" class="row">
      <?= csrf_field() ?><input type="hidden" name="do" value="move"><input type="hidden" name="id" value="<?= $l['id'] ?>">
      <label class="row small">Folder <select name="folder_id" data-autosubmit><?= layout_folder_options($l['folder_id'] ? (int)$l['folder_id'] : null) ?></select></label>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

  <div class="card">
    <h2>New layout<?= $current ? ' in “' . h($current['name']) . '”' : '' ?></h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="create">
      <label>Name <input name="name" required placeholder="Reception screen"></label>
      <label>Orientation <select name="orientation"><?= select_options(PP_ORIENTATIONS, 'landscape') ?></select></label>
      <label>Start from <select name="template"><?= select_options($templates, 'full') ?></select></label>
      <button class="btn btn-primary">Create layout</button>
    </form>
  </div>
</div>

<?php if ($current): ?>
<div class="card">
  <h2>Folder settings</h2>
  <form method="post" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="folder_save">
    <label>Name <input name="name" value="<?= h($current['name']) ?>" required maxlength="80"></label>
    <button class="btn">Save folder</button>
  </form>
  <p></p>
  <?= post_button(['do' => 'folder_delete'], 'Delete folder', 'btn btn-sm btn-danger', 'Delete this folder? Its layouts are moved to the top level, nothing is deleted.') ?>
</div>
<?php endif;
page_footer();
