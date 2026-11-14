<?php
declare(strict_types=1);

/*
 * Notifications: emails about screens that need attention (offline, low disk or memory, high temperature, failed
 * client update, a lasting error, a new screen waiting for approval), and when the problem is over.
 *
 * - Screen reports are checked on every sync (notify_device). Offline screens are found by a check that runs at
 *   most once a minute, after any sync, web player poll or admin page (notify_after_response), or when an
 *   external cron job / uptime monitor opens notify.php?key=… – the only way to notice that every screen is down.
 * - A problem is watched in the alerts table: it must last the rule's delay before it is notified (memory and
 *   temperature go up and down), is notified once, and is cleared with a margin so it does not flap.
 * - Notifications are queued in the notifications table and mailed together: one email for many screens going
 *   offline at once. Sending happens after the response has gone out, so screens never wait for the mail server.
 */

// kind => [label, value unit, default value, problem title, resolved title]
const PP_NOTIFY_KINDS = [
    'offline' => ['Screen goes offline', 'minutes without contact', 10, 'Screen offline', 'Screen back online'],
    'disk' => ['Low disk space', 'GB free or less', 1, 'Low disk space', 'Disk space OK again'],
    'memory' => ['Low memory', '% free or less, for 5 minutes', 10, 'Low memory', 'Memory OK again'],
    'temp' => ['High temperature', '°C or more, for 2 minutes', 75, 'High temperature', 'Temperature OK again'],
    'update' => ['Client update fails', null, null, 'Client update failed', 'Client update problem over'],
    'error' => ['Screen reports an error', 'minutes in a row', 15, 'Screen error', 'Screen error over'],
    'pending' => ['New screen waits for approval', null, null, 'New screen waiting for approval', ''],
];

/** The rules as set on the Notifications page: kind => ['on' => bool, 'value' => float|null]. */
function notify_rules(): array
{
    $out = [];
    foreach (PP_NOTIFY_KINDS as $k => $def) {
        $v = setting('notify_' . $k . '_value', null);
        $out[$k] = ['on' => setting('notify_' . $k, '1') === '1', 'value' => $def[2] === null ? null : (float)($v ?? $def[2])];
    }
    return $out;
}

function notify_duration(int $s): string
{
    $s = max(0, $s);
    if ($s < 90) return $s . ' seconds';
    if ($s < 5400) return round($s / 60) . ' minutes';
    if ($s < 172800) return floor($s / 3600) . ' h ' . round(($s % 3600) / 60) . ' min';
    return round($s / 86400, 1) . ' days';
}

function notify_when(int $ts): string
{
    return date(date('Y-m-d', $ts) === date('Y-m-d') ? 'H:i' : 'Y-m-d H:i', $ts);
}

/** Adds a notification to the queue (and the log). $state: problem, resolved or info. */
function notify_queue(array $device, string $kind, string $state, string $message): void
{
    x('INSERT INTO notifications (ts, device_id, device, kind, state, message) VALUES (?, ?, ?, ?, ?, ?)',
        [now(), (int)$device['id'], (string)$device['name'], $kind, $state, $message]);
    $title = PP_NOTIFY_KINDS[$kind][$state === 'resolved' ? 4 : 3] ?? $kind;
    ($state === 'problem' ? 'log_warning' : 'log_info')('Notification: ' . $title . ' – ' . $message, ['device' => log_device($device)]);
    $GLOBALS['pp_notify_pending'] = true;
}

/**
 * Moves one problem of one screen along: $bad = the problem is there now, $clear = it is clearly over (a margin
 * below the limit, so a value hovering around it does not send a mail every minute). In between, nothing changes.
 */
function notify_state(array $device, string $kind, bool $on, bool $bad, bool $clear, int $delay, string $problem, string $resolved, ?int $since = null): void
{
    $row = q1('SELECT * FROM alerts WHERE device_id = ? AND kind = ?', [(int)$device['id'], $kind]);
    if (!$on) {
        if ($row) {
            x('DELETE FROM alerts WHERE device_id = ? AND kind = ?', [(int)$device['id'], $kind]);
        }
        return;
    }
    if ($bad) {
        if (!$row) {
            $row = ['since' => $since ?? now(), 'active' => 0];
            x('INSERT INTO alerts (device_id, kind, since, active) VALUES (?, ?, ?, 0)', [(int)$device['id'], $kind, $row['since']]);
        }
        if (!(int)$row['active'] && now() - (int)$row['since'] >= $delay) {
            notify_queue($device, $kind, 'problem', $problem);
            x('UPDATE alerts SET active = 1 WHERE device_id = ? AND kind = ?', [(int)$device['id'], $kind]);
        }
    } elseif ($clear && $row) {
        if ((int)$row['active'] && setting('notify_resolved', '1') === '1' && $resolved !== '') {
            notify_queue($device, $kind, 'resolved', str_replace('{for}', notify_duration(now() - (int)$row['since']), $resolved));
        }
        x('DELETE FROM alerts WHERE device_id = ? AND kind = ?', [(int)$device['id'], $kind]);
    }
}

