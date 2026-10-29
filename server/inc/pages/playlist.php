<?php
$id = (int)($_GET['id'] ?? 0);
$playlist = q1('SELECT * FROM playlists WHERE id = ?', [$id]);
if (!$playlist) {
    flash('Playlist not found.', 'error');
    redirect(url(['p' => 'playlists']));
}
if (!can_edit_playlist($id)) {
    deny('You do not have access to this playlist.');
}
$back = url(['p' => 'playlist', 'id' => $id]);

/** Reads and validates the entry fields of a submitted form. */
function entry_fields(): array
{
    $days = array_values(array_filter(array_map('intval', (array)($_POST['days'] ?? [])), fn($d) => $d >= 1 && $d <= 7));
    sort($days);
    $time = fn($k) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : '';
    $date = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : '';
    return [
        // Empty = 0 = until the layout's content has played once (videos to the end).
        'duration' => trim((string)($_POST['duration'] ?? '')) === '' ? 0 : max(5, min(86400, (int)$_POST['duration'])),
        'days' => count($days) === 7 ? '' : implode(',', $days),
        'time_from' => $time('time_from'),
        'time_to' => $time('time_to'),
        'date_from' => $date('date_from'),
        'date_to' => $date('date_to'),
        'yearly' => !empty($_POST['yearly']) ? 1 : 0,
    ];
}

function entry_of_playlist(int $entryId, int $playlistId): ?array
{
    return q1('SELECT * FROM playlist_entries WHERE id = ? AND playlist_id = ?', [$entryId, $playlistId]);
}

