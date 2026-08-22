<?php
/* ===========================================================================
   SUPPEX admin — settlement report
   ---------------------------------------------------------------------------
   The page both sides of the partnership look at. With no payment gateway,
   this is the ledger, so what it counts and what it leaves out has to be
   stated on the page itself rather than assumed:

     * Only paid / shipped / completed orders count. An order still sitting at
       "ثبت شده" is a message somebody sent, not a sale.
     * Commission comes from each order's own stored rate, not today's rate.
     * Cancelled orders are excluded and shown separately, so a month with an
       unusual number of them is visible instead of merely producing a smaller
       total that nobody can explain.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to   = (string) ($_GET['to']   ?? date('Y-m-d'));

/* Dates come straight from the query string, so anything that is not an
   ISO date is replaced rather than passed to the query. */
$dateRe = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRe, $from)) { $from = date('Y-m-01'); }
if (!preg_match($dateRe, $to))   { $to   = date('Y-m-d'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }

$s = orders_settlement($from, $to);

$cancelled = db_one(
    'SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount
       FROM orders WHERE status = "cancelled" AND created_at >= ? AND created_at <= ?',
    [$from . ' 00:00:00', $to . ' 23:59:59']
);
$pending = db_one(
    'SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount
       FROM orders WHERE status = "new" AND created_at >= ? AND created_at <= ?',
    [$from . ' 00:00:00', $to . ' 23:59:59']
);

$daily = db_all(
    'SELECT DATE(created_at) AS day, COUNT(*) AS orders,
            COALESCE(SUM(subtotal), 0) AS goods,
            COALESCE(SUM(commission_amount), 0) AS commission
       FROM orders
      WHERE status IN ("paid","shipped","done") AND created_at >= ? AND created_at <= ?
      GROUP BY DATE(created_at)
      ORDER BY day DESC',
    [$from . ' 00:00:00', $to . ' 23:59:59']
);

$topProducts = db_all(
    'SELECT oi.name_fa, SUM(oi.qty) AS qty, SUM(oi.line_total) AS revenue
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
      WHERE o.status IN ("paid","shipped","done") AND o.created_at >= ? AND o.created_at <= ?
      GROUP BY oi.name_fa
      ORDER BY revenue DESC
      LIMIT 10',
    [$from . ' 00:00:00', $to . ' 23:59:59']
);

admin_head('گزارش مالی', ['user' => $user]);
?>

<form class="filters" method="get" action="report.php">
  <div class="field">
    <label for="from">از تاریخ</label>
    <input type="date" id="from" name="from" value="<?= e($from) ?>">
  </div>
  <div class="field">
    <label for="to">تا تاریخ</label>
    <input type="date" id="to" name="to" value="<?= e($to) ?>">
  </div>
  <button class="btn btn--primary" type="submit">نمایش</button>
  <a class="btn btn--ghost" href="?from=<?= e(date('Y-m-01')) ?>&to=<?= e(date('Y-m-d')) ?>">این ماه</a>
  <a class="btn btn--ghost" href="?from=<?= e(date('Y-m-01', strtotime('first day of last month'))) ?>&to=<?= e(date('Y-m-t', strtotime('last day of last month'))) ?>">ماه قبل</a>
</form>

<div class="stats">
  <div class="stat">
    <div class="stat__label">سفارش‌های پرداخت‌شده</div>
    <div class="stat__value num"><?= $s['order_count'] ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">فروش کالا</div>
    <div class="stat__value num"><?= money($s['goods_total']) ?>
      <span class="stat__unit">تومان</span></div>
  </div>
  <div class="stat">
    <div class="stat__label">سود خالص فروشگاه</div>
    <div class="stat__value num"><?= money($s['profit_total'] ?? 0) ?>
      <span class="stat__unit">تومان</span></div>
  </div>
  <div class="stat stat--accent">
    <div class="stat__label">سهم همکاری</div>
    <div class="stat__value num"><?= money($s['commission_total']) ?>
      <span class="stat__unit">تومان</span></div>
  </div>
</div>

<div class="card">
  <h2 class="card__title">این عدد چطور حساب شده است</h2>
  <ul style="padding-inline-start:20px;line-height:2.1;color:var(--text-muted)">
    <li>فقط سفارش‌هایی شمرده شده‌اند که وضعیتشان «پرداخت شده»، «ارسال شده» یا «تکمیل شده» است.</li>
    <?php $basisIsProfit = setting('commission_basis', 'goods') === 'profit'; ?>
    <li>
      سهم همکاری روی
      <strong><?= $basisIsProfit ? 'سود خالص' : 'مبلغ کالاها' ?></strong>
      حساب می‌شود، نه روی هزینه ارسال.
      <?php if ($basisIsProfit): ?>
        سود هر قلم = قیمت فروش منهای قیمت تمام‌شده،
        و قیمت تمام‌شده همان قیمت خرید به درهم است که با نرخ لحظه
        قیمت‌گذاری به تومان تبدیل شده بود.
      <?php endif; ?>
    </li>
    <li>درصد هر سفارش همان درصدی است که در لحظه ثبت آن سفارش تعیین شده بود؛
        تغییر درصد در تنظیمات، گذشته را عوض نمی‌کند.</li>
    <li>
      در این بازه
      <span class="num"><?= (int) $pending['n'] ?></span>
      سفارش هنوز بررسی نشده
      (<span class="num"><?= money((int) $pending['amount']) ?></span> تومان)
      و
      <span class="num"><?= (int) $cancelled['n'] ?></span>
      سفارش لغو شده
      (<span class="num"><?= money((int) $cancelled['amount']) ?></span> تومان)
      — هیچ‌کدام در ارقام بالا نیامده‌اند.
    </li>
    <li>هر تغییر وضعیت با نام کاربر و زمان آن در تاریخچه همان سفارش ثبت می‌شود.</li>
  </ul>
</div>

<div class="split">
  <div>
    <div class="card">
      <h2 class="card__title">به تفکیک روز</h2>
      <?php if (!$daily): ?>
        <div class="empty">در این بازه سفارش پرداخت‌شده‌ای نبوده است.</div>
      <?php else: ?>
        <div class="table-wrap" style="border:0">
          <table style="min-width:0">
            <thead>
              <tr><th>تاریخ</th><th>سفارش</th><th>فروش کالا</th><th class="u-right">سهم همکاری</th></tr>
            </thead>
            <tbody>
              <?php foreach ($daily as $d): ?>
                <tr>
                  <td class="num u-nowrap"><?= e(date('Y/m/d', strtotime($d['day']))) ?></td>
                  <td class="num"><?= (int) $d['orders'] ?></td>
                  <td class="num u-nowrap"><?= money((int) $d['goods']) ?></td>
                  <td class="num u-nowrap u-right"><?= money((int) $d['commission']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <h2 class="card__title">پرفروش‌ترین‌ها</h2>
      <?php if (!$topProducts): ?>
        <div class="empty">داده‌ای نیست.</div>
      <?php else: ?>
        <div class="table-wrap" style="border:0">
          <table style="min-width:0">
            <thead>
              <tr><th>کالا</th><th>تعداد</th><th class="u-right">فروش</th></tr>
            </thead>
            <tbody>
              <?php foreach ($topProducts as $p): ?>
                <tr>
                  <td><?= e($p['name_fa']) ?></td>
                  <td class="num"><?= (int) $p['qty'] ?></td>
                  <td class="num u-nowrap u-right"><?= money((int) $p['revenue']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
