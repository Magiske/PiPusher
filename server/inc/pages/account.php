<?php
$me = current_user();
$do = $_POST['do'] ?? 'password';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $do !== 'password') {
    // Two-factor authentication. While setting up, the new secret stays in the session until a code confirms it.
    $codeOk = function (string $code) use ($me): bool {
        $step = mfa_enabled($me) ? totp_verify($me['totp_secret'], $code, (int)$me['totp_last']) : null;
        if ($step !== null) {
            x('UPDATE users SET totp_last = ? WHERE id = ?', [$step, $me['id']]);
            return true;
        }
        return mfa_enabled($me) && preg_match('/^[a-z0-9]{5}-[a-z0-9]{5}$/i', trim($code)) && use_recovery_code($me, $code);
    };
    if ($do === 'mfa_start' && !mfa_enabled($me)) {
        $_SESSION['totp_setup'] = totp_new_secret();
    } elseif ($do === 'mfa_cancel') {
        unset($_SESSION['totp_setup']);
    } elseif ($do === 'mfa_confirm' && !mfa_enabled($me) && !empty($_SESSION['totp_setup'])) {
        $step = totp_verify($_SESSION['totp_setup'], (string)($_POST['code'] ?? ''));
        if ($step === null) {
            flash('That code is not right. Check that the time on your phone is correct and try the next code.', 'error');
        } else {
            x('UPDATE users SET totp_secret = ?, totp_last = ? WHERE id = ?', [$_SESSION['totp_setup'], $step, $me['id']]);
            unset($_SESSION['totp_setup']);
            $_SESSION['recovery_codes'] = new_recovery_codes((int)$me['id']);
            flash('Two-factor authentication is on. From now on you enter a code from your app when you log in.');
        }
    } elseif ($do === 'mfa_off' && mfa_enabled($me)) {
        if (mfa_required($me)) {
            flash('Two-factor authentication is required for your account (Settings), so it cannot be turned off.', 'error');
        } elseif (!password_verify((string)($_POST['current'] ?? ''), $me['password_hash']) || !$codeOk((string)($_POST['code'] ?? ''))) {
            flash('Wrong password or code. Two-factor authentication is still on.', 'error');
        } else {
            disable_mfa((int)$me['id']);
            flash('Two-factor authentication is off.');
        }
    } elseif ($do === 'mfa_codes' && mfa_enabled($me)) {
        if ($codeOk((string)($_POST['code'] ?? ''))) {
            $_SESSION['recovery_codes'] = new_recovery_codes((int)$me['id']);
            flash('New recovery codes created. The old ones no longer work.');
        } else {
            flash('Wrong code.', 'error');
        }
    }
    redirect(url(['p' => 'account']));
}
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
$setupSecret = mfa_enabled($me) ? null : ($_SESSION['totp_setup'] ?? null);
$showCodes = $_SESSION['recovery_codes'] ?? null;
unset($_SESSION['recovery_codes']); // shown once
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
      <?= csrf_field() ?><input type="hidden" name="do" value="password">
      <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password <input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
      <label>Repeat new password <input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary">Change password</button>
    </form>
  </div>

  <div class="card" id="mfa">
    <h2>Two-factor authentication <?= mfa_enabled($me) ? '<span class="badge badge-follow">ON</span>' : '<span class="badge badge-own">OFF</span>' ?></h2>
    <?php if ($showCodes): ?>
      <div class="flash flash-ok"><strong>Your recovery codes.</strong> Save or print them now; they are not shown again.
        Each code works once, if you lose your phone.</div>
      <pre class="recovery-codes"><?= h(implode("\n", $showCodes)) ?></pre>
    <?php endif; ?>

    <?php if (mfa_enabled($me)): ?>
      <p>When you log in you also enter a code from your authenticator app. <?= recovery_codes_left($me) ?> recovery code(s) left.</p>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="do" value="mfa_codes">
        <label>Code from your app <input name="code" required autocomplete="one-time-code" maxlength="11"></label>
        <button class="btn">Create new recovery codes</button>
      </form>
      <?php if (!mfa_required($me)): ?>
        <form method="post" class="stack" data-confirm="Turn off two-factor authentication?">
          <?= csrf_field() ?><input type="hidden" name="do" value="mfa_off">
          <h3>Turn off</h3>
          <label>Password <input type="password" name="current" required autocomplete="current-password"></label>
          <label>Code from your app (or a recovery code) <input name="code" required autocomplete="one-time-code" maxlength="11"></label>
          <button class="btn btn-danger">Turn off two-factor</button>
        </form>
      <?php else: ?>
        <p class="muted small">Required for your account by the administrator.</p>
      <?php endif; ?>

    <?php elseif ($setupSecret): ?>
      <ol class="steps">
        <li>Scan this QR code with an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, …).
          <div class="qr" data-qr="<?= h(totp_uri($setupSecret, $me['username'])) ?>"></div>
          <span class="muted small">The app lists it as <strong><?= h(totp_site_name()) ?>: <?= h($me['username']) ?></strong>.<br>
            Can't scan? Enter this key in the app: <code><?= h(trim(chunk_split($setupSecret, 4, ' '))) ?></code></span></li>
        <li>Enter the 6-digit code the app shows:
          <form method="post" class="row wrap align-end">
            <?= csrf_field() ?><input type="hidden" name="do" value="mfa_confirm">
            <label class="grow">Code <input name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" pattern="\s*\d{3}\s*\d{3}\s*" maxlength="7"></label>
            <button class="btn btn-primary">Turn on</button>
          </form></li>
      </ol>
      <?= post_button(['do' => 'mfa_cancel'], 'Cancel', 'btn btn-sm') ?>
      <script src="assets/qrcode.js?v=<?= PP_VERSION ?>"></script>

    <?php else: ?>
      <p>Protect your account with a code from an authenticator app on your phone, in addition to your password.</p>
      <?php if (mfa_required($me)): ?><div class="flash flash-error">Two-factor authentication is required for your account. Turn it on to continue.</div><?php endif; ?>
      <?= post_button(['do' => 'mfa_start'], 'Set up two-factor authentication', 'btn btn-primary') ?>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
