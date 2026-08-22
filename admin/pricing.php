<?php
/* ===========================================================================
   SUPPEX admin — dirham rate and catalogue re-pricing
   ---------------------------------------------------------------------------
   Enter today's rate, look at what it does to every price, then apply it.
   The preview between those two steps is the entire point of the page: it is
   the only thing standing between a mistyped rate and a catalogue that is
   suddenly priced at ten times, or a tenth of, what it should be.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* The pricing columns arrive with db/migrate.php. Without them every query on
   this page is a fatal error, so say so plainly instead. */
$ready = db_value(
    'SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "products" AND COLUMN_NAME = "price_mode"'
) !== null;

if (!$ready) {
    admin_head('قیمت‌گذاری', ['user' => $user]);
    echo '<div class="flash flash--error">'
       . 'ساختار دیتابیس هنوز به‌روز نشده است. '
       . '<a href="migrate.php" style="text-decoration:underline">اجرای به‌روزرسانی</a>'
       . '</div>';
    admin_foot();
    exit;
}

$errors  = [];
$notice  = null;
$rateIn  = pricing_rate();
$preview = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    $posted = (float) to_latin_digits(str_replace(',', '', (string) ($_POST['rate'] ?? '')));

    if ($posted <= 0) {
        $errors['rate'] = 'نرخ درهم را وارد کنید.';
    }

    if (!$errors && $action === 'preview') {
        $rateIn  = $posted;
        $preview = pricing_preview($posted);
        $notice  = pricing_rate_warning($posted);
    }

    if (!$errors && $action === 'apply') {
        /* Re-confirmed, not trusted from the preview round-trip: the preview
           was rendered from a rate in a hidden field, and between then and now
           a product could have been edited in another tab. Recomputing here
           means what gets written is derived from the database as it is at the
           moment of writing. */
        $onlyKeys = array_values(array_filter(
            array_map('strval', (array) ($_POST['ids'] ?? [])),
            static fn(string $k): bool => (bool) preg_match('/^(product|size):[0-9]+$/', $k)
        ));

        try {
            $result = pricing_apply($posted, $user, $onlyKeys);
            $msg = 'قیمت ' . $result['applied'] . ' محصول با نرخ ' . number_format($posted) . ' به‌روز شد.';
            if ($result['skipped'] > 0) {
                $msg .= ' ' . $result['skipped'] . ' مورد به دلیل نداشتن سود اعمال نشد.';
            }
            flash('ok', $msg);
        } catch (Throwable $e) {
            flash('error', 'اعمال قیمت‌ها ناموفق بود. هیچ قیمتی تغییر نکرد.');
        }

        header('Location: pricing.php');
        exit;
    }
}

/* Default view: preview at the stored rate, so opening the page already
   answers "are my prices current?" without anyone typing anything. */
if (!$preview && $rateIn !== null) {
    $preview = pricing_preview($rateIn);
}

$stale   = pricing_staleness();
$history = db_all('SELECT * FROM rate_history ORDER BY id DESC LIMIT 10');
$manualCount = (int) db_value(
    'SELECT COUNT(*) FROM products WHERE is_active = 1 AND (price_mode <> "aed" OR cost_aed IS NULL OR cost_aed <= 0)'
);

$changed = array_values(array_filter($preview, static fn(array $r) => $r['delta'] !== 0));
$noMargin = array_values(array_filter($preview, static fn(array $r) => $r['no_margin']));
$odd      = array_values(array_filter($preview, static fn(array $r) => $r['implausible'] && !$r['no_margin']));

admin_head('قیمت‌گذاری بر اساس درهم', ['user' => $user]);
?>

<?php if ($notice !== null): ?>
  <div class="flash flash--error"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($stale['stale']): ?>
  <div class="flash flash--error">
    <?php if ($stale['days'] !== null && $stale['days'] >= 1): ?>
      نرخ درهم
      <strong><span class="num"><?= e((string) (int) $stale['days']) ?></span> روز</strong>
      است که به‌روز نشده است.
    <?php endif; ?>
    <?php if ($stale['behind'] > 0): ?>
      <span class="num"><?= $stale['behind'] ?></span> از
      <span class="num"><?= $stale['total'] ?></span>
      قلم هنوز با نرخ قبلی قیمت‌گذاری شده‌اند.
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat">
    <div class="stat__label">نرخ فعلی درهم</div>
    <div class="stat__value num"><?= $rateIn === null ? '—' : money((int) $rateIn) ?>
      <span class="stat__unit">تومان</span></div>
  </div>
  <div class="stat">
    <div class="stat__label">آخرین به‌روزرسانی</div>
    <div class="stat__value" style="font-size:1rem">
      <?php $when = (string) setting('aed_rate_updated_at', ''); ?>
      <?= $when === '' ? '—' : e(date('Y/m/d H:i', strtotime($when))) ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat__label">اقلام قابل خرید</div>
    <div class="stat__value num"><?= count($preview) ?></div>
    <span class="stat__unit">هر اندازه، یک قلم</span>
  </div>
  <div class="stat<?= $changed ? ' stat--accent' : '' ?>">
    <div class="stat__label">نیازمند تغییر قیمت</div>
    <div class="stat__value num"><?= count($changed) ?></div>
  </div>
