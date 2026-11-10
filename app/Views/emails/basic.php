<!doctype html>
<html><body style="margin:0;padding:24px;background:#f7f7f9;font:15px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2128">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e7e7ec;border-radius:12px">
<tr><td style="padding:26px 28px">
  <p style="margin:0 0 18px;font-weight:700;font-size:15px"><span style="display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;border-radius:6px;background:#5b5bd6;color:#fff;font-size:12px;margin-right:6px">L</span>LeanPM</p>
  <p style="margin:0 0 12px">Hi <?= e($name) ?>,</p>
  <?php foreach ($lines as $line): ?><p style="margin:0 0 12px"><?= $line ?></p><?php endforeach; ?>
  <p style="margin:20px 0 6px"><a href="<?= e($url) ?>" style="display:inline-block;padding:10px 18px;background:#5b5bd6;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600"><?= e($button) ?></a></p>
  <p style="margin:12px 0 0;font-size:12.5px;color:#6b6f7b">Or paste this into your browser: <?= e($url) ?></p>
  <?php if ($note !== ''): ?><p style="margin:14px 0 0;font-size:13px;color:#6b6f7b"><?= $note ?></p><?php endif; ?>
</td></tr>
</table>
</td></tr></table>
</body></html>
