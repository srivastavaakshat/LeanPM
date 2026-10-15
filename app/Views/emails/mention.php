<!doctype html>
<html><body style="margin:0;padding:24px;background:#f7f7f9;font:15px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2128">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e7e7ec;border-radius:12px">
<tr><td style="padding:24px 28px">
  <p style="margin:0 0 6px;font-size:13px;color:#6b6f7b"><?= e($org) ?> · <?= e($project) ?></p>
  <p style="margin:0 0 16px"><strong><?= e($actor) ?></strong> mentioned you in <?= e($place) ?> <strong>“<?= e($task) ?>”</strong>.</p>
  <?php if ($excerpt !== ''): ?>
  <p style="margin:0 0 20px;padding:12px 14px;background:#f4f4f7;border-left:3px solid #5b5bd6;border-radius:6px;color:#3c3f48"><?= e($excerpt) ?></p>
  <?php endif; ?>
  <p style="margin:0 0 4px"><a href="<?= e($url) ?>" style="display:inline-block;padding:9px 16px;background:#5b5bd6;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600">Open the task</a></p>
</td></tr>
</table>
<p style="max-width:560px;margin:14px auto 0;font-size:12px;color:#9a9eaa">You get this email because you were @mentioned in LeanPM. <a href="<?= e($accountUrl) ?>" style="color:#9a9eaa">Turn these emails off</a>.</p>
</td></tr></table>
</body></html>
