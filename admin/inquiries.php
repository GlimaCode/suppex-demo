<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/inquiries.php';
require_once SUPPEX_ROOT . '/lib/seo.php';
require_once __DIR__ . '/partials/layout.php';
$user = auth_require();
$ready = inquiries_ready();
$id = max(0, (int) ($_GET['id'] ?? 0));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (!$ready) { flash('error', 'ابتدا ساختار دیتابیس را به‌روز کنید.'); }
    else {
        $action = (string) ($_POST['action'] ?? '');
        $rid = (int) ($_POST['id'] ?? 0);
        if ($action === 'notify') {
            $sent = inquiry_notify($rid);
            flash($sent ? 'ok' : 'error', $sent ? 'اعلان به گروه رسید.' : 'اعلان به گروه نرسید؛ تنظیمات ربات، گروه و ارتباط هاست را بررسی کنید. درخواست در پنل محفوظ است.');
        } else {
            $result = inquiry_decide($rid, $action, $user['name'], (string) ($_POST['note'] ?? ''));
            flash($result['ok'] ? 'ok' : 'error', !$result['ok'] ? $result['error']
                : (!empty($result['duplicate']) ? 'این درخواست قبلاً تعیین تکلیف شده؛ موجودی دوباره اضافه نشد.'
                : ($action === 'approved' ? 'همان تعداد درخواست‌شده به موجودی عمومی اضافه شد و خرید فعال است.' : 'ناموجود بودن ثبت شد؛ موجودی تغییری نکرد.')));
        }
    }
    header('Location: inquiries.php' . ($id ? '?id=' . $id : ''));
    exit;
}
$filter = (string) ($_GET['status'] ?? 'pending');
if (!isset(INQUIRY_STATUS_FA[$filter]) && $filter !== 'all') { $filter = 'pending'; }
$rows = !$ready ? [] : db_all('SELECT * FROM stock_inquiries'
    . ($id ? ' WHERE id = ?' : ($filter === 'all' ? '' : ' WHERE status = ?'))
    . ' ORDER BY id DESC LIMIT 100', $id ? [$id] : ($filter === 'all' ? [] : [$filter]));
admin_head('استعلام موجودی', ['user' => $user]);
?>
<div class="card">
  <h2>درخواست‌های موجودی</h2>
  <p>تأیید، دقیقاً تعداد ثبت‌شده را یک‌بار به موجودی عمومی همان طعم و اندازه اضافه و تیک استعلام را خاموش می‌کند. کالا برای شخص خاص رزرو نمی‌شود. قیمت و سایر طعم‌ها تغییر نمی‌کنند.</p>
  <p>نتیجه در صفحهٔ پیگیری مشتری نمایش داده می‌شود؛ پاسخ تا ۳۰ دقیقه به‌عهدهٔ فروشگاه است. گذشت زمان به معنی تأیید نیست.</p>
  <p>اعلان فقط به گروه تلگرام تنظیمات می‌رود؛ پیامی به پیوی مدیر ارسال نمی‌شود. <a href="settings.php#telegram">تنظیمات گروه و ربات</a></p>
  <?php if (!$ready): ?><div class="flash flash--info">ابتدا <a href="migrate.php">به‌روزرسانی دیتابیس</a> را اجرا کنید.</div><?php endif; ?>
  <div class="actions">
    <a class="btn" href="inquiries.php">در انتظار (<?= e((string) inquiries_waiting()) ?>)</a>
    <a class="btn" href="inquiries.php?status=approved">تأییدشده</a>
    <a class="btn" href="inquiries.php?status=rejected">ناموجود</a>
    <a class="btn" href="inquiries.php?status=all">همه</a>
  </div>
</div>
<?php if ($ready && !$rows): ?><div class="card">درخواستی در این بخش نیست.</div><?php endif; ?>
<?php foreach ($rows as $r): ?>
<section class="card">
  <h2><?= e($r['product_name']) ?></h2>
  <p><?= e($r['variant_label']) ?> — <strong><?= e((string) $r['qty']) ?> عدد</strong></p>
  <p><bdi><?= e($r['code']) ?></bdi> · <?= e(INQUIRY_STATUS_FA[$r['status']] ?? $r['status']) ?></p>
  <p><?= e($r['customer_name']) ?> · <bdi><?= e($r['phone']) ?></bdi></p>
  <p>ثبت: <bdi><?= e($r['created_at']) ?></bdi> · مهلت پاسخ: <bdi><?= e($r['respond_by']) ?></bdi>
    <?php if ($r['status'] === 'pending' && strtotime($r['respond_by']) < time()): ?><strong> — مهلت پاسخ گذشته است</strong><?php endif; ?></p>
  <p>اعلان گروه: <?= $r['notify_state'] === 'sent' ? 'ارسال شد' : ($r['notify_state'] === 'failed' ? 'ارسال ناموفق / گروه آماده نیست' : 'در انتظار ارسال') ?></p>
  <?php if ($r['status'] === 'pending'): ?>
  <form method="post" action="inquiries.php<?= $id ? '?id=' . $id : '' ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <label>یادداشت داخلی (اختیاری)<input class="input" type="text" name="note" maxlength="300"></label>
    <div class="actions" style="margin-block-start:12px">
      <button class="btn btn--primary" type="submit" name="action" value="approved">تأیید موجودی <?= e((string) $r['qty']) ?> عدد</button>
      <button class="btn" type="submit" name="action" value="rejected">موجود نیست</button>
    </div>
  </form>
  <?php else: ?>
    <p>بررسی توسط <?= e($r['decided_by']) ?> · <bdi><?= e($r['decided_at']) ?></bdi></p>
    <?php if ($r['status'] === 'approved'): ?><p>موجودی در لحظه تأیید: <?= e((string) $r['stock_before']) ?> ← <?= e((string) $r['stock_after']) ?></p><?php endif; ?>
    <?php if ($r['decision_note'] !== ''): ?><p><?= e($r['decision_note']) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php if ($r['notify_state'] !== 'sent'): ?>
  <form method="post" action="inquiries.php<?= $id ? '?id=' . $id : '' ?>" style="margin-block-start:12px">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <button class="btn" type="submit" name="action" value="notify">تلاش دوباره برای اعلان گروه</button>
  </form>
  <?php endif; ?>
  <p><a href="product-edit.php?id=<?= (int) $r['product_id'] ?>">ویرایش محصول</a></p>
</section>
<?php endforeach; admin_foot(); ?>
