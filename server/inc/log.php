<?php
/**
 * Server log: what happens on this server (logins, screens registering and syncing, admin changes, PHP problems),
 * kept in its own SQLite database data/logs.sqlite, apart from pipusher.sqlite. Backups and restores leave it alone.
 *
 * Levels: info (normal events), warning (something went wrong or looks suspicious) and debug (every request in
 * detail; only stored while "Log debug messages" is on, under Logs). Entries older than the retention time
 * (default 30 days) are removed. Logging never breaks a request: when the log cannot be written, it is skipped.
 */
declare(strict_types=1);

const PP_LOG_LEVELS = ['info' => 'Info', 'warning' => 'Warning', 'debug' => 'Debug'];
const PP_LOG_DEFAULT_DAYS = 30;

/** The log database, or null when it cannot be opened (the data folder is not writable, no pdo_sqlite). */
function log_db(): ?PDO
{
    static $pdo = null, $failed = false;
    if ($pdo || $failed) {
        return $pdo;
    }
    try {
        ensure_data_dirs();
        $pdo = new PDO('sqlite:' . PP_DATA . '/logs.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 3000');
        if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
            $pdo->exec('PRAGMA journal_mode = WAL'); // screens syncing at the same time do not wait for each other
            $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                level TEXT NOT NULL,
                source TEXT NOT NULL,
                user TEXT,
                device TEXT,
                ip TEXT,
                message TEXT NOT NULL,
                context TEXT
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS logs_ts ON logs (ts)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS logs_level ON logs (level, ts)');
            $pdo->exec('PRAGMA user_version = 1');
        }
    } catch (Throwable $e) {
        $pdo = null;
        $failed = true;
    }
    return $pdo;
}

function log_setting(string $key, string $default = ''): string
{
    try {
        $st = log_db()?->prepare('SELECT value FROM settings WHERE key = ?');
        if (!$st) {
            return $default;
        }
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

function log_set_setting(string $key, string $value): void
{
    log_db()?->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
}

/** Whether debug messages are stored (Logs → "Log debug messages"). */
function log_debug_enabled(): bool
{
    static $on = null;
    return $on ??= log_setting('debug', '0') === '1';
}

/** Days entries are kept (1–365). */
function log_keep_days(): int
{
    return max(1, min(365, (int)log_setting('keep_days', (string)PP_LOG_DEFAULT_DAYS)));
}

/**
 * Writes a log entry. $context is stored as JSON; its "device" and "user" keys fill the columns of the same name
 * (the logged-in user is filled in by itself).
 */
function app_log(string $level, string $message, array $context = []): void
{
    if (!isset(PP_LOG_LEVELS[$level]) || ($level === 'debug' && !log_debug_enabled())) {
        return;
    }
    try {
        $db = log_db();
        if (!$db) {
            return;
        }
        $user = $context['user'] ?? null;
        if ($user === null && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']) && function_exists('current_user')) {
            $user = current_user()['username'] ?? null;
        }
        $device = $context['device'] ?? null;
        unset($context['user'], $context['device']);
        $db->prepare('INSERT INTO logs (ts, level, source, user, device, ip, message, context) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([time(), $level, log_source(), $user === null ? null : (string)$user, $device === null ? null : (string)$device,
                $_SERVER['REMOTE_ADDR'] ?? null, mb_substr($message, 0, 2000),
                $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null]);
        // Now and then, remove entries past the retention time.
        if ((int)$db->lastInsertId() % 200 === 0) {
            log_prune();
        }
    } catch (Throwable $e) {
        // never let logging break the request
    }
}

function log_info(string $message, array $context = []): void
{
    app_log('info', $message, $context);
}

function log_warning(string $message, array $context = []): void
{
    app_log('warning', $message, $context);
}

function log_debug(string $message, array $context = []): void
{
    app_log('debug', $message, $context);
}

/** Where the entry comes from: the admin panel, the screen API, the web player, ... */
function log_source(): string
{
    if (PHP_SAPI === 'cli') {
        return 'cli';
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    return ['index' => 'admin', 'api' => 'api', 'play' => 'web player', 'preview' => 'preview', 'backup' => 'backup',
        'media' => 'media', 'screenshot' => 'screenshot', 'status' => 'status', 'download' => 'download'][$script] ?? ($script ?: 'server');
}

/** "Name (#id)" for a screen row, for the device column. */
function log_device(array $device): string
{
    return ($device['name'] ?? '?') . ' (#' . ($device['id'] ?? '?') . ')';
}

/** Stored context JSON as "key: value · key: value" for the Logs page. */
function log_context_text(string $json): string
{
    $c = json_decode($json, true);
    if (!is_array($c)) {
        return $json;
    }
    $parts = [];
    foreach ($c as $k => $v) {
        $v = is_bool($v) ? ($v ? 'yes' : 'no')
            : (is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $parts[] = str_replace('_', ' ', (string)$k) . ': ' . ($v === '' ? '–' : $v);
    }
    return implode(' · ', $parts);
}

function log_prune(): void
{
    try {
        log_db()?->prepare('DELETE FROM logs WHERE ts < ?')->execute([time() - log_keep_days() * 86400]);
    } catch (Throwable $e) {
    }
}

/** PHP warnings and fatal errors go to the log too (warning level; notices and deprecations as debug). */
function log_install_php_handlers(): void
{
    set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
        if (error_reporting() & $no) { // not silenced with @
            $level = $no & (E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED) ? 'debug' : 'warning';
            app_log($level, 'PHP: ' . $msg, ['file' => basename($file) . ':' . $line]);
        }
        return false; // PHP's own handling continues as before
    });
    register_shutdown_function(function (): void {
        $e = error_get_last();
        if ($e && $e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
            app_log('warning', 'PHP fatal error: ' . $e['message'], ['file' => basename($e['file']) . ':' . $e['line'],
                'url' => $_SERVER['REQUEST_URI'] ?? '']);
        }
    });
}
