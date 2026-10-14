<?php
/* ===========================================================================
   SUPPEX admin — products from Excel
   ---------------------------------------------------------------------------
   Three steps on one page: take the workbook (empty, or today's catalogue),
   drop the photographs it names, upload it filled in. Then a preview, and
   nothing is written until «ثبت در فروشگاه».

   Same shape as the pricing page and for the same reason: the preview between
   upload and apply is the whole safety mechanism. A mistyped column is obvious
   in a table of proposed products and invisible in "48 products imported".
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/import_sheet.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* --- Downloads -------------------------------------------------------------
   Served before any HTML is emitted, or the file would carry the page with it. */
$xlsxType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
if (($_GET['template'] ?? '') === 'xlsx') {
    header('Content-Type: ' . $xlsxType);
    header('Content-Disposition: attachment; filename="suppex-products-template.xlsx"');
    echo import_template_xlsx();
    exit;
}
if (($_GET['export'] ?? '') === 'xlsx') {
    /* One product - «اکسل» on its row in the list, «خروجی اکسل» on its edit
       page - or the whole catalogue. The same workbook either way, so one
       product's file goes back in through this page like any other. */
    $oneId = (int) ($_GET['product'] ?? 0);
    if ($oneId > 0) {
        $one = db_one('SELECT id, slug FROM products WHERE id = ?', [$oneId]);
        if ($one === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'این محصول پیدا نشد.';
            exit;
        }
        header('Content-Type: ' . $xlsxType);
        header('Content-Disposition: attachment; filename="' . import_export_filename($one) . '"');
        echo import_catalogue_xlsx((int) $one['id']);
        exit;
    }
    header('Content-Type: ' . $xlsxType);
    header('Content-Disposition: attachment; filename="suppex-products-' . date('Y-m-d') . '.xlsx"');
    echo import_catalogue_xlsx();
    exit;
}
/* The old CSV sample, for anyone with a sheet built on it. */
if (($_GET['template'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suppex-products.csv"');
    echo import_template_csv();
    exit;
}

/** A small copy of a photograph for this page, where one exists. */
function import_thumb(string $path): string
{
    return admin_img(in_array(160, image_variant_widths($path), true) ? image_variant_path($path, 160) : $path);
}

$plan    = null;
$errors  = [];

/* The parsed file is held in the session between preview and apply. Re-reading
   the upload is impossible — PHP deletes it when the request ends — and asking
   for it twice would mean the thing applied might not be the thing previewed. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    /* One photograph from the uploader below, answered as JSON. They come one
       request at a time, so a hundred of them never meet the host's limit on
       files per request, and one that fails does not take the rest with it. */
    if ($action === 'photo') {
        $file = $_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $res  = import_photo_store($file);
        if ($res['ok']) {
            $res['thumb'] = import_thumb((string) $res['path']);
            unset($res['path']);
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash($res['ok'] ? 'ok' : 'error', $res['ok'] ? 'عکس آماده شد.' : (string) $res['error']);
        header('Location: import.php');
        exit;
    }

    /* The same, several at once - the form without JavaScript. */
    if ($action === 'photos') {
        $done = 0;
        $bad  = [];
        $files = $_FILES['photos'] ?? null;
        foreach ((array) ($files['name'] ?? []) as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $res = import_photo_store([
                'name' => $name, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0,
            ]);
            if ($res['ok']) { $done++; } else { $bad[] = $name . ': ' . $res['error']; }
        }
        flash($bad ? 'error' : 'ok', $done . ' عکس آماده شد.' . ($bad ? ' ' . implode(' — ', array_slice($bad, 0, 5)) : ''));
        header('Location: import.php');
        exit;
    }

    if ($action === 'photos_clear') {
        $n = import_photos_clear();
        flash('ok', $n . ' عکس بی‌استفاده پاک شد.');
        header('Location: import.php');
        exit;
    }

    if ($action === 'preview') {
        $file = $_FILES['sheet'] ?? null;

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'فایلی انتخاب نشده است.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'فایل معتبر نیست.';
        } elseif ((int) $file['size'] > 4 * 1024 * 1024) {
            $errors[] = 'حجم فایل بیش از ۴ مگابایت است.';
        } else {
            $read = import_read_file($file['tmp_name']);
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
                    '%d محصول جدید، %d محصول به‌روزرسانی، %d اندازه، %d عکس و %d درصد همکاری ثبت شد.',
                    $result['created'], $result['updated'], $result['sizes'],
                    $result['photos'] ?? 0, $result['rates'] ?? 0
                ));
                header('Location: products.php');
                exit;
            } catch (Throwable $e) {
                $errors[] = 'اعمال ناموفق بود و هیچ چیزی ثبت نشد.';
            }
        }
    }
}

