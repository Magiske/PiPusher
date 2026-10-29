<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'create') {
        $name = trim((string)($_POST['name'] ?? '')) ?: 'New layout';
        $id = create_layout($name, (string)($_POST['orientation'] ?? 'landscape'), (string)($_POST['template'] ?? 'full'));
        flash('Layout created. Add content to its zones below.');
        redirect(url(['p' => 'layout', 'id' => $id]));
    }
    if ($do === 'duplicate') {
        $src = q1('SELECT * FROM layouts WHERE id = ?', [(int)$_POST['id']]);
        if ($src) {
            $pdo = db();
            $pdo->beginTransaction();
            x('INSERT INTO layouts (name, orientation, background, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$src['name'] . ' (copy)', $src['orientation'], $src['background'], now(), now()]);
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
        redirect(url(['p' => 'layouts']));
    }
    if ($do === 'delete') {
        x('DELETE FROM layouts WHERE id = ?', [(int)$_POST['id']]);
        flash('Layout deleted.');
        redirect(url(['p' => 'layouts']));
    }
}

$layouts = q('SELECT l.*,
    (SELECT COUNT(*) FROM zones z WHERE z.layout_id = l.id) AS zone_count,
    (SELECT COUNT(DISTINCT e.playlist_id) FROM playlist_entries e WHERE e.layout_id = l.id) AS playlist_count
    FROM layouts l ORDER BY l.name COLLATE NOCASE');
$templates = array_map(fn($t) => $t['label'], layout_templates());
page_header('Layouts', 'layouts');
?>
<div class="page-head"><h1>Layouts</h1></div>

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
  </div>
<?php endforeach; ?>

  <div class="card">
    <h2>New layout</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="create">
      <label>Name <input name="name" required placeholder="Reception screen"></label>
      <label>Orientation <select name="orientation"><?= select_options(PP_ORIENTATIONS, 'landscape') ?></select></label>
      <label>Start from <select name="template"><?= select_options($templates, 'full') ?></select></label>
      <button class="btn btn-primary">Create layout</button>
    </form>
  </div>
</div>
<?php page_footer();
