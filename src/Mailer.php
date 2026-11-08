<?php

declare(strict_types=1);

namespace Shepherd;

/**
 * Sends plain-text email: through an SMTP server ('mail' => ['transport' => 'smtp', …]
 * in config/config.php) or PHP's mail() (the default). Used for offline alerts.
 *
 * The SMTP client is built in, so no extra libraries are needed: STARTTLS (587) or
 * implicit TLS (465) with certificate checks, AUTH PLAIN or LOGIN, and a refusal to
 * send a password over an unencrypted connection to anything but this machine.
 */
final class Mailer
{
    public static function transport(): string
    {
        return Config::get('mail.transport', 'mail') === 'smtp' ? 'smtp' : 'mail';
    }

    /** The sender address: mail.from, then alerts.email_from, then kiosky-shepherd@<server>. */
    public static function from(): string
    {
        foreach ([Config::get('mail.from'), Config::get('alerts.email_from')] as $a) {
            if (is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL)) {
                return $a;
            }
        }
        return 'kiosky-shepherd@' . self::serverHost();
    }

    private static function fromName(): string
    {
        return self::oneLine((string) Config::get('mail.from_name', 'Kiosky Shepherd')) ?: 'Kiosky Shepherd';
    }

    private static function serverHost(): string
    {
        return (string) (parse_url(Util::baseUrl(), PHP_URL_HOST) ?: 'localhost');
    }

    /** What the console shows. Never the password. */
    public static function summary(): array
    {
        $out = ['transport' => self::transport(), 'from' => self::from(), 'from_name' => self::fromName()];
        if ($out['transport'] === 'smtp') {
            $out += [
                'host' => (string) Config::get('mail.host', ''),
                'port' => self::port(),
                'encryption' => self::encryption(),
                'auth' => (string) Config::get('mail.username', '') !== '',
                'username' => (string) Config::get('mail.username', ''),
            ];
        }
        return $out;
    }

    private static function encryption(): string
    {
        $e = strtolower((string) Config::get('mail.encryption', 'starttls'));
        return in_array($e, ['starttls', 'tls', 'none'], true) ? $e : 'starttls';
    }

    private static function port(): int
    {
        $p = (int) Config::get('mail.port', 0);
        if ($p > 0 && $p < 65536) {
            return $p;
        }
        return match (self::encryption()) {
            'tls' => 465,
            'none' => 25,
            default => 587,
        };
    }

    /**
     * Sends one message to every address in $to.
     *
     * @param list<string> $to
     */
    public static function send(array $to, string $subject, string $body): void
    {
        $to = array_values(array_filter($to, static fn ($a) => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL)));
        if ($to === []) {
            throw new \RuntimeException('No valid recipient address.');
        }
        $subject = self::oneLine('[Kiosky Shepherd] ' . $subject);
        if (self::transport() === 'smtp') {
            self::smtp($to, $subject, $body);
            return;
        }
        $headers = [
            'From' => self::mimeWord(self::fromName()) . ' <' . self::from() . '>',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'X-Mailer' => 'KioskyShepherd/' . SHEPHERD_VERSION,
        ];
        if (!mail(implode(', ', $to), mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers)) {
            throw new \RuntimeException('PHP mail() refused the message. Check the host\'s mail settings, or configure an SMTP server.');
        }
    }

    // ---- SMTP --------------------------------------------------------------------

    /** @param list<string> $to */
    private static function smtp(array $to, string $subject, string $body): void
    {
        $host = trim((string) Config::get('mail.host', ''));
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:.]+\]$/', $host)) {
            throw new \RuntimeException('Set mail.host to your SMTP server, e.g. smtp.example.com.');
        }
        $port = self::port();
        $enc = self::encryption();
        $user = (string) Config::get('mail.username', '');
        $pass = (string) Config::get('mail.password', '');
        $timeout = max(5, min(60, (int) Config::get('mail.timeout', 15)));
        $verify = (bool) Config::get('mail.verify_peer', true);
        $local = in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]'], true);
        if ($user !== '' && $enc === 'none' && !$local) {
            throw new \RuntimeException('Refusing to send the SMTP password unencrypted. Use encryption "starttls" (port 587) or "tls" (port 465).');
        }

        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'peer_name' => trim($host, '[]'),
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
        ]]);
        $remote = ($enc === 'tls' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $s = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            throw new \RuntimeException("Can't connect to $host:$port ($errstr).");
        }
        stream_set_timeout($s, $timeout);
        try {
            self::expect($s, [220], 'greeting');
            $ehlo = self::ehlo($s);
            if ($enc === 'starttls') {
                if (!self::hasExtension($ehlo, 'STARTTLS')) {
                    throw new \RuntimeException("$host doesn't offer STARTTLS. Use encryption \"tls\" (usually port 465), or check the port.");
                }
                self::command($s, 'STARTTLS', [220]);
                $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (@stream_socket_enable_crypto($s, true, $crypto) !== true) {
                    throw new \RuntimeException("TLS with $host failed (check its certificate, or mail.verify_peer).");
                }
                $ehlo = self::ehlo($s);
            }
            if ($user !== '') {
                self::auth($s, $ehlo, $user, $pass);
            }
            $from = self::from();
            self::command($s, 'MAIL FROM:<' . $from . '>', [250]);
            foreach ($to as $rcpt) {
                self::command($s, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            self::command($s, 'DATA', [354]);
            self::write($s, self::message($from, $to, $subject, $body) . "\r\n.");
            self::expect($s, [250], 'message');
            @fwrite($s, "QUIT\r\n");
        } finally {
            fclose($s);
        }
    }

    /** @return list<string> the EHLO response lines */
    private static function ehlo($s): array
    {
        [, $lines] = self::command($s, 'EHLO ' . self::serverHost(), [250]);
        return $lines;
    }

    /** @param list<string> $ehlo */
    private static function hasExtension(array $ehlo, string $ext): bool
    {
        foreach ($ehlo as $line) {
            if (stripos(substr($line, 4), $ext) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $ehlo */
    private static function auth($s, array $ehlo, string $user, string $pass): void
    {
        $mechs = '';
        foreach ($ehlo as $line) {
            if (preg_match('/^250[ -]AUTH[ =](.*)$/i', $line, $m)) {
                $mechs .= ' ' . strtoupper($m[1]);
            }
        }
        if (str_contains($mechs, 'PLAIN')) {
            self::command($s, 'AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235], 'authentication');
        } elseif (str_contains($mechs, 'LOGIN')) {
            self::command($s, 'AUTH LOGIN', [334]);
            self::command($s, base64_encode($user), [334], 'authentication');
            self::command($s, base64_encode($pass), [235], 'authentication');
        } else {
            throw new \RuntimeException('The SMTP server offers no login method this app supports (PLAIN or LOGIN).');
        }
    }

    /**
     * Sends a command and checks the reply code. $what replaces the command in errors,
     * so credentials never end up in a log.
     *
     * @param list<int> $ok
     * @return array{0: int, 1: list<string>}
     */
    private static function command($s, string $line, array $ok, ?string $what = null): array
    {
        self::write($s, $line);
        return self::expect($s, $ok, $what ?? strtok($line, ' :'));
    }

    private static function write($s, string $data): void
    {
        if (@fwrite($s, $data . "\r\n") === false) {
            throw new \RuntimeException('The SMTP connection closed unexpectedly.');
        }
    }

    /**
     * @param list<int> $ok
     * @return array{0: int, 1: list<string>}
     */
    private static function expect($s, array $ok, string $what): array
    {
        $lines = [];
        while (true) {
            $line = fgets($s, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($s);
                throw new \RuntimeException($meta['timed_out'] ? "The SMTP server didn't answer ($what)." : "The SMTP server closed the connection ($what).");
            }
            $line = rtrim($line, "\r\n");
            $lines[] = $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr(end($lines), 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new \RuntimeException("SMTP $what failed: " . mb_substr(end($lines), 0, 300));
        }
        return [$code, $lines];
    }

    /** @param list<string> $to */
    private static function message(string $from, array $to, string $subject, string $body): string
    {
        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::serverHost() . '>',
            'From: ' . self::mimeWord(self::fromName()) . ' <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
            'X-Mailer: KioskyShepherd/' . SHEPHERD_VERSION,
        ];
        // base64 bodies never contain a line that starts with "." (no dot-stuffing needed)
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
    }

    private static function mimeWord(string $s): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $s) && !preg_match('/[",<>@]/', $s)
            ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    /** Header values must not contain line breaks (header injection). */
    private static function oneLine(string $s): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $s));
    }
}
