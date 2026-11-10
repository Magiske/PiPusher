<?php
$id = (int)($_GET['id'] ?? 0);
$m = q1('SELECT * FROM media WHERE id = ?', [$id]);
if (!$m) {
    flash('Media not found.', 'error');
    redirect(url(['p' => 'media']));
}
$back = url(['p' => 'media_edit', 'id' => $id]);
$entryPath = $m['entry'] ? media_dir($id) . '/' . $m['entry'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'render' && $m['type'] === 'text') {
        // Live preview of the text editor: the page as it would be saved, with the current photo.
        header('Content-Type: text/html; charset=utf-8');
        echo text_media_html(text_design_from_post($_POST, text_media_design($m)['photo']));
        exit;
    }
    if ($do === 'save') {
        x('UPDATE media SET name = ?, folder_id = ?, updated_at = ? WHERE id = ?',
            [trim((string)$_POST['name']) ?: $m['name'], valid_folder_id($_POST['folder_id'] ?? null), now(), $id]);
        if ($m['type'] === 'html' && isset($_POST['content']) && $entryPath) {
            file_put_contents($entryPath, str_replace("\r\n", "\n", (string)$_POST['content']));
            reindex_media($id);
        }
        if ($m['type'] === 'text') {
            $photo = text_media_design($m)['photo'];
            $upload = $_FILES['photo_file'] ?? null;
            if ($upload && $upload['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, PP_IMAGE_EXT, true)) {
                    flash('The photo must be an image file.', 'error');
                } else {
                    if ($photo !== '') {
                        @unlink(media_dir($id) . '/' . $photo);
                    }
                    $photo = 'photo.' . $ext;
                    move_uploaded_file($upload['tmp_name'], media_dir($id) . '/' . $photo);
                }
            } elseif (!empty($_POST['photo_remove']) && $photo !== '') {
                @unlink(media_dir($id) . '/' . $photo);
                $photo = '';
            }
            save_text_media($id, text_design_from_post($_POST, $photo));
        }
        if ($m['type'] === 'url') {
            $u = trim((string)($_POST['url'] ?? ''));
            if (preg_match('#^https?://#i', $u) && filter_var($u, FILTER_VALIDATE_URL)) {
                x('UPDATE media SET url = ? WHERE id = ?', [$u, $id]);
            } else {
                flash('Invalid URL – not changed.', 'error');
            }
            $cookies = parse_cookies((string)($_POST['cookies'] ?? ''));
            x('UPDATE media SET cookies = ? WHERE id = ?',
                [implode("\n", array_map(fn($c) => $c['name'] . '=' . $c['value'], $cookies)), $id]);
            $storage = parse_storage((string)($_POST['storage'] ?? ''));
            x('UPDATE media SET storage = ? WHERE id = ?',
                [implode("\n", array_map(fn($c) => $c['name'] . '=' . $c['value'], $storage)), $id]);
        }
        flash('Saved. Screens pick up the change on their next sync.');
    } elseif ($do === 'replace' && in_array($m['type'], ['image', 'video'], true) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $allowed = $m['type'] === 'image' ? PP_IMAGE_EXT : PP_VIDEO_EXT;
        if (!in_array($ext, $allowed, true)) {
            flash('The replacement must be a ' . $m['type'] . ' file.', 'error');
        } else {
            rrmdir(media_dir($id));
            mkdir(media_dir($id), 0775, true);
            move_uploaded_file($_FILES['file']['tmp_name'], media_dir($id) . '/file.' . $ext);
            x('UPDATE media SET entry = ? WHERE id = ?', ['file.' . $ext, $id]);
            reindex_media($id);
            flash('File replaced.');
        }
    }
    redirect($back);
}