/** Checks what a screen reported on a sync. $before = its row before this sync, $info = what it sent. */
function notify_device(array $before, array $info): void
{
    try {
        if ($before['status'] !== 'approved' || !(int)($before['notify'] ?? 1)) {
            x('DELETE FROM alerts WHERE device_id = ?', [(int)$before['id']]);
            return;
        }
        $r = notify_rules();
        // It is in contact, so an offline problem is over.
        notify_state($before, 'offline', $r['offline']['on'], false, true, 0, '', 'The screen is back after {for} offline.');
        if (isset($info['disk_free'])) {
            $free = (int)$info['disk_free'];
            $limit = $r['disk']['value'] * 1073741824;
            $of = !empty($info['disk_total']) ? ' of ' . human_size((int)$info['disk_total']) : '';
            notify_state($before, 'disk', $r['disk']['on'], $free <= $limit, $free > $limit * 1.25 + 268435456, 0,
                'Only ' . human_size($free) . ' free' . $of . ' on the disk. Remove unused media or clear space on the screen.',
                'Now ' . human_size($free) . ' free' . $of . '.');
        }
        if (!empty($info['mem_total']) && isset($info['mem_free'])) {
            $pct = 100 * (int)$info['mem_free'] / max(1, (int)$info['mem_total']);
            $v = $r['memory']['value'];
            notify_state($before, 'memory', $r['memory']['on'], $pct <= $v, $pct > $v + 5, 300,
                human_size((int)$info['mem_free']) . ' (' . round($pct) . ' %) of ' . human_size((int)$info['mem_total'])
                . ' memory free. Heavy pages or many videos in one layout can fill it; the screen may slow down or restart its browser.',
                'Now ' . round($pct) . ' % memory free.');
        }
        if (isset($info['cpu_temp_c'])) {
            $t = (float)$info['cpu_temp_c'];
            $v = $r['temp']['value'];
            notify_state($before, 'temp', $r['temp']['on'], $t >= $v, $t <= $v - 5, 120,
                'CPU temperature ' . $t . ' °C. Above about 80 °C a Raspberry Pi slows down: check its cooling and airflow.',
                'Now ' . $t . ' °C.');
        }
        $upd = trim((string)($info['update_error'] ?? ''));
        notify_state($before, 'update', $r['update']['on'], $upd !== '', $upd === '', 0,
            'The client could not update itself: ' . mb_substr($upd, 0, 400), 'The client no longer reports an update problem.');
        if (array_key_exists('last_error', $info)) {
            $err = trim((string)$info['last_error']);
            notify_state($before, 'error', $r['error']['on'], $err !== '', $err === '', (int)($r['error']['value'] * 60),
                'The screen has reported this error for ' . notify_duration((int)($r['error']['value'] * 60)) . ': ' . mb_substr($err, 0, 400),
                'The screen no longer reports an error (it lasted {for}).');
        }
    } catch (Throwable $e) {
        log_warning('Notification check failed: ' . $e->getMessage());
    }
}

