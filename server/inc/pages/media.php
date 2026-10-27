<?php
const PP_HTML_STARTER = <<<'HTML'
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  html, body { margin: 0; height: 100%; overflow: hidden; }
  body { display: flex; align-items: center; justify-content: center;
         font-family: system-ui, sans-serif; background: #1f2937; color: #fff; }
  h1 { font-size: 8vh; }
</style>
</head>
<body>
  <h1>Hello from PiPusher</h1>
  <script>
    // Pages can be dynamic: use JavaScript to fetch data and update the page.
    // The page itself is cached on the screen, so it still loads while the server is offline.
  </script>
</body>
</html>
HTML;

$folderId = valid_folder_id($_GET['folder'] ?? null);
$filter = in_array($_GET['type'] ?? '', ['html', 'image', 'video', 'url'], true) ? $_GET['type'] : '';
$search = trim((string)($_GET['q'] ?? ''));
$here = fn(?int $fid = null) => url(array_filter(['p' => 'media', 'folder' => $fid, 'type' => $filter ?: null]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    $target = valid_folder_id($_POST['folder_id'] ?? null);
    if ($do === 'upload') {
        $files = $_FILES['files'] ?? null;
        $ok = 0;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    flash("Upload of $name failed (error {$files['error'][$i]}). The file may exceed upload_max_filesize.", 'error');
                    continue;
                }
                [$mid, $err] = import_upload($files['tmp_name'][$i], $name, $target);
                $err ? flash($err, 'error') : $ok++;
            }
        } elseif (empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('Upload too large for the PHP limits (post_max_size).', 'error');
        }
        if ($ok) {
            flash("$ok file(s) added.");
        }
    } elseif ($do === 'html_new') {
        $mid = create_html_media(trim((string)$_POST['name']) ?: 'New page', PP_HTML_STARTER, $target);
        redirect(url(['p' => 'media_edit', 'id' => $mid]));
    } elseif ($do === 'url_new') {
        $u = trim((string)($_POST['url'] ?? ''));
        if (!preg_match('#^https?://#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) {
            flash('Enter a valid http(s) URL.', 'error');
        } else {
            create_media(trim((string)$_POST['name']) ?: (parse_url($u, PHP_URL_HOST) ?: $u), 'url', null, $u, false, $target);
            reindex_media(last_id());
            flash('Link added.');
        }
    } elseif ($do === 'delete') {
        delete_media((int)$_POST['id']);
        flash('Media deleted.');
    } elseif ($do === 'move') {
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        foreach ($ids as $mid) {
            x('UPDATE media SET folder_id = ? WHERE id = ?', [$target, $mid]);
        }
        flash(count($ids) . ' item(s) moved to ' . ($target ? media_folders()[$target]['path'] : 'the top level') . '.');
    } elseif ($do === 'folder_new') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        if ($name !== '') {
            x('INSERT INTO media_folders (name, parent_id, created_at) VALUES (?, ?, ?)', [$name, $target, now()]);
            redirect($here(last_id()));
        }
    } elseif ($do === 'folder_save' && $folderId) {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        $parent = in_array($target, folder_descendants($folderId), true) ? media_folders()[$folderId]['parent_id'] : $target;
        x('UPDATE media_folders SET name = ?, parent_id = ? WHERE id = ?', [$name ?: media_folders()[$folderId]['name'], $parent, $folderId]);
        flash('Folder saved.');
    } elseif ($do === 'folder_delete' && $folderId) {
        // Keep the contents: move items and sub-folders up one level, then remove the folder.
        $parent = media_folders()[$folderId]['parent_id'];
        x('UPDATE media SET folder_id = ? WHERE folder_id = ?', [$parent, $folderId]);
        x('UPDATE media_folders SET parent_id = ? WHERE parent_id = ?', [$parent, $folderId]);
        x('DELETE FROM media_folders WHERE id = ?', [$folderId]);
        flash('Folder deleted; its contents moved up one level.');
        redirect($here($parent ? (int)$parent : null));
    }
    redirect($here($folderId));
}