</div>

<div class="card">
  <h2 class="card__title">نرخ امروز</h2>
  <form method="post" action="pricing.php">
    <?= csrf_field() ?>
    <div class="filters" style="margin:0">
      <div class="field" style="min-width:220px">
        <label for="rate">هر ۱ درهم چند تومان؟</label>
        <input type="text" id="rate" name="rate" dir="ltr" inputmode="numeric"
               value="<?= e($rateIn === null ? '' : (string) (int) $rateIn) ?>"
               placeholder="مثلاً 24500">
        <?php if (!empty($errors['rate'])): ?>
          <span class="err"><?= e($errors['rate']) ?></span>
        <?php endif; ?>
      </div>
      <button class="btn btn--ghost" type="submit" name="action" value="preview">
        محاسبه قیمت‌ها
      </button>
    </div>
    <p class="hint" style="margin-block-start:12px">
      نرخ <strong>بازار آزاد</strong> را وارد کنید — همان نرخی که واقعاً با آن خرید می‌کنید،
      نه نرخ رسمی بانک مرکزی. تا وقتی دکمه «اعمال» را نزنید، هیچ قیمتی روی سایت تغییر نمی‌کند.
    </p>
  </form>
</div>

<?php if ($noMargin): ?>
  <div class="flash flash--error">
    <strong><span class="num"><?= count($noMargin) ?></span> محصول</strong>
    با این نرخ هیچ سودی ندارند و اعمال نمی‌شوند —
    مقدار سود آن محصول‌ها را در صفحه ویرایش وارد کنید.
  </div>
<?php endif; ?>

<?php if ($odd): ?>
  <div class="flash flash--error">
    <strong><span class="num"><?= count($odd) ?></span> محصول</strong>
    قیمت جدیدشان بیش از ۵ برابر با قیمت فعلی فاصله دارد.
    تقریباً همیشه یعنی قیمت خرید به جای درهم، به تومان وارد شده است.
    تیکشان برداشته شده — اگر واقعاً درست است، دستی تیک بزنید.
  </div>
<?php endif; ?>

<?php if (!$preview): ?>
  <div class="card">
    <div class="empty">
      هنوز محصولی به درهم قیمت‌گذاری نشده است.
      <p class="hint" style="margin-block-start:12px;max-width:460px;margin-inline:auto">
        در صفحه ویرایش هر محصول، حالت قیمت را روی «بر اساس درهم» بگذارید و
        قیمت خرید به درهم و سود مورد نظر را وارد کنید.
      </p>
      <div style="margin-block-start:16px">
        <a class="btn btn--primary" href="products.php">رفتن به محصولات</a>
      </div>
    </div>
  </div>
