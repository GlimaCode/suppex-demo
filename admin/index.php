<?php
/* SUPPEX admin — dashboard. What happened today, and what needs attention.

   Built around one list, «کارهای امروز»: every warning the panel knows about,
   each one line with the button that deals with it, and nothing at all when
   there is nothing to do. It replaced a stack of cards, each with a paragraph
   explaining itself, which read the same on a quiet day as on a bad one. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once SUPPEX_ROOT . '/lib/stock.php';
require_once SUPPEX_ROOT . '/lib/reviews.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

orders_expire_due();

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');

$newCount   = orders_count(['status' => 'new']);
$todayCount = orders_count(['from' => $today, 'to' => $today]);
$month      = orders_settlement($monthStart, $today);
$recent     = orders_list(['limit' => 7]);
$reviewsWaiting = admin_reviews_waiting();

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
   first, so it leads the list rather than sitting inside the pricing screen
   that nobody visits when they have no reason to. */
$priceStale = ['stale' => false];
try {
    $priceStale = pricing_staleness();
} catch (Throwable $e) {
    /* Pricing columns not migrated yet — the panel still works without them. */
}

/* The list, most urgent first. kind: urgent | info | warn | calm. */
$todo = [];
if (!empty($priceStale['stale'])) {
    /* drift_pct is read from the hourly readings (pricing_staleness()), and is
       null without a recent one. It was read here before pricing_staleness()
       returned it at all, so the line always said «۰٪ پایین‌تر». */
    $drift = $priceStale['drift_pct'] ?? null;
    if ($drift !== null && abs((float) $drift) >= 0.1) {
        $todo[] = ['kind' => 'urgent', 'icon' => 'coins',
            'title' => 'نرخ درهم ' . fa_digits(number_format(abs((float) $drift), 1)) . '٪ '
                     . ((float) $drift > 0 ? 'بالاتر' : 'پایین‌تر') . ' از قیمت‌های سایت است',
            'sub'   => (float) $drift > 0 ? 'یعنی الان ارزان‌تر از قیمت تمام‌شدهٔ امروز می‌فروشید.'
                                         : 'قیمت‌ها را با نرخ تازه به‌روز کنید.',
            'href'  => 'pricing.php', 'cta' => 'به‌روزرسانی قیمت‌ها'];
    } else {
        $todo[] = ['kind' => 'urgent', 'icon' => 'coins',
            'title' => 'قیمت‌های درهمی به‌روز نیستند',
            'sub'   => (int) ($priceStale['behind'] ?? 0) > 0
                ? fa_digits((string) (int) $priceStale['behind']) . ' قلم هنوز با نرخ قبلی قیمت‌گذاری شده‌اند.'
                : 'نرخ درهم ' . fa_digits((string) (int) ($priceStale['days'] ?? 0)) . ' روز است بررسی نشده.',
            'href'  => 'pricing.php', 'cta' => 'به‌روزرسانی قیمت‌ها'];
    }
}
if (!$cardIsSet) {
    $todo[] = ['kind' => 'urgent', 'icon' => 'alert', 'title' => 'شماره کارت هنوز ثبت نشده است',
        'sub' => 'تا ثبت نشود، مشتری بعد از سفارش شماره‌ای برای واریز نمی‌بیند.',
        'href' => 'settings.php', 'cta' => 'ثبت شماره کارت'];
} elseif (!$cardIsValid) {
    $todo[] = ['kind' => 'urgent', 'icon' => 'alert', 'title' => 'شماره کارت ثبت‌شده معتبر نیست',
        'sub' => 'احتمالاً اشتباه تایپ شده است.', 'href' => 'settings.php', 'cta' => 'بررسی تنظیمات'];
}
if ($newCount > 0) {
    $todo[] = ['kind' => 'info', 'icon' => 'box',
        'title' => fa_digits((string) $newCount) . ' سفارش منتظر بررسی',
        'sub' => 'موجودی و مبلغ را تأیید کنید تا شماره کارت برای مشتری فرستاده شود.',
        'href' => 'orders.php?status=new', 'cta' => 'بررسی سفارش‌ها'];
}
if ($reviewsWaiting > 0) {
    $todo[] = ['kind' => 'info', 'icon' => 'star',
        'title' => fa_digits((string) $reviewsWaiting) . ' نظر منتظر تأیید',
        'sub' => 'تا تأیید نکنید روی سایت نمی‌آید.', 'href' => 'reviews.php', 'cta' => 'دیدن نظرها'];
}
if ($runningOut) {
    $todo[] = ['kind' => 'warn', 'icon' => 'boxes',
        'title' => fa_digits((string) count($runningOut)) . (count($runningOut) >= 12 ? '+' : '') . ' قلم رو به اتمام',
        'sub' => 'سفارش‌های باز از این عددها کم شده‌اند.', 'href' => 'stock.php?low=1', 'cta' => 'موجودی انبار',
        'chips' => array_map(static function (array $r): array {
            $n = (int) $r['n'];
            return ['label' => $r['name_fa'] . ($r['size_label'] !== '' ? ' · ' . $r['size_label'] : '')
                             . ' — ' . ($n < 0 ? fa_digits((string) abs($n)) . ' بیش‌فروش' : ($n === 0 ? 'تمام شد' : fa_digits((string) $n) . ' عدد')),
                    'pill' => $n <= 0 ? 'cancelled' : 'new', 'href' => 'stock.php?low=1'];
        }, $runningOut)];
}
if ($offSale) {
    $todo[] = ['kind' => 'calm', 'icon' => 'tag',
        'title' => fa_digits((string) count($offSale)) . ' محصول ناموجود',
        'sub' => 'در سایت دیده می‌شوند ولی دکمهٔ خرید ندارند؛ تا خودتان روشنش نکنید همین می‌ماند.',
        'chips' => array_map(static fn(array $p): array => [
            'label' => $p['name_fa'], 'pill' => 'off',
            'href' => 'product-edit.php?slug=' . rawurlencode($p['slug'])], $offSale)];
}