$folders = media_folders();
$current = $folderId ? $folders[$folderId] : null;
$subfolders = array_filter($folders, fn($f) => (int)$f['parent_id'] === (int)$folderId);
$where = [];
$params = [];
if ($search !== '') {
    $where[] = 'm.name LIKE ?';
    $params[] = '%' . $search . '%';
} else {
    $where[] = $folderId ? 'm.folder_id = ?' : 'm.folder_id IS NULL';
    if ($folderId) {
        $params[] = $folderId;
    }
}
if ($filter) {
    $where[] = 'm.type = ?';
    $params[] = $filter;
}
$items = q('SELECT m.*, (SELECT COUNT(*) FROM playlist_items p WHERE p.media_id = m.id) AS uses FROM media m
            WHERE ' . implode(' AND ', $where) . ' ORDER BY m.name COLLATE NOCASE', $params);
$counts = [];
foreach (q('SELECT folder_id, COUNT(*) AS n FROM media WHERE folder_id IS NOT NULL GROUP BY folder_id') as $r) {
    $counts[(int)$r['folder_id']] = (int)$r['n'];
}
$crumbs = [];
for ($f = $current; $f; $f = $folders[(int)$f['parent_id']] ?? null) {
    array_unshift($crumbs, $f);
}
page_header($current ? $current['name'] : 'Media', 'media');
?>
<div class="page-head">
  <h1 class="crumbs">
    <a href="<?= $here() ?>">Media</a>
    <?php foreach ($crumbs as $c): ?><span class="muted">/</span> <a href="<?= $here((int)$c['id']) ?>">📁 <?= h($c['name']) ?></a><?php endforeach; ?>
  </h1>
  <form class="row" method="get">
    <input type="hidden" name="p" value="media">
    <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search all folders…">
  </form>
</div>
<div class="row wrap" style="margin-bottom:1rem">
  <?php foreach (['' => 'All types', 'html' => 'HTML', 'image' => 'Images', 'video' => 'Videos', 'url' => 'Links'] as $k => $label): ?>
    <a class="chip <?= $filter === $k ? 'active' : '' ?>" href="<?= url(array_filter(['p' => 'media', 'folder' => $folderId, 'type' => $k ?: null, 'q' => $search ?: null])) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($search !== ''): ?>
  <p class="muted"><?= count($items) ?> result(s) for “<?= h($search) ?>” in all folders · <a href="<?= $here($folderId) ?>">clear search</a></p>
<?php else: ?>
<div class="folder-grid">
  <?php foreach ($subfolders as $fid => $f): ?>
    <a class="folder" href="<?= $here($fid) ?>"><span class="folder-icon">📁</span><span class="folder-name"><?= h($f['name']) ?></span>
      <span class="muted small"><?= $counts[$fid] ?? 0 ?> item(s)</span></a>
  <?php endforeach; ?>
  <details class="folder folder-new">
    <summary><span class="folder-icon">＋</span><span class="folder-name">New folder</span></summary>
    <form method="post" class="row">
      <?= csrf_field() ?><input type="hidden" name="do" value="folder_new"><input type="hidden" name="folder_id" value="<?= $folderId ?>">
      <input name="name" required maxlength="80" placeholder="Folder name"><button class="btn btn-sm btn-primary">Create</button>
    </form>
  </details>
</div>
<?php endif; ?>

<details class="card add-media" <?= !$items && !$subfolders && $search === '' ? 'open' : '' ?>>
  <summary><strong>＋ Add media<?= $current ? ' to “' . h($current['name']) . '”' : '' ?></strong> <span class="muted small">upload files, write an HTML page or add a link</span></summary>
  <div class="grid-3">
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="upload"><input type="hidden" name="folder_id" value="<?= $folderId ?>">
      <h3>Upload files</h3>
      <label class="dropzone">
        <input type="file" name="files[]" multiple required accept=".html,.htm,.zip,image/*,video/*">
        <span>Drop or choose files</span>
      </label>
      <p class="muted small">HTML pages, ZIP bundles (HTML + CSS/JS/images, with an index.html), images and videos (MP4/H.264 plays best on a Pi).
        Max <?= h(ini_get('upload_max_filesize')) ?> per file.</p>
      <button class="btn btn-primary">Upload</button>
    </form>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="html_new"><input type="hidden" name="folder_id" value="<?= $folderId ?>">
      <h3>New HTML page</h3>
      <label>Name <input name="name" required placeholder="Today's menu"></label>
      <button class="btn btn-primary">Create and edit</button>
    </form>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="url_new"><input type="hidden" name="folder_id" value="<?= $folderId ?>">
      <h3>Link (web page)</h3>
      <label>Name <input name="name" placeholder="Company dashboard"></label>
      <label>URL <input name="url" type="url" required placeholder="https://"></label>
      <p class="muted small">Shown live, so it is skipped while the screen is offline. Some sites refuse to be embedded.</p>
      <button class="btn btn-primary">Add link</button>
    </form>
  </div>
</details>

<?php if (!$items): ?>
  <?php if ($search !== '' || !$subfolders): ?><div class="card empty"><p class="muted"><?= $search !== '' ? 'Nothing found.' : 'This folder is empty.' ?></p></div><?php endif; ?>
<?php else: ?>
<form method="post" id="media-select">
  <?= csrf_field() ?><input type="hidden" name="do" value="move">
  <div class="card row wrap bulk-bar">
    <label class="check"><input type="checkbox" data-select-all> Select all</label>
    <span class="muted small" data-selected-count>0 selected</span>
    <span class="grow"></span>
    <label class="row">Move to <select name="folder_id"><?= folder_options($folderId) ?></select></label>
    <button class="btn" data-needs-selection disabled>Move</button>
  </div>
</form>
<div class="media-grid">
  <?php foreach ($items as $m): $src = 'media.php/' . $m['id'] . '/' . str_replace('%2F', '/', rawurlencode((string)$m['entry'])); ?>
  <div class="card media-card">
    <a class="thumb" href="<?= url(['p' => 'media_edit', 'id' => $m['id']]) ?>">
      <?php if ($m['type'] === 'image'): ?><img src="<?= h($src) ?>" loading="lazy" alt="">
      <?php elseif ($m['type'] === 'video'): ?><video src="<?= h($src) ?>#t=1" preload="metadata" muted></video>
      <?php elseif ($m['type'] === 'html'): ?><iframe src="<?= h($src) ?>" loading="lazy" tabindex="-1" scrolling="no"></iframe>
      <?php else: ?><span class="thumb-url">🔗 <?= h(parse_url((string)$m['url'], PHP_URL_HOST)) ?></span><?php endif; ?>
    </a>
    <div class="media-meta">
      <input type="checkbox" name="ids[]" value="<?= $m['id'] ?>" form="media-select" title="Select">
      <?= type_badge($m['type']) ?>
      <a href="<?= url(['p' => 'media_edit', 'id' => $m['id']]) ?>" class="media-name"><?= h($m['name']) ?></a>
    </div>
    <?php if ($search !== '' && $m['folder_id']): ?><span class="muted small">📁 <?= h($folders[(int)$m['folder_id']]['path'] ?? '') ?></span><?php endif; ?>
    <div class="row space-between">
      <span class="muted small"><?= $m['type'] === 'url' ? 'live link' : h(human_size((int)$m['size'])) ?> · used <?= (int)$m['uses'] ?>×</span>
      <?= post_button(['do' => 'delete', 'id' => $m['id']], 'Delete', 'btn btn-sm btn-danger',
          (int)$m['uses'] ? 'This item is used in ' . $m['uses'] . ' zone(s). Delete it and remove it from them?' : 'Delete this item?') ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($current): ?>
<div class="card">
  <h2>Folder settings</h2>
  <form method="post" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="folder_save">
    <label>Name <input name="name" value="<?= h($current['name']) ?>" required maxlength="80"></label>
    <label>Inside <select name="folder_id"><?= folder_options($current['parent_id'] ? (int)$current['parent_id'] : null, $folderId) ?></select></label>
    <button class="btn">Save folder</button>
  </form>
  <p></p>
  <?= post_button(['do' => 'folder_delete'], 'Delete folder', 'btn btn-sm btn-danger', 'Delete this folder? Its media and sub-folders are moved up one level, nothing is deleted.') ?>
</div>
<?php endif;
page_footer();
