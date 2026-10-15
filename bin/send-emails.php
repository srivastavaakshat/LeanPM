<?php
/**
 * Send queued email (@mention notifications). The app already sends right after each request;
 * this cron job picks up anything that failed or was left over.
 *   hPanel → Advanced → Cron Jobs, every 5 minutes:
 *   /usr/bin/php /home/YOUR-USER/domains/houseofjugaad.com/public_html/pm/bin/send-emails.php
 *   php bin/send-emails.php --status    shows whether mail is configured and what's waiting
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database as DB;
use App\Services\EmailQueue;
use App\Services\Mailer;

if (in_array('--status', $argv, true)) {
    echo 'Mail: ' . (Mailer::configured() ? 'SMTP ' . config('mail.host') . ' as ' . Mailer::fromAddress() : 'not configured (messages go to storage/logs/mail.log)') . "\n";
    echo 'Waiting: ' . DB::value('SELECT COUNT(*) FROM email_queue WHERE sent_at IS NULL AND attempts < 5') . "\n";
    foreach (DB::all('SELECT to_email, attempts, last_error FROM email_queue WHERE sent_at IS NULL ORDER BY id DESC LIMIT 5') as $r) {
        echo "  {$r['to_email']}  attempts={$r['attempts']}  {$r['last_error']}\n";
    }
    exit(0);
}
[$sent, $failed] = EmailQueue::flush(100);
if ($sent || $failed) {
    echo "Sent $sent, failed $failed.\n";
}
