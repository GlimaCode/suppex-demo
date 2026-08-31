<?php
/* ===========================================================================
   SUPPEX admin — stock counts
   ---------------------------------------------------------------------------
   Its own page rather than a field on product-edit, for two reasons.

   The first is the job. A shipment arrives and twenty counts change at once;
   doing that through the product editor is twenty page loads, twenty saves and
   twenty chances to knock something else out of shape. Nobody keeps a
   catalogue accurate that way, and an inaccurate count is worse than none —
   it states a number that is wrong.

   The second is that this number moves on its own. Every order takes from it
   while the panel is open, so a form that posts every field it rendered would
   quietly undo a sale each time somebody saved a page they had not touched.
   Only fields that were actually EDITED are written: each input carries what
   it was rendered with, and one that comes back unchanged is left alone.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/stock.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* Orders that lapsed while nobody was looking are holding goods this page is
   about to report as missing. Expiring them first means the numbers below are
   the numbers the shop actually has. */
orders_expire_due();

/**
 * A posted count: '' means untracked, anything else is a number.
 *
 * Negative is accepted deliberately. The shop can be oversold — reopening a
 * cancelled order does exactly that — and refusing to let the figure be typed
 * back would leave the operator unable to record what is on the shelf.
 */
function stock_posted(?string $raw): ?int
{
    $raw = trim(to_latin_digits((string) $raw));
    if ($raw === '') {
        return null;
    }
    $neg = str_starts_with($raw, '-');
    $n   = (int) digits_only($raw);
    return $neg ? -$n : $n;
}

/** Did this field actually change? Compares the two the same way. */
function stock_changed(?string $posted, ?string $was): bool
{
    return stock_posted($posted) !== stock_posted($was);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $touched = 0;

    foreach ((array) ($_POST['p'] ?? []) as $pid => $raw) {
        $pid = (int) $pid;
        $was = (string) ($_POST['p_was'][$pid] ?? '');
        if ($pid <= 0 || !stock_changed((string) $raw, $was)) {
            continue;
        }
        db_query('UPDATE products SET stock_qty = ? WHERE id = ?',
            [stock_posted((string) $raw), $pid]);
        $touched++;
    }

    foreach ((array) ($_POST['s'] ?? []) as $pid => $rows) {
        $pid = (int) $pid;
        foreach ((array) $rows as $ext => $raw) {
            $ext = (string) $ext;
            $was = (string) ($_POST['s_was'][$pid][$ext] ?? '');
            if ($pid <= 0 || $ext === '' || !stock_changed((string) $raw, $was)) {
                continue;
            }
            db_query('UPDATE product_sizes SET stock_qty = ? WHERE product_id = ? AND ext_id = ?',
                [stock_posted((string) $raw), $pid, $ext]);
            $touched++;
        }
    }

    flash('ok', $touched > 0
        ? 'موجودی ' . fa_digits((string) $touched) . ' مورد ذخیره شد.'
        : 'چیزی تغییر نکرده بود.');
    header('Location: stock.php' . (isset($_GET['low']) ? '?low=1' : ''));
    exit;
}

$lowOnly = isset($_GET['low']);
$low     = stock_low_threshold();

$products = db_all(
    'SELECT p.id, p.slug, p.name_fa, p.name_en, p.brand, p.in_stock, p.is_active,
            p.stock_qty, p.image,
            (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = p.id) AS sizes_total
       FROM products p
      ORDER BY p.sort_order, p.id'
);

