<?php
/** Server log (data/logs.sqlite): browse, filter, settings (debug on/off, retention) and clearing. Administrators only. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if (!log_db()) {
        flash('The log database cannot be opened. Check that the data folder is writable.', 'error');
    } elseif ($do === 'settings') {
        $debug = isset($_POST['debug']);
        $days = max(1, min(365, (int)($_POST['keep_days'] ?? PP_LOG_DEFAULT_DAYS)));
        $wasDebug = log_debug_enabled();
        log_set_setting('debug', $debug ? '1' : '0');
        log_set_setting('keep_days', (string)$days);
        if ($debug !== $wasDebug) {
            log_info($debug ? 'Debug logging turned on' : 'Debug logging turned off');
        }
        log_prune();
        flash('Log settings saved.');
    } elseif ($do === 'clear') {
        log_db()->exec('DELETE FROM logs');
        log_db()->exec('VACUUM');
        log_info('Log cleared');
        flash('Log cleared.');
    }
    redirect(url(['p' => 'logs']));
}

$perPage = 100;
$level = isset(PP_LOG_LEVELS[$_GET['level'] ?? '']) ? $_GET['level'] : '';
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$where = [];
$args = [];
if ($level !== '') {
    $where[] = 'level = ?';
    $args[] = $level;
}
if ($search !== '') {
    $where[] = "(message LIKE ? ESCAPE '\\' OR user LIKE ? ESCAPE '\\' OR device LIKE ? ESCAPE '\\' OR ip LIKE ? ESCAPE '\\' OR source LIKE ? ESCAPE '\\' OR context LIKE ? ESCAPE '\\')";
    $like = '%' . addcslashes($search, '%_\\') . '%';
    array_push($args, $like, $like, $like, $like, $like, $like);
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$rows = [];
$total = 0;
$counts = [];
if ($db = log_db()) {
    $st = $db->prepare('SELECT COUNT(*) FROM logs' . $sqlWhere);
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare('SELECT * FROM logs' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($db->query('SELECT level, COUNT(*) AS n FROM logs GROUP BY level') as $r) {
        $counts[$r['level']] = (int)$r['n'];
    }
}
$levelOptions = ['' => 'All levels (' . array_sum($counts) . ')'];
foreach (PP_LOG_LEVELS as $k => $label) {
    $levelOptions[$k] = $label . ' (' . ($counts[$k] ?? 0) . ')';
}
$pages = max(1, (int)ceil($total / $perPage));
$link = fn(array $extra) => url(array_filter(['p' => 'logs', 'level' => $level, 'q' => $search] + $extra, fn($v) => $v !== '' && $v !== null));
$dbSize = (int)@filesize(PP_DATA . '/logs.sqlite') + (int)@filesize(PP_DATA . '/logs.sqlite-wal');

page_header('Logs', 'logs');
?>
<div class="page-head"><h1>Logs</h1></div>
<?php if (!log_db()): ?>
  <div class="flash flash-error">The log database <code>data/logs.sqlite</code> cannot be opened. Check that the data folder is writable.</div>
<?php endif; ?>

<div class="card">
  <form method="get" class="row log-filter">
    <input type="hidden" name="p" value="logs">
    <select name="level" aria-label="Level">
      <?= select_options($levelOptions, $level) ?>
    </select>
    <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search message, user, screen, IP">
    <button class="btn btn-sm">Filter</button>
    <?php if ($level !== '' || $search !== ''): ?><a class="btn btn-sm" href="<?= url(['p' => 'logs']) ?>">Clear filter</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <p class="muted"><?= $total || $level !== '' || $search !== '' ? 'No entries match the filter.' : 'The log is empty.' ?></p>
  <?php else: ?>
    <table class="table compact log-table">
      <thead><tr><th>Time</th><th>Level</th><th>Source</th><th>User / screen</th><th>IP</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="log-<?= h($r['level']) ?>">
          <td class="nowrap"><?= h(date('Y-m-d H:i:s', (int)$r['ts'])) ?></td>
          <td><span class="badge badge-log-<?= h($r['level']) ?>"><?= h(PP_LOG_LEVELS[$r['level']] ?? $r['level']) ?></span></td>
          <td class="nowrap"><?= h($r['source']) ?></td>
          <td><?= h(implode(' · ', array_filter([(string)$r['user'], (string)$r['device']]))) ?></td>
          <td class="nowrap"><?= h((string)$r['ip']) ?></td>
          <td><?= h($r['message']) ?>
            <?php if ($r['context']): ?><div class="muted small log-context"><?= h($r['context']) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="row log-pager">
      <span class="muted small"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?> · page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= $link(['page' => $page - 1]) ?>">← Newer</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= $link(['page' => $page + 1]) ?>">Older →</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Log settings</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <label class="check"><input type="checkbox" name="debug" value="1"<?= log_debug_enabled() ? ' checked' : '' ?>> Log debug messages</label>
    <span class="muted small">Info and warnings are always logged. Debug adds every sync, file download, screenshot and page
      in the admin panel, which adds up fast with many screens: turn it on while looking into a problem.</span>
    <label>Keep entries for (days)
      <input type="number" name="keep_days" min="1" max="365" value="<?= log_keep_days() ?>" style="max-width:8rem"></label>
    <button class="btn btn-primary">Save</button>
  </form>
  <p class="muted small">The log is stored in its own database, <code>data/logs.sqlite</code> (<?= h(human_size($dbSize)) ?>).
    It is not part of backups, and a restore leaves it as it is.</p>
  <?= post_button(['do' => 'clear'], 'Clear log', 'btn btn-sm btn-danger', 'Delete all log entries?') ?>
</div>
<?php page_footer();