/** A new screen waits for approval. */
function notify_pending_screen(array $device, array $info): void
{
    if (notify_rules()['pending']['on']) {
        notify_queue($device, 'pending', 'info', 'Hostname ' . ($info['hostname'] ?: '?') . ', client ' . ($info['client_version'] ?: '?')
            . ', IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . '. Approve it under Screens, or set a registration key so screens are approved by themselves.');
    }
}

/** Finds screens that stopped making contact. */
function notify_check_offline(): void
{
    $r = notify_rules()['offline'];
    $limit = max((int)($r['value'] * 60), poll_interval() * 3 + 15);
    foreach (q("SELECT * FROM devices WHERE status = 'approved' AND notify = 1 AND last_seen IS NOT NULL") as $d) {
        $seen = (int)$d['last_seen'];
        if (now() - $seen > $limit) {
            notify_state($d, 'offline', $r['on'], true, false, 0,
                'No contact since ' . notify_when($seen) . ' (' . notify_duration(now() - $seen) . ' ago). The screen may be switched off, '
                . 'have lost its network, or the client has stopped.', '', $seen);
        }
    }
    // Screens since left out (notify off, removed from approval): forget their offline state.
    x("DELETE FROM alerts WHERE kind = 'offline' AND device_id IN (SELECT id FROM devices WHERE status <> 'approved' OR notify = 0)");
}

/** The address of the admin panel for links in emails (remembered from the last admin visit). */
function notify_base_url(): string
{
    return (string)setting('notify_base_url', '') ?: server_base_url();
}

/** Mails the queued notifications in one email. Returns the number sent. */
function notify_flush(): int
{
    $claim = random_token(8);
    x('UPDATE notifications SET claim = ?, claimed_at = ? WHERE sent_at IS NULL AND attempts < 5 AND ts > ?
       AND (claim IS NULL OR claimed_at < ?)', [$claim, now(), now() - 86400, now() - 300]);
    $rows = q('SELECT * FROM notifications WHERE claim = ? ORDER BY id', [$claim]);
    if (!$rows) {
        return 0;
    }
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    $to = mail_addresses((string)setting('notify_to', ''));
    if (!$to) {
        x("UPDATE notifications SET attempts = 5, error = 'No email address set', claim = NULL WHERE id IN ($ids)");
        return 0;
    }
    [$subject, $text] = notify_compose($rows);
    $err = send_mail($to, $subject, $text);
    if ($err === null) {
        x("UPDATE notifications SET sent_at = ?, error = '', claim = NULL WHERE id IN ($ids)", [now()]);
        log_info('Notification email sent to ' . implode(', ', $to) . ': ' . $subject);
        return count($rows);
    }
    x("UPDATE notifications SET attempts = attempts + 1, error = ?, claim = NULL WHERE id IN ($ids)", [mb_substr($err, 0, 500)]);
    log_warning('Notification email failed: ' . $err);
    return 0;
}

/** Subject and text of the email for some notifications. */
function notify_compose(array $rows): array
{
    $site = (string)setting('site_name', '') ?: 'PiPusher';
    $base = notify_base_url();
    $title = fn($r) => PP_NOTIFY_KINDS[$r['kind']][$r['state'] === 'resolved' ? 4 : 3] ?? $r['kind'];
    if (count($rows) === 1) {
        $subject = $title($rows[0]) . ': ' . $rows[0]['device'];
    } else {
        $n = ['problem' => 0, 'resolved' => 0, 'info' => 0];
        foreach ($rows as $r) {
            $n[$r['state']] = ($n[$r['state']] ?? 0) + 1;
        }
        $parts = array_filter([$n['problem'] ? $n['problem'] . ' problem' . ($n['problem'] > 1 ? 's' : '') : '',
            $n['resolved'] ? $n['resolved'] . ' resolved' : '', $n['info'] ? $n['info'] . ' new screen' . ($n['info'] > 1 ? 's' : '') : '']);
        $subject = implode(', ', $parts);
    }
    $text = '';
    foreach ($rows as $r) {
        $mark = ['problem' => '[!]', 'resolved' => '[OK]', 'info' => '[i]'][$r['state']] ?? '';
        $text .= $mark . ' ' . $title($r) . ' – ' . $r['device'] . "\n"
            . '    ' . notify_when((int)$r['ts']) . ': ' . $r['message'] . "\n"
            . '    ' . $base . 'index.php?p=device&id=' . (int)$r['device_id'] . "\n\n";
    }
    $text .= "-- \n" . ($site === 'PiPusher' ? '' : $site . ' · ') . 'PiPusher ' . PP_VERSION . "\n" . 'Notification settings: ' . $base . "index.php?p=notifications\n";
    return ['[' . $site . '] ' . $subject, $text];
}

/** Runs the offline check (at most once a minute unless forced) and sends what is queued. */
function notify_run(bool $force = false): int
{
    $last = (int)setting('notify_checked_at', '0');
    if ($force || now() - $last >= 60) {
        set_setting('notify_checked_at', (string)now());
        notify_check_offline();
    }
    return notify_flush();
}

/**
 * Has the check and the sending run after this request's response has been sent, when there is something to do.
 * Called by api.php, play.php and the admin panel.
 */
function notify_after_response(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    register_shutdown_function(function () {
        try {
            $due = now() - (int)setting('notify_checked_at', '0') >= 60;
            if (!$due && empty($GLOBALS['pp_notify_pending'])) {
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ignore_user_abort(true);
            @set_time_limit(90);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            notify_run();
        } catch (Throwable $e) {
            log_warning('Notifications failed: ' . $e->getMessage());
        }
    });
}
