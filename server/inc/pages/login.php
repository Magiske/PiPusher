<?php
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = q1('SELECT * FROM users WHERE username = ?', [trim((string)($_POST['username'] ?? ''))]);
    // Verify against a dummy hash for unknown users so response time doesn't reveal valid usernames.
    $hash = $user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuJ5QlSbNgVJbZQYYQ/1u2iVdeG1qPqK2';
    if (password_verify((string)($_POST['password'] ?? ''), $hash) && $user) {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            x('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $user['id']]);
        }
        login_user($user);
        redirect(url());
    }
    usleep(500000);
    $error = 'Wrong username or password.';
}
page_header('Log in');
?>
<div class="card">
  <h1><span class="brand-mark">▶</span> PiPusher</h1>
  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Username <input name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>"></label>
    <label>Password <input type="password" name="password" required></label>
    <button class="btn btn-primary">Log in</button>
  </form>
</div>
<?php page_footer();
