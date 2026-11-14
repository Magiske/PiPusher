<?php
declare(strict_types=1);

/*
 * Sending email: through an SMTP server (recommended: the mailbox of your web hotel, Gmail with an app password,
 * Microsoft 365, …) or PHP's own mail(). Settings: mail_method (smtp|php), smtp_host, smtp_port, smtp_secure
 * (ssl|tls|none), smtp_user, smtp_pass, mail_from.
 */

/** Email addresses from a list separated by commas, semicolons, spaces or new lines; invalid ones are dropped. */
function mail_addresses(string $list): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $a) {
        if (filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($a)] = $a;
        }
    }
    return array_values($out);
}

/** The sender address: the setting, else the SMTP user name if it is an address, else pipusher@<this host>. */
function mail_from(): string
{
    foreach ([(string)setting('mail_from', ''), (string)setting('smtp_user', '')] as $a) {
        if (filter_var($a, FILTER_VALIDATE_EMAIL)) {
            return $a;
        }
    }
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url(server_base_url(), PHP_URL_HOST))) ?: 'localhost';
    return 'pipusher@' . $host;
}

/** Sends a plain text email. Returns null when sent, else the error. */
function send_mail(array $to, string $subject, string $text): ?string
{
    if (!$to) {
        return 'No recipients.';
    }
    $from = mail_from();
    $name = (string)setting('site_name', '') ?: 'PiPusher';
    $headers = [
        'Date' => date('r'),
        'From' => mb_encode_mimeheader($name, 'UTF-8') . ' <' . $from . '>',
        'Message-ID' => '<' . random_token(12) . '@' . (explode('@', $from)[1] ?? 'pipusher') . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
        'X-Mailer' => 'PiPusher ' . PP_VERSION,
    ];
    $subjectEnc = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    $body = chunk_split(base64_encode(str_replace(["\r\n", "\n"], ["\n", "\r\n"], $text)), 76, "\r\n");

    if (setting('mail_method', 'smtp') === 'php') {
        $lines = [];
        foreach ($headers as $k => $v) {
            if ($k !== 'Date') {
                $lines[] = "$k: $v";
            }
        }
        $ok = @mail(implode(', ', $to), $subjectEnc, $body, implode("\r\n", $lines), '-f' . $from);
        return $ok ? null : 'PHP mail() failed: the server may not be set up to send mail. Use SMTP instead.';
    }
    $headers = ['To' => implode(', ', $to), 'Subject' => $subjectEnc] + $headers;
    $msg = '';
    foreach ($headers as $k => $v) {
        $msg .= "$k: $v\r\n";
    }
    return smtp_send($from, $to, $msg . "\r\n" . $body);
}

/** Talks SMTP to the configured server. Returns null when the message was accepted, else the error. */
function smtp_send(string $from, array $to, string $data): ?string
{
    $host = trim((string)setting('smtp_host', ''));
    if ($host === '') {
        return 'No SMTP server set.';
    }
    $secure = (string)setting('smtp_secure', 'tls');
    $port = (int)setting('smtp_port', '') ?: ($secure === 'ssl' ? 465 : 587);
    $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return "Cannot connect to $host:$port: " . ($errstr ?: 'error ' . $errno);
    }
    stream_set_timeout($fp, 20);
    $last = '';
    // Reads a (multi-line) reply and checks its code.
    $expect = function (array $codes) use ($fp, &$last): bool {
        $last = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $last .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return in_array((int)substr($last, 0, 3), $codes, true);
    };
    $cmd = function (string $c, array $codes) use ($fp, $expect): bool {
        fwrite($fp, $c . "\r\n");
        return $expect($codes);
    };
    $fail = function (string $what) use ($fp, &$last): string {
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return $what . ': ' . (trim($last) ?: 'no answer from the server');
    };
    $me = preg_replace('/[^a-z0-9.-]/i', '', (string)parse_url(server_base_url(), PHP_URL_HOST)) ?: 'localhost';
    if (!$expect([220])) {
        return $fail('SMTP greeting');
    }
    if (!$cmd('EHLO ' . $me, [250])) {
        return $fail('EHLO');
    }
    if ($secure === 'tls') {
        if (!$cmd('STARTTLS', [220])) {
            return $fail('STARTTLS');
        }
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($fp, true, $method)) {
            $last = 'TLS handshake failed (certificate or TLS version)';
            return $fail('STARTTLS');
        }
        if (!$cmd('EHLO ' . $me, [250])) {
            return $fail('EHLO');
        }
    }
    $user = (string)setting('smtp_user', '');
    if ($user !== '') {
        if (!$cmd('AUTH LOGIN', [334]) || !$cmd(base64_encode($user), [334])
            || !$cmd(base64_encode((string)setting('smtp_pass', '')), [235])) {
            return $fail('Login');
        }
    }
    if (!$cmd('MAIL FROM:<' . $from . '>', [250])) {
        return $fail('Sender ' . $from . ' refused');
    }
    foreach ($to as $rcpt) {
        if (!$cmd('RCPT TO:<' . $rcpt . '>', [250, 251])) {
            return $fail('Recipient ' . $rcpt . ' refused');
        }
    }
    if (!$cmd('DATA', [354])) {
        return $fail('DATA');
    }
    // The body is base64 and ends with a line break, so no line starts with a dot and the end marker follows.
    if (!$cmd($data . '.', [250])) {
        return $fail('Message refused');
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return null;
}
