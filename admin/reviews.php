<?php
/* ===========================================================================
   SUPPEX admin — reviews
   ---------------------------------------------------------------------------
   What buyers wrote on the tracking page (lib/reviews.php), waiting for the
   owner. Nothing reaches the storefront until it is approved here; a rejected
   one is kept, so the same buyer is not asked again and the decision can be
   reversed, and a deleted one is gone - its buyer may then write again.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/reviews.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

$tab = (string) ($_GET['s'] ?? 'pending');
if (!in_array($tab, REVIEW_STATUSES, true)) {
    $tab = 'pending';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $done   = false;
    if ($action === 'approve') {
        $done = review_decide($id, 'approved');
        flash($done ? 'ok' : 'error', $done ? 'نظر منتشر شد.' : 'این نظر پیدا نشد.');
    } elseif ($action === 'reject') {
        $done = review_decide($id, 'rejected');
        flash($done ? 'ok' : 'error', $done ? 'نظر رد شد و نمایش داده نمی‌شود.' : 'این نظر پیدا نشد.');
    } elseif ($action === 'delete') {
        $done = review_delete($id);
        flash($done ? 'ok' : 'error', $done ? 'نظر حذف شد.' : 'این نظر پیدا نشد.');
    }
    header('Location: reviews.php?s=' . rawurlencode($tab));
    exit;
}

$counts = [];
foreach (REVIEW_STATUSES as $s) {
    $counts[$s] = reviews_ready()
        ? (int) db_value('SELECT COUNT(*) FROM product_reviews WHERE status = ?', [$s]) : 0;
}
$list  = reviews_list($tab);
$names = ['pending' => 'منتظر تأیید', 'approved' => 'منتشرشده', 'rejected' => 'ردشده'];

admin_head('نظرات مشتریان', ['user' => $user]);
?>

<?php if (!reviews_ready()): ?>
  <div class="card">
    <p>برای فعال شدن نظرات، یک بار <a href="migrate.php">به‌روزرسانی دیتابیس</a> را اجرا کنید.</p>
  </div>
<?php else: ?>
  <div class="card">
    <p class="hint">
      مشتری بعد از ارسال سفارش، در صفحهٔ پیگیری سفارش (با شمارهٔ پیگیری و موبایلش) برای
      محصولات همان سفارش نظر می‌نویسد. هیچ نظری تا اینجا تأییدش نکنید روی سایت نمی‌آید؛
      ستاره‌ها و امتیاز محصول هم فقط از نظرهای تأییدشده حساب می‌شوند.
    </p>
    <nav style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-block-start:12px" aria-label="وضعیت نظرها">
      <?php foreach (REVIEW_STATUSES as $s): ?>
        <a class="btn btn--sm <?= $s === $tab ? 'btn--primary' : 'btn--ghost' ?>" href="reviews.php?s=<?= e($s) ?>">
          <?= e($names[$s]) ?> (<?= e(fa_digits((string) $counts[$s])) ?>)
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php if (!$list): ?>
    <div class="card"><p class="u-dim">نظری در این بخش نیست.</p></div>
  <?php endif; ?>

  <?php foreach ($list as $r): ?>
    <div class="card">
      <div style="display:flex;align-items:center;flex-wrap:wrap;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
          <strong><a href="../product.php?p=<?= e(rawurlencode((string) $r['slug'])) ?>#reviews" target="_blank" rel="noopener"><?= e((string) $r['product_name']) ?></a></strong>
          <div class="u-dim" style="margin-block-start:4px">
            <span style="color:#F2A23D;letter-spacing:2px"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?></span>
            · <?= e((string) $r['author'] !== '' ? (string) $r['author'] : 'بدون نام (نمایش: خریدار)') ?>
            · <?= e(review_date_fa((string) $r['created_at'])) ?>
            · سفارش <a class="num" dir="ltr" href="order.php?id=<?= (int) $r['order_id'] ?>"><?= e((string) $r['order_code']) ?></a>
          </div>
        </div>
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px">
          <?php if ($r['status'] !== 'approved'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="action" value="approve">
              <button class="btn btn--primary btn--sm" type="submit">تأیید و انتشار</button>
            </form>
          <?php endif; ?>
          <?php if ($r['status'] !== 'rejected'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="action" value="reject">
              <button class="btn btn--ghost btn--sm" type="submit">رد</button>
            </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('این نظر برای همیشه حذف شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn btn--danger btn--sm" type="submit">حذف</button>
          </form>
        </div>
      </div>
      <?php if (trim((string) $r['body']) !== ''): ?>
        <p style="margin-block-start:12px;line-height:1.9;white-space:pre-line"><?= e((string) $r['body']) ?></p>
      <?php else: ?>
        <p class="u-dim" style="margin-block-start:12px">(فقط امتیاز، بدون متن)</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php admin_foot(); ?>
