<?php
/** Notifications: who gets emails, how they are sent, what about, and what was sent (inc/notify.php). */
const PP_NOTIFY_LIMITS = ['offline' => [2, 1440], 'disk' => [0.1, 1000], 'memory' => [1, 50], 'temp' => [40, 110], 'error' => [1, 1440]];

if (setting('notify_key', '') === '') {
    set_setting('notify_key', random_token(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'email' || $do === 'test') {
        $list = (string)($_POST['notify_to'] ?? '');
        $to = mail_addresses($list);
        set_setting('notify_to', implode(', ', $to));
        set_setting('mail_method', ($_POST['mail_method'] ?? '') === 'php' ? 'php' : 'smtp');
        set_setting('smtp_host', mb_substr(trim((string)($_POST['smtp_host'] ?? '')), 0, 200));
        $port = (int)($_POST['smtp_port'] ?? 0);
        set_setting('smtp_port', $port > 0 && $port < 65536 ? (string)$port : '');
        set_setting('smtp_secure', in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'tls');
        set_setting('smtp_user', mb_substr(trim((string)($_POST['smtp_user'] ?? '')), 0, 200));
        // The password is never shown again: an empty field keeps it.
        if (($_POST['smtp_pass'] ?? '') !== '') {
            set_setting('smtp_pass', (string)$_POST['smtp_pass']);
        } elseif (!empty($_POST['smtp_pass_clear'])) {
            set_setting('smtp_pass', '');
        }
        $from = trim((string)($_POST['mail_from'] ?? ''));
        set_setting('mail_from', filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '');
        log_info('Notification email settings saved', ['recipients' => count($to), 'method' => setting('mail_method')]);
        $dropped = count(preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: []) - count($to);
        if ($dropped > 0) {
            flash($dropped . ' address(es) were not valid email addresses and were left out.', 'error');
        }
        if ($do === 'test') {
            $site = (string)setting('site_name', '') ?: 'PiPusher';
            $err = send_mail($to, '[' . $site . '] Test email', "This is a test from PiPusher.\n\nNotifications about your screens will arrive like this.\n\n-- \n"
                . 'Notification settings: ' . server_base_url() . "index.php?p=notifications\n");
            if ($err === null) {
                log_info('Test email sent to ' . implode(', ', $to));
                flash('Test email sent to ' . implode(', ', $to) . '. If it does not arrive, look in the spam folder.');
            } else {
                log_warning('Test email failed: ' . $err);
                flash('The test email could not be sent: ' . $err, 'error');
            }
        } else {
            flash('Email settings saved.');
        }
    } elseif ($do === 'rules') {
        foreach (PP_NOTIFY_KINDS as $k => $def) {
            set_setting('notify_' . $k, !empty($_POST['on'][$k]) ? '1' : '0');
            if ($def[2] !== null && isset($_POST['value'][$k])) {
                [$min, $max] = PP_NOTIFY_LIMITS[$k];
                $v = (float)str_replace(',', '.', (string)$_POST['value'][$k]);
                set_setting('notify_' . $k . '_value', (string)max($min, min($max, $v ?: (float)$def[2])));
            }
        }
        set_setting('notify_resolved', !empty($_POST['resolved']) ? '1' : '0');
        log_info('Notification rules saved');
        flash('Saved. The rules apply from the next check.');
    } elseif ($do === 'new_key') {
        set_setting('notify_key', random_token(16));
        flash('New check link made. Update your cron job or monitor: the old link no longer works.');
    } elseif ($do === 'clear') {
        x('DELETE FROM notifications');
        flash('Notification history cleared.');
    }
    redirect(url(['p' => 'notifications']));
}

$rules = notify_rules();
$open = q("SELECT a.*, d.name FROM alerts a JOIN devices d ON d.id = a.device_id WHERE a.active = 1 ORDER BY a.since");
$recent = q('SELECT * FROM notifications ORDER BY id DESC LIMIT 100');
$cronUrl = server_base_url() . 'cron.php?key=' . setting('notify_key');
$lastCheck = (int)setting('notify_checked_at', '0');
$lastCron = (int)setting('notify_cron_at', '0');
$opts = function (array $choices, string $current): string {
    $out = '';
    foreach ($choices as $k => $label) {
        $out .= '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
};
$fmt = fn(float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
page_header('Notifications', 'notifications');
?>
<div class="page-head"><h1>Notifications</h1></div>
<p class="muted">Emails when a screen needs attention, and when it is fine again. Screens can be left out on their own page.</p>

<div class="card">
  <h2>Open problems</h2>
  <?php if ($open): ?>
    <table class="table">
      <tr><th>Screen</th><th>Problem</th><th>Since</th></tr>
      <?php foreach ($open as $a): ?>
        <tr><td><a href="<?= url(['p' => 'device', 'id' => $a['device_id']]) ?>"><?= h($a['name']) ?></a></td>
          <td><span class="badge badge-log-warning"><?= h(PP_NOTIFY_KINDS[$a['kind']][3] ?? $a['kind']) ?></span></td>
          <td><?= h(notify_when((int)$a['since'])) ?> <span class="muted small">(<?= h(time_ago((int)$a['since'])) ?>)</span></td></tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?><p class="muted">None: every screen that is watched is fine.</p><?php endif; ?>
</div>

<div class="grid-2">
  <form method="post" class="card stack">
    <?= csrf_field() ?>
    <h2>Email</h2>
    <label>Send to <input name="notify_to" value="<?= h((string)setting('notify_to', '')) ?>" placeholder="you@example.com, colleague@example.com"></label>
    <label>Send with <select name="mail_method" id="mail-method"><?= $opts(['smtp' => 'SMTP server (recommended)', 'php' => "PHP's mail() (the web host's own mail)"], (string)setting('mail_method', 'smtp')) ?></select></label>
    <div class="stack" id="smtp-fields">
      <div class="row wrap align-end">
        <label class="grow">SMTP server <input name="smtp_host" value="<?= h((string)setting('smtp_host', '')) ?>" placeholder="smtp.example.com"></label>
        <label>Security <select name="smtp_secure"><?= $opts(['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (port 25)'], (string)setting('smtp_secure', 'tls')) ?></select></label>
        <label>Port <input name="smtp_port" type="number" min="1" max="65535" style="width:6rem" value="<?= h((string)setting('smtp_port', '')) ?>" placeholder="auto"></label>
      </div>
      <div class="row wrap align-end">
        <label class="grow">User name <input name="smtp_user" value="<?= h((string)setting('smtp_user', '')) ?>" autocomplete="off" placeholder="Often the email address"></label>
        <label class="grow">Password <input name="smtp_pass" type="password" autocomplete="new-password" placeholder="<?= setting('smtp_pass', '') !== '' ? '•••••• (saved; type to change)' : '' ?>"></label>
      </div>
      <?php if (setting('smtp_pass', '') !== ''): ?><label class="check"><input type="checkbox" name="smtp_pass_clear" value="1"> Remove the saved password</label><?php endif; ?>
    </div>
    <label>From address <input name="mail_from" value="<?= h((string)setting('mail_from', '')) ?>" placeholder="<?= h(mail_from()) ?>"></label>
    <p class="muted small">Use the mailbox of your web host or mail provider. Gmail and Microsoft 365 need an <em>app password</em>
      (made in the account's security settings) instead of the normal one. Many servers only accept a From address of the
      mailbox you log in with. The SMTP password is stored on the server (also in backups).</p>
    <div class="row wrap">
      <button class="btn btn-primary" name="do" value="email">Save</button>
      <button class="btn" name="do" value="test">Save and send a test email</button>
    </div>
  </form>

  <form method="post" class="card stack">
    <?= csrf_field() ?><input type="hidden" name="do" value="rules">
    <h2>Notify me when</h2>
    <table class="table notify-rules">
      <?php foreach (PP_NOTIFY_KINDS as $k => $def): $r = $rules[$k]; ?>
        <tr>
          <td><label class="check"><input type="checkbox" name="on[<?= $k ?>]" value="1" <?= $r['on'] ? 'checked' : '' ?>> <?= h($def[0]) ?></label></td>
          <td><?php if ($def[2] !== null): ?>
            <input name="value[<?= $k ?>]" type="number" step="any" min="<?= PP_NOTIFY_LIMITS[$k][0] ?>" max="<?= PP_NOTIFY_LIMITS[$k][1] ?>" value="<?= h($fmt((float)$r['value'])) ?>">
            <span class="muted small"><?= h($def[1]) ?></span>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <label class="check"><input type="checkbox" name="resolved" value="1" <?= setting('notify_resolved', '1') === '1' ? 'checked' : '' ?>> Also when the problem is over (screen back online, disk space freed, …)</label>
    <p class="muted small">Disk space and memory are reported by client 1.42.0 or newer (older Pi clients report disk space only),
      temperature by Raspberry Pi and Android screens. A screen error is only notified when it lasts, so a short network
      hiccup sends nothing. Many screens going offline at once arrive in one email.</p>
    <div class="row"><button class="btn btn-primary">Save</button></div>
  </form>
</div>

<div class="card">
  <h2>Regular check</h2>
  <p>Offline screens are looked for whenever a screen syncs or someone uses this admin panel. If <strong>every</strong>
    screen loses its connection at once (a power cut or an internet outage at the location), nothing syncs and the
    problem would not be noticed. Let a scheduled job open this link every 5 minutes:</p>
  <p><code class="break-all"><?= h($cronUrl) ?></code></p>
  <p class="muted small">In your web host's control panel add a <em>cron job</em>, for example
    <code>*/5 * * * * curl -fsS "<?= h($cronUrl) ?>" &gt;/dev/null</code>, or use a free monitor such as cron-job.org or
    UptimeRobot (which also tells you when this server itself is down). Keep the link private.
    Last check: <?= $lastCheck ? h(notify_when($lastCheck)) . ' (' . h(time_ago($lastCheck)) . ')' : 'never' ?> ·
    last check by the link: <?= $lastCron ? h(notify_when($lastCron)) . ' (' . h(time_ago($lastCron)) . ')' : 'never' ?>.</p>
  <?= post_button(['do' => 'new_key'], 'Make a new link', 'btn btn-sm', 'Make a new link? The old link stops working.') ?>
</div>

<div class="card">
  <div class="row space-between"><h2>Recent notifications</h2>
    <?php if ($recent): ?><?= post_button(['do' => 'clear'], 'Clear', 'btn btn-sm btn-danger', 'Clear the notification history?') ?><?php endif; ?></div>
  <?php if ($recent): ?>
    <table class="table log-table">
      <tr><th>Time</th><th>Screen</th><th>Notification</th><th>Email</th></tr>
      <?php foreach ($recent as $n):
          $title = PP_NOTIFY_KINDS[$n['kind']][$n['state'] === 'resolved' ? 4 : 3] ?? $n['kind'];
          $badge = ['problem' => 'badge-log-warning', 'resolved' => 'badge-log-info', 'info' => 'badge-log-debug'][$n['state']] ?? ''; ?>
        <tr>
          <td class="nowrap"><?= h(date('Y-m-d H:i', (int)$n['ts'])) ?></td>
          <td class="nowrap"><?= $n['device_id'] ? '<a href="' . url(['p' => 'device', 'id' => $n['device_id']]) . '">' . h($n['device']) . '</a>' : h($n['device']) ?></td>
          <td><span class="badge <?= $badge ?>"><?= h($title) ?></span> <span class="small"><?= h($n['message']) ?></span></td>
          <td class="small nowrap"><?php if ($n['sent_at']): ?>Sent <?= h(notify_when((int)$n['sent_at'])) ?>
            <?php elseif ($n['error'] !== ''): ?><span class="text-error" style="white-space:normal"><?= (int)$n['attempts'] >= 5 ? 'Not sent' : 'Retrying' ?>: <?= h($n['error']) ?></span>
            <?php else: ?><span class="muted">Waiting</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?><p class="muted">Nothing yet.</p><?php endif; ?>
</div>
<script>
  (function () {
    var m = document.getElementById('mail-method'), f = document.getElementById('smtp-fields');
    var sync = function () { f.style.display = m.value === 'smtp' ? '' : 'none'; };
    m.addEventListener('change', sync); sync();
  })();
</script>
<?php page_footer();