$rate    = pricing_rate();
$pending = import_photos_pending();
$photoOk = import_photos_ready();

admin_head('ورود محصولات از اکسل', ['user' => $user]);
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

<div class="steps">

  <section class="card step">
    <h2 class="step__title"><span class="step__num" aria-hidden="true">۱</span> فایل اکسل را بگیرید</h2>
    <p class="step__lead">
      محصولات تازه را در فایل خالی بنویسید. برای عوض کردن قیمت، موجودی یا مشخصات
      چند محصول با هم، فایل محصولات فعلی را بگیرید، ویرایش کنید و همین‌جا برگردانید.
    </p>
    <div class="step__actions">
      <a class="btn btn--primary" href="?template=xlsx"><?= admin_icon('download') ?> فایل خالی</a>
      <a class="btn btn--ghost" href="?export=xlsx"><?= admin_icon('download') ?> همهٔ محصولات فعلی</a>
    </div>
    <ul class="ticks">
      <li>هر اندازه یک سطر است؛ نام محصول در سطرهای آن یکسان می‌ماند.</li>
      <li>خانهٔ خالی یعنی «دست نزن»؛ چیزی پاک نمی‌شود.</li>
      <li>در اکسل، با انتخاب هر خانه راهنمای ستونش نمایش داده می‌شود. برگه‌های «نمونه» و «راهنما» هم در فایل هست.</li>
    </ul>
    <details class="disclosure">
      <summary>راهنمای همهٔ ستون‌ها</summary>
      <div class="table-wrap" style="border:0">
        <table class="table--compact">
          <thead><tr><th>ستون</th><th>چه بنویسیم</th></tr></thead>
          <tbody>
            <?php foreach (IMPORT_LAYOUT as $col): ?>
              <tr>
                <td class="t-title u-nowrap"><?= e(IMPORT_COLUMNS[$col['key']]) ?><?= !empty($col['req']) ? ' *' : '' ?></td>
                <td class="t-sub"><?= e($col['hint']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint" style="margin-block-start:10px">
        فایل CSV ساخته‌شده با نمونهٔ قدیمی هم قبول می‌شود
        (<a href="?template=1" style="text-decoration:underline">نمونهٔ CSV</a>).
      </p>
    </details>
  </section>

  <section class="card step">
    <h2 class="step__title"><span class="step__num" aria-hidden="true">۲</span> عکس‌ها <span class="step__tag">اختیاری</span></h2>
    <p class="step__lead">
      نام فایل عکس‌های هر محصول را در ستون «عکس‌ها» بنویسید (اولی عکس اصلی است) و
      خود عکس‌ها را اینجا بیندازید. همه را یک‌جا انتخاب کنید؛ یکی‌یکی بارگذاری می‌شوند.
    </p>

    <?php if (!$photoOk): ?>
      <div class="flash flash--info" style="margin:0">
        برای بارگذاری عکس‌ها، اول یک بار
        <a href="migrate.php" style="text-decoration:underline">به‌روزرسانی دیتابیس</a> را اجرا کنید.
      </div>
    <?php else: ?>
      <form class="drop" method="post" action="import.php" enctype="multipart/form-data" data-drop
            data-max="<?= (int) UPLOAD_MAX_BYTES ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="photos">
        <label class="drop__zone" for="photos">
          <?= admin_icon('image') ?>
          <span class="drop__main">عکس‌ها را اینجا بکشید یا <u>انتخاب کنید</u></span>
          <span class="drop__sub">JPG، PNG یا WebP · هر عکس تا ۶ مگابایت</span>
        </label>
        <input class="sr-only" type="file" id="photos" name="photos[]" multiple
               accept="image/jpeg,image/png,image/webp">
        <noscript><button class="btn btn--ghost btn--sm" type="submit" style="margin-block-start:10px">بارگذاری</button></noscript>
      </form>

      <div class="upload" data-upload hidden>
        <div class="upload__bar"><span></span></div>
        <p class="upload__status" aria-live="polite"></p>
        <ul class="upload__errors"></ul>
      </div>

      <div class="pool" data-pool<?= $pending ? '' : ' hidden' ?>>
        <div class="pool__head">
          <strong><span class="num" data-pool-count><?= count($pending) ?></span> عکس آمادهٔ وصل شدن</strong>
          <form method="post" action="import.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="photos_clear">
            <button class="btn btn--ghost btn--sm" type="submit"
                    data-confirm="عکس‌هایی که هنوز به محصولی وصل نشده‌اند پاک شوند؟">پاک کردن همه</button>
          </form>
        </div>
        <ul class="pool__list" data-pool-list>
          <?php foreach (array_slice($pending, 0, 60) as $ph): ?>
            <li class="pool__item" data-key="<?= e($ph['name_key']) ?>">
              <img src="<?= e(import_thumb((string) $ph['path'])) ?>" alt="" width="40" height="40" loading="lazy">
              <span class="lat"><?= e($ph['name_key']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($pending) > 60): ?>
          <p class="hint">و <span class="num"><?= count($pending) - 60 ?></span> عکس دیگر.</p>
        <?php endif; ?>
      </div>
      <input type="hidden" data-csrf value="<?= e(csrf_token()) ?>">
    <?php endif; ?>
  </section>

  <section class="card step">
    <h2 class="step__title"><span class="step__num" aria-hidden="true">۳</span> فایل پرشده را بارگذاری کنید</h2>
    <form method="post" action="import.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preview">
      <div class="field">
        <label for="sheet">فایل اکسل (‎.xlsx‎) یا CSV</label>
        <input type="file" id="sheet" name="sheet" required
               accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
        <span class="hint">تا «ثبت در فروشگاه» را نزنید چیزی عوض نمی‌شود؛ اول همه‌چیز را پیش‌نمایش می‌بینید.</span>
      </div>
      <div class="form-foot">
        <button class="btn btn--primary" type="submit">بررسی فایل</button>
      </div>
    </form>
  </section>
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
        <details style="margin-block-start:6px">
          <summary>و <span class="num"><?= count($plan['warnings']) - 1 ?></span> مورد دیگر</summary>
          <ul style="padding-inline-start:20px;margin-block-start:6px">
            <?php foreach (array_slice($plan['warnings'], 1, 40) as $w): ?>
              <li><?= e($w) ?></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2 class="card__title">
      پیش‌نمایش: <span class="num"><?= count($plan['products']) ?></span> محصول
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
        $anyStock = false;
        $anyPhoto = false;
        foreach ($plan['products'] as $pp) {
            if (!empty($pp['images']) || !empty($pp['flavor_images'])) { $anyPhoto = true; }
            foreach (($pp['sizes'] ?: [$pp['single'] ?? null]) as $uu) {
                if (!is_array($uu)) { continue; }
                if (!empty($uu['promo_toman'])) { $anyPromo = true; }
                if (($uu['commission'] ?? null) !== null) { $anyRate = true; }
                if (($uu['cost_aed'] ?? null) !== null) { $anyAed = true; }
                if (!empty($uu['cost_toman'])) { $anyToman = true; }
                if (!empty($uu['stock_given'])) { $anyStock = true; }
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
              <?php if ($anyStock): ?><th>موجودی</th><?php endif; ?>
              <th class="u-right">قیمت فروش</th>
              <?php if ($anyPhoto): ?><th>عکس‌ها</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plan['products'] as $p): ?>
              <?php
                $units = $p['sizes'] ?: [$p['single'] ?? null];
                $units = array_values(array_filter($units));
                $exists = import_find_product($p['name_fa'], (string) ($p['brand'] ?? '')) !== null;
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
                      <?php if (($p['is_active'] ?? null) === false): ?>
                        <span class="pill pill--off">پنهان</span>
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
                  <?php if ($anyStock): ?>
                    <td class="u-nowrap">
                      <?php if (empty($u['stock_given'])): ?>
                        <span class="u-dim">بدون تغییر</span>
                      <?php elseif ($u['stock_qty'] === null): ?>
                        <span class="u-dim">شمرده نشود</span>
                      <?php else: ?>
                        <span class="num"><?= (int) $u['stock_qty'] ?></span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td class="num u-nowrap u-right">
                    <strong><?= (int) $u['price'] > 0 ? money((int) $u['price']) : '—' ?></strong>
                  </td>
                  <?php if ($anyPhoto && $i === 0): ?>
                    <td rowspan="<?= count($units) ?>">
                      <div class="shots">
                        <?php foreach ($p['images'] ?? [] as $k => $key): ?>
                          <?php $st = $p['photo_status'][$key] ?? 'missing'; ?>
                          <?php if ($st === 'ready' && ($w = import_photo_find($key)) !== null): ?>
                            <img class="shots__img<?= $k === 0 ? ' is-main' : '' ?>" src="<?= e(import_thumb($w['path'])) ?>"
                                 alt="<?= e($key) ?>" title="<?= e($key) ?>" width="36" height="36" loading="lazy">
                          <?php elseif ($st === 'attached'): ?>
                            <span class="pill pill--done" title="<?= e($key) ?>">وصل است</span>
                          <?php else: ?>
                            <span class="pill pill--cancelled lat" title="پیدا نشد"><?= e($key) ?></span>
                          <?php endif; ?>
                        <?php endforeach; ?>
                        <?php /* Each flavour's own picture, titled with the flavour. */ ?>
                        <?php foreach ($p['flavor_images'] ?? [] as $flavor => $key): ?>
                          <?php $st = $p['flavor_photo_status'][$flavor] ?? 'missing'; ?>
                          <?php $w = $st === 'ready' ? import_photo_find($key) : null; ?>
                          <?php if ($w !== null): ?>
                            <img class="shots__img shots__img--flavor" src="<?= e(import_thumb($w['path'])) ?>"
                                 alt="<?= e($flavor) ?>" title="طعم <?= e($flavor) ?>" width="28" height="28" loading="lazy">
                          <?php elseif ($st !== 'missing'): ?>
                            <span class="pill pill--done" title="<?= e($key) ?>">طعم <?= e($flavor) ?></span>
                          <?php else: ?>
                            <span class="pill pill--cancelled" title="<?= e($key) ?>">طعم <?= e($flavor) ?>: پیدا نشد</span>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </div>
                    </td>
                  <?php endif; ?>
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

<script>
/* The photograph uploader: everything chosen or dropped goes up one file per
   request, with a running count, so a hundred photographs never meet the
   host's per-request limits and one bad file does not stop the rest. Without
   JavaScript the form above posts them all at once instead. */
(function () {
  var form = document.querySelector('[data-drop]');
  var input = document.getElementById('photos');
  var box = document.querySelector('[data-upload]');
  var tokenEl = document.querySelector('[data-csrf]');
  if (!form || !input || !box || !tokenEl || !window.fetch || !window.FormData) { return; }
  var zone = form.querySelector('.drop__zone');
  var bar = box.querySelector('.upload__bar span');
  var status = box.querySelector('.upload__status');
  var errs = box.querySelector('.upload__errors');
  var pool = document.querySelector('[data-pool]');
  var list = document.querySelector('[data-pool-list]');
  var count = document.querySelector('[data-pool-count]');
  var max = +form.getAttribute('data-max') || 6291456;
  var busy = false;
  var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); }); };
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

  function addToPool(r) {
    var old = list.querySelector('[data-key="' + r.key.replace(/"/g, '\\"') + '"]');
    if (old) { old.parentNode.removeChild(old); }
    var li = document.createElement('li');
    li.className = 'pool__item is-new';
    li.setAttribute('data-key', r.key);
    li.innerHTML = '<img src="' + esc(r.thumb) + '" alt="" width="40" height="40"><span class="lat">' + esc(r.key) + '</span>';
    list.insertBefore(li, list.firstChild);
    /* The list shows sixty at most; the count is of all of them. */
    if (!old) { count.textContent = (parseInt(count.textContent, 10) || 0) + 1; }
    pool.hidden = false;
  }
  function fail(name, why) {
    var li = document.createElement('li');
    li.textContent = name + ': ' + why;
    errs.appendChild(li);
  }

  function run(files) {
    files = Array.prototype.filter.call(files || [], function (f) {
      return /^image\/(jpeg|png|webp)$/.test(f.type) || /\.(jpe?g|png|webp)$/i.test(f.name);
    });
    if (!files.length || busy) { return; }
    busy = true;
    box.hidden = false;
    errs.innerHTML = '';
    form.classList.add('is-busy');
    bar.style.transform = 'scaleX(0)';
    var done = 0, ok = 0;
    var step = function () {
      done++;
      bar.style.transform = 'scaleX(' + (done / files.length) + ')';
      next();
    };
    var next = function () {
      if (done === files.length) {
        status.textContent = fa(ok) + ' عکس از ' + fa(files.length) + ' آماده شد.' +
          (ok < files.length ? ' بقیه را از فهرست زیر ببینید.' : '');
        form.classList.remove('is-busy');
        input.value = '';
        busy = false;
        return;
      }
      var f = files[done];
      status.textContent = 'بارگذاری ' + fa(done + 1) + ' از ' + fa(files.length) + ': ' + f.name;
      if (f.size > max) { fail(f.name, 'حجمش بیشتر از ۶ مگابایت است.'); step(); return; }
      var fd = new FormData();
      fd.append('_csrf', tokenEl.value);
      fd.append('action', 'photo');
      fd.append('ajax', '1');
      fd.append('photo', f, f.name);
      fetch('import.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) { ok++; addToPool(j); } else { fail(f.name, (j && j.error) || 'بارگذاری نشد.'); }
        })
        .catch(function () { fail(f.name, 'پاسخی نیامد؛ اگر صفحه مدتی باز مانده، آن را تازه کنید.'); })
        .then(step);
    };
    next();
  }

  input.addEventListener('change', function () { run(input.files); });
  ['dragenter', 'dragover'].forEach(function (t) {
    zone.addEventListener(t, function (e) { e.preventDefault(); form.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (t) {
    zone.addEventListener(t, function () { form.classList.remove('is-over'); });
  });
  zone.addEventListener('drop', function (e) { e.preventDefault(); run(e.dataTransfer && e.dataTransfer.files); });
})();
</script>

<?php admin_foot(); ?>
