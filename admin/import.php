<?php
/* ===========================================================================
   SUPPEX admin — bulk catalogue import
   ---------------------------------------------------------------------------
   Upload the cost sheet, look at what it would do, then apply it.

   Same shape as the pricing page and for the same reason: the preview between
   the two steps is the whole safety mechanism. A mistyped column is obvious in
   a table of proposed products and invisible in "48 products imported".
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/import.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* --- The template download ------------------------------------------------
   Served before any HTML is emitted, or the CSV would carry the page with it. */
if (($_GET['template'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suppex-products.csv"');
    echo import_template_csv();
    exit;
}

$plan    = null;
$errors  = [];
$notice  = null;

/* The parsed file is held in the session between preview and apply. Re-reading
   the upload is impossible — PHP deletes it when the request ends — and asking
   for it twice would mean the thing applied might not be the thing previewed. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        $file = $_FILES['sheet'] ?? null;

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'فایلی انتخاب نشده است.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'فایل معتبر نیست.';
        } elseif ((int) $file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'حجم فایل بیش از ۲ مگابایت است.';
        } else {
            $read = import_read_csv($file['tmp_name']);
            if ($read['error'] !== null) {
                $errors[] = $read['error'];
            } elseif (!$read['rows']) {
                $errors[] = 'هیچ سطر داده‌ای در فایل نبود.';
            } else {
                /* The column list travels with the rows: a column absent
                   from the file must not be cleared on an update. */
                $plan = import_plan($read['rows'], $read['columns'] ?? []);

                /* Only a clean plan is parked for the apply step, and a
                   refused one clears whatever was there.

                   The errors were being shown on screen while the products
                   went into the session regardless, so the apply form of an
                   earlier, clean preview would write a plan the validator had
                   since rejected - which made every check in import_plan() a
                   suggestion rather than a rule. */
                if ($plan['errors']) {
                    unset($_SESSION['import_plan']);
                } else {
                    $_SESSION['import_plan'] = $plan['products'];
                }
            }
        }
    }

    if ($action === 'apply') {
        $products = $_SESSION['import_plan'] ?? null;
        if (!is_array($products) || !$products) {
            $errors[] = 'چیزی برای اعمال نبود. فایل را دوباره بارگذاری کنید.';
        } else {
            try {
                $result = import_apply($products, $user);
                unset($_SESSION['import_plan']);
                flash('ok', sprintf(
                    '%d محصول جدید، %d محصول به‌روزرسانی، %d اندازه، و %d درصد همکاری ثبت شد.',
                    $result['created'], $result['updated'], $result['sizes'],
                    $result['rates'] ?? 0
                ));
                header('Location: products.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'اعمال ناموفق بود و هیچ چیزی ثبت نشد.';
            }
        }
    }
}

$rate = pricing_rate();

admin_head('ورود گروهی محصولات', ['user' => $user]);
?>

<?php foreach ($errors as $msg): ?>
  <div class="flash flash--error"><?= e($msg) ?></div>
<?php endforeach; ?>

<?php if ($rate === null): ?>
  <div class="flash flash--info">
    نرخ درهم هنوز ثبت نشده است. می‌توانید همین حالا محصولات را وارد کنید —
    قیمت فروششان بعد از وارد کردن نرخ در
    <a href="pricing.php" style="text-decoration:underline">صفحه قیمت‌گذاری</a>
    محاسبه می‌شود.
  </div>
<?php endif; ?>

