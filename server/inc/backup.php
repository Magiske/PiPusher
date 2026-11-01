<?php
declare(strict_types=1);

/*
 * Backup and restore (Settings). A backup is a zip with
 *   backup.json        {app, version, created_at, site_name}
 *   pipusher.sqlite    a consistent copy of the database (VACUUM INTO)
 *   media/...          uploaded media
 *   previews/...       layout previews
 * Screenshots are left out: screens send new ones.
 */

/** Builds a backup zip in the data directory and returns its path (the caller deletes it). */
function backup_create(): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is missing.');
    }
    @set_time_limit(0);
    backup_cleanup();
    $base = PP_DATA . '/backup-' . bin2hex(random_bytes(6));
    $dbCopy = $base . '.sqlite';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($dbCopy));
    } catch (PDOException) {
        copy(PP_DATA . '/pipusher.sqlite', $dbCopy); // SQLite older than 3.27
    }
    $zip = new ZipArchive();
    if ($zip->open($base . '.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($dbCopy);
        throw new RuntimeException('Cannot create the backup file in the data directory.');
    }
    $zip->addFromString('backup.json', json_encode([
        'app' => 'PiPusher',
        'version' => PP_VERSION,
        'created_at' => now(),
        'site_name' => (string)setting('site_name', ''),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->addFile($dbCopy, 'pipusher.sqlite');
    foreach (['media' => PP_MEDIA, 'previews' => PP_PREVIEWS] as $prefix => $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $name = $prefix . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
                $zip->addFile($f->getPathname(), $name);
                $zip->setCompressionName($name, ZipArchive::CM_STORE); // media is mostly compressed already
            }
        }
    }
    $ok = $zip->close();
    @unlink($dbCopy);
    if (!$ok) {
        @unlink($base . '.zip');
        throw new RuntimeException('Writing the backup failed (is the disk full?).');
    }
    return $base . '.zip';
}

/** Removes temporary backup files and restore folders left behind by an interrupted run (older than a day). */
function backup_cleanup(): void
{
    foreach ([...glob(PP_DATA . '/backup-*.zip') ?: [], ...glob(PP_DATA . '/backup-*.sqlite') ?: [], ...glob(PP_DATA . '/restore-*') ?: []] as $p) {
        if (filemtime($p) < time() - 86400) {
            is_dir($p) ? rrmdir($p) : @unlink($p);
        }
    }
}

/**
 * Replaces the database, media and previews with those in a backup zip. The current data is kept in
 * data/before-restore/ (replacing an older copy there). Throws, changing nothing, when the zip is not a usable backup.
 */
function backup_restore(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is missing.');
    }
    @set_time_limit(0);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('The file is not a zip file.');
    }
    $meta = json_decode((string)$zip->getFromName('backup.json'), true);
    if (!is_array($meta) || ($meta['app'] ?? '') !== 'PiPusher' || $zip->locateName('pipusher.sqlite') === false) {
        throw new RuntimeException('This zip is not a PiPusher backup.');
    }
    $version = (string)($meta['version'] ?? '0');
    if (version_compare($version, PP_VERSION, '>')) {
        throw new RuntimeException("The backup was made by PiPusher $version, which is newer than this server (" . PP_VERSION . '). Update the server first.');
    }

    $stage = PP_DATA . '/restore-' . bin2hex(random_bytes(6));
    try {
        mkdir($stage, 0775, true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            $parts = explode('/', $name);
            if (!preg_match('#^(backup\.json|pipusher\.sqlite|(media|previews)/.+)$#', $name)
                || str_contains($name, '\\') || array_intersect($parts, ['', '.', '..'])) {
                throw new RuntimeException("Unexpected file in the backup: $name");
            }
            $dest = $stage . '/' . $name;
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0775, true)) {
                throw new RuntimeException('Cannot unpack the backup in the data directory.');
            }
            $in = $zip->getStream($name);
            $out = fopen($dest, 'wb');
            if (!$in || !$out || stream_copy_to_stream($in, $out) === false) {
                throw new RuntimeException("Unpacking $name failed (is the disk full?).");
            }
            fclose($in);
            fclose($out);
        }
        $zip->close();
        // The database must open and hold PiPusher's tables.
        $check = new PDO('sqlite:' . $stage . '/pipusher.sqlite');
        $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $counts = [];
        foreach (['layouts', 'playlists', 'devices', 'media', 'users'] as $t) {
            $counts[$t] = (int)$check->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        }
        $check = null;
        if ($counts['users'] === 0) {
            throw new RuntimeException('The backup has no users, so nobody could log in after restoring it.');
        }
    } catch (Throwable $e) {
        $check = null;
        rrmdir($stage);
        throw $e instanceof PDOException ? new RuntimeException('The database in the backup cannot be read.') : $e;
    }

    // Swap: current data to data/before-restore/, the unpacked backup into place.
    db_close();
    $old = PP_DATA . '/before-restore';
    rrmdir($old);
    mkdir($old, 0775, true);
    rename(PP_DATA . '/pipusher.sqlite', $old . '/pipusher.sqlite');
    foreach (['media' => PP_MEDIA, 'previews' => PP_PREVIEWS, 'screenshots' => PP_SCREENS] as $n => $dir) {
        if (is_dir($dir)) {
            rename($dir, "$old/$n");
        }
        if (is_dir("$stage/$n")) {
            rename("$stage/$n", $dir);
        }
    }
    rename("$stage/pipusher.sqlite", PP_DATA . '/pipusher.sqlite');
    rrmdir($stage);
    ensure_data_dirs();
    db(); // migrates a backup from an older version
    return ['version' => $version, 'created_at' => (int)($meta['created_at'] ?? 0)] + $counts;
}
