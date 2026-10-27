<?php
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if ($user === '' || strlen($pass) < 8) {
        $error = 'Choose a username and a password of at least 8 characters.';
    } elseif ($pass !== ($_POST['password2'] ?? '')) {
        $error = 'The passwords do not match.';
    } else {
        x("INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, 'admin', ?)",
            [$user, password_hash($pass, PASSWORD_DEFAULT), now()]);
        $admin = q1('SELECT * FROM users WHERE id = ?', [last_id()]);
        set_setting('registration_key', random_code(10));
        set_setting('poll_interval', '60');
        if (!empty($_POST['samples'])) {
            require PP_ROOT . '/inc/samples.php';
            set_setting('default_layout', (string)install_samples());
        }
        login_user($admin);
        flash('PiPusher is ready. Install the client on a Raspberry Pi and it will appear here.');
        redirect(url());
    }
}
page_header('Setup');
?>
<div class="card">
  <h1>Welcome to PiPusher</h1>
  <p class="muted">Create the administrator account to get started.</p>
  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Username <input name="username" required value="<?= h($_POST['username'] ?? 'admin') ?>"></label>
    <label>Password <input type="password" name="password" required minlength="8"></label>
    <label>Repeat password <input type="password" name="password2" required minlength="8"></label>
    <label class="check"><input type="checkbox" name="samples" value="1" checked> Add sample pages and a demo layout</label>
    <button class="btn btn-primary">Create account</button>
  </form>
</div>
<?php page_footer();