$sizes = [];
foreach (db_all('SELECT product_id, ext_id, label, stock_qty FROM product_sizes
                  ORDER BY product_id, sort_order, id') as $s) {
    $sizes[(int) $s['product_id']][] = $s;
}

/* How many units the open orders are sitting on. The count above already has
   these subtracted, so without this line the operator counts four tubs on the
   shelf, reads three on the screen and concludes the page is broken. */
$held = [];
foreach (db_all(
    'SELECT oi.product_id, COALESCE(oi.stock_from, "") AS src, SUM(oi.stock_held) AS n
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
      WHERE oi.stock_held > 0
      GROUP BY oi.product_id, src'
) as $h) {
    $held[(int) $h['product_id'] . '|' . (string) $h['src']] = (int) $h['n'];
}
$heldOf = static fn(int $pid, string $src): int => $held[$pid . '|' . $src] ?? 0;

/** Is this row worth showing when the page is filtered to what needs attention? */
$needsEye = static function (array $p) use ($sizes, $low): bool {
    if ($p['stock_qty'] !== null && (int) $p['stock_qty'] <= $low) {
        return true;
    }
    foreach ($sizes[(int) $p['id']] ?? [] as $s) {
        if ($s['stock_qty'] !== null && (int) $s['stock_qty'] <= $low) {
            return true;
        }
    }
    return false;
};

$rows    = $lowOnly ? array_values(array_filter($products, $needsEye)) : $products;
$counted = count(array_filter($products, static fn(array $p): bool => $p['stock_qty'] !== null))
         + count(array_filter($sizes, static fn(array $ss): bool => (bool) array_filter(
               $ss, static fn(array $s): bool => $s['stock_qty'] !== null)));

admin_head('موجودی انبار', ['user' => $user]);
?>

<p class="u-dim" style="max-width:70ch;line-height:2;margin-block-end:16px">
  خالی یعنی «شمرده نشده» — محصول بدون محدودیت فروخته می‌شود، دقیقاً مثل قبل.
  عدد بگذارید تا فروشگاه بیشتر از آن نفروشد. با ثبت هر سفارش یکی کم می‌شود و
  با لغو یا انقضای همان سفارش برمی‌گردد.
  <br>
  اگر محصولی اندازه دارد، عدد هر اندازه جداگانه شمرده می‌شود؛ اندازه‌ای که
  خالی بماند از عدد خودِ محصول استفاده می‌کند.
</p>

<div class="filters" style="margin-block-end:16px">
  <a class="btn btn--<?= $lowOnly ? 'ghost' : 'primary' ?> btn--sm" href="stock.php">همه</a>
  <a class="btn btn--<?= $lowOnly ? 'primary' : 'ghost' ?> btn--sm" href="stock.php?low=1">
    فقط رو به اتمام
  </a>
  <span class="u-dim" style="align-self:center">
    <span class="num"><?= (int) $counted ?></span> مورد شمرده شده
  </span>
</div>

<?php if (!$rows): ?>
  <p class="u-dim">
    <?= $lowOnly ? 'هیچ محصولی رو به اتمام نیست.' : 'هنوز محصولی ثبت نشده.' ?>
  </p>
<?php else: ?>
<form method="post" action="stock.php<?= $lowOnly ? '?low=1' : '' ?>">
  <?= csrf_field() ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>محصول</th>
          <th style="width:9rem">موجودی</th>
          <th style="width:11rem">رزرو سفارش‌های باز</th>
          <th style="width:8rem">وضعیت</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $p):
            $pid      = (int) $p['id'];
            $mySizes  = $sizes[$pid] ?? [];
            $pStock   = $p['stock_qty'] === null ? '' : (string) (int) $p['stock_qty'];
            $pHeld    = $heldOf($pid, '');
        ?>
          <tr>
            <td>
              <div class="t-title"><?= e($p['name_fa']) ?></div>
              <div class="t-sub lat"><?= e($p['brand']) ?></div>
              <?php if ((int) $p['is_active'] !== 1): ?>
                <div class="t-sub">در سایت پنهان است</div>
              <?php elseif ((int) $p['in_stock'] !== 1): ?>
                <div class="t-sub">با کلید «موجود است» خاموش شده</div>
              <?php endif; ?>
            </td>
            <td>
              <input type="text" inputmode="numeric" dir="ltr" style="width:100%"
                     name="p[<?= $pid ?>]" value="<?= e($pStock) ?>"
                     placeholder="<?= $mySizes ? 'اندازه‌های بی‌عدد' : 'شمرده نشده' ?>"
                     aria-label="موجودی <?= e($p['name_fa']) ?>">
              <input type="hidden" name="p_was[<?= $pid ?>]" value="<?= e($pStock) ?>">
            </td>
            <td class="num u-dim"><?= $pHeld > 0 ? (int) $pHeld : '—' ?></td>
            <td>
              <?php
              $n = $p['stock_qty'] === null ? null : (int) $p['stock_qty'];
              if ($n === null): ?>
                <span class="pill pill--off">شمرده نشده</span>
              <?php elseif ($n < 0): ?>
                <span class="pill pill--cancelled">
                  <span class="num"><?= abs($n) ?></span> تا بیشتر فروخته شده
                </span>
              <?php elseif ($n === 0): ?>
                <span class="pill pill--cancelled">تمام شد</span>
              <?php elseif ($n <= $low): ?>
                <span class="pill pill--new">رو به اتمام</span>
              <?php else: ?>
                <span class="pill pill--paid">موجود</span>
              <?php endif; ?>
            </td>
          </tr>

          <?php foreach ($mySizes as $s):
              $ext    = (string) $s['ext_id'];
              $sStock = $s['stock_qty'] === null ? '' : (string) (int) $s['stock_qty'];
              $sHeld  = $heldOf($pid, $ext);
          ?>
            <tr>
              <td style="padding-inline-start:2.5rem">
                <div class="t-sub">↳ <?= e($s['label']) ?></div>
              </td>
              <td>
                <input type="text" inputmode="numeric" dir="ltr" style="width:100%"
                       name="s[<?= $pid ?>][<?= e($ext) ?>]" value="<?= e($sStock) ?>"
                       placeholder="از محصول"
                       aria-label="موجودی <?= e($p['name_fa'] . ' — ' . $s['label']) ?>">
                <input type="hidden" name="s_was[<?= $pid ?>][<?= e($ext) ?>]" value="<?= e($sStock) ?>">
              </td>
              <td class="num u-dim"><?= $sHeld > 0 ? (int) $sHeld : '—' ?></td>
              <td>
                <?php
                $sn = $s['stock_qty'] === null ? null : (int) $s['stock_qty'];
                if ($sn === null): ?>
                  <span class="pill pill--off">از محصول</span>
                <?php elseif ($sn < 0): ?>
                  <span class="pill pill--cancelled">
                    <span class="num"><?= abs($sn) ?></span> تا بیشتر
                  </span>
                <?php elseif ($sn === 0): ?>
                  <span class="pill pill--cancelled">تمام شد</span>
                <?php elseif ($sn <= $low): ?>
                  <span class="pill pill--new">رو به اتمام</span>
                <?php else: ?>
                  <span class="pill pill--paid">موجود</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="row" style="gap:12px;margin-block-start:20px">
    <button class="btn btn--primary" type="submit">ذخیره موجودی‌ها</button>
    <span class="u-dim" style="align-self:center">
      فقط خانه‌هایی که تغییر داده‌اید نوشته می‌شوند.
    </span>
  </div>
</form>
<?php endif; ?>

<?php admin_foot(); ?>