function renumber_entries(int $playlistId): void
{
    foreach (q('SELECT id FROM playlist_entries WHERE playlist_id = ? ORDER BY position, id', [$playlistId]) as $i => $row) {
        x('UPDATE playlist_entries SET position = ? WHERE id = ?', [$i, $row['id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    $layoutOk = fn($lid) => $lid && q1('SELECT id FROM layouts WHERE id = ?', [$lid]);
    if ($do === 'settings' && !is_admin()) {
        deny('Only administrators can rename a playlist or change its fallback layout.');
    }
    if ($do === 'settings') {
        $fb = (int)($_POST['fallback_layout_id'] ?? 0);
        x('UPDATE playlists SET name = ?, fallback_layout_id = ?, updated_at = ? WHERE id = ?',
            [trim((string)$_POST['name']) ?: $playlist['name'], $layoutOk($fb) ? $fb : null, now(), $id]);
        flash('Playlist saved.');
    } elseif ($do === 'add' && $layoutOk((int)$_POST['layout_id'])) {
        $f = entry_fields();
        $pos = (int)(q1('SELECT MAX(position) AS m FROM playlist_entries WHERE playlist_id = ?', [$id])['m'] ?? -1) + 1;
        x('INSERT INTO playlist_entries (playlist_id, layout_id, position, duration, days, time_from, time_to, date_from, date_to, yearly)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, (int)$_POST['layout_id'], $pos, ...array_values($f)]);
        flash('Layout added to the playlist.');
    } elseif ($do === 'save' && ($e = entry_of_playlist((int)$_POST['entry_id'], $id)) && $layoutOk((int)$_POST['layout_id'])) {
        $f = entry_fields();
        x('UPDATE playlist_entries SET layout_id = ?, duration = ?, days = ?, time_from = ?, time_to = ?, date_from = ?, date_to = ?, yearly = ?
           WHERE id = ?', [(int)$_POST['layout_id'], ...array_values($f), $e['id']]);
        flash('Entry saved.');
    } elseif ($do === 'delete' && ($e = entry_of_playlist((int)$_POST['entry_id'], $id))) {
        x('DELETE FROM playlist_entries WHERE id = ?', [$e['id']]);
        renumber_entries($id);
    } elseif ($do === 'move' && ($e = entry_of_playlist((int)$_POST['entry_id'], $id))) {
        renumber_entries($id);
        $e = entry_of_playlist((int)$e['id'], $id);
        $other = q1('SELECT * FROM playlist_entries WHERE playlist_id = ? AND position = ?',
            [$id, $e['position'] + (($_POST['dir'] ?? '') === 'up' ? -1 : 1)]);
        if ($other) {
            x('UPDATE playlist_entries SET position = ? WHERE id = ?', [$other['position'], $e['id']]);
            x('UPDATE playlist_entries SET position = ? WHERE id = ?', [$e['position'], $other['id']]);
        }
    }
    x('UPDATE playlists SET updated_at = ? WHERE id = ?', [now(), $id]);
    redirect($back);
}

$layouts = [];
foreach (q('SELECT id, name, orientation FROM layouts ORDER BY name COLLATE NOCASE') as $l) {
    $layouts[$l['id']] = $l['name'] . (str_starts_with($l['orientation'], 'portrait') ? ' (portrait)' : '');
}
$entries = q('SELECT e.*, l.name AS layout_name FROM playlist_entries e JOIN layouts l ON l.id = e.layout_id
              WHERE e.playlist_id = ? ORDER BY e.position, e.id', [$id]);
$devices = devices_showing(null, $id);
$anyActive = (bool)array_filter($entries, fn($e) => entry_active($e));

/** The form fields shared by "add" and "edit". */
function entry_form_fields(array $e, array $layouts): string
{
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $days = array_filter(explode(',', (string)$e['days']));
    ob_start(); ?>
    <div class="entry-grid">
      <label>Layout <select name="layout_id" required><?= select_options($layouts, $e['layout_id'] ?? '') ?></select></label>
      <label>Show for (seconds) <input type="number" name="duration" min="5" max="86400" value="<?= (int)($e['duration'] ?? 0) ?: '' ?>" placeholder="auto" class="num">
        <span class="muted small">empty = until its content has played once</span></label>
      <fieldset class="days">
        <legend>Days <span class="muted small">(none = every day)</span></legend>
        <?php foreach ($dayNames as $n => $label): ?>
          <label class="check"><input type="checkbox" name="days[]" value="<?= $n ?>" <?= in_array((string)$n, $days, true) ? 'checked' : '' ?>> <?= $label ?></label>
        <?php endforeach; ?>
      </fieldset>
      <label>From time <input type="time" name="time_from" value="<?= h($e['time_from'] ?? '') ?>"></label>
      <label>To time <input type="time" name="time_to" value="<?= h($e['time_to'] ?? '') ?>"></label>
      <label>From date <input type="date" name="date_from" value="<?= h($e['date_from'] ?? '') ?>"></label>
      <label>To date <input type="date" name="date_to" value="<?= h($e['date_to'] ?? '') ?>"></label>
      <label class="check"><input type="checkbox" name="yearly" value="1" <?= !empty($e['yearly']) ? 'checked' : '' ?>> Repeat dates every year</label>
    </div>
    <?php return (string)ob_get_clean();
}

page_header($playlist['name'], 'playlists');
?>
<div class="page-head">
  <h1>▶ <?= h($playlist['name']) ?></h1>
  <a class="btn btn-primary" target="_blank" href="preview.php?playlist=<?= $id ?>">Preview ↗</a>
</div>

<div class="card">
  <?php if (is_admin()): ?>
  <form method="post" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <label>Name <input name="name" value="<?= h($playlist['name']) ?>" required></label>
    <label>When nothing is scheduled, show
      <select name="fallback_layout_id"><?= select_options(['' => '— a blank screen —'] + $layouts, $playlist['fallback_layout_id'] ?? '') ?></select></label>
    <button class="btn">Save</button>
  </form>
  <p class="muted small">Screens using this playlist:
    <?= $devices ? implode(', ', array_map(fn($d) => '<a href="' . url(['p' => 'device', 'id' => $d['id']]) . '">' . h($d['name']) . '</a>', $devices)) : 'none yet – assign it on a screen\'s page' ?>.</p>
  <?php else: ?>
  <p class="muted">When nothing is scheduled, screens show
    <strong><?= $playlist['fallback_layout_id'] ? h($layouts[$playlist['fallback_layout_id']] ?? '') : 'a blank screen' ?></strong>.
    Used by <?= count($devices) ?> screen(s).</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Queue</h2>
  <p class="muted small">Layouts play top to bottom and then repeat. An entry is skipped while its rules don't match
    (each screen checks the rules on its own clock, also when offline). Time windows may cross midnight, e.g. 22:00–06:00.
    <strong>Active now</strong> is based on the server's clock (<?= h(date('D H:i')) ?>).</p>
  <?php if (!$entries): ?>
    <p class="muted">No layouts yet. Add the first one below.</p>
  <?php else: ?>
  <div class="entries">
    <?php foreach ($entries as $i => $e): $active = entry_active($e); ?>
    <details class="entry <?= $active ? 'active' : '' ?>">
      <summary>
        <span class="entry-num"><?= $i + 1 ?></span>
        <span class="entry-main"><strong><?= h($e['layout_name']) ?></strong>
          <span class="muted"><?= (int)$e['duration'] ? (int)$e['duration'] . ' s' : 'until content ends' ?> ·<?= h(describe_when($e)) ?></span></span>
        <?php if ($active): ?><span class="badge badge-active">ACTIVE NOW</span><?php endif; ?>
        <span class="entry-actions">
          <?= post_button(['do' => 'move', 'dir' => 'up', 'entry_id' => $e['id']], '↑', 'btn btn-sm') ?>
          <?= post_button(['do' => 'move', 'dir' => 'down', 'entry_id' => $e['id']], '↓', 'btn btn-sm') ?>
          <span class="btn btn-sm">Edit</span>
          <?= post_button(['do' => 'delete', 'entry_id' => $e['id']], 'Remove', 'btn btn-sm btn-danger') ?>
        </span>
      </summary>
      <form method="post" class="entry-form">
        <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="entry_id" value="<?= $e['id'] ?>">
        <?= entry_form_fields($e, $layouts) ?>
        <div class="row"><button class="btn btn-primary">Save entry</button>
          <?php if (is_admin()): ?><a class="btn" target="_blank" href="<?= url(['p' => 'layout', 'id' => $e['layout_id']]) ?>">Edit layout ↗</a>
          <?php else: ?><a class="btn" target="_blank" href="preview.php?layout=<?= $e['layout_id'] ?>">Preview layout ↗</a><?php endif; ?></div>
      </form>
    </details>
    <?php endforeach; ?>
  </div>
  <?php if (!$anyActive): ?>
    <p class="muted small">No entry is active right now, so screens show
      <?= $playlist['fallback_layout_id'] ? '<strong>' . h($layouts[$playlist['fallback_layout_id']] ?? '') . '</strong>' : 'a blank screen' ?>.</p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Add a layout</h2>
  <?php if (!$layouts): ?>
    <p><?= is_admin() ? '<a href="' . url(['p' => 'layouts']) . '">Create a layout</a> first.' : 'There are no layouts yet. Ask an administrator to create some.' ?></p>
  <?php else: ?>
  <form method="post" class="entry-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <?= entry_form_fields(['days' => ''], $layouts) ?>
    <div class="row"><button class="btn btn-primary">Add to queue</button></div>
  </form>
  <p class="muted small">Examples: <em>Mon–Fri 08:00–16:00</em> for opening hours · <em>1 Dec – 26 Dec, every year</em> for Christmas ·
    a single date range for an event.</p>
  <?php endif; ?>
</div>
<?php page_footer();
