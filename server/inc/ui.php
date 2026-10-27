<?php
declare(strict_types=1);

function page_header(string $title, string $active = ''): void
{
    $nav = is_admin()
        ? ['devices' => 'Screens', 'playlists' => 'Playlists', 'layouts' => 'Layouts', 'media' => 'Media', 'users' => 'Users', 'settings' => 'Settings']
        : ['playlists' => 'Playlists'];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · PiPusher</title>
<link rel="stylesheet" href="assets/admin.css?v=<?= PP_VERSION ?>">
</head>
<body>
<?php if (is_logged_in()): ?>
<header class="topbar">
  <a class="brand" href="<?= url() ?>"><span class="brand-mark">▶</span> PiPusher</a>
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