<?php else: ?>
  <form method="post" action="pricing.php">
    <?= csrf_field() ?>
    <input type="hidden" name="rate" value="<?= e((string) (int) $rateIn) ?>">

    <div class="card">
      <h2 class="card__title">پیش‌نمایش قیمت‌ها</h2>

      <div class="table-wrap" style="border:0">
        <table>
          <thead>
            <tr>
              <th style="width:34px"></th>
              <th>محصول</th>
              <th>خرید (درهم)</th>
              <th>قیمت تمام‌شده</th>
              <th>سود</th>
              <th>قیمت فعلی</th>
              <th>قیمت جدید</th>
              <th>قبل از تخفیف</th>
              <th class="u-right">تغییر</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $row): ?>
              <?php $suspect = $row['no_margin'] || $row['implausible']; ?>
              <tr<?= $suspect ? ' style="background:rgba(226,86,74,.07)"' : '' ?>>
                <td>
                  <input type="checkbox" name="ids[]"
                         value="<?= e($row['kind'] . ':' . $row['id']) ?>"
                         <?= $row['no_margin'] ? 'disabled' : ($suspect ? '' : 'checked') ?>
                         style="width:17px;height:17px;accent-color:var(--accent)">
                </td>
                <td>
                  <div class="t-title"><?= e($row['label']) ?></div>
                  <?php if ($row['sub'] !== ''): ?>
                    <div class="t-sub"><?= e($row['sub']) ?></div>
                  <?php endif; ?>
                  <?php if ($row['no_margin']): ?>
                    <div class="t-sub" style="color:#f2a29a">بدون سود — اعمال نمی‌شود</div>
                  <?php elseif ($row['implausible']): ?>
                    <div class="t-sub" style="color:#f0c473">تغییر غیرعادی — قیمت خرید را بررسی کنید</div>
                  <?php endif; ?>
                </td>
                <td class="num u-nowrap"><?= e(rtrim(rtrim(number_format($row['cost_aed'], 2), '0'), '.')) ?></td>
                <td class="num u-nowrap u-dim"><?= money($row['cost']) ?></td>
                <td class="num u-nowrap u-dim"><?= money($row['profit']) ?></td>
                <td class="num u-nowrap"><?= money($row['current']) ?></td>
                <td class="num u-nowrap"><strong><?= money($row['proposed']) ?></strong></td>
                <td class="num u-nowrap u-dim">
                  <?= $row['compare_at'] === null ? '—' : money($row['compare_at']) ?>
                </td>
                <td class="num u-nowrap u-right"
                    style="color:<?= $row['delta'] > 0 ? '#f0c473' : ($row['delta'] < 0 ? '#8fe0aa' : 'var(--text-dim)') ?>">
                  <?= $row['delta'] === 0 ? '—'
                      : ($row['delta'] > 0 ? '+' : '−') . money(abs($row['delta'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-foot">
        <button class="btn btn--primary" type="submit" name="action" value="apply"
                data-confirm="قیمت محصولات انتخاب‌شده با این نرخ روی سایت اعمال شود؟">
          اعمال روی سایت
        </button>
        <span class="spacer"></span>
        <span class="u-dim">
          تیک هر محصولی را که نمی‌خواهید تغییر کند بردارید.
        </span>
      </div>
    </div>
  </form>
<?php endif; ?>

<div class="split">
  <div>
    <div class="card">
      <h2 class="card__title">تاریخچه نرخ</h2>
      <?php if (!$history): ?>
        <div class="empty">هنوز نرخی اعمال نشده است.</div>
      <?php else: ?>
        <div class="table-wrap" style="border:0">
          <table style="min-width:0">
            <thead>
              <tr><th>تاریخ</th><th>نرخ</th><th>تغییر</th><th>محصولات</th><th>توسط</th></tr>
            </thead>
            <tbody>
              <?php foreach ($history as $h): ?>
                <tr>
                  <td class="num u-nowrap u-dim"><?= e(date('Y/m/d H:i', strtotime($h['created_at']))) ?></td>
                  <td class="num u-nowrap"><?= money((int) $h['rate']) ?></td>
                  <td class="num u-nowrap u-dim">
                    <?php if ($h['previous'] === null): ?>—<?php else: ?>
                      <?php $d = (float) $h['rate'] - (float) $h['previous']; ?>
                      <?= ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . money((int) abs($d)) ?>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= (int) $h['applied_to'] ?></td>
                  <td class="u-dim"><?= e($h['admin_name']) ?></td>
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
      <h2 class="card__title">چطور کار می‌کند</h2>
      <p style="color:var(--text-muted);line-height:2.1">
        قیمت هر محصول درهمی از این فرمول ساخته می‌شود:
      </p>
      <p style="background:var(--surface-2);padding:14px;border-radius:12px;
                text-align:center;margin-block:12px" class="num">
        (خرید به درهم × نرخ) + سود − تخفیف
      </p>
      <p style="color:var(--text-muted);line-height:2.1">
        هر <strong>اندازه</strong> یک قلم جداگانه است و قیمت خرید خودش را دارد —
        چون قوطی ۹۰۰ گرمی و ۲۲۷۰ گرمی دو خرید جدا با دو قیمت جدا هستند.
      </p>
      <p style="color:var(--text-muted);line-height:2.1">
        نتیجه رو به بالا به نزدیک‌ترین
        <span class="num"><?= money(pricing_step()) ?></span>
        تومان گرد می‌شود تا قیمت رُند بماند. گرد کردن همیشه رو به بالاست،
        چون گرد کردن به پایین گاهی سود را از چیزی که تعیین کرده‌اید کمتر می‌کند.
      </p>
      <?php if ($manualCount > 0): ?>
        <p style="color:var(--text-muted);line-height:2.1;margin-block-start:12px">
          <span class="num"><?= $manualCount ?></span>
          محصول فعال، قیمت دستی دارند و این نرخ روی آن‌ها اثری ندارد.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