<div class="card">
  <h2 class="card__title">۱ — فایل نمونه را بگیرید</h2>
  <p style="color:var(--text-muted);line-height:2.1">
    ستون‌ها را عوض نکنید و عنوان‌ها را دست‌نخورده بگذارید.
    <strong>برای هر اندازه یک سطر جدا بنویسید</strong> — قوطی ۹۰۰ گرمی و ۲۲۷۰ گرمی
    دو خرید جدا با دو قیمت جدا هستند، و سیستم آن‌ها را از روی نام یکسان
    کنار هم می‌گذارد.
  </p>
  <p style="color:var(--text-muted);line-height:2.1;margin-block-start:10px">
    اگر محصولی فقط یک اندازه دارد، ستون اندازه را خالی بگذارید.
  </p>

  <div class="table-wrap" style="border:0;margin-block-start:18px">
    <table style="min-width:0">
      <thead><tr><th>ستون</th><th>چه چیزی بنویسیم</th></tr></thead>
      <tbody>
        <tr><td class="t-title">قیمت خرید (درهم)</td>
            <td class="t-sub">عدد درهمی که برای خرید همان قوطی داده‌اید — مثلاً <span class="num">40</span>.
              قیمت این قلم با نرخ روز درهم خودش به‌روز می‌شود.</td></tr>
        <tr><td class="t-title">قیمت خرید (تومان)</td>
            <td class="t-sub">برای قلمی که داخل ایران خریده می‌شود.
              <strong>فقط یکی از این دو ستون را پر کنید، نه هر دو را.</strong>
              قیمت این قلم ثابت می‌ماند و با نرخ درهم تکان نمی‌خورد.</td></tr>
        <tr><td class="t-title">سود (تومان)</td>
            <td class="t-sub">سودی که روی همان قوطی می‌خواهید. قیمت فروش
              از قیمت خرید + همین عدد ساخته می‌شود.</td></tr>
        <tr><td class="t-title">قیمت فروش (تومان)</td>
            <td class="t-sub">فقط وقتی قیمت را بازار تعیین کرده، نه حاشیه سود شما.
              در این حالت ستون سود را خالی بگذارید ولی
              <strong>قیمت خرید را حتماً بنویسید</strong> — بدون آن،
              سود این قلم در گزارش‌ها صفر دیده می‌شود.</td></tr>
        <tr><td class="t-title">درصد همکاری</td>
            <td class="t-sub"><strong>خالی = درصد پیش‌فرض فروشگاه.</strong>
              فقط برای محصولی عدد بنویسید که جداگانه توافق شده —
              مثلاً قلمی که رقیب زیاد دارد و حاشیه سودش کم است.
              صفر یعنی «روی این قلم سهمی برداشته نشود» و با خالی یکی نیست.</td></tr>
        <tr><td class="t-title">تخفیف (تومان)</td>
            <td class="t-sub">مقداری که از قیمت کم می‌شود، نه قیمت دوم. بیشتر از نیمی
              از سود قبول نمی‌شود.</td></tr>
        <tr><td class="t-title">طعم‌ها</td>
            <td class="t-sub">با ویرگول جدا کنید: شکلاتی، وانیلی، توت فرنگی</td></tr>
        <tr><td class="t-title">موجود</td>
            <td class="t-sub">«بله» یا «خیر». خالی یعنی موجود.</td></tr>
      </tbody>
    </table>
  </div>

  <p style="color:var(--text-muted);line-height:2.1;margin-block-start:14px">
    عکس محصولات از این فایل خوانده نمی‌شود — بعد از ورود،
    از صفحه هر محصول آپلود می‌شود.
  </p>
  <div style="margin-block-start:16px">
    <a class="btn btn--ghost" href="?template=1">دانلود فایل نمونه (CSV)</a>
  </div>
</div>

<div class="card">
  <h2 class="card__title">۲ — فایل پرشده را بارگذاری کنید</h2>
  <form method="post" action="import.php" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview">
    <div class="field">
      <label for="sheet">فایل CSV</label>
      <input type="file" id="sheet" name="sheet" accept=".csv,text/csv" required>
      <span class="hint">
        در اکسل: File ← Save As ← <strong>CSV UTF-8</strong>.
        اگر با «;» جدا شده باشد هم خوانده می‌شود.
      </span>
    </div>
    <div class="form-foot">
      <button class="btn btn--primary" type="submit">بررسی فایل</button>
    </div>
  </form>
</div>

