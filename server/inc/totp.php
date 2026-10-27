<?php
declare(strict_types=1);

/**
 * Two-factor authentication with time-based one-time codes (TOTP, RFC 6238: SHA-1, 6 digits, 30 s),
 * as used by Google Authenticator, Microsoft Authenticator, 1Password and similar apps.
 * Recovery codes are single-use and stored as password hashes.
 */

const PP_TOTP_PERIOD = 30;

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
        $v = strpos($alphabet, $c);
        if ($v !== false) {
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, int $step): string
{
    $hash = hash_hmac('sha1', pack('N2', 0, $step), base32_decode($secret), true);
    $o = ord($hash[19]) & 0x0f;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Checks a code against the current time step ±1 (clock drift). Returns the matching step, or null.
 * Steps at or before $lastStep are refused, so a code cannot be used twice.
 */
function totp_verify(string $secret, string $code, int $lastStep = 0): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $now = intdiv(time(), PP_TOTP_PERIOD);
    for ($step = $now - 1; $step <= $now + 1; $step++) {
        if ($step > $lastStep && hash_equals(totp_code($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/** Name authenticator apps show above the username (Settings → Security; default "PiPusher (host)"). */
function totp_site_name(): string
{
    // A colon separates site and username in the otpauth label, so it cannot be part of the name.
    $name = trim(str_replace(':', '', (string)setting('site_name', '')));
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return $name !== '' ? $name : 'PiPusher (' . str_replace(':', '', $host) . ')';
}

/** otpauth:// link for authenticator apps (shown as a QR code): the app lists it as "<site name>: <username>". */
function totp_uri(string $secret, string $username): string
{
    $issuer = totp_site_name();
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=' . PP_TOTP_PERIOD;
}

/** Creates 10 new recovery codes for a user and returns them in plain text (shown once). */
function new_recovery_codes(int $userId): array
{
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $codes[] = strtolower(random_code(5) . '-' . random_code(5));
    }
    x('UPDATE users SET recovery_codes = ? WHERE id = ?',
        [json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes)), $userId]);
    return $codes;
}

/** Uses up a recovery code if it matches one of the user's unused codes. */
function use_recovery_code(array $user, string $code): bool
{
    $code = strtolower(trim($code));
    $hashes = json_decode((string)$user['recovery_codes'], true) ?: [];
    foreach ($hashes as $i => $hash) {
        if (password_verify($code, $hash)) {
            unset($hashes[$i]);
            x('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode(array_values($hashes)), $user['id']]);
            return true;
        }
    }
    return false;
}

function recovery_codes_left(array $user): int
{
    return count(json_decode((string)$user['recovery_codes'], true) ?: []);
}

function mfa_enabled(array $user): bool
{
    return !empty($user['totp_secret']);
}

/** Whether Settings require two-factor authentication for this user. */
function mfa_required(array $user): bool
{
    $req = (string)setting('require_mfa', '');
    return $req === 'all' || ($req === 'admins' && $user['role'] === 'admin');
}

function disable_mfa(int $userId): void
{
    x('UPDATE users SET totp_secret = NULL, totp_last = 0, recovery_codes = NULL WHERE id = ?', [$userId]);
}
