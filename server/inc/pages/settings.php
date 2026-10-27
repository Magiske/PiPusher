<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'general') {
        set_setting('poll_interval', (string)max(10, min(3600, (int)$_POST['poll_interval'])));
        [$dl, $dp] = parse_content_ref($_POST['default_content'] ?? '');
        set_setting('default_layout', $dl ? "layout:$dl" : ($dp ? "playlist:$dp" : ''));
        $dg = (int)($_POST['default_group'] ?? 0);
        set_setting('default_group', isset(screen_groups()[$dg]) ? (string)$dg : '');
        set_setting('screenshot_interval', (string)max(0, min(1440, (int)$_POST['screenshot_interval'])));
        $key = trim((string)($_POST['registration_key'] ?? ''));
        set_setting('registration_key', preg_replace('/[^A-Za-z0-9_-]/', '', $key));
        flash('Settings saved.');
    } elseif ($do === 'regen_key') {
        set_setting('registration_key', random_code(10));
        flash('New registration key generated. Existing screens are not affected.');
    } elseif ($do === 'security') {
        $req = $_POST['require_mfa'] ?? '';
        set_setting('require_mfa', in_array($req, ['admins', 'all'], true) ? $req : '');
        set_setting('site_name', mb_substr(trim(str_replace(':', '', (string)($_POST['site_name'] ?? ''))), 0, 60));
        flash('Settings saved.' . ($req !== '' ? ' Users without two-factor authentication must set it up before they can continue.' : ''));
    } elseif ($do === 'web_key') {
        set_setting('web_key', ($_POST['enable'] ?? '') === '1' ? random_token(12) : '');
        flash(($_POST['enable'] ?? '') === '1'
            ? 'New web player link created. The old link no longer adds screens; existing web screens keep working.'
            : 'Web player link turned off. Existing web screens keep working.');
    }
    redirect(url(['p' => 'settings']));
}

[$defLayout, $defPlaylist] = parse_content_ref(setting('default_layout'));
$key = (string)setting('registration_key', '');
$webKey = (string)setting('web_key', '');
page_header('Settings', 'settings');
?>
<div class="page-head"><h1>Settings</h1></div>
<div class="grid-2">
  <div class="card">
    <h2>Screens</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="general">
      <label>Sync interval (seconds)
        <input type="number" name="poll_interval" min="10" max="3600" value="<?= poll_interval() ?>">
        <span class="muted small">How often screens check for new content and report status.</span></label>
      <label>Screenshot every (minutes)
        <input type="number" name="screenshot_interval" min="0" max="1440" value="<?= h(setting('screenshot_interval', '10')) ?>">
        <span class="muted small">0 = only when you click "Take screenshot" on a screen.</span></label>
      <label>New screens join group <select name="default_group"><?= select_options(['' => '— no group —'] + array_map(fn($g) => $g['name'], screen_groups()), setting('default_group', '')) ?></select>
        <span class="muted small">They follow the group's content.</span></label>
      <label>New screens without a group show <select name="default_content"><?= content_options($defLayout, $defPlaylist, '— nothing (assign manually) —') ?></select></label>
      <label>Registration key
        <input name="registration_key" value="<?= h($key) ?>" pattern="[A-Za-z0-9_-]*">
        <span class="muted small">Screens installed with this key are approved automatically. Leave empty to approve every screen by hand.</span></label>
      <button class="btn btn-primary">Save</button>
    </form>
    <?= post_button(['do' => 'regen_key'], 'Generate new key', 'btn btn-sm') ?>
  </div>

  <?php
  $dl = client_downloads();
  $base = server_base_url();
  $dlButton = fn(string $f) => empty($dl[$f]['path'])
      ? '<span class="muted small">' . h($dl[$f]['label']) . ': not included with this server</span>'
      : '<a class="btn btn-sm" href="download.php?file=' . $f . '">⬇ ' . h($dl[$f]['label']) . ' <span class="muted small">' . h(human_size((int)$dl[$f]['size'])) . '</span></a>';
  ?>
  <div class="card">
    <h2>Install a Raspberry Pi screen</h2>
    <p>Raspberry Pi OS Bookworm or newer, desktop edition (Pi 3, 4, 5, Zero 2 W).
      <?= $key ? 'On the Pi, open a terminal and run:' : 'Download the client package, copy it to the Pi (e.g. <code>scp</code>) and run on the Pi:' ?></p>
    <pre><?php if ($key): ?>curl -fLo pipusher-client.tar.gz "<?= h(client_download_url('pi')) ?>"
