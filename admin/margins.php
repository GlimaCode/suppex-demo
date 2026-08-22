<?php
/* ===========================================================================
   SUPPEX admin — margin sheet
   ---------------------------------------------------------------------------
   Every buyable unit with what it cost, what it sells for, what it earns, and
   what the partnership takes. One screen, sortable, because the questions this
   answers — "which lines are thin?", "what is my real margin?", "what does the
   share actually cost me on this product?" — are otherwise a spreadsheet
   exercise nobody repeats after the first month.

   It is also the page that makes the commission arrangement legible to both
   sides. A number neither party can see is a number both parties will
   eventually dispute.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

$ready = db_value(
    'SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "products" AND COLUMN_NAME = "commission_percent"'
) !== null;

if (!$ready) {
    admin_head('حاشیه سود', ['user' => $user]);
    echo '<div class="flash flash--error">ساختار دیتابیس هنوز به‌روز نشده است. '
       . '<a href="migrate.php" style="text-decoration:underline">اجرای به‌روزرسانی</a></div>';
    admin_foot();
    exit;
}

$shopRate = max(0.0, min(100.0, setting_float('commission_percent', 0)));
$onProfit = setting('commission_basis', 'goods') === 'profit';

/* One row per buyable unit — a product with sizes is never sold at its own
   price, so listing it would double-count and mislead. */
$rows = [];
$products = db_all(
    'SELECT p.id, p.name_fa, p.price, p.cost_toman, p.commission_percent, p.is_active,
            c.name_fa AS category,
            (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = p.id) AS size_count
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      ORDER BY p.sort_order, p.id'
);

foreach ($products as $p) {
    if ((int) $p['size_count'] === 0) {
        $rows[] = [
            'label' => $p['name_fa'], 'sub' => '', 'category' => $p['category'],
            'price' => (int) $p['price'], 'cost' => (int) ($p['cost_toman'] ?? 0),
            'rate'  => $p['commission_percent'], 'active' => (int) $p['is_active'] === 1,
            'href'  => 'product-edit.php?id=' . (int) $p['id'],
        ];
        continue;
    }
    foreach (db_all('SELECT label, price, cost_toman, commission_percent
                       FROM product_sizes WHERE product_id = ? ORDER BY sort_order, id',
                    [(int) $p['id']]) as $sz) {
        $rows[] = [
            'label' => $p['name_fa'], 'sub' => $sz['label'], 'category' => $p['category'],
            'price' => (int) $sz['price'], 'cost' => (int) ($sz['cost_toman'] ?? 0),
            'rate'  => $sz['commission_percent'] ?? $p['commission_percent'],
            'active' => (int) $p['is_active'] === 1,
            'href'  => 'product-edit.php?id=' . (int) $p['id'],
        ];
    }
}

/* Derive margin and commission for each row. */
foreach ($rows as $i => $r) {
    $profit = $r['cost'] > 0 ? max(0, $r['price'] - $r['cost']) : null;
    $rate   = $r['rate'] === null ? $shopRate : (float) $r['rate'];
    $base   = $onProfit ? ($profit ?? 0) : $r['price'];

    $rows[$i]['profit']      = $profit;
    $rows[$i]['margin_pct']  = ($profit !== null && $r['price'] > 0)
        ? round($profit / $r['price'] * 100, 1) : null;
    $rows[$i]['rate_used']   = $rate;
    $rows[$i]['overridden']  = $r['rate'] !== null;
    $rows[$i]['commission']  = (int) round($base * $rate / 100);
    /* What the shop keeps after the share — the number that decides whether a
       thin line is still worth stocking. */
    $rows[$i]['net'] = $profit === null ? null : $profit - $rows[$i]['commission'];
}

$sort = (string) ($_GET['sort'] ?? 'margin');
usort($rows, static function (array $a, array $b) use ($sort) {
    return match ($sort) {
        'price'      => $b['price'] <=> $a['price'],
        'profit'     => ($b['profit'] ?? -1) <=> ($a['profit'] ?? -1),
        'commission' => $b['commission'] <=> $a['commission'],
        default      => ($a['margin_pct'] ?? 999) <=> ($b['margin_pct'] ?? 999),
    };
});

$priced   = array_values(array_filter($rows, static fn($r) => $r['profit'] !== null));
$missing  = count($rows) - count($priced);
$totalP   = array_sum(array_column($priced, 'profit'));
$totalC   = array_sum(array_column($priced, 'commission'));
$avgMargin = $priced
    ? round(array_sum(array_column($priced, 'margin_pct')) / count($priced), 1)
    : 0;

$log = db_all('SELECT * FROM commission_log ORDER BY id DESC LIMIT 12');

admin_head('حاشیه سود و سهم', ['user' => $user]);
?>

