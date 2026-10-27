<?php
$error = null;
// Step 2 (two-factor): the password was right; the user id waits in the session for the code (5 minutes, 5 tries).
$pending = $_SESSION['mfa_pending'] ?? null;
if ($pending && ($pending['until'] < now() || $pending['tries'] >= 5 || !q1('SELECT id FROM users WHERE id = ?', [$pending['user']]))) {
    unset($_SESSION['mfa_pending']);
    $pending = null;
    $error = 'The sign-in expired. Log in again.';
}
if (isset($_GET['cancel'])) {
    unset($_SESSION['mfa_pending']);
    redirect(url(['p' => 'login']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pending && isset($_POST['code'])) {
    $user = q1('SELECT * FROM users WHERE id = ?', [$pending['user']]);
    $code = (string)$_POST['code'];
    $step = totp_verify((string)$user['totp_secret'], $code, (int)$user['totp_last']);
    if ($step !== null || (preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/i', trim($code)) && use_recovery_code($user, $code))) {
        if ($step !== null) {
            x('UPDATE users SET totp_last = ? WHERE id = ?', [$step, $user['id']]);
        } else {
            flash('You signed in with a recovery code. ' . recovery_codes_left(q1('SELECT * FROM users WHERE id = ?', [$user['id']]))
                . ' left – create new ones under your account if you are running low.', 'error');
        }
        unset($_SESSION['mfa_pending']);
        login_user($user);
        redirect(url());
    }
    $_SESSION['mfa_pending']['tries']++;
    usleep(500000);
    $error = 'Wrong code. Try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $user = q1('SELECT * FROM users WHERE username = ?', [trim((string)($_POST['username'] ?? ''))]);
    // Verify against a dummy hash for unknown users so response time doesn't reveal valid usernames.
    $hash = $user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuJ5QlSbNgVJbZQYYQ/1u2iVdeG1qPqK2';
    if (password_verify((string)($_POST['password'] ?? ''), $hash) && $user) {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            x('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $user['id']]);
        }
        if (mfa_enabled($user)) {
            session_regenerate_id(true);
            $_SESSION['mfa_pending'] = ['user' => (int)$user['id'], 'until' => now() + 300, 'tries' => 0];
            redirect(url(['p' => 'login']));
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
  <?php if ($pending): ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <p>Enter the 6-digit code from your authenticator app.</p>
      <label>Code <input name="code" required autofocus autocomplete="one-time-code" maxlength="11"
        pattern="\d{6}|\s*[A-Za-z0-9]{5}-[A-Za-z0-9]{5}\s*"></label>
      <button class="btn btn-primary">Verify</button>
      <p class="muted small">Lost your phone? Enter one of your recovery codes (xxxxx-xxxxx) instead.
        <a href="<?= url(['p' => 'login', 'cancel' => 1]) ?>">Cancel</a></p>
    </form>
  <?php else: ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label>Username <input name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>"></label>
      <label>Password <input type="password" name="password" required></label>
      <button class="btn btn-primary">Log in</button>
    </form>
  <?php endif; ?>
</div>
<?php page_footer();