$weekdays = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
$dateFa   = $weekdays[(int) date('w')] . ' ' . review_date_fa($today);

admin_head('داشبورد', ['user' => $user]);
?>

<div class="hello">
  <div>
    <p class="hello__title">سلام<?= ($user['name'] ?? '') !== '' ? '، ' . e($user['name']) : '' ?></p>
    <p class="hello__date"><?= e($dateFa) ?></p>
  </div>
  <div class="quick-actions">
    <a class="btn btn--primary" href="product-edit.php"><?= admin_icon('plus') ?> محصول جدید</a>
    <a class="btn btn--ghost" href="import.php"><?= admin_icon('upload') ?> ورود از اکسل</a>
    <a class="btn btn--ghost" href="../" target="_blank" rel="noopener"><?= admin_icon('external') ?> دیدن سایت</a>
  </div>
</div>

<div class="stats">
  <a class="stat<?= $newCount > 0 ? ' stat--hot' : '' ?>" href="orders.php?status=new">
    <span class="stat__icon"><?= admin_icon('box') ?></span>
    <div class="stat__label">سفارش‌های در انتظار بررسی</div>
    <div class="stat__value num"><?= $newCount ?></div>
  </a>
  <a class="stat" href="orders.php?from=<?= e($today) ?>&amp;to=<?= e($today) ?>">
    <span class="stat__icon"><?= admin_icon('clock') ?></span>
    <div class="stat__label">سفارش امروز</div>
    <div class="stat__value num"><?= $todayCount ?></div>
  </a>
  <a class="stat" href="report.php">
    <span class="stat__icon"><?= admin_icon('chart') ?></span>
    <div class="stat__label">فروش این ماه (پرداخت‌شده)</div>
    <div class="stat__value num"><?= money($month['goods_total']) ?>
      <span class="stat__unit">تومان</span></div>
  </a>
  <a class="stat stat--accent" href="report.php">
    <span class="stat__icon"><?= admin_icon('scale') ?></span>
    <div class="stat__label">سهم همکاری این ماه</div>
    <div class="stat__value num"><?= money($month['commission_total']) ?>
      <span class="stat__unit">تومان</span></div>
  </a>
</div>

<div class="dash">
  <section class="card">
    <h2 class="card__title">کارهای امروز</h2>
    <?php if (!$todo): ?>
      <div class="todo__done"><?= admin_icon('check') ?> کاری منتظر شما نیست؛ همه‌چیز مرتب است.</div>
    <?php else: ?>
      <ul class="todo">
        <?php foreach ($todo as $t): ?>
          <li class="todo__item todo__item--<?= e($t['kind']) ?>">
            <span class="todo__icon"><?= admin_icon($t['icon']) ?></span>
            <div>
              <div class="todo__title"><?= e($t['title']) ?></div>
              <div class="todo__sub"><?= e($t['sub']) ?></div>
            </div>
            <?php if (!empty($t['href']) && !empty($t['cta'])): ?>
              <a class="btn btn--ghost btn--sm" href="<?= e($t['href']) ?>"><?= e($t['cta']) ?></a>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
            <?php if (!empty($t['chips'])): ?>
              <div class="todo__more chips">
                <?php foreach ($t['chips'] as $c): ?>
                  <a class="pill pill--<?= e($c['pill']) ?>" href="<?= e($c['href']) ?>"><?= e($c['label']) ?></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2 class="card__title">آخرین سفارش‌ها</h2>
    <?php if (!$recent): ?>
      <div class="empty">هنوز سفارشی ثبت نشده است.</div>
    <?php else: ?>
      <ul class="orders-mini">
        <?php foreach ($recent as $o): ?>
          <li>
            <a href="order.php?id=<?= (int) $o['id'] ?>">
              <span class="orders-mini__name"><?= e($o['customer_name']) ?></span>
              <span class="orders-mini__end">
                <span class="num"><?= money((int) $o['total']) ?></span>
                <span class="pill pill--<?= e($o['status']) ?>"><?= e(ORDER_STATUS_FA[$o['status']] ?? $o['status']) ?></span>
              </span>
              <span class="orders-mini__meta">
                <span class="num"><?= e($o['code']) ?></span> ·
                <?= e(review_date_fa((string) $o['created_at'])) ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <a class="card__more" href="orders.php">همهٔ سفارش‌ها <?= admin_icon('arrow') ?></a>
    <?php endif; ?>
  </section>
</div>

<?php admin_foot(); ?>
