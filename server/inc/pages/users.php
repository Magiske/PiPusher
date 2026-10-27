<?php
$me = current_user();
$editId = (int)($_GET['edit'] ?? 0);
$adminCount = fn() => (int)q1("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")['n'];

/** Saves the playlist access of a user from the submitted form. */
function save_playlist_access(int $userId): void
{
    $all = ($_POST['scope'] ?? 'all') === 'all' ? 1 : 0;
    x('UPDATE users SET all_playlists = ? WHERE id = ?', [$all, $userId]);
    x('DELETE FROM user_playlists WHERE user_id = ?', [$userId]);
    if (!$all) {
        foreach ((array)($_POST['playlists'] ?? []) as $pid) {
            if (q1('SELECT id FROM playlists WHERE id = ?', [(int)$pid])) {
                x('INSERT OR IGNORE INTO user_playlists (user_id, playlist_id) VALUES (?, ?)', [$userId, (int)$pid]);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    $role = isset(PP_ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'playlist';
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($do === 'create') {
        if (!preg_match('/^[\w.@-]{2,60}$/u', $username)) {
            flash('Username: 2–60 letters, digits or . _ - @', 'error');
        } elseif (q1('SELECT id FROM users WHERE username = ?', [$username])) {
            flash("The username \"$username\" is already taken.", 'error');
        } elseif (strlen($password) < 8) {
            flash('The password must be at least 8 characters.', 'error');
        } else {
            x('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $role, now()]);
            save_playlist_access(last_id());
            flash("User \"$username\" created.");
        }
        redirect(url(['p' => 'users']));
    }
    $user = q1('SELECT * FROM users WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    if (!$user) {
        redirect(url(['p' => 'users']));
    }
    if ($do === 'save') {
        if ($user['role'] === 'admin' && $role !== 'admin' && $adminCount() <= 1) {
            flash('There must be at least one administrator.', 'error');
        } elseif (!preg_match('/^[\w.@-]{2,60}$/u', $username) || q1('SELECT id FROM users WHERE username = ? AND id != ?', [$username, $user['id']])) {
            flash('That username is invalid or already taken.', 'error');
        } elseif ($password !== '' && strlen($password) < 8) {
            flash('The new password must be at least 8 characters (or leave it empty).', 'error');
        } else {
            x('UPDATE users SET username = ?, role = ? WHERE id = ?', [$username, $role, $user['id']]);
            if ($password !== '') {
                x('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            save_playlist_access((int)$user['id']);
            flash("User \"$username\" saved.");
            redirect(url(['p' => 'users']));
        }
        redirect(url(['p' => 'users', 'edit' => $user['id']]));
    }
    if ($do === 'mfa_reset') {
        disable_mfa((int)$user['id']);
        flash('Two-factor authentication turned off for "' . $user['username'] . '"' . (mfa_required($user)
            ? '. They must set it up again at their next login.' : '. They can set it up again under their account.'));
        redirect(url(['p' => 'users']));
    }
    if ($do === 'delete') {
        if ((int)$user['id'] === (int)$me['id']) {
            flash('You cannot delete your own account.', 'error');
        } elseif ($user['role'] === 'admin' && $adminCount() <= 1) {
            flash('There must be at least one administrator.', 'error');
        } else {
            x('DELETE FROM users WHERE id = ?', [$user['id']]);
            flash('User "' . $user['username'] . '" deleted.');
        }
        redirect(url(['p' => 'users']));
    }
}

$users = q('SELECT * FROM users ORDER BY role, username COLLATE NOCASE');
$playlists = q('SELECT id, name FROM playlists ORDER BY name COLLATE NOCASE');
$editing = $editId ? q1('SELECT * FROM users WHERE id = ?', [$editId]) : null;

/** Role, playlist access and password fields shared by the create and edit forms. */
function user_form_fields(?array $u, array $playlists): string
{
    $role = $u['role'] ?? 'playlist';
    $all = (int)($u['all_playlists'] ?? 1) === 1;
    $chosen = $u ? array_map('intval', array_column(q('SELECT playlist_id FROM user_playlists WHERE user_id = ?', [$u['id']]), 'playlist_id')) : [];
    ob_start(); ?>
    <label>Username <input name="username" required pattern="[\w.@\-]{2,60}" value="<?= h($u['username'] ?? '') ?>" autocomplete="off"></label>
    <label><?= $u ? 'New password <span class="muted small">(leave empty to keep)</span>' : 'Password' ?>
      <input type="password" name="password" minlength="8" <?= $u ? '' : 'required' ?> autocomplete="new-password"></label>
    <label>Role <select name="role" data-role-select><?= select_options(PP_ROLES, $role) ?></select></label>
    <fieldset class="access" data-playlist-access <?= $role === 'admin' ? 'hidden' : '' ?>>
      <legend>Playlists this user may edit</legend>
      <label class="check"><input type="radio" name="scope" value="all" <?= $all ? 'checked' : '' ?>> All playlists (also new ones)</label>
      <label class="check"><input type="radio" name="scope" value="selected" <?= $all ? '' : 'checked' ?>> Only these:</label>
      <div class="access-list">
        <?php foreach ($playlists as $pl): ?>
          <label class="check"><input type="checkbox" name="playlists[]" value="<?= $pl['id'] ?>" <?= in_array((int)$pl['id'], $chosen, true) ? 'checked' : '' ?>> <?= h($pl['name']) ?></label>
        <?php endforeach; ?>
        <?php if (!$playlists): ?><span class="muted small">No playlists yet.</span><?php endif; ?>
      </div>
    </fieldset>
    <?php return (string)ob_get_clean();
}

page_header('Users', 'users');
?>
<div class="page-head"><h1>Users</h1></div>
<div class="grid-2">
  <div class="card">
    <table class="table">
      <thead><tr><th>User</th><th>Role</th><th>Playlists</th><th>2FA</th><th>Last login</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u):
          $scope = 'all';
          if ($u['role'] !== 'admin' && !(int)$u['all_playlists']) {
              $names = array_column(q('SELECT p.name FROM user_playlists up JOIN playlists p ON p.id = up.playlist_id
                                        WHERE up.user_id = ? ORDER BY p.name', [$u['id']]), 'name');
              $scope = $names ? implode(', ', $names) : 'none';
          } ?>
        <tr>
          <td><strong><?= h($u['username']) ?></strong><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="muted small">(you)</span>' : '' ?></td>
          <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-editor' ?>"><?= $u['role'] === 'admin' ? 'ADMIN' : 'PLAYLIST EDITOR' ?></span></td>
          <td class="small"><?= $u['role'] === 'admin' ? '<span class="muted">full control</span>' : h($scope) ?></td>
          <td><?= mfa_enabled($u) ? '<span class="badge badge-follow" title="Two-factor authentication on">ON</span>'
              : '<span class="badge badge-own">OFF</span>' . (mfa_required($u) ? ' <span class="muted small">required</span>' : '') ?></td>
          <td class="small"><?= h(time_ago($u['last_login'] ? (int)$u['last_login'] : null)) ?></td>
          <td class="right">
            <a class="btn btn-sm" href="<?= url(['p' => 'users', 'edit' => $u['id']]) ?>">Edit</a>
            <?php if (mfa_enabled($u) && (int)$u['id'] !== (int)$me['id']): ?>
              <?= post_button(['do' => 'mfa_reset', 'id' => $u['id']], 'Reset 2FA', 'btn btn-sm',
                  'Turn off two-factor authentication for ' . $u['username'] . '? Use this when they lost their phone and recovery codes.') ?>
            <?php endif; ?>
            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
              <?= post_button(['do' => 'delete', 'id' => $u['id']], 'Delete', 'btn btn-sm btn-danger', 'Delete user ' . $u['username'] . '?') ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small"><strong>Administrators</strong> can do everything, including managing users.
      <strong>Playlist editors</strong> only see Playlists: they can add, remove, reorder and change the timing/rules of layouts
      in the playlists they are allowed to edit, and preview them. They cannot create or delete playlists, or change screens,
      layouts, media or settings.</p>
    <p class="muted small"><strong>Two-factor authentication (2FA)</strong>: each user turns it on under their account (👤) with an
      authenticator app. <strong>Reset 2FA</strong> turns it off for a user who lost their phone and recovery codes. To require it,
      see <a href="<?= url(['p' => 'settings']) ?>">Settings</a>.</p>
  </div>

  <div class="card">
    <?php if ($editing): ?>
      <h2>Edit <?= h($editing['username']) ?></h2>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $editing['id'] ?>">
        <?= user_form_fields($editing, $playlists) ?>
        <div class="row"><button class="btn btn-primary">Save user</button><a class="btn" href="<?= url(['p' => 'users']) ?>">Cancel</a></div>
      </form>
    <?php else: ?>
      <h2>Add user</h2>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="do" value="create">
        <?= user_form_fields(null, $playlists) ?>
        <button class="btn btn-primary">Create user</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
