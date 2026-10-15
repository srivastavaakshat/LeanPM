<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Sends email over SMTP (Hostinger: smtp.hostinger.com, port 465, a mailbox address and its
 * password, all in config/local.php). Copied from Rare Flavours. Without config('mail.host')
 * nothing is sent: messages are written to storage/logs/mail.log, so local testing never emails
 * anyone. One connection can send many messages (open → send… → close).
 */
final class Mailer
{
    /** @var resource|null */
    private $sock = null;
    private string $lastError = '';

    public static function configured(): bool
    {
        return (string) config('mail.host', '') !== '';
    }

    public static function fromAddress(): string
    {
        return (string) (config('mail.from') ?: config('mail.username') ?: 'leanpm@localhost');
    }

    public function error(): string
    {
        return $this->lastError;
    }

    /**
     * $headers: extra headers, e.g. ['List-Unsubscribe' => '<https://…>'].
     * Returns true when the server accepted the message.
     */
    public function send(string $to, string $subject, string $html, string $text, array $headers = []): bool
    {
        $from = self::fromAddress();
        $fromName = (string) (config('mail.from_name') ?: 'LeanPM');
        $boundary = 'lpm' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $head = [
            'From' => self::encodeName($fromName) . " <$from>",
            'To' => $to,
            'Subject' => self::encodeHeader($subject),
            'Date' => date(DATE_RFC2822),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . "@$domain>",
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
        ] + $headers;
        if ($reply = (string) config('mail.reply_to', '')) {
            $head['Reply-To'] = $head['Reply-To'] ?? $reply;
        }
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($text) . "\r\n--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n--$boundary--\r\n";

        if (!self::configured()) {
            // Local development: log instead of sending.
            $log = config('storage_path') . '/logs/mail.log';
            @file_put_contents($log, '[' . now() . "] To: $to\nSubject: $subject\n\n$text\n" . str_repeat('-', 60) . "\n", FILE_APPEND);
            $this->lastError = '';
            return true;
        }
        try {
            $this->open();
            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            $msg = implode("\r\n", array_map(fn ($k, $v) => "$k: $v", array_keys($head), $head)) . "\r\n\r\n" . $body;
            $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $msg)); // dot-stuffing
            $this->cmd($msg . "\r\n.", [250]);
            $this->lastError = '';
            return true;
        } catch (\RuntimeException $e) {
            $this->lastError = mb_substr($e->getMessage(), 0, 250);
            $this->close(); // start a fresh connection for the next message
            return false;
        }
    }

    private function open(): void
    {
        if ($this->sock) {
            try {
                $this->cmd('RSET', [250]);
                return;
            } catch (\RuntimeException) {
                $this->close();
            }
        }
        $host = (string) config('mail.host');
        $port = (int) config('mail.port', 465);
        $secure = (string) config('mail.encryption', $port === 465 ? 'ssl' : 'tls');
        $this->sock = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 20);
        if (!$this->sock) {
            throw new \RuntimeException("Can't connect to $host:$port ($errstr)");
        }
        stream_set_timeout($this->sock, 30);
        $this->read([220]);
        $ehlo = 'EHLO ' . (parse_url((string) config('url', ''), PHP_URL_HOST) ?: 'localhost');
        $this->cmd($ehlo, [250]);
        if ($secure === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed');
            }
            $this->cmd($ehlo, [250]);
        }
        if ((string) config('mail.username', '') !== '') {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode((string) config('mail.username')), [334]);
            $this->cmd(base64_encode((string) config('mail.password')), [235]);
        }
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "QUIT\r\n");
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function cmd(string $line, array $expect): string
    {
        fwrite($this->sock, $line . "\r\n");
        return $this->read($expect);
    }

    private function read(array $expect): string
    {
        $out = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($out, 0, 3);
        if (!in_array($code, $expect, true)) {
            throw new \RuntimeException(trim($out) ?: 'No reply from the mail server');
        }
        return $out;
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function encodeName(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? self::encodeHeader($s) : '"' . addcslashes($s, '"\\') . '"';
    }
}
