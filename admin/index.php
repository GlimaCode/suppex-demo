<?php
/* SUPPEX admin — dashboard. What happened today, and what needs attention. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');

$newCount   = orders_count(['status' => 'new']);
$todayCount = orders_count(['from' => $today, 'to' => $today]);
$month      = orders_settlement($monthStart, $today);
$recent     = orders_list(['limit' => 12]);

$lowStock = db_all(
    'SELECT slug, name_fa FROM products WHERE is_active = 1 AND in_stock = 0 ORDER BY name_fa LIMIT 8'
);

/* A card number that fails the Luhn check is almost always a typo, and the
   consequence is a customer transferring money into someone else's account.
   Surfaced on the dashboard because nobody reopens the settings page to look. */
$cardNumber  = (string) setting('card_number');
$cardIsSet   = $cardNumber !== '';
$cardIsValid = $cardIsSet && is_valid_card_number($cardNumber);

admin_head('داشبورد', ['user' => $user]);
?>

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

<?php if ($lowStock): ?>
  <div class="card">
    <h2 class="card__title">محصولات ناموجود</h2>
    <p class="u-dim" style="margin-block-end:12px">
      این محصولات در سایت دیده می‌شوند اما دکمه افزودن به سبد آن‌ها غیرفعال است.
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach ($lowStock as $p): ?>
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
