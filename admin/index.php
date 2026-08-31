<?php
/* SUPPEX admin — dashboard. What happened today, and what needs attention. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once SUPPEX_ROOT . '/lib/stock.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

orders_expire_due();

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');

$newCount   = orders_count(['status' => 'new']);
$todayCount = orders_count(['from' => $today, 'to' => $today]);
$month      = orders_settlement($monthStart, $today);
$recent     = orders_list(['limit' => 12]);

/* Switched off by hand. Unchanging by nature — it is only ever this way
   because somebody made it so — but still worth a line, because "why is
   nobody buying this" has this as its answer more often than it should. */
$offSale = db_all(
    'SELECT slug, name_fa FROM products WHERE is_active = 1 AND in_stock = 0 ORDER BY name_fa LIMIT 8'
);

/* Run down on their own, which is the half nobody sees coming. Sizes are
   listed by name because "وی پروتئین" being low says nothing useful when it is
   sold in six sizes and only the 900g one is nearly gone. */
$lowThreshold = stock_low_threshold();
$runningOut = db_all(
    'SELECT p.slug, p.name_fa, "" AS size_label, p.stock_qty AS n
       FROM products p
      WHERE p.is_active = 1 AND p.in_stock = 1
        AND p.stock_qty IS NOT NULL AND p.stock_qty <= ?
        AND NOT EXISTS (SELECT 1 FROM product_sizes s WHERE s.product_id = p.id)
     UNION ALL
     SELECT p.slug, p.name_fa, s.label AS size_label, s.stock_qty AS n
       FROM product_sizes s
       JOIN products p ON p.id = s.product_id
      WHERE p.is_active = 1 AND p.in_stock = 1
        AND s.stock_qty IS NOT NULL AND s.stock_qty <= ?
     ORDER BY n ASC, name_fa ASC
      LIMIT 12',
    [$lowThreshold, $lowThreshold]
);

/* A card number that fails the Luhn check is almost always a typo, and the
   consequence is a customer transferring money into someone else's account.
   Surfaced on the dashboard because nobody reopens the settings page to look. */
$cardNumber  = (string) setting('card_number');
$cardIsSet   = $cardNumber !== '';
$cardIsValid = $cardIsSet && is_valid_card_number($cardNumber);

/* Stale prices are the failure this shop is most exposed to: the dirham
   moves, nobody re-prices, and every sale that day is made at yesterday's
   cost. It is invisible unless something says so on the page people open
   first, so it goes above the numbers rather than inside the pricing screen
   that nobody visits when they have no reason to. */
$priceStale = ['stale' => false];
try {
    $priceStale = pricing_staleness();
} catch (Throwable $e) {
    /* Pricing columns not migrated yet — the panel still works without them. */
}

admin_head('داشبورد', ['user' => $user]);
?>

<?php if (!empty($priceStale['stale'])): ?>
  <div class="flash flash--error">
    <?php $drift = (float) $priceStale['drift_pct']; ?>
    نرخ درهم نسبت به قیمت‌های روی سایت
    <span class="num"><?= e((string) abs($drift)) ?>٪</span>
    <?= $drift > 0 ? 'بالاتر' : 'پایین‌تر' ?> رفته است.
    <?php if ($drift > 0): ?>
      <strong>یعنی الان ارزان‌تر از قیمت تمام‌شده امروز می‌فروشید.</strong>
    <?php endif; ?>
    <a href="pricing.php" style="text-decoration:underline">به‌روزرسانی قیمت‌ها</a>
  </div>
<?php endif; ?>

<?php if (!$cardIsSet): ?>
  <div class="flash flash--error">
    شماره کارت هنوز ثبت نشده است. تا وقتی ثبت نشود، مشتری بعد از سفارش
    شماره‌ای برای واریز نمی‌بیند.
    <a href="settings.php" style="text-decoration:underline">ثبت شماره کارت</a>
  </div>
<?php elseif (!$cardIsValid): ?>
  <div class="flash flash--error">
    شماره کارت ثبت‌شده معتبر نیست و احتمالاً اشتباه تایپ شده است.
    <a href="settings.php" style="text-decoration:underline">بررسی تنظیمات</a>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat">
    <div class="stat__label">سفارش‌های در انتظار بررسی</div>
    <div class="stat__value num"><?= $newCount ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">سفارش امروز</div>
    <div class="stat__value num"><?= $todayCount ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">فروش این ماه (پرداخت‌شده)</div>
    <div class="stat__value num"><?= money($month['goods_total']) ?>
      <span class="stat__unit">تومان</span></div>
  </div>
  <div class="stat stat--accent">
    <div class="stat__label">سهم همکاری این ماه</div>
    <div class="stat__value num"><?= money($month['commission_total']) ?>
      <span class="stat__unit">تومان</span></div>
  </div>
</div>

<?php if ($runningOut): ?>
  <div class="card">
    <h2 class="card__title">رو به اتمام</h2>
    <p class="u-dim" style="margin-block-end:12px">
      موجودی این‌ها کم شده یا تمام شده است. تعدادی که سفارش‌های باز رزرو کرده‌اند
      از این عددها کم شده.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach ($runningOut as $r): $n = (int) $r['n']; ?>
        <a class="pill <?= $n <= 0 ? 'pill--cancelled' : 'pill--new' ?>" href="stock.php?low=1">
          <?= e($r['name_fa']) ?><?= $r['size_label'] !== '' ? ' · ' . e($r['size_label']) : '' ?>
          —
          <?php if ($n < 0): ?>
            <span class="num"><?= abs($n) ?></span> تا بیشتر فروخته شده
          <?php elseif ($n === 0): ?>
            تمام شد
          <?php else: ?>
            <span class="num"><?= $n ?></span> عدد
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($offSale): ?>
  <div class="card">
    <h2 class="card__title">محصولات ناموجود</h2>
    <p class="u-dim" style="margin-block-end:12px">
      این محصولات در سایت دیده می‌شوند اما دکمه افزودن به سبد آن‌ها غیرفعال است.
      این حالت دستی است و تا خودتان روشنش نکنید تغییر نمی‌کند.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach ($offSale as $p): ?>
        <a class="pill pill--off" href="product-edit.php?slug=<?= e(rawurlencode($p['slug'])) ?>">
          <?= e($p['name_fa']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h2 class="card__title">آخرین سفارش‌ها</h2>

  <?php if (!$recent): ?>
    <div class="empty">هنوز سفارشی ثبت نشده است.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>کد</th>
            <th>مشتری</th>
            <th>مبلغ</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $o): ?>
            <tr>
              <td class="num u-nowrap"><?= e($o['code']) ?></td>
              <td>
                <div class="t-title"><?= e($o['customer_name']) ?></div>
                <div class="t-sub num"><?= e($o['phone']) ?></div>
              </td>
              <td class="num u-nowrap"><?= money((int) $o['total']) ?></td>
              <td>
                <span class="pill pill--<?= e($o['status']) ?>">
                  <?= e(ORDER_STATUS_FA[$o['status']] ?? $o['status']) ?>
                </span>
              </td>
              <td class="num u-nowrap u-dim"><?= e(date('Y/m/d H:i', strtotime($o['created_at']))) ?></td>
              <td class="u-right">
                <a class="btn btn--ghost btn--sm" href="order.php?id=<?= (int) $o['id'] ?>">مشاهده</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php admin_foot(); ?>
