<?php
/* ===========================================================================
   SUPPEX admin — one order, printed: the parcel's label and the invoice that
   goes in the box
   ---------------------------------------------------------------------------
   Both carry «SUPPEX — suppex.ir» (lib/order_messages.php): the owner asked on
   2026-10-03 for the shop's name and address on everything a customer
   receives, and the box is the one thing every customer keeps for a while.

   Its own page rather than print styles on order.php, so the printout is the
   label and the slip and nothing of the panel around them - and so a browser's
   «print» on order.php itself still prints the screen people expect.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/reviews.php';          // review_date_fa()
require_once SUPPEX_ROOT . '/lib/order_messages.php';

$user = auth_require();

$id    = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? order_get($id) : null;
if ($order === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'این سفارش وجود ندارد.';
    exit;
}

$shopPhone = trim((string) setting('shop_phone', ''));
$status    = ORDER_STATUS_FA[(string) $order['status']] ?? (string) $order['status'];
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>برچسب و فاکتور <?= e((string) $order['code']) ?></title>
<style>
  @font-face { font-family: 'Vazirmatn'; src: url('../assets/fonts/Vazirmatn-Regular.woff2') format('woff2'); font-weight: 400; }
  @font-face { font-family: 'Vazirmatn'; src: url('../assets/fonts/Vazirmatn-Bold.woff2') format('woff2'); font-weight: 700; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #eceae6; color: #111; font: 13px/1.8 'Vazirmatn', Tahoma, sans-serif; }
  .sheet { width: 190mm; margin: 12px auto; background: #fff; padding: 10mm; }
  .bar { width: 190mm; margin: 12px auto 0; display: flex; gap: 8px; }
  .bar button, .bar a { font: inherit; padding: 6px 16px; border-radius: 8px; border: 1px solid #999;
                         background: #fff; color: #111; text-decoration: none; cursor: pointer; }
  .bar button { background: #f25914; border-color: #f25914; color: #fff; font-weight: 700; }
  .num { font-variant-numeric: tabular-nums; }
  .ltr { direction: ltr; unicode-bidi: isolate; display: inline-block; }
  h2 { font-size: 12px; margin: 0 0 4px; color: #555; font-weight: 700; }
  .brand { font-weight: 700; letter-spacing: .04em; }

  /* The label: cut along the dashed line and stick it on the box. */
  .label { border: 2px dashed #111; padding: 6mm; display: grid; grid-template-columns: 1.4fr 1fr; gap: 6mm; }
  .label__to { font-size: 15px; }
  .label__to .who { font-size: 18px; font-weight: 700; }
  .label__from { border-inline-start: 1px solid #ccc; padding-inline-start: 6mm; }
  .label__code { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center;
                 border-top: 1px solid #ccc; padding-top: 3mm; font-size: 14px; }
  .label__code strong { font-size: 18px; letter-spacing: .06em; }

  /* The invoice: folded into the box. */
  .invoice { margin-top: 10mm; }
  .invoice__head { display: flex; justify-content: space-between; align-items: flex-end;
                   border-bottom: 2px solid #111; padding-bottom: 3mm; margin-bottom: 4mm; }
  .invoice__head .brand { font-size: 22px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; text-align: start; vertical-align: top; }
  th { font-size: 12px; color: #555; }
  td.n, th.n { text-align: end; white-space: nowrap; }
  .sub { color: #555; font-size: 12px; }
  .totals { margin-top: 4mm; margin-inline-start: auto; width: 70mm; }
  .totals div { display: flex; justify-content: space-between; padding: 2px 0; }
  .totals .grand { border-top: 2px solid #111; margin-top: 2mm; padding-top: 2mm; font-weight: 700; font-size: 15px; }
  .invoice__foot { margin-top: 8mm; padding-top: 3mm; border-top: 1px solid #ccc; display: flex;
                   justify-content: space-between; gap: 6mm; font-size: 12px; color: #333; }

  @media print {
    @page { size: A4; margin: 10mm; }
    body { background: #fff; }
    .bar { display: none; }
    .sheet { width: auto; margin: 0; padding: 0; }
  }
</style>
</head>
<body>

<div class="bar">
  <button type="button" onclick="window.print()">چاپ</button>
  <a href="order.php?id=<?= (int) $order['id'] ?>">بازگشت به سفارش</a>
</div>

<div class="sheet">

  <section class="label" aria-label="برچسب مرسوله">
    <div class="label__to">
      <h2>گیرنده</h2>
      <div class="who"><?= e((string) $order['customer_name']) ?></div>
      <div style="white-space:pre-line"><?= e((string) $order['address']) ?></div>
      <div>کد پستی: <span class="num ltr"><?= e((string) $order['postal']) ?></span></div>
      <div>تلفن: <span class="num ltr"><?= e((string) $order['phone']) ?></span></div>
    </div>
    <div class="label__from">
      <h2>فرستنده</h2>
      <div class="brand"><?= e(brand_name()) ?></div>
      <div class="ltr"><?= e(brand_host()) ?></div>
      <?php if ($shopPhone !== ''): ?>
        <div>تلفن: <span class="num ltr"><?= e($shopPhone) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="label__code">
      <span>سفارش <strong class="num ltr"><?= e((string) $order['code']) ?></strong></span>
      <span class="ltr"><?= e(brand_signature()) ?></span>
    </div>
  </section>

  <section class="invoice" aria-label="فاکتور">
    <div class="invoice__head">
      <div>
        <div class="brand"><?= e(brand_name()) ?></div>
        <div class="ltr"><?= e(brand_host()) ?></div>
      </div>
      <div style="text-align:end">
        <div>فاکتور سفارش <strong class="num ltr"><?= e((string) $order['code']) ?></strong></div>
        <div class="sub">تاریخ: <?= e(review_date_fa((string) $order['created_at'])) ?> · وضعیت: <?= e($status) ?></div>
      </div>
    </div>

    <div class="sub" style="margin-bottom:3mm">
      گیرنده: <?= e((string) $order['customer_name']) ?> —
      <span class="num ltr"><?= e((string) $order['phone']) ?></span>
    </div>

    <table>
      <thead>
        <tr><th style="width:8mm">#</th><th>کالا</th><th class="n">تعداد</th><th class="n">قیمت واحد</th><th class="n">جمع</th></tr>
      </thead>
      <tbody>
        <?php foreach ($order['items'] as $i => $item): ?>
          <tr>
            <td class="num"><?= $i + 1 ?></td>
            <td>
              <?= e((string) $item['name_fa']) ?>
              <?php if ((string) $item['variant_label'] !== ''): ?>
                <div class="sub"><?= e((string) $item['variant_label']) ?></div>
              <?php endif; ?>
            </td>
            <td class="n num"><?= (int) $item['qty'] ?></td>
            <td class="n num"><?= money((int) $item['unit_price']) ?></td>
            <td class="n num"><?= money((int) $item['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals">
      <div><span>جمع کالاها</span><span class="num"><?= money((int) $order['subtotal']) ?> تومان</span></div>
      <div><span>هزینه ارسال</span><span class="num"><?= (int) $order['shipping'] === 0
          ? 'رایگان' : money((int) $order['shipping']) . ' تومان' ?></span></div>
      <div class="grand"><span>مبلغ کل</span><span class="num"><?= money((int) $order['total']) ?> تومان</span></div>
    </div>

    <div class="invoice__foot">
      <span>پیگیری سفارش: <span class="ltr"><?= e(brand_track_url()) ?></span> — با شماره سفارش و شماره موبایل</span>
      <span class="brand ltr"><?= e(brand_signature()) ?></span>
    </div>
  </section>

</div>
</body>
</html>
