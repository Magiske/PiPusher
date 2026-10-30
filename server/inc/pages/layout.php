<?php
$id = (int)($_GET['id'] ?? 0);
$layout = q1('SELECT * FROM layouts WHERE id = ?', [$id]);
if (!$layout) {
    flash('Layout not found.', 'error');
    redirect(url(['p' => 'layouts']));
}
$back = url(['p' => 'layout', 'id' => $id]);

function zone_of_layout(int $zoneId, int $layoutId): ?array
{
    return q1('SELECT * FROM zones WHERE id = ? AND layout_id = ?', [$zoneId, $layoutId]);
}

function item_of_layout(int $itemId, int $layoutId): ?array
{
    return q1('SELECT p.* FROM playlist_items p JOIN zones z ON z.id = p.zone_id WHERE p.id = ? AND z.layout_id = ?', [$itemId, $layoutId]);
}

/**
 * Normalises a picture size: '' = fit the zone, "50" or "50%" = of the picture's own size,
 * "800x600" = pixels, "800x" / "x600" = one side in pixels, keeping the aspect ratio. Null when invalid.
 */
function item_size(string $s): ?string
{
    $s = strtolower(str_replace([' ', '×', '*'], ['', 'x', 'x'], trim($s)));
    if ($s === '') {
        return '';
    }
    if (preg_match('/^(\d{1,4})%?$/', $s, $m)) {
        return max(1, min(1000, (int)$m[1])) . '%';
    }
    if (preg_match('/^(\d{1,5})?x(\d{1,5})?$/', $s, $m) && (($m[1] ?? '') !== '' || ($m[2] ?? '') !== '')) {
        $px = fn($v) => ($v ?? '') === '' ? '' : (string)max(1, min(10000, (int)$v));
        return $px($m[1] ?? '') . 'x' . $px($m[2] ?? '');
    }
    return null;
}

/** Duration from a form field: empty = the default (videos until they end, everything else 10 s). */
function item_duration(string $type): int
{
    $v = trim((string)($_POST['duration'] ?? ''));
    return $v === '' ? ($type === 'video' ? 0 : 10) : max(0, (int)$v);
}