<?php if ($plan !== null): ?>

  <?php if ($plan['errors']): ?>
    <div class="card">
      <h2 class="card__title" style="color:#f2a29a">
        <span class="num"><?= count($plan['errors']) ?></span> ایراد — تا رفع نشوند چیزی ثبت نمی‌شود
      </h2>
      <ul style="padding-inline-start:20px;line-height:2.2;color:#f2a29a">
        <?php foreach (array_slice($plan['errors'], 0, 30) as $e): ?>
          <li><?= e($e) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($plan['errors']) > 30): ?>
        <p class="hint"><span class="num"><?= count($plan['errors']) - 30 ?></span> مورد دیگر نمایش داده نشد.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($plan['warnings']): ?>
    <div class="flash flash--info">
      <?= e($plan['warnings'][0]) ?>
      <?php if (count($plan['warnings']) > 1): ?>
        (و <span class="num"><?= count($plan['warnings']) - 1 ?></span> مورد مشابه)
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2 class="card__title">
      ۳ — پیش‌نمایش: <span class="num"><?= count($plan['products']) ?></span> محصول
    </h2>

    <?php if (!$plan['products']): ?>
      <div class="empty">هیچ محصول معتبری در فایل نبود.</div>
    <?php else: ?>
      <?php
        /* Only show the columns this sheet actually used. The number the
           operator is checking is the final price, and with eight columns it
           is the one that scrolls off the edge — which defeats the point of
           having a preview at all. Most sheets set neither a discount nor a
           per-product share, so most previews are two columns narrower. */
        $anyPromo = false;
        $anyRate  = false;
        $anyAed   = false;
        $anyToman = false;
        foreach ($plan['products'] as $pp) {
            foreach (($pp['sizes'] ?: [$pp['single'] ?? null]) as $uu) {
                if (!is_array($uu)) { continue; }
                if (!empty($uu['promo_toman'])) { $anyPromo = true; }
                if (($uu['commission'] ?? null) !== null) { $anyRate = true; }
                if (($uu['cost_aed'] ?? null) !== null) { $anyAed = true; }
                if (!empty($uu['cost_toman'])) { $anyToman = true; }
            }
        }
      ?>
      <div class="table-wrap" style="border:0">
        <table>
          <thead>
            <tr>
              <th>محصول</th><th>دسته</th><th>اندازه</th>
              <?php if ($anyAed): ?><th>خرید (درهم)</th><?php endif; ?>
              <?php if ($anyToman): ?><th>هزینه به تومان</th><?php endif; ?>
              <th>سود</th>
              <?php if ($anyPromo): ?><th>تخفیف</th><?php endif; ?>
              <?php if ($anyRate): ?><th>سهم ما</th><?php endif; ?>
              <th class="u-right">قیمت فروش</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plan['products'] as $p): ?>
              <?php
                $units = $p['sizes'] ?: [$p['single'] ?? null];
                $units = array_values(array_filter($units));
                $exists = db_value('SELECT 1 FROM products WHERE name_fa = ?', [$p['name_fa']]) !== null;
              ?>
              <?php foreach ($units as $i => $u): ?>
                <tr>
                  <?php if ($i === 0): ?>
                    <td rowspan="<?= count($units) ?>">
                      <div class="t-title"><?= e($p['name_fa']) ?></div>
                      <div class="t-sub lat"><?= e($p['name_en']) ?></div>
                      <?php if ($exists): ?>
                        <span class="pill pill--shipped">به‌روزرسانی</span>
                      <?php else: ?>
                        <span class="pill pill--new">جدید</span>
                      <?php endif; ?>
                    </td>
                    <td rowspan="<?= count($units) ?>" class="u-dim"><?= e($p['category'] ?: '—') ?></td>
                  <?php endif; ?>
                  <td><?= e($u['label'] !== '' ? $u['label'] : '—') ?></td>
                  <?php if ($anyAed): ?>
                    <td class="num u-nowrap">
                      <?= $u['cost_aed'] === null ? '—'
                          : e(rtrim(rtrim(number_format((float) $u['cost_aed'], 2), '0'), '.')) ?>
                    </td>
                  <?php endif; ?>
                  <?php if ($anyToman): ?>
                    <td class="num u-nowrap">
                      <?= empty($u['cost_toman']) ? '—' : money((int) $u['cost_toman']) ?>
                    </td>
                  <?php endif; ?>
                  <td class="num u-nowrap u-dim">
                    <?= $u['profit_toman'] === null ? '—' : money((int) $u['profit_toman']) ?>
                  </td>
                  <?php if ($anyPromo): ?>
                    <td class="num u-nowrap u-dim">
                      <?= empty($u['promo_toman']) ? '—' : money((int) $u['promo_toman']) ?>
                    </td>
                  <?php endif; ?>
                  <?php if ($anyRate): ?>
                    <td class="u-nowrap">
                      <?php if (($u['commission'] ?? null) === null): ?>
                        <span class="u-dim">پیش‌فرض</span>
                      <?php else: ?>
                        <span class="num"><?= e(rtrim(rtrim(number_format((float) $u['commission'], 2), '0'), '.')) ?></span>٪
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td class="num u-nowrap u-right">
                    <strong><?= (int) $u['price'] > 0 ? money((int) $u['price']) : '—' ?></strong>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!$plan['errors']): ?>
        <form method="post" action="import.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="apply">
          <div class="form-foot">
            <button class="btn btn--primary" type="submit"
                    data-confirm="این محصولات ثبت شوند؟ محصولات هم‌نام به‌روزرسانی می‌شوند.">
              ثبت در فروشگاه
            </button>
            <span class="spacer"></span>
            <span class="u-dim">
              محصول هم‌نام به‌روزرسانی می‌شود، نه تکرار. اندازه‌های هر محصول
              کامل با فایل جایگزین می‌شوند.
            </span>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php admin_foot(); ?>
