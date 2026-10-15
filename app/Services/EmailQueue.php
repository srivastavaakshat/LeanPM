<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;

/**
 * Outgoing email goes into a queue first, so saving a comment never waits on the mail server.
 * The queue is sent right after the response (see flushAfterResponse) and, as a fallback, by the
 * cron job bin/send-emails.php. A message is retried up to 5 times.
 */
final class EmailQueue
{
    private const MAX_ATTEMPTS = 5;
    private static bool $scheduled = false;

    public static function push(string $toEmail, string $toName, string $subject, string $text, string $html): void
    {
        DB::insert('email_queue', [
            'to_email' => $toEmail, 'to_name' => mb_substr($toName, 0, 120), 'subject' => mb_substr($subject, 0, 255),
            'body_text' => $text, 'body_html' => $html, 'created_at' => now(), 'attempts' => 0,
        ]);
        self::flushAfterResponse();
    }

    /** Send queued mail once the browser already has its response. */
    public static function flushAfterResponse(): void
    {
        if (self::$scheduled || PHP_SAPI === 'cli') {
            return;
        }
        self::$scheduled = true;
        register_shutdown_function(function () {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // don't hold the session lock while talking to the mail server
            }
            if (function_exists('litespeed_finish_request')) {
                litespeed_finish_request(); // Hostinger runs LiteSpeed
            } elseif (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::flush(20);
            } catch (\Throwable $e) {
                error_log('Email queue: ' . $e->getMessage());
            }
        });
    }

    /** Send up to $limit pending messages. Returns [sent, failed]. */
    public static function flush(int $limit = 50): array
    {
        $rows = DB::all('SELECT * FROM email_queue WHERE sent_at IS NULL AND attempts < ? ORDER BY id LIMIT ' . max(1, $limit), [self::MAX_ATTEMPTS]);
        if (!$rows) {
            return [0, 0];
        }
        $mailer = new Mailer();
        $sent = $failed = 0;
        foreach ($rows as $r) {
            // Claim the row first, so a cron run and a request never send the same message twice.
            $claimed = DB::query('UPDATE email_queue SET attempts = attempts + 1 WHERE id = ? AND attempts = ? AND sent_at IS NULL', [$r['id'], $r['attempts']])->rowCount();
            if (!$claimed) {
                continue;
            }
            $to = $r['to_name'] !== '' ? '"' . addcslashes($r['to_name'], '"\\') . '" <' . $r['to_email'] . '>' : $r['to_email'];
            if ($mailer->send($r['to_email'], $r['subject'], $r['body_html'], $r['body_text'], ['To' => $to])) {
                DB::update('email_queue', ['sent_at' => now(), 'last_error' => null], 'id = ?', [$r['id']]);
                $sent++;
            } else {
                DB::update('email_queue', ['last_error' => mb_substr($mailer->error(), 0, 255)], 'id = ?', [$r['id']]);
                $failed++;
            }
        }
        $mailer->close();
        return [$sent, $failed];
    }
}