$uses = q('SELECT l.id, l.name AS layout, l.orientation, z.name AS zone, z.w, z.h FROM playlist_items p JOIN zones z ON z.id = p.zone_id
           JOIN layouts l ON l.id = z.layout_id WHERE p.media_id = ? GROUP BY l.id, z.id', [$id]);
$files = q('SELECT * FROM media_files WHERE media_id = ? ORDER BY path', [$id]);
$src = $m['type'] === 'url' ? $m['url'] : 'media.php/' . $id . '/' . str_replace('%2F', '/', rawurlencode((string)$m['entry']));
page_header($m['name'], 'media');
?>
<div class="page-head">
  <h1><?= type_badge($m['type']) ?> <?= h($m['name']) ?></h1>
  <a class="btn" href="<?= url(array_filter(['p' => 'media', 'folder' => $m['folder_id']])) ?>">← <?= $m['folder_id'] ? '📁 ' . h(media_folders()[(int)$m['folder_id']]['name'] ?? 'Media') : 'Media' ?></a>
</div>

<form method="post" class="<?= in_array($m['type'], ['html', 'text'], true) ? 'editor-layout' : 'grid-2' ?>" id="media-form"<?= $m['type'] === 'text' ? ' enctype="multipart/form-data" data-text-editor' : '' ?>>
  <?= csrf_field() ?><input type="hidden" name="do" value="save">
  <div class="card stack">
    <label>Name <input name="name" value="<?= h($m['name']) ?>" required></label>
    <label>Folder <select name="folder_id"><?= folder_options($m['folder_id'] ? (int)$m['folder_id'] : null) ?></select></label>
    <?php if ($m['type'] === 'url'): ?>
      <label>URL <input name="url" type="url" value="<?= h($m['url']) ?>" required></label>
      <label>Cookies <span class="muted small">(optional, one per line: name=value)</span>
        <textarea name="cookies" class="code cookies" rows="3" spellcheck="false" placeholder="CookieConsent=…"><?= h($m['cookies']) ?></textarea>
      </label>
      <p class="muted small">For a page that asks to accept cookies: screens set these cookies before showing it, so the
        cookie banner does not appear. Open the page in Chrome or Edge, accept the banner, press F12 → <em>Application</em>
        → <em>Cookies</em> → the site, and copy the consent cookie's name and value, for example <code>CookieConsent</code>
        (Cookiebot), <code>OptanonConsent</code> and <code>OptanonAlertBoxClosed</code> (OneTrust) or
        <code>euconsent-v2</code>. A pasted <code>Cookie:</code> header works too. Screens renew the cookies every day;
        when the site asks again (often after 6–12 months), copy them again.
        <?php if (str_starts_with(strtolower((string)$m['url']), 'http://')): ?><br><strong>Only https pages can take
        cookies inside a layout zone.</strong><?php endif; ?></p>
      <label>Local storage <span class="muted small">(optional, one per line: name=value)</span>
        <textarea name="storage" class="code cookies" rows="2" spellcheck="false" placeholder="cookie-consent=accepted"><?= h($m['storage']) ?></textarea>
      </label>
      <p class="muted small">Some sites keep the accepted banner in local storage instead of a cookie: when the
        <em>Cookies</em> list stays empty after accepting, look under F12 → <em>Application</em> → <em>Local
        storage</em> → the site and copy the consent entry here, for example <code>cookie-consent=accepted</code>.
        Screens set these entries for the page before showing it.</p>
    <?php endif; ?>
    <?php if ($m['type'] === 'html' && $entryPath && is_file($entryPath)): ?>
      <label>HTML <span class="muted small">(<?= h($m['entry']) ?><?= count($files) > 1 ? ', part of a ' . count($files) . '-file bundle' : '' ?>)</span>
        <textarea name="content" id="html-content" class="code" spellcheck="false" data-base="<?= h(server_base_url() . 'media.php/' . $id . '/' . (dirname($m['entry']) === '.' ? '' : dirname($m['entry']) . '/')) ?>"><?= h(file_get_contents($entryPath)) ?></textarea>
      </label>
      <p class="muted small">Ctrl+S saves. The preview updates as you type.</p>
    <?php endif; ?>
    <?php if ($m['type'] === 'text') require PP_ROOT . '/inc/pages/text_editor.php'; ?>
    <div class="row"><button class="btn btn-primary">Save</button></div>
  </div>
  <div class="card">
    <h2>Preview</h2>
    <?php if ($m['type'] === 'text'): ?>
      <label class="row">Shape <select id="text-shape"><?= text_shape_options($uses) ?></select></label>
    <?php endif; ?>
    <div class="preview-box">
      <?php if ($m['type'] === 'image'): ?><img src="<?= h($src) ?>" alt="">
      <?php elseif ($m['type'] === 'video'): ?><video src="<?= h($src) ?>" controls muted></video>
      <?php else: ?><iframe id="html-preview" src="<?= h($src) ?>"></iframe><?php endif; ?>
    </div>
    <h3>Used in</h3>
    <?php if ($uses): ?>
      <ul><?php foreach ($uses as $u): ?><li><a href="<?= url(['p' => 'layout', 'id' => $u['id']]) ?>"><?= h($u['layout']) ?></a> → <?= h($u['zone']) ?></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted">Not used in any layout yet.</p><?php endif; ?>
    <?php if ($files): ?>
      <h3>Files</h3>
      <ul class="small"><?php foreach ($files as $f): ?><li><code><?= h($f['path']) ?></code> <span class="muted"><?= h(human_size((int)$f['size'])) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
</form>

<?php if (in_array($m['type'], ['image', 'video'], true)): ?>
<div class="card">
  <h2>Replace file</h2>
  <form method="post" enctype="multipart/form-data" class="row wrap align-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="replace">
    <input type="file" name="file" required accept="<?= $m['type'] ?>/*">
    <button class="btn">Replace</button>
  </form>
</div>
<?php endif;
page_footer();
