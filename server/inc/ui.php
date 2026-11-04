<?php
declare(strict_types=1);

function page_header(string $title, string $active = ''): void
{
    $nav = is_admin()
        ? ['devices' => 'Screens', 'playlists' => 'Playlists', 'layouts' => 'Layouts', 'media' => 'Media', 'users' => 'Users', 'settings' => 'Settings']
        : ['playlists' => 'Playlists'];
    ?><!doctype html>
<html lang="en"<?= time_format() !== '' ? ' data-time-format="' . time_format() . '"' : '' ?><?= console_date_format() !== '' ? ' data-date-format="' . console_date_format() . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · PiPusher</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/admin.css?v=<?= PP_VERSION ?>">
</head>
<body>
<?php if (is_logged_in()): ?>
<header class="topbar">
  <a class="brand" href="<?= url() ?>"><img class="brand-mark" src="assets/favicon.svg" alt=""> PiPusher</a>
  <nav>
    <?php foreach ($nav as $k => $label): ?>
      <a href="<?= url(['p' => $k]) ?>" class="<?= $active === $k ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="topbar-user">
    <span class="version" title="PiPusher server version">v<?= h(PP_VERSION) ?></span>
    <a class="account <?= $active === 'account' ? 'active' : '' ?>" href="<?= url(['p' => 'account']) ?>" title="Your account">👤 <?= h(current_user()['username']) ?></a>
  </span>
  <a class="logout" href="<?= url(['p' => 'logout']) ?>">Log out</a>
</header>
<?php endif; ?>
<main class="<?= is_logged_in() ? '' : 'narrow' ?>">
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash flash-<?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

function page_footer(): void
{
    ?>
</main>
<script src="assets/admin.js?v=<?= PP_VERSION ?>"></script>
</body>
</html>
<?php
}

function select_options(array $options, $selected): string
{
    $out = '';
    foreach ($options as $value => $label) {
        $out .= '<option value="' . h($value) . '"' . ((string)$value === (string)$selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $out;
}

/**
 * Hour and minute selects for a time of day ("HH:MM"), posted as <name>_h and <name>_m (read with posted_time()).
 * $special adds choices before the hours, such as '' => 'Off'; they hide the minutes. admin.js relabels the
 * hours in the browser's own time format (3 AM / 03).
 */
function time_select(string $name, string $value, array $special): string
{
    [$vh, $vm] = is_hhmm($value) ? array_map('intval', explode(':', $value)) : [null, 0];
    $out = '<span class="time-select" data-time-select><select name="' . h($name) . '_h" aria-label="Hour">';
    foreach ($special as $k => $label) {
        $hm = preg_match('/([01]\d|2[0-3]):[0-5]\d/', $label, $mm) ? ' data-hm="' . $mm[0] . '"' : ''; // a time in the label is localised too
        $out .= '<option value="' . h((string)$k) . '"' . $hm . ($vh === null && (string)$k === $value ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    for ($i = 0; $i < 24; $i++) {
        $out .= '<option value="' . $i . '" data-hour="' . $i . '"' . ($vh === $i ? ' selected' : '') . '>' . sprintf('%02d', $i) . '</option>';
    }
    $out .= '</select><span class="time-sep">:</span><select name="' . h($name) . '_m" aria-label="Minute">';
    for ($i = 0; $i < 60; $i++) {
        if ($i % 5 === 0 || $i === $vm) {
            $out .= '<option value="' . $i . '"' . ($vm === $i ? ' selected' : '') . '>' . sprintf('%02d', $i) . '</option>';
        }
    }
    return $out . '</select></span>';
}

/**
 * Day, month and year selects posting <name>_d, <name>_m and <name>_y ('' = no date); read with posted_date().
 * admin.js puts them in the viewer's order (Settings -> Date format, or the browser's) and names the months.
 */
function date_select(string $name, string $value): string
{
    [$vy, $vm, $vd] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? array_map('intval', explode('-', $value)) : [0, 0, 0];
    $sel = fn($a, $b) => $a === $b ? ' selected' : '';
    $out = '<span class="date-select" data-date-select><select name="' . h($name) . '_d" aria-label="Day"><option value="">—</option>';
    for ($i = 1; $i <= 31; $i++) {
        $out .= '<option value="' . $i . '"' . $sel($vd, $i) . '>' . $i . '</option>';
    }
    $out .= '</select><select name="' . h($name) . '_m" aria-label="Month"><option value="">—</option>';
    for ($i = 1; $i <= 12; $i++) {
        $out .= '<option value="' . $i . '"' . $sel($vm, $i) . '>' . date('M', mktime(0, 0, 0, $i, 1)) . '</option>';
    }
    $out .= '</select><select name="' . h($name) . '_y" aria-label="Year"><option value="">—</option>';
    $now = (int)date('Y');
    $years = array_unique(array_merge($vy ? [$vy] : [], range($now - 1, $now + 5)));
    sort($years);
    foreach ($years as $y) {
        $out .= '<option value="' . $y . '"' . $sel($vy, $y) . '>' . $y . '</option>';
    }
    return $out . '</select></span>';
}

/** A time of day shown in the viewer's own format by admin.js ("3:00 AM" / "03.00"); HH:MM without script. */
function local_time(string $hhmm): string
{
    return '<time data-hm="' . h($hhmm) . '">' . h($hhmm) . '</time>';
}

function type_badge(string $type): string
{
    return '<span class="badge badge-' . h($type) . '">' . h(strtoupper($type)) . '</span>';
}

function post_button(array $fields, string $label, string $class = 'btn', ?string $confirm = null): string
{
    $out = '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $out .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return $out . '<button class="' . h($class) . '">' . h($label) . '</button></form>';
}
