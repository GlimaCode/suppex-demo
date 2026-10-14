<?php
/* SUPPEX admin — brands.

   The brands are not made here. They come from the brand typed on each
   product (lib/brands.php), every way of writing one together, so a product
   brings its brand with it and there is nothing to keep in step. What is made
   here is how the storefront shows each one: the logo on the wall at the foot
   of the home page, a Persian name, a line for the brand's own page, the
   order, and whether it is shown at all.

   A logo is uploaded once, as the brand publishes it. Two versions are made
   from it (brand_logo_variants()): the site's cream, drawn by default, and
   the logo's own colours, both with the background taken away. The form shows
   both, the way the storefront will, and the owner picks. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/images.php';
require_once SUPPEX_ROOT . '/lib/brands.php';
require_once __DIR__ . '/partials/layout.php';

$user   = auth_require();
$ready  = brands_ready();
$errors = [];

$bySlug = [];
foreach (brands_all(true) as $b) {
    $bySlug[$b['slug']] = $b;
}

/** Every file one logo is made of. */
$logoFiles = static function (array $b): array {
    return array_values(array_filter([$b['logoFile'], $b['logoMono'], $b['logoColor']],
        static fn(string $f): bool => $f !== ''));
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $slug   = (string) ($_POST['slug'] ?? '');
    $brand  = $bySlug[$slug] ?? null;

    if (!$ready) {
        flash('error', 'اول یک بار «به‌روزرسانی دیتابیس» را اجرا کنید.');
        header('Location: brands.php');
        exit;
    }

    /* Many logos at once, each filed under the brand its file name says
       (brand_attach_logo()) - including a brand with no product yet, whose
       logo then waits for the catalogue to bring its products. */
    if ($action === 'bulk') {
        @set_time_limit(180);
        $in = $_FILES['logos'] ?? [];
        $files = [];
        foreach ((array) ($in['name'] ?? []) as $k => $name) {
            if ((int) ($in['error'][$k] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = ['name' => (string) $name, 'type' => (string) ($in['type'][$k] ?? ''),
                        'tmp_name' => (string) ($in['tmp_name'][$k] ?? ''),
                        'error' => (int) ($in['error'][$k] ?? 0), 'size' => (int) ($in['size'][$k] ?? 0)];
        }
        if (!$files) {
            flash('error', 'فایلی انتخاب نشده بود.');
            header('Location: brands.php');
            exit;
        }
        $saved = [];
        $failed = [];
        foreach ($files as $f) {
            $stored = image_store($f, 'brands', BRAND_LOGO_MAX_EDGE);
            if (empty($stored['ok'])) {
                $failed[] = '«' . $f['name'] . '»: ' . ($stored['error'] ?? 'ذخیره نشد.');
                continue;
            }
            $r = brand_attach_logo($f['name'], (string) $stored['path']);
            if (empty($r['ok'])) {
                image_delete((string) $stored['path']);
                $failed[] = '«' . $f['name'] . '»: ' . ($r['error'] ?? 'ذخیره نشد.');
                continue;
            }
            foreach ($r['replaced'] as $old) {
                image_delete($old);
            }
            $saved[] = $r['name'] . ($r['hasProducts'] ? '' : ' (هنوز محصولی ندارد)');
        }
        if ($saved) {
            flash('ok', fa_digits((string) count($saved)) . ' لوگو ذخیره شد: ' . implode('، ', $saved) . '.');
        }
        if ($failed) {
            flash('error', 'این فایل‌ها ذخیره نشدند — ' . implode(' | ', $failed));
        }
        /* PHP takes at most max_file_uploads files per request and drops the
           rest without a word; reaching the limit exactly means some may
           have been dropped. */
        $limit = (int) ini_get('max_file_uploads');
        if ($limit > 0 && count($files) >= $limit) {
            flash('error', 'سرور در هر بار حداکثر ' . fa_digits((string) $limit) . ' فایل می‌گیرد. اگر بیشتر انتخاب کرده بودید، '
                . 'بقیه را در یک بار دیگر بفرستید؛ لوگوهایی که رسیده‌اند دوباره فرستادن نمی‌خواهند.');
        }
        header('Location: brands.php');
        exit;
    }

    /* Only a slug the products (or an earlier save) produced. Anything else
       would be the panel inventing a brand no product has. */
    if ($brand === null) {
        flash('error', 'این برند پیدا نشد.');
        header('Location: brands.php');
        exit;
    }

    if ($action === 'forget') {
        /* Only a row that no product names any more. For a brand that still
           has products this would only throw its logo away - the brand itself
           would be back on the next page load. */
        if ($brand['total'] > 0) {
            flash('error', 'این برند هنوز محصول دارد؛ برای پنهان کردنش، تیک «نمایش در سایت» را بردارید.');
        } else {
            foreach ($logoFiles($brand) as $f) {
                image_delete($f);
            }
            db_query('DELETE FROM brands WHERE slug = ?', [$slug]);
            flash('ok', 'برند از فهرست برداشته شد.');
        }
        header('Location: brands.php');
        exit;
    }

    if ($action === 'save') {
        $logo  = $brand['logoFile'];
        $mono  = $brand['logoMono'];
        $color = $brand['logoColor'];

        if (!empty($_POST['logo_remove'])) {
            $logo = $mono = $color = '';
        } elseif (!empty($_FILES['logo']['name'])) {
            $stored = image_store($_FILES['logo'], 'brands', BRAND_LOGO_MAX_EDGE);
            if (empty($stored['ok'])) {
                $errors['logo'] = $stored['error'] ?? 'لوگو ذخیره نشد.';
            } else {
                $made = brand_logo_variants($stored['path']);
                if (empty($made['ok'])) {
                    image_delete($stored['path']);
                    $errors['logo'] = $made['error'] ?? 'لوگو ذخیره نشد.';
                } else {
                    [$logo, $mono, $color] = [$stored['path'], $made['mono'], $made['color']];
                }
            }
        } elseif ($logo !== '' && ($mono === '' || $color === '')) {
            /* A logo saved before the two versions existed: made now. */
            $made = brand_logo_variants($logo);
            if (!empty($made['ok'])) {
                [$mono, $color] = [$made['mono'], $made['color']];
            }
        }

        if (!$errors) {
            /* The old files go only once the new state is certain - a rejected
               upload must not have cost the logo that was there. */
            foreach ($logoFiles($brand) as $f) {
                if (!in_array($f, [$logo, $mono, $color], true)) {
                    image_delete($f);
                }
            }
            brand_save($slug, [
                'name'       => $_POST['name'] ?? '',
                'name_fa'    => $_POST['name_fa'] ?? '',
                'logo'       => $logo,
                'logo_mono'  => $mono,
                'logo_color' => $color,
                'logo_style' => ($_POST['logo_style'] ?? 'mono') === 'color' ? 'color' : 'mono',
                'blurb'      => $_POST['blurb'] ?? '',
                'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                'is_visible' => !empty($_POST['is_visible']),
            ]);
            flash('ok', 'برند «' . $brand['name'] . '» ذخیره شد.');
            header('Location: brands.php');
            exit;
        }
    }
}

$editSlug = (string) ($_GET['edit'] ?? ($_POST['slug'] ?? ''));
$editing  = $bySlug[$editSlug] ?? null;

/* The storefront's well, drawn here with inline styles because the panel does
   not load the storefront's stylesheet: dark, with the same orange glow
   behind the logo that the product photographs stand in. */
$well = static function (string $src, string $name, int $width): string {
    $box = 'display:flex;align-items:center;justify-content:center;aspect-ratio:16/10;width:' . $width . 'px;'
         . 'padding:' . (int) round($width * 0.12) . 'px;box-sizing:border-box;border-radius:12px;'
         . 'background:radial-gradient(65% 60% at 50% 50%,rgba(242,124,61,.16),transparent 70%),#242019;'
         . 'border:1px solid rgba(242,237,227,.11)';
    $inner = $src !== ''
        ? '<img src="' . e(admin_img($src)) . '" alt="' . e($name) . '" style="max-width:100%;max-height:100%;object-fit:contain">'
        : '<span class="lat" style="color:#F2EDE3;font-weight:800;font-size:' . max(10, (int) round($width / 11))
          . 'px;text-align:center;line-height:1.2">' . e($name) . '</span>';
    return '<span style="' . $box . '">' . $inner . '</span>';
};

admin_head('برندها', ['user' => $user]);
?>

<?php if (!$ready): ?>
  <div class="flash flash--info" style="margin-block-end:16px">
    برندها از روی محصولات ساخته می‌شوند و همین حالا در سایت هستند. برای گذاشتن لوگو و نام فارسی،
    یک بار «به‌روزرسانی دیتابیس» را اجرا کنید.
  </div>
<?php endif; ?>

<div class="split">
  <div>
    <?php if (!$bySlug): ?>
      <div class="card"><div class="empty">هنوز محصولی با نام برند ثبت نشده است. برند هر محصول را در صفحهٔ ویرایش محصول بنویسید.</div></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>در سایت</th><th>برند</th><th>محصول</th><th>نمایش</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($bySlug as $b): ?>
              <tr>
                <td><?= $well($b['logo'], $b['name'], 96) ?></td>
                <td>
                  <div class="t-title lat"><?= e($b['name']) ?></div>
                  <?php if ($b['nameFa'] !== ''): ?><div class="t-sub"><?= e($b['nameFa']) ?></div><?php endif; ?>
                  <?php if ($b['logoFile'] === ''): ?><div class="t-sub u-dim">بدون لوگو</div><?php endif; ?>
                  <?php if (count($b['spellings']) > 1): ?>
                    <!-- One brand written more than one way. It is one brand on
                         the site either way; this is so the owner can see it. -->
                    <div class="t-sub">
                      روی محصولات به
                      <?php $sp = []; foreach ($b['spellings'] as $spelling => $n) { $sp[] = '«<bdi>' . e((string) $spelling) . '</bdi>» (' . e(fa_digits((string) $n)) . ')'; } ?>
                      <?= implode('، ', $sp) ?>
                      نوشته شده است.
                    </div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= e(fa_digits((string) $b['count'])) ?><?php if ($b['total'] !== $b['count']): ?>
                  <span class="u-dim"> از <?= e(fa_digits((string) $b['total'])) ?></span><?php endif; ?></td>
                <td>
                  <?php if (!$b['visible']): ?>
                    <span class="pill pill--off">پنهان</span>
                  <?php elseif ($b['total'] === 0): ?>
                    <span class="pill pill--off">هنوز محصولی ندارد</span>
                  <?php elseif ($b['count'] === 0): ?>
                    <span class="pill pill--off">بدون محصول فعال</span>
                  <?php else: ?>
                    <span class="pill pill--new">نمایش</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="t-actions">
                    <?php if ($ready): ?>
                      <a class="btn btn--ghost btn--sm" href="?edit=<?= e(rawurlencode($b['slug'])) ?>">ویرایش</a>
                    <?php endif; ?>
                    <?php if ($b['visible'] && $b['count'] > 0): ?>
                      <a class="btn btn--ghost btn--sm" href="../<?= e(brand_url($b['slug'])) ?>" target="_blank" rel="noopener">دیدن در سایت</a>
                    <?php endif; ?>
                    <?php if ($ready && $b['saved'] && $b['total'] === 0): ?>
                      <form method="post" action="brands.php" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="forget">
                        <input type="hidden" name="slug" value="<?= e($b['slug']) ?>">
                        <button class="btn btn--danger btn--sm" type="submit"
                          data-confirm="برند «<?= e($b['name']) ?>» که محصولی ندارد از فهرست برداشته شود؟ لوگوی آن هم پاک می‌شود.">
                          حذف
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($editing === null && $ready): ?>
      <div class="card" style="margin-block-end:16px">
        <h2 class="card__title">آپلود گروهی لوگوها</h2>
        <p class="hint" style="line-height:2">
          هر فایل را به نام برندش بنامید، مثل <span class="lat" dir="ltr">Optimum Nutrition.png</span>،
          یا با نام فارسی در پرانتز: <span dir="ltr">Optimum Nutrition (اپتیموم نوتریشن).png</span>.
          همهٔ فایل‌ها را با هم انتخاب کنید. برندی که هنوز محصولی ندارد هم ساخته می‌شود و لوگویش
          آماده می‌ماند تا محصولاتش اضافه شوند.
        </p>
        <!-- Without the enctype a file input posts nothing at all, silently. -->
        <form method="post" action="brands.php" enctype="multipart/form-data" style="margin-block-start:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="bulk">
          <input type="file" name="logos[]" multiple required accept="image/png,image/jpeg,image/webp">
          <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:12px">آپلود لوگوها</button>
        </form>
      </div>
    <?php endif; ?>
    <?php if ($editing === null): ?>
      <div class="card">
        <h2 class="card__title">لوگوی برندها</h2>
        <p class="hint" style="line-height:2">
          برندها از روی نام برندِ هر محصول ساخته می‌شوند؛ برای برند تازه کافی است محصولش را ثبت کنید.
          اینجا برای هر برند لوگو، نام فارسی و ترتیب نمایش را مشخص کنید — روی «ویرایش» کنار آن برند بزنید.
        </p>
        <p class="hint" style="line-height:2;margin-block-start:10px">
          لوگوی رسمی برند را همان‌طور که منتشر کرده آپلود کنید. سایت پس‌زمینه را برمی‌دارد و دو نسخه می‌سازد:
          کرم، هماهنگ با رنگ سایت، و رنگ اصلی لوگو. هر کدام را که بهتر نشست انتخاب کنید.
          PNG با پس‌زمینهٔ شفاف بهترین نتیجه را می‌دهد؛ لوگو روی پس‌زمینهٔ سفید هم درست می‌شود.
        </p>
      </div>
    <?php else: ?>
      <div class="card">
        <h2 class="card__title">ویرایش برند <span class="lat"><?= e($editing['name']) ?></span></h2>
        <!-- Without the enctype a file input posts nothing at all, silently. -->
        <form method="post" action="brands.php" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="slug" value="<?= e($editing['slug']) ?>">

          <div class="field">
            <label for="logo">لوگوی رسمی برند</label>
            <?php if ($editing['logoFile'] !== ''): ?>
              <!-- Both versions, the way the storefront draws them. -->
              <div style="display:flex;gap:14px;flex-wrap:wrap;margin-block-end:10px">
                <?php foreach (['mono' => ['کرم، هماهنگ با سایت', $editing['logoMono']],
                                'color' => ['رنگ اصلی لوگو', $editing['logoColor']]] as $style => [$label, $src]): ?>
                  <?php if ($src === '') { continue; } ?>
                  <label style="display:grid;gap:8px;justify-items:center;cursor:pointer">
                    <?= $well($src, $editing['name'], 170) ?>
                    <span class="check">
                      <input type="radio" name="logo_style" value="<?= e($style) ?>"<?= $editing['logoStyle'] === $style ? ' checked' : '' ?>>
                      <span><?= e($label) ?></span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
              <label class="check" style="margin-block-end:8px">
                <input type="checkbox" name="logo_remove" value="1">
                <span>لوگو حذف شود</span>
              </label>
            <?php endif; ?>
            <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
            <?php if (!empty($errors['logo'])): ?><span class="err"><?= e($errors['logo']) ?></span><?php endif; ?>
            <span class="hint">PNG با پس‌زمینهٔ شفاف بهتر از همه است؛ JPG و WebP روی پس‌زمینهٔ سفید هم پذیرفته می‌شود.</span>
          </div>

          <div class="field" style="margin-block-start:12px">
            <label for="name_fa">نام فارسی</label>
            <input type="text" id="name_fa" name="name_fa" maxlength="120"
                   value="<?= e($editing['nameFaSaved']) ?>"
                   placeholder="<?= e($editing['nameFa'] !== '' ? $editing['nameFa'] : 'مثلاً ماسل تک') ?>">
            <span class="hint">زیر لوگو و در عنوان صفحهٔ برند می‌آید.<?= $editing['nameFa'] !== '' && $editing['nameFaSaved'] === ''
              ? ' خالی بگذارید تا همان «' . e($editing['nameFa']) . '» که روی محصولات آمده نمایش داده شود.' : '' ?></span>
          </div>

          <div class="field" style="margin-block-start:12px">
            <label for="name">نام برند (لاتین)</label>
            <input type="text" id="name" name="name" dir="ltr" maxlength="120"
                   value="<?= e($editing['nameSaved']) ?>"
                   placeholder="<?= e($editing['name']) ?>">
            <span class="hint">خالی بگذارید تا همان نامی که روی محصولات نوشته شده نمایش داده شود.</span>
          </div>

          <div class="field" style="margin-block-start:12px">
            <label for="blurb">یک خط دربارهٔ برند</label>
            <textarea id="blurb" name="blurb" rows="2" maxlength="255"><?= e($editing['blurb']) ?></textarea>
            <span class="hint">اختیاری؛ بالای صفحهٔ برند نمایش داده می‌شود. فقط چیزی بنویسید که درباره‌اش مطمئن هستید.</span>
          </div>

          <div class="field" style="margin-block-start:12px">
            <label for="sort_order">ترتیب</label>
            <input type="number" id="sort_order" name="sort_order" value="<?= (int) $editing['sort'] ?>">
            <span class="hint">عدد کوچک‌تر اول می‌آید. برندهای هم‌عدد به ترتیب تعداد محصول می‌آیند.</span>
          </div>

          <label class="check" style="margin-block-start:14px">
            <input type="checkbox" name="is_visible" value="1"<?= $editing['visible'] ? ' checked' : '' ?>>
            <span>در سایت نمایش داده شود</span>
          </label>

          <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:18px">ذخیره</button>
          <a class="btn btn--ghost btn--block" href="brands.php" style="margin-block-start:8px">انصراف</a>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php admin_foot(); ?>