<?php if ($missing > 0): ?>
  <div class="flash flash--info">
    <span class="num"><?= $missing ?></span> قلم هنوز قیمت خرید ندارند و در ارقام زیر نیامده‌اند.
    تا وارد نشود، نه سود واقعی معلوم است نه سهم همکاری.
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat">
    <div class="stat__label">اقلام با قیمت خرید</div>
    <div class="stat__value num"><?= count($priced) ?><span class="stat__unit"> از <?= count($rows) ?></span></div>
  </div>
  <div class="stat">
    <div class="stat__label">میانگین حاشیه سود</div>
    <div class="stat__value num"><?= $avgMargin ?>٪</div>
  </div>
  <div class="stat">
    <div class="stat__label">سود روی یک عدد از هر قلم</div>
    <div class="stat__value num"><?= money($totalP) ?><span class="stat__unit"> تومان</span></div>
  </div>
  <div class="stat stat--accent">
    <div class="stat__label">سهم همکاری روی همان</div>
    <div class="stat__value num"><?= money($totalC) ?><span class="stat__unit"> تومان</span></div>
  </div>
</div>

<div class="card">
  <h2 class="card__title">مبنای محاسبه</h2>
  <p style="color:var(--text-muted);line-height:2.1">
    سهم همکاری <strong><?= $onProfit ? 'درصدی از سود خالص' : 'درصدی از مبلغ کالا' ?></strong>
    است، با نرخ کلی <span class="num"><?= rtrim(rtrim(number_format($shopRate, 2), '0'), '.') ?>٪</span>.
    <?php if ($onProfit): ?>
      چون مبنا سود است، قلم کم‌حاشیه خودبه‌خود سهم کمتری می‌دهد —
      ستون «سهم» را با ستون «حاشیه» مقایسه کنید تا ببینید.
      <strong>برای همین معمولاً لازم نیست درصد را روی تک‌تک محصولات دستی تنظیم کنید.</strong>
    <?php endif; ?>
  </p>
</div>

<div class="filters">
  <span class="u-dim">مرتب‌سازی:</span>
  <?php foreach ([
      'margin' => 'کم‌حاشیه‌ترین اول',
      'profit' => 'بیشترین سود',
      'price'  => 'گران‌ترین',
      'commission' => 'بیشترین سهم',
  ] as $key => $label): ?>
    <a class="btn btn--sm <?= $sort === $key ? 'btn--primary' : 'btn--ghost' ?>"
       href="?sort=<?= e($key) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="card"><div class="empty">هنوز محصولی ثبت نشده است.</div></div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>قلم</th>
          <th>خرید</th>
          <th>فروش</th>
          <th>سود</th>
          <th>حاشیه</th>
          <th>نرخ</th>
          <th>سهم</th>
          <th class="u-right">خالص فروشگاه</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr<?= $r['active'] ? '' : ' style="opacity:.5"' ?>>
            <td>
              <div class="t-title"><a href="<?= e($r['href']) ?>"><?= e($r['label']) ?></a></div>
              <div class="t-sub">
                <?= $r['sub'] !== '' ? e($r['sub']) . ' · ' : '' ?><?= e($r['category'] ?? '—') ?>
              </div>
            </td>
            <td class="num u-nowrap u-dim">
              <?= $r['cost'] > 0 ? money($r['cost']) : '—' ?>
            </td>
            <td class="num u-nowrap"><?= money($r['price']) ?></td>
            <td class="num u-nowrap">
              <?= $r['profit'] === null ? '—' : money($r['profit']) ?>
            </td>
            <td class="num u-nowrap">
              <?php if ($r['margin_pct'] === null): ?>—
              <?php else: ?>
                <span class="pill <?= $r['margin_pct'] < 15 ? 'pill--cancelled'
                                     : ($r['margin_pct'] < 25 ? 'pill--shipped' : 'pill--paid') ?>">
                  <?= $r['margin_pct'] ?>٪
                </span>
              <?php endif; ?>
            </td>
            <td class="num u-nowrap">
              <?= rtrim(rtrim(number_format($r['rate_used'], 2), '0'), '.') ?>٪
              <?php if ($r['overridden']): ?>
                <div class="t-sub" style="color:#f0c473">اختصاصی</div>
              <?php endif; ?>
            </td>
            <td class="num u-nowrap u-dim"><?= money($r['commission']) ?></td>
            <td class="num u-nowrap u-right">
              <strong><?= $r['net'] === null ? '—' : money($r['net']) ?></strong>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="card">
  <h2 class="card__title">تاریخچه تغییر درصد</h2>
  <?php if (!$log): ?>
    <div class="empty">هنوز درصدی تغییر نکرده است.</div>
  <?php else: ?>
    <div class="table-wrap" style="border:0">
      <table style="min-width:0">
        <thead>
          <tr><th>تاریخ</th><th>مورد</th><th>از</th><th>به</th><th>توسط</th></tr>
        </thead>
        <tbody>
          <?php foreach ($log as $l): ?>
            <tr>
              <td class="num u-nowrap u-dim"><?= e(date('Y/m/d H:i', strtotime($l['created_at']))) ?></td>
              <td><?= e($l['label'] !== '' ? $l['label'] : $l['scope']) ?></td>
              <td class="num"><?= $l['old_percent'] === null ? 'پیش‌فرض' : e($l['old_percent']) . '٪' ?></td>
              <td class="num"><?= $l['new_percent'] === null ? 'پیش‌فرض' : e($l['new_percent']) . '٪' ?></td>
              <td class="u-dim"><?= e($l['admin_name']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php admin_foot(); ?>
