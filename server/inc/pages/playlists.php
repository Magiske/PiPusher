<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_admin()) {
        deny('Only administrators can create, duplicate or delete playlists.');
    }
    $do = $_POST['do'] ?? '';
    if ($do === 'create') {
        x('INSERT INTO playlists (name, created_at, updated_at) VALUES (?, ?, ?)',
            [trim((string)($_POST['name'] ?? '')) ?: 'New playlist', now(), now()]);
        $id = last_id();
        flash('Playlist created. Add layouts to it below.');
        redirect(url(['p' => 'playlist', 'id' => $id]));
    }
    if ($do === 'duplicate' && ($src = q1('SELECT * FROM playlists WHERE id = ?', [(int)$_POST['id']]))) {
        $pdo = db();
        $pdo->beginTransaction();
        x('INSERT INTO playlists (name, fallback_layout_id, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$src['name'] . ' (copy)', $src['fallback_layout_id'], now(), now()]);
        $newId = last_id();
        x('INSERT INTO playlist_entries (playlist_id, layout_id, duration, position, days, time_from, time_to, date_from, date_to, yearly)
           SELECT ?, layout_id, duration, position, days, time_from, time_to, date_from, date_to, yearly
           FROM playlist_entries WHERE playlist_id = ?', [$newId, $src['id']]);
        $pdo->commit();
        flash('Playlist duplicated.');
        redirect(url(['p' => 'playlists']));
    }
    if ($do === 'delete') {
        x('DELETE FROM playlists WHERE id = ?', [(int)$_POST['id']]);
        flash('Playlist deleted.');
        redirect(url(['p' => 'playlists']));
    }
}

$allowed = allowed_playlist_ids();
$playlists = array_filter(q('SELECT p.*, f.name AS fallback_name,
    (SELECT COUNT(*) FROM playlist_entries e WHERE e.playlist_id = p.id) AS entry_count
    FROM playlists p LEFT JOIN layouts f ON f.id = p.fallback_layout_id ORDER BY p.name COLLATE NOCASE'),
    fn($p) => $allowed === null || in_array((int)$p['id'], $allowed, true));
page_header('Playlists', 'playlists');
?>
<div class="page-head"><h1>Playlists</h1></div>
<p class="muted">A playlist is a queue of layouts. Each layout plays for its duration, and rules (weekdays, time of day, dates)
  decide when it may play, so one set of layouts can be reused for opening hours, weekends, events and seasons.</p>

<div class="layout-grid">
<?php foreach ($playlists as $p):
    $entries = q('SELECT e.*, l.name AS layout_name FROM playlist_entries e JOIN layouts l ON l.id = e.layout_id
                  WHERE e.playlist_id = ? ORDER BY e.position, e.id', [$p['id']]); ?>
  <div class="card layout-card">
    <h3><a href="<?= url(['p' => 'playlist', 'id' => $p['id']]) ?>">▶ <?= h($p['name']) ?></a></h3>
    <p class="muted"><?= (int)$p['entry_count'] ?> layout(s) · <?= count(devices_showing(null, (int)$p['id'])) ?> screen(s)</p>
    <ol class="entry-mini">
      <?php foreach (array_slice($entries, 0, 6) as $e): ?>
        <li class="<?= entry_active($e) ? 'active' : '' ?>"><?= h($e['layout_name']) ?> <span class="muted small">· <?= h(describe_when($e)) ?></span></li>
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
  </div>
<?php endforeach; ?>

<?php if (!is_admin() && !$playlists): ?>
  <div class="card empty"><p>You have not been given access to any playlists yet. Ask an administrator.</p></div>
<?php endif; ?>

<?php if (is_admin()): ?>
  <div class="card">
    <h2>New playlist</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="create">
      <label>Name <input name="name" required placeholder="Reception – weekly"></label>
      <button class="btn btn-primary">Create playlist</button>
    </form>
  </div>
<?php endif; ?>
</div>
<?php page_footer();
