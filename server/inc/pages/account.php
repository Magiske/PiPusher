<?php
$me = current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!password_verify((string)($_POST['current'] ?? ''), $me['password_hash'])) {
        flash('Current password is wrong.', 'error');
    } elseif (strlen((string)($_POST['new'] ?? '')) < 8 || $_POST['new'] !== ($_POST['new2'] ?? '')) {
        flash('The new password must be at least 8 characters and match the repeat.', 'error');
    } else {
        x('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['new'], PASSWORD_DEFAULT), $me['id']]);
        session_regenerate_id(true);
        flash('Password changed.');
    }
    redirect(url(['p' => 'account']));
}

$allowed = allowed_playlist_ids();
page_header('Your account', 'account');
?>
<div class="page-head"><h1>👤 <?= h($me['username']) ?></h1></div>
<div class="grid-2">
  <div class="card">
    <h2>Access</h2>
    <dl class="kv">
      <dt>Role</dt><dd><?= h(PP_ROLES[$me['role']] ?? $me['role']) ?></dd>
      <?php if ($me['role'] !== 'admin'): ?>
        <dt>Playlists</dt><dd><?= $allowed === null ? 'All playlists' : ($allowed
            ? h(implode(', ', array_column(q('SELECT name FROM playlists WHERE id IN (' . implode(',', $allowed) . ') ORDER BY name'), 'name')))
            : 'None – ask an administrator for access') ?></dd>
      <?php endif; ?>
      <dt>Last login</dt><dd><?= $me['last_login'] ? h(date('Y-m-d H:i', (int)$me['last_login'])) : '—' ?></dd>
    </dl>
  </div>
  <div class="card">
    <h2>Change password</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password <input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
      <label>Repeat new password <input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary">Change password</button>
    </form>
  </div>
</div>
<?php page_footer();
