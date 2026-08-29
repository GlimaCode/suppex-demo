<?php
/* ===========================================================================
   SUPPEX admin — launch preflight
   ---------------------------------------------------------------------------
   Renders lib/preflight.php's findings. All the logic is there; this file is a
   template, so the checks can be exercised without rendering a page.

   Reachable without a login ONLY while no admin account exists — during that
   window there is nothing to protect, and that is exactly when the PHP and
   extension checks are needed. The moment setup creates an account it goes
   behind the login, because from then on it reports server paths and
   configuration state.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/preflight.php';

$chrome = preflight_has_admin();
if ($chrome) {
    auth_require();
    require_once __DIR__ . '/partials/layout.php';
}

$checks  = preflight_checks();
$summary = preflight_summary($checks);

$groups = [];
foreach ($checks as $c) {
    $groups[$c['group']][] = $c;
}

if ($chrome) {
    admin_head('آمادگی راه‌اندازی', ['user' => auth_user()]);
} else {
    /* Rendered bare when there is no admin yet: the sidebar links to pages that
       would only bounce to a login. */
    ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>آمادگی راه‌اندازی — SUPPEX</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="content" style="max-width:920px;margin-inline:auto">
<?php
}
?>

<div class="flash flash--<?= $summary['ready'] ? 'ok' : 'error' ?>">
  <?php if ($summary['ready']): ?>
    <strong>آماده است.</strong>
    <?php if ($summary['warns'] > 0): ?>
      <span class="num"><?= $summary['warns'] ?></span> هشدار هست که جلوی راه‌اندازی را
      نمی‌گیرد، ولی بهتر است قبل از تبلیغات رفع شود.
    <?php else: ?>
      هیچ ایراد و هشداری پیدا نشد.
    <?php endif; ?>
  <?php else: ?>
    <strong><span class="num"><?= $summary['blockers'] ?></span> مورد باید قبل از
    اولین سفارش واقعی درست شود.</strong> هر کدام پایین با راه‌حلش آمده است.
  <?php endif; ?>
</div>

<?php foreach ($groups as $name => $items): ?>
  <div class="card">
    <h2 class="card__title"><?= e($name) ?></h2>
    <div class="table-wrap" style="border:0">
      <table style="min-width:0">
        <tbody>
          <?php foreach ($items as $c): ?>
            <tr>
              <td style="width:92px">
                <span class="pill pill--<?= $c['state'] === 'ok' ? 'paid'
                    : ($c['state'] === 'warn' ? 'shipped' : 'cancelled') ?>">
                  <?= $c['state'] === 'ok' ? 'درست'
                      : ($c['state'] === 'warn' ? 'هشدار' : 'ایراد') ?>
                </span>
              </td>
              <td>
                <div class="t-title"><?= e($c['label']) ?></div>
                <?php if ($c['detail'] !== ''): ?>
                  <div class="t-sub" style="direction:ltr;unicode-bidi:plaintext;text-align:start">
                    <?= e($c['detail']) ?>
                  </div>
                <?php endif; ?>
                <?php if ($c['state'] !== 'ok' && $c['fix'] !== ''): ?>
                  <div class="t-sub" style="color:var(--text-muted);line-height:1.95;margin-block-start:5px">
                    <?= e($c['fix']) ?>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<div class="card">
  <h2 class="card__title">چیزهایی که این صفحه نمی‌تواند بررسی کند</h2>
  <ul style="padding-inline-start:20px;line-height:2.1;color:var(--text-muted)">
    <li>
      اینکه <strong>mod_rewrite</strong> کار می‌کند یا نه. خودتان
      <a href="../sitemap.xml" target="_blank" rel="noopener" style="text-decoration:underline">sitemap.xml</a>
      و
      <a href="../robots.txt" target="_blank" rel="noopener" style="text-decoration:underline">robots.txt</a>
      را باز کنید — اگر باز شدند، کار می‌کند.
    </li>
    <li>
      اینکه شماره کارت <strong>واقعاً مال شماست</strong>. الگوریتم فقط می‌گوید عدد بدشکل نیست.
      یک واریز ۱۰,۰۰۰ تومانی از کارت دیگری بزنید و مطمئن شوید رسید.
    </li>
    <li>اینکه هاست اجازه اتصال بیرونی به پنل پیامکی می‌دهد یا نه.</li>
    <li>
      اینکه از دیتابیس <strong>پشتیبان</strong> گرفته‌اید. سفارش‌ها فقط آنجا هستند و
      بدون پشتیبان قابل بازیابی نیستند —
      <a href="backup.php" style="text-decoration:underline">صفحه پشتیبان‌گیری</a>.
    </li>
  </ul>
</div>

<?php
if ($chrome) {
    admin_foot();
} else {
    echo '</div></body></html>';
}