tar xzf pipusher-client.tar.gz<?php else: ?>tar xzf pipusher-client-<?= h(PP_VERSION) ?>.tar.gz<?php endif; ?>

cd pipusher-client-<?= h(PP_VERSION) ?>/
sudo bash install.sh --server <?= h($base) ?><?= $key ? ' --key ' . h($key) : '' ?>

sudo reboot</pre>
    <div class="row"><?= $dlButton('pi') ?></div>
    <p class="muted small">The installer sets up the agent as a service, installs Chromium if needed, starts the player full screen at
      login and turns off screen blanking. <?= $key ? 'With the registration key the screen is approved automatically.'
        : 'Without a registration key the screen shows a code that you approve under Screens.' ?>
      To update, run the same commands with the new version; the configuration and cache are kept.</p>
  </div>

  <div class="card">
    <h2>Install a Windows screen</h2>
    <p>A Windows PC with Google Chrome (or Edge) works like a Pi: cached content, offline playback, screenshots and remote commands.
      No administrator rights needed.</p>
    <h3>Windows 10/11</h3>
    <?php if ($key): ?>
      <p>Open PowerShell (not as administrator) and run:</p>
      <pre>[Net.ServicePointManager]::SecurityProtocol = 'Tls12'
Invoke-WebRequest "<?= h(client_download_url('windows')) ?>" -OutFile "$env:TEMP\pipusher.zip" -UseBasicParsing
Expand-Archive "$env:TEMP\pipusher.zip" "$env:TEMP\pipusher" -Force
powershell -ExecutionPolicy Bypass -File "$env:TEMP\pipusher\pipusher-client-windows-<?= h(PP_VERSION) ?>\install.ps1" -Server <?= h($base) ?> -Key <?= h($key) ?></pre>
    <?php else: ?>
      <p>Download and unpack the client, then in its folder open PowerShell and run:</p>
      <pre>powershell -ExecutionPolicy Bypass -File install.ps1 -Server <?= h($base) ?></pre>
    <?php endif; ?>
    <h3>Windows 7, 8 and 8.1</h3>
    <p>Download and unpack the Windows 7/8 package (32- and 64-bit), then in its folder open PowerShell and run:</p>
    <pre>powershell -ExecutionPolicy Bypass -File install.ps1 -Server <?= h($base) ?><?= $key ? ' -Key ' . h($key) : '' ?></pre>
    <div class="row wrap"><?= $dlButton('windows') ?><?= $dlButton('windows7') ?></div>
    <p class="muted small">The installer copies the client to <code>%LOCALAPPDATA%\PiPusher</code>, starts the player full screen now and
      at every login, and adds <em>PiPusher</em> and <em>Stop PiPusher</em> to the Start menu. Options: <code>-Name "Screen name"</code>,
      <code>-NoAutostart</code>, <code>-NoReboot</code> (the Reboot command does not restart the PC). For a dedicated screen PC, turn on
      automatic sign-in so it comes back after a power cut. To update, run the installer of the new version; the configuration and
      cache are kept. Remove with <code>uninstall.ps1</code> in <code>%LOCALAPPDATA%\PiPusher</code>.</p>
    <p class="muted small">Server version <?= h(PP_VERSION) ?> · PHP <?= h(PHP_VERSION) ?> · SQLite <?= h(db()->query('SELECT sqlite_version()')->fetchColumn()) ?></p>
  </div>

  <div class="card">
    <h2>🔐 Security</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="security">
      <label>Require two-factor authentication for
        <select name="require_mfa"><?= select_options(['' => 'Nobody (each user decides)', 'admins' => 'Administrators', 'all' => 'All users'],
            setting('require_mfa', '')) ?></select>
        <span class="muted small">Users it applies to must set up an authenticator app under their account before they can use
          PiPusher, and cannot turn it off. Set it up for yourself first.</span></label>
      <label>Site name in authenticator apps
        <input name="site_name" maxlength="60" value="<?= h(setting('site_name', '')) ?>" placeholder="<?= h(setting('site_name', '') !== '' ? '' : totp_site_name()) ?>">
        <span class="muted small">Apps list each account as <em>site name</em> + <em>username</em>, e.g. "<?= h(totp_site_name()) ?>: <?= h(current_user()['username']) ?>".
          Applies to new setups; users who already scanned a code can create a new one by turning 2FA off and on.</span></label>
      <button class="btn btn-primary">Save</button>
    </form>
    <?php $without = array_column(array_filter(q('SELECT * FROM users ORDER BY username COLLATE NOCASE'), fn($u) => !mfa_enabled($u)), 'username'); ?>
    <p class="muted small"><?= $without ? 'Without two-factor: ' . h(implode(', ', $without)) . '.' : 'Every user has two-factor authentication on.' ?>
      Manage users under <a href="<?= url(['p' => 'users']) ?>">Users</a>.</p>
  </div>

  <div class="card">
    <h2>🌐 Web player</h2>
    <p>Show content in any browser (smart TV, PC, tablet) without installing the client. Add a single web screen with its own link
      under <a href="<?= url() ?>">Screens</a>, or share this link: every browser that opens it adds itself as a new, approved web screen
      (it follows the defaults above). Add <code>&amp;name=Reception</code> to name it. A cookie remembers the browser, so opening
      the link again reuses its screen.</p>
    <?php if ($webKey !== ''): ?>
      <pre><?= h(server_base_url() . 'play.php?key=' . $webKey) ?></pre>
      <div class="row">
        <?= post_button(['do' => 'web_key', 'enable' => '1'], 'New link', 'btn btn-sm', 'Create a new link? The current one stops adding screens.') ?>
        <?= post_button(['do' => 'web_key', 'enable' => '0'], 'Turn off', 'btn btn-sm') ?>
      </div>
    <?php else: ?>
      <p class="muted">The self-registration link is off.</p>
      <?= post_button(['do' => 'web_key', 'enable' => '1'], 'Create link', 'btn btn-sm btn-primary') ?>
    <?php endif; ?>
    <p class="muted small">Keep the link private. Web screens need the network all the time (no offline cache) and cannot take
      screenshots or reboot. Changing or turning off the link does not affect existing web screens; remove them under Screens.</p>
  </div>

  <div class="card">
    <h2>Server diagnostics</h2>
    <?php
    $yes = fn(bool $ok, string $note = '') => $ok ? '✅ ' . h($note ?: 'yes') : '❌ ' . h($note ?: 'no');
    $diag = [
        'Web server' => h($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
        'PHP' => h(PHP_VERSION . ' (' . PHP_SAPI . ')'),
        'SQLite (pdo_sqlite)' => $yes(extension_loaded('pdo_sqlite')),
        'ZIP bundles (zip)' => $yes(class_exists('ZipArchive'), class_exists('ZipArchive') ? 'yes' : 'missing – ZIP upload disabled'),
        'Max upload' => h(ini_get('upload_max_filesize') . ' per file, ' . ini_get('post_max_size') . ' per request'),
        'Data folder writable' => $yes(is_writable(PP_DATA), PP_DATA),
        'Data folder protected' => is_file(PP_DATA . '/.htaccess') ? '✅ .htaccess present (verify: <a href="data/pipusher.sqlite" target="_blank">this link</a> must give 403/404)' : '❌ no .htaccess',
        'HTTPS' => $yes(str_starts_with(server_base_url(), 'https://'), str_starts_with(server_base_url(), 'https://') ? 'yes' : 'no – use https in production'),
    ];
    ?>
    <table class="table compact diag">
      <?php foreach ($diag as $k => $v): ?><tr><td><?= h($k) ?></td><td><?= $v ?></td></tr><?php endforeach; ?>
    </table>
  </div>

</div>
<p class="muted small">User accounts are managed under <a href="<?= url(['p' => 'users']) ?>">Users</a>; change your own password under <a href="<?= url(['p' => 'account']) ?>">your account</a>.</p>
<?php page_footer();
