<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    ensure_data_dirs();
    $pdo = new PDO('sqlite:' . PP_DATA . '/pipusher.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function ensure_data_dirs(): void
{
    foreach ([PP_DATA, PP_MEDIA, PP_SCREENS] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data directory: $dir");
        }
    }
    $ht = PP_DATA . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
}

function migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $migrations = [
        1 => [
            'CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT)',
            "CREATE TABLE layouts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                orientation TEXT NOT NULL DEFAULT 'landscape',
                background TEXT NOT NULL DEFAULT '#000000',
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )",
            "CREATE TABLE zones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                layout_id INTEGER NOT NULL REFERENCES layouts(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                x REAL NOT NULL, y REAL NOT NULL, w REAL NOT NULL, h REAL NOT NULL,
                z INTEGER NOT NULL DEFAULT 0,
                fit TEXT NOT NULL DEFAULT 'contain',
                transition TEXT NOT NULL DEFAULT 'fade'
            )",
            "CREATE TABLE devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT NOT NULL UNIQUE,
                secret_hash TEXT NOT NULL,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                layout_id INTEGER REFERENCES layouts(id) ON DELETE SET NULL,
                command TEXT,
                last_seen INTEGER,
                ip TEXT,
                info TEXT,
                created_at INTEGER NOT NULL
            )",
            "CREATE TABLE media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                type TEXT NOT NULL,
                entry TEXT,
                url TEXT,
                size INTEGER NOT NULL DEFAULT 0,
                editable INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )",
            "CREATE TABLE media_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE CASCADE,
                path TEXT NOT NULL,
                size INTEGER NOT NULL,
                sha256 TEXT NOT NULL,
                UNIQUE(media_id, path)
            )",
            "CREATE TABLE playlist_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zone_id INTEGER NOT NULL REFERENCES zones(id) ON DELETE CASCADE,
                media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE CASCADE,
                duration INTEGER NOT NULL DEFAULT 10,
                position INTEGER NOT NULL DEFAULT 0
            )",
            'CREATE INDEX idx_zones_layout ON zones(layout_id)',
            'CREATE INDEX idx_items_zone ON playlist_items(zone_id)',
        ],
        2 => [
            'ALTER TABLE devices ADD COLUMN screenshot_at INTEGER',
        ],
        3 => [
            // Playlists: an ordered queue of layouts, each with a duration and optional "when" rules.
            "CREATE TABLE playlists (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                fallback_layout_id INTEGER REFERENCES layouts(id) ON DELETE SET NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )",
            "CREATE TABLE playlist_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                playlist_id INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
                layout_id INTEGER NOT NULL REFERENCES layouts(id) ON DELETE CASCADE,
                duration INTEGER NOT NULL DEFAULT 60,
                position INTEGER NOT NULL DEFAULT 0,
                days TEXT NOT NULL DEFAULT '',
                time_from TEXT NOT NULL DEFAULT '',
                time_to TEXT NOT NULL DEFAULT '',
                date_from TEXT NOT NULL DEFAULT '',
                date_to TEXT NOT NULL DEFAULT '',
                yearly INTEGER NOT NULL DEFAULT 0
            )",
            'CREATE INDEX idx_entries_playlist ON playlist_entries(playlist_id)',
            'ALTER TABLE devices ADD COLUMN playlist_id INTEGER REFERENCES playlists(id) ON DELETE SET NULL',
        ],
        4 => [
            // Multiple users with roles (admin = everything, playlist = edit playlist entries).
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'playlist',
                all_playlists INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL,
                last_login INTEGER
            )",
            // Playlists a "playlist" user may edit when all_playlists = 0.
            "CREATE TABLE user_playlists (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                playlist_id INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, playlist_id)
            )",
            // Move the single admin account of earlier versions into the users table.
            function (PDO $pdo): void {
                $get = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
                $get->execute(['admin_user']);
                $user = $get->fetchColumn();
                $get->execute(['admin_password']);
                $hash = $get->fetchColumn();
                if ($user !== false && $hash !== false) {
                    $pdo->prepare("INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, 'admin', ?)")
                        ->execute([$user, $hash, time()]);
                }
                $pdo->exec("DELETE FROM settings WHERE key IN ('admin_user', 'admin_password')");
            },
        ],
        5 => [
            // Folders (nestable) to organise the media library.
            "CREATE TABLE media_folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                parent_id INTEGER REFERENCES media_folders(id) ON DELETE CASCADE,
                created_at INTEGER NOT NULL
            )",
            'ALTER TABLE media ADD COLUMN folder_id INTEGER REFERENCES media_folders(id) ON DELETE SET NULL',
            'CREATE INDEX idx_media_folder ON media(folder_id)',
        ],
        6 => [
            // Screen groups: members either follow the group's content or run their own.
            "CREATE TABLE screen_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                layout_id INTEGER REFERENCES layouts(id) ON DELETE SET NULL,
                playlist_id INTEGER REFERENCES playlists(id) ON DELETE SET NULL,
                created_at INTEGER NOT NULL
            )",
            'ALTER TABLE devices ADD COLUMN group_id INTEGER REFERENCES screen_groups(id) ON DELETE SET NULL',
            'ALTER TABLE devices ADD COLUMN use_group_content INTEGER NOT NULL DEFAULT 1',
        ],
        7 => [
            // Web screens: browsers playing straight from the server (play.php), identified by a secret link token.
            "ALTER TABLE devices ADD COLUMN kind TEXT NOT NULL DEFAULT 'pi'",
            'ALTER TABLE devices ADD COLUMN web_token TEXT',
            'CREATE UNIQUE INDEX idx_devices_web_token ON devices(web_token)',
        ],
        8 => [
            // Two-factor authentication: TOTP secret (NULL = off), last used time step, hashed recovery codes (JSON).
            'ALTER TABLE users ADD COLUMN totp_secret TEXT',
            'ALTER TABLE users ADD COLUMN totp_last INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE users ADD COLUMN recovery_codes TEXT',
        ],
        9 => [
            // Sound off on this screen (videos and HTML content play muted).
            'ALTER TABLE devices ADD COLUMN muted INTEGER NOT NULL DEFAULT 0',
        ],
        10 => [
            // Picture size in its zone: '' = fit the zone, '50%' = of the picture's own size, '800x600' / '800x' / 'x600' = pixels.
            "ALTER TABLE playlist_items ADD COLUMN size TEXT NOT NULL DEFAULT ''",
        ],
        11 => [
            // Screen alignment: the player's area on the display in % (layouts are placed inside it).
            'ALTER TABLE devices ADD COLUMN frame_x REAL NOT NULL DEFAULT 0',
            'ALTER TABLE devices ADD COLUMN frame_y REAL NOT NULL DEFAULT 0',
            'ALTER TABLE devices ADD COLUMN frame_w REAL NOT NULL DEFAULT 100',
            'ALTER TABLE devices ADD COLUMN frame_h REAL NOT NULL DEFAULT 100',
        ],
        12 => [
            // Folders (one level) to organise layouts.
            "CREATE TABLE layout_folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )",
            'ALTER TABLE layouts ADD COLUMN folder_id INTEGER REFERENCES layout_folders(id) ON DELETE SET NULL',
            'CREATE INDEX idx_layouts_folder ON layouts(folder_id)',
        ],
    ];
    foreach ($migrations as $v => $statements) {
        if ($v <= $version) {
            continue;
        }
        $pdo->beginTransaction();
        foreach ($statements as $sql) {
            $sql instanceof Closure ? $sql($pdo) : $pdo->exec($sql);
        }
        $pdo->exec('PRAGMA user_version = ' . (int)$v);
        $pdo->commit();
    }
}

function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function q1(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function x(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function last_id(): int
{
    return (int)db()->lastInsertId();
}

function setting(string $key, ?string $default = null): ?string
{
    $row = q1('SELECT value FROM settings WHERE key = ?', [$key]);
    return $row ? $row['value'] : $default;
}

function set_setting(string $key, ?string $value): void
{
    x('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)', [$key, $value]);
}