function renumber_zone(int $zoneId): void
{
    foreach (q('SELECT id FROM playlist_items WHERE zone_id = ? ORDER BY position, id', [$zoneId]) as $i => $row) {
        x('UPDATE playlist_items SET position = ? WHERE id = ?', [$i, $row['id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'settings') {
        $bg = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['background'] ?? '')) ? $_POST['background'] : '#000000';
        $orientation = isset(PP_ORIENTATIONS[$_POST['orientation'] ?? '']) ? $_POST['orientation'] : 'landscape';
        x('UPDATE layouts SET name = ?, orientation = ?, background = ?, folder_id = ?, updated_at = ? WHERE id = ?',
            [trim((string)$_POST['name']) ?: $layout['name'], $orientation, $bg, valid_layout_folder_id($_POST['folder_id'] ?? null), now(), $id]);
        flash('Layout settings saved.');
    } elseif ($do === 'zones') {
        $zones = json_decode((string)($_POST['zones_json'] ?? ''), true);
        if (!is_array($zones) || !$zones) {
            flash('A layout needs at least one zone.', 'error');
            redirect($back);
        }
        $clamp = fn($v, $min, $max) => max($min, min($max, round((float)$v, 2)));
        $pdo = db();
        $pdo->beginTransaction();
        $existing = array_column(q('SELECT id FROM zones WHERE layout_id = ?', [$id]), 'id');
        $keep = [];
        foreach ($zones as $i => $z) {
            $x = $clamp($z['x'] ?? 0, 0, 99);
            $y = $clamp($z['y'] ?? 0, 0, 99);
            $row = [
                mb_substr(trim((string)($z['name'] ?? '')) ?: 'Zone ' . ($i + 1), 0, 60),
                $x, $y, $clamp($z['w'] ?? 100, 1, 100 - $x), $clamp($z['h'] ?? 100, 1, 100 - $y),
                (int)($z['z'] ?? $i),
                isset(PP_FITS[$z['fit'] ?? '']) ? $z['fit'] : 'contain',
                isset(PP_TRANSITIONS[$z['transition'] ?? '']) ? $z['transition'] : 'fade',
            ];
            $zid = (int)($z['id'] ?? 0);
            if ($zid && in_array($zid, $existing)) {
                x('UPDATE zones SET name = ?, x = ?, y = ?, w = ?, h = ?, z = ?, fit = ?, transition = ? WHERE id = ?', [...$row, $zid]);
                $keep[] = $zid;
            } else {
                x('INSERT INTO zones (name, x, y, w, h, z, fit, transition, layout_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$row, $id]);
            }
        }
        foreach (array_diff($existing, $keep) as $gone) {
            x('DELETE FROM zones WHERE id = ?', [$gone]);
        }
        x('UPDATE layouts SET updated_at = ? WHERE id = ?', [now(), $id]);
        $pdo->commit();
        flash('Zones saved.');
    } elseif ($do === 'item_add') {
        $zone = zone_of_layout((int)$_POST['zone_id'], $id);
        $media = q1('SELECT * FROM media WHERE id = ?', [(int)($_POST['media_id'] ?? 0)]);
        $size = $media && $media['type'] === 'image' ? item_size((string)($_POST['size'] ?? '')) : '';
        if ($zone && $media && $size === null) {
            flash('Picture size must be a percentage like 50% or pixels like 800x600, 800x or x600.', 'error');
        } elseif ($zone && $media) {
            $pos = (int)(q1('SELECT MAX(position) AS m FROM playlist_items WHERE zone_id = ?', [$zone['id']])['m'] ?? -1) + 1;
            x('INSERT INTO playlist_items (zone_id, media_id, duration, size, position) VALUES (?, ?, ?, ?, ?)',
                [$zone['id'], $media['id'], item_duration($media['type']), $size, $pos]);
            flash('Added "' . $media['name'] . '" to ' . $zone['name'] . '.');
        }
    } elseif ($do === 'item_save' && ($item = item_of_layout((int)$_POST['item_id'], $id))) {
        $type = (string)q1('SELECT type FROM media WHERE id = ?', [$item['media_id']])['type'];
        $size = isset($_POST['size']) ? item_size((string)$_POST['size']) : $item['size'];
        if ($size === null) {
            flash('Picture size must be a percentage like 50% or pixels like 800x600, 800x or x600.', 'error');
            $size = $item['size'];
        }
        x('UPDATE playlist_items SET duration = ?, size = ? WHERE id = ?', [item_duration($type), $size, $item['id']]);
    } elseif ($do === 'item_delete' && ($item = item_of_layout((int)$_POST['item_id'], $id))) {
        x('DELETE FROM playlist_items WHERE id = ?', [$item['id']]);
        renumber_zone((int)$item['zone_id']);
    } elseif ($do === 'item_move' && ($item = item_of_layout((int)$_POST['item_id'], $id))) {
        renumber_zone((int)$item['zone_id']);
        $item = item_of_layout((int)$item['id'], $id);
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $other = q1('SELECT * FROM playlist_items WHERE zone_id = ? AND position = ?', [$item['zone_id'], $item['position'] + $dir]);
        if ($other) {
            x('UPDATE playlist_items SET position = ? WHERE id = ?', [$other['position'], $item['id']]);
            x('UPDATE playlist_items SET position = ? WHERE id = ?', [$item['position'], $other['id']]);
        }
    }
    redirect($back . (isset($_POST['zone_id']) ? '#zone-' . (int)$_POST['zone_id'] : ''));
}

$zones = q('SELECT * FROM zones WHERE layout_id = ? ORDER BY z, id', [$id]);
$allMedia = q('SELECT id, name, type, folder_id FROM media ORDER BY name COLLATE NOCASE');
$byFolder = [];
foreach ($allMedia as $m) {
    $byFolder[(int)$m['folder_id']][] = $m;
}
$mediaOptions = '';
$groups = [0 => 'Media (top level)'] + array_map(fn($f) => '📁 ' . $f['path'], media_folders());
foreach ($groups as $fid => $label) {
    if (empty($byFolder[$fid])) {
        continue;
    }
    $mediaOptions .= '<optgroup label="' . h($label) . '">';
    foreach ($byFolder[$fid] as $m) {
        $mediaOptions .= '<option value="' . $m['id'] . '">[' . h(strtoupper($m['type'])) . '] ' . h($m['name']) . '</option>';
    }
    $mediaOptions .= '</optgroup>';
}
$devices = devices_showing($id, null);
$editorZones = array_map(fn($z) => [
    'id' => (int)$z['id'], 'name' => $z['name'], 'x' => (float)$z['x'], 'y' => (float)$z['y'],
    'w' => (float)$z['w'], 'h' => (float)$z['h'], 'z' => (int)$z['z'], 'fit' => $z['fit'], 'transition' => $z['transition'],
], $zones);
$templates = array_map(fn($t) => ['label' => $t['label'], 'zones' => array_map(
    fn($z) => ['name' => $z[0], 'x' => $z[1], 'y' => $z[2], 'w' => $z[3], 'h' => $z[4]], $t['zones'])], layout_templates());

page_header($layout['name'], 'layouts');
?>
<div class="page-head">
  <h1 class="crumbs"><a href="<?= url(['p' => 'layouts']) ?>">Layouts</a> <span class="muted">/</span>
    <?php if ($layout['folder_id'] && isset(layout_folders()[(int)$layout['folder_id']])): ?>
      <a href="<?= url(['p' => 'layouts', 'folder' => (int)$layout['folder_id']]) ?>">📁 <?= h(layout_folders()[(int)$layout['folder_id']]['name']) ?></a> <span class="muted">/</span>
    <?php endif; ?><?= h($layout['name']) ?></h1>
  <a class="btn btn-primary" target="_blank" href="preview.php?layout=<?= $id ?>">Preview ↗</a>
</div>

<div class="card">
  <form method="post" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <label>Name <input name="name" value="<?= h($layout['name']) ?>" required></label>
    <label>Orientation <select name="orientation"><?= select_options(PP_ORIENTATIONS, $layout['orientation']) ?></select></label>
    <label>Background <input type="color" name="background" value="<?= h($layout['background']) ?>"></label>
    <label>Folder <select name="folder_id"><?= layout_folder_options($layout['folder_id'] ? (int)$layout['folder_id'] : null) ?></select></label>
    <button class="btn">Save settings</button>
  </form>
  <p class="muted small">Portrait layouts are rotated by the player, so the Pi's display output can stay in its normal landscape mode.
    Used by: <?= $devices ? implode(', ', array_map(fn($d) => '<a href="' . url(['p' => 'device', 'id' => $d['id']]) . '">' . h($d['name']) . '</a>', $devices)) : 'no screens' ?>.</p>
</div>

<div class="card">
  <div class="page-head">
    <h2>Zones (windows)</h2>
    <div class="row wrap" id="zone-templates"></div>
  </div>
  <form method="post" id="zone-form">
    <?= csrf_field() ?><input type="hidden" name="do" value="zones"><input type="hidden" name="zones_json" id="zones_json">
    <div class="zone-editor">
      <div class="zone-stage-wrap">
        <div id="zone-stage" class="zone-stage <?= str_starts_with($layout['orientation'], 'portrait') ? 'portrait' : '' ?>" style="background:<?= h($layout['background']) ?>"></div>
        <p class="muted small">Drag zones to move them, drag the corner to resize. Values are % of the screen.</p>
      </div>
      <div class="zone-list">
        <table class="table compact zone-table">
          <thead><tr><th>Name</th><th>X</th><th>Y</th><th>W</th><th>H</th><th>Layer</th><th>Fit</th><th>Transition</th><th></th></tr></thead>
          <tbody id="zone-rows"></tbody>
        </table>
        <div class="row">
          <button type="button" class="btn" id="zone-add">+ Add zone</button>
          <button class="btn btn-primary">Save zones</button>
        </div>
        <p class="muted small">Removing a zone also removes its playlist.</p>
      </div>
    </div>
  </form>
</div>

<?php foreach ($zones as $z):
    $items = q('SELECT p.*, m.name AS media_name, m.type FROM playlist_items p JOIN media m ON m.id = p.media_id
                WHERE p.zone_id = ? ORDER BY p.position, p.id', [$z['id']]); ?>
<div class="card" id="zone-<?= $z['id'] ?>">
  <div class="page-head">
    <h2><?= h($z['name']) ?> <span class="muted small"><?= h("{$z['w']}% × {$z['h']}% at {$z['x']}%, {$z['y']}%") ?></span></h2>
  </div>
  <?php if (!$items): ?>
    <p class="muted">Empty – this zone shows the layout background. Add content below.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>#</th><th>Content</th><th>Duration (s)</th><th>Picture size</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $i => $it): $video = $it['type'] === 'video'; $form = 'item-' . $it['id']; ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= type_badge($it['type']) ?> <a href="<?= url(['p' => 'media_edit', 'id' => $it['media_id']]) ?>"><?= h($it['media_name']) ?></a></td>
        <td>
          <form method="post" class="inline" id="<?= $form ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="item_save"><input type="hidden" name="item_id" value="<?= $it['id'] ?>"><input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
            <input type="number" name="duration" min="0" value="<?= $video && !(int)$it['duration'] ? '' : (int)$it['duration'] ?>"
              placeholder="<?= $video ? 'auto' : '10' ?>" class="num" data-autosubmit>
          </form>
          <?php if ($video && !(int)$it['duration']): ?><span class="muted small">until video ends</span><?php endif; ?>
        </td>
        <td>
          <?php if ($it['type'] === 'image'): ?>
            <input name="size" form="<?= $form ?>" value="<?= h($it['size']) ?>" placeholder="fit zone" class="num" data-autosubmit>
          <?php endif; ?>
        </td>
        <td class="right">
          <?= post_button(['do' => 'item_move', 'dir' => 'up', 'item_id' => $it['id'], 'zone_id' => $z['id']], '↑', 'btn btn-sm') ?>
          <?= post_button(['do' => 'item_move', 'dir' => 'down', 'item_id' => $it['id'], 'zone_id' => $z['id']], '↓', 'btn btn-sm') ?>
          <?= post_button(['do' => 'item_delete', 'item_id' => $it['id'], 'zone_id' => $z['id']], 'Remove', 'btn btn-sm btn-danger') ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?php if ($allMedia): ?>
  <form method="post" class="row wrap align-end add-item">
    <?= csrf_field() ?><input type="hidden" name="do" value="item_add"><input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
    <label class="grow">Add content <select name="media_id"><?= $mediaOptions ?></select></label>
    <label>Duration (s) <input type="number" name="duration" min="0" placeholder="auto" class="num"></label>
    <label>Picture size <input name="size" placeholder="fit zone" class="num"></label>
    <button class="btn">Add</button>
  </form>
  <p class="muted small">Duration empty: videos play to the end, everything else 10 s. Picture size empty: the picture fits the zone
    (see Fit above). Or 50% of the picture's own size, 800x600 pixels, or one side like 800x or x600 (keeps the aspect ratio);
    the picture is centred in the zone.</p>
  <?php else: ?>
    <p><a href="<?= url(['p' => 'media']) ?>">Upload or create media</a> first.</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<script>
window.PP_ZONE_EDITOR = {
  zones: <?= json_encode($editorZones, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  templates: <?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  fits: <?= json_encode(PP_FITS) ?>,
  transitions: <?= json_encode(PP_TRANSITIONS) ?>
};
</script>
<?php page_footer();
