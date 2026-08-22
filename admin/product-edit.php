<?php
/* ===========================================================================
   SUPPEX admin — create / edit a product
   ---------------------------------------------------------------------------
   The screen the client asked for by name: images, names, descriptions,
   prices, flavours, sizes and nutrition, all editable without touching code.

   Flavours, sizes and nutrition rows are posted as parallel arrays
   (flavor_id[], flavor_name[]) and rewritten wholesale on save. Diffing them
   row by row would mean giving every row a hidden database id and handling
   the three-way create/update/delete case for a table that never holds more
   than a handful of rows.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/images.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

$id = (int) ($_GET['id'] ?? 0);
if ($id === 0 && !empty($_GET['slug'])) {
    $id = (int) db_value('SELECT id FROM products WHERE slug = ?', [(string) $_GET['slug']]);
}

$isNew   = $id === 0;
$product = $isNew ? null : db_one('SELECT * FROM products WHERE id = ?', [$id]);

if (!$isNew && $product === null) {
    http_response_code(404);
    admin_head('محصول پیدا نشد', ['user' => $user]);
    echo '<div class="card"><div class="empty">این محصول وجود ندارد.</div></div>';
    admin_foot();
    exit;
}

$errors = [];

/* --------------------------------------------------------------------------
   Image actions — delete one, or promote one to the main image
   -------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['action'] ?? '', ['del_image', 'main_image'], true)) {
    csrf_check();
    $imgId = (int) ($_POST['image_id'] ?? 0);
    $row   = db_one('SELECT * FROM product_images WHERE id = ? AND product_id = ?', [$imgId, $id]);

    if ($row !== null) {
        if ($_POST['action'] === 'del_image') {
            db_query('DELETE FROM product_images WHERE id = ?', [$imgId]);
            image_delete($row['path']);

            /* If the deleted file was the main image, promote whatever is left
               rather than leaving the storefront with a broken thumbnail. */
            if ($product['image'] === $row['path']) {
                $next = (string) (db_value(
                    'SELECT path FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT 1',
                    [$id]
                ) ?? '');
                db_query('UPDATE products SET image = ? WHERE id = ?', [$next, $id]);
            }
            flash('ok', 'تصویر حذف شد.');
        } else {
            db_query('UPDATE products SET image = ? WHERE id = ?', [$row['path'], $id]);
            flash('ok', 'تصویر اصلی تغییر کرد.');
        }
    }

    header('Location: product-edit.php?id=' . $id);
    exit;
}

/* --------------------------------------------------------------------------
   Save
   -------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrf_check();

    $nameFa = clean_text($_POST['name_fa'] ?? '', 180);
    $nameEn = clean_text($_POST['name_en'] ?? '', 180);
    $price  = parse_money($_POST['price'] ?? '');

    if ($nameFa === '') { $errors['name_fa'] = 'نام فارسی محصول لازم است.'; }

    /* The typed price is required only in manual mode. In dirham mode the
       formula produces it, and insisting on a number that is about to be
       overwritten only invites someone to type a wrong one. */
    $wantsAed = (($_POST['price_mode'] ?? 'manual') === 'aed');
    if ($price <= 0 && !$wantsAed) { $errors['price'] = 'قیمت را وارد کنید.'; }

    $compareRaw = trim((string) ($_POST['compare_at'] ?? ''));
    $compareAt  = $compareRaw === '' ? null : parse_money($compareRaw);
    if ($compareAt !== null && $compareAt > 0 && $compareAt <= $price) {
        /* Otherwise the storefront would render a "discount" of 0% or a
           negative one — the badge is derived from these two numbers. */
        $errors['compare_at'] = 'قیمت قبل از تخفیف باید بیشتر از قیمت فعلی باشد.';
    }
    if ($compareAt === 0) { $compareAt = null; }

    $badges = [];
    foreach (['bestseller', 'new'] as $b) {
        if (!empty($_POST['badge_' . $b])) { $badges[] = $b; }
    }

    $categoryId = (int) ($_POST['category_id'] ?? 0) ?: null;

    /* --- Dirham pricing ---------------------------------------------------
       A product is dirham-linked only when it has a dirham cost to work from.
       Allowing the mode without one would leave the product invisible to the
       pricing page and its price frozen forever, with nothing on screen
       explaining why. */
    $priceMode   = (($_POST['price_mode'] ?? 'manual') === 'aed') ? 'aed' : 'manual';
    $costAedRaw  = trim(to_latin_digits((string) ($_POST['cost_aed'] ?? '')));
    $costAed     = $costAedRaw === '' ? null : round((float) str_replace(',', '', $costAedRaw), 2);
    $profitToman = parse_money($_POST['profit_toman'] ?? '');

    if ($priceMode === 'aed') {
        if ($costAed === null || $costAed <= 0) {
            $errors['cost_aed'] = 'برای قیمت‌گذاری درهمی، قیمت خرید به درهم لازم است.';
        }
        if ($profitToman <= 0) {
            $errors['profit_toman'] = 'مقدار سود روی این محصول را وارد کنید.';
        }
    }
    if ($costAed !== null && $costAed <= 0) { $costAed = null; }
    if ($profitToman <= 0) { $profitToman = null; }

    /* Blank means "use the shop-wide rate", which is not the same as 0 — a zero
       override says this product pays no commission at all, and that has to be
       something someone typed on purpose rather than something a blank field
       decays into. */
    $commRaw = trim(to_latin_digits((string) ($_POST['commission_percent'] ?? '')));
    $commissionOverride = null;
    if ($commRaw !== '') {
        $commissionOverride = round((float) str_replace(',', '', $commRaw), 2);
        if ($commissionOverride < 0 || $commissionOverride > 100) {
            $errors['commission_percent'] = 'درصد باید بین ۰ تا ۱۰۰ باشد.';
        }
    }

    /* The slug is the product's URL. It is generated once from the name and
       then left alone: changing it silently breaks every link anyone has
       already shared. Editing it stays possible, deliberately, but by hand. */
    $slugInput = clean_text($_POST['slug'] ?? '', 120);
    $slugBase  = $slugInput !== '' ? slugify($slugInput) : slugify($nameEn !== '' ? $nameEn : $nameFa);

    if (!$errors) {
        $fields = [
            'slug'              => unique_slug($slugBase, 'products', $isNew ? null : $id),
            'name_fa'           => $nameFa,
            'name_en'           => $nameEn,
            'brand'             => clean_text($_POST['brand'] ?? '', 120),
            'category_id'       => $categoryId,
            'price'             => $price,
            'compare_at'        => $compareAt,
            'in_stock'          => empty($_POST['in_stock']) ? 0 : 1,
            'badges'            => implode(',', $badges),
            'variant_label'     => clean_text($_POST['variant_label'] ?? '', 180),
            'short'             => clean_text($_POST['short'] ?? '', 500),
            'description'       => clean_multiline($_POST['description'] ?? '', 5000),
            'features'          => json_encode_pretty(lines_to_array($_POST['features'] ?? '')),
            'ingredients'       => clean_multiline($_POST['ingredients'] ?? '', 2000),
            'usage_text'        => clean_multiline($_POST['usage_text'] ?? '', 2000),
            'nutrition_serving' => clean_text($_POST['nutrition_serving'] ?? '', 180),
            'sort_order'        => (int) ($_POST['sort_order'] ?? 0),
            'is_active'         => empty($_POST['is_active']) ? 0 : 1,

            /* Dirham-linked pricing. cost_aed is a decimal, so it keeps its
               fractional part — dirham prices are routinely quoted as 42.50,
               and rounding that to 42 loses about 1% of the cost basis on
               every single unit. */
            'cost_aed'          => $costAed,
            'profit_toman'      => $profitToman,
            'price_mode'        => $priceMode,
            'commission_percent' => $commissionOverride,
        ];

        /* Nutrition rows arrive as two parallel arrays; empty labels are
           dropped so a blank template row does not become a blank table row. */
        $nRows   = [];
        $nLabels = (array) ($_POST['nutrition_label'] ?? []);
        $nValues = (array) ($_POST['nutrition_value'] ?? []);
        foreach ($nLabels as $i => $label) {
            $label = clean_text((string) $label, 80);
            $value = clean_text((string) ($nValues[$i] ?? ''), 40);
            if ($label !== '') {
                $nRows[] = ['label' => $label, 'value' => $value];
            }
        }
        $fields['nutrition_rows'] = json_encode_pretty($nRows);

        /* Read before writing, so the log records what it actually changed
           from. Captured here rather than after the UPDATE for the obvious
           reason. */
        $previousComm = $isNew
            ? null
            : db_value('SELECT commission_percent FROM products WHERE id = ?', [$id]);

        $columns = array_keys($fields);
        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($isNew) {
                $sql = 'INSERT INTO products (' . implode(', ', $columns) . ') VALUES ('
                     . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $id = db_insert($sql, array_values($fields));
            } else {
                $sql = 'UPDATE products SET '
                     . implode(', ', array_map(static fn($c) => $c . ' = ?', $columns))
                     . ' WHERE id = ?';
                db_query($sql, array_merge(array_values($fields), [$id]));
            }

            /* Variants: replace rather than diff. */
            db_query('DELETE FROM product_flavors WHERE product_id = ?', [$id]);
            $fIds   = (array) ($_POST['flavor_id'] ?? []);
            $fNames = (array) ($_POST['flavor_name'] ?? []);
            foreach ($fNames as $i => $name) {
                $name = clean_text((string) $name, 120);
                if ($name === '') { continue; }
                $ext = clean_text((string) ($fIds[$i] ?? ''), 60);
                db_query(
                    'INSERT INTO product_flavors (product_id, ext_id, name, sort_order)
                     VALUES (?, ?, ?, ?)',
                    [$id, $ext !== '' ? $ext : slugify($name), $name, $i]
                );
            }

            db_query('DELETE FROM product_sizes WHERE product_id = ?', [$id]);
            $sIds      = (array) ($_POST['size_id'] ?? []);
            $sLabels   = (array) ($_POST['size_label'] ?? []);
            $sServings = (array) ($_POST['size_servings'] ?? []);
            $sPrices   = (array) ($_POST['size_price'] ?? []);
            $sCompares = (array) ($_POST['size_compare'] ?? []);
            $sCostAed  = (array) ($_POST['size_cost_aed'] ?? []);
            $sProfit   = (array) ($_POST['size_profit'] ?? []);
            foreach ($sLabels as $i => $label) {
                $label = clean_text((string) $label, 120);
                if ($label === '') { continue; }
                $ext     = clean_text((string) ($sIds[$i] ?? ''), 60);
                $sPrice  = parse_money($sPrices[$i] ?? 0);
                $sCompRaw = trim((string) ($sCompares[$i] ?? ''));
                $sComp   = $sCompRaw === '' ? null : parse_money($sCompRaw);
                $serv    = (int) digits_only((string) ($sServings[$i] ?? ''));

                /* Each size carries its own dirham cost, because each is a
                   separate purchase in Dubai at a separate price. Left blank,
                   it falls back to the parent's figures at pricing time. */
                $sAedRaw  = trim(to_latin_digits((string) ($sCostAed[$i] ?? '')));
                $sAed     = $sAedRaw === '' ? null : round((float) str_replace(',', '', $sAedRaw), 2);
                $sProfitT = parse_money($sProfit[$i] ?? '');

                db_query(
                    'INSERT INTO product_sizes
                        (product_id, ext_id, label, servings, price, compare_at,
                         cost_aed, profit_toman, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $ext !== '' ? $ext : slugify($label), $label,
                     $serv > 0 ? $serv : null,
                     $sPrice > 0 ? $sPrice : $price,
                     ($sComp !== null && $sComp > 0) ? $sComp : null,
                     ($sAed !== null && $sAed > 0) ? $sAed : null,
                     $sProfitT > 0 ? $sProfitT : null,
                     $i]
                );
            }

            /* A commission change moves money between two parties, so it is
               not an ordinary edit and does not belong only in the row it
               changed. Logged append-only, with who and when. */
            $before = $previousComm === null ? null : (float) $previousComm;
            $after  = $commissionOverride;
            if ($before !== $after) {
                db_query(
                    'INSERT INTO commission_log
                        (scope, scope_id, label, old_percent, new_percent, admin_id, admin_name)
                     VALUES ("product", ?, ?, ?, ?, ?, ?)',
                    [$id, $nameFa, $before, $after, $user['id'], $user['name']]
                );
            }

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('[suppex] product save failed: ' . $ex->getMessage());
            $errors['_'] = 'ذخیره محصول با خطا مواجه شد.';
        }

        /* --- Uploads --------------------------------------------------------
           After the product exists, so a new product's first images have an id
           to attach to. Each file is reported on individually: a rejected
           15 MB photo must not silently disappear among four that worked. */
        if (!$errors && !empty($_FILES['images']['name'][0])) {
            $count = count($_FILES['images']['name']);
            $sort  = (int) db_value(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?',
                [$id]
            );

            for ($i = 0; $i < min($count, 8); $i++) {
                $file = [
                    'name'     => $_FILES['images']['name'][$i],
                    'type'     => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                if ($file['error'] === UPLOAD_ERR_NO_FILE) { continue; }

                $stored = image_store($file);
                if ($stored['ok']) {
                    db_query(
                        'INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, ?)',
                        [$id, $stored['path'], $sort++]
                    );
                    if ((string) db_value('SELECT image FROM products WHERE id = ?', [$id]) === '') {
                        db_query('UPDATE products SET image = ? WHERE id = ?', [$stored['path'], $id]);
                    }
                } else {
                    flash('error', $file['name'] . ' — ' . $stored['error']);
                }
            }
        }

        if (!$errors) {
            flash('ok', $isNew ? 'محصول ساخته شد.' : 'تغییرات ذخیره شد.');
            header('Location: product-edit.php?id=' . $id);
            exit;
        }
    }

    /* Validation failed: re-render with what was typed, so nothing is retyped. */
    $product = array_merge($product ?? [], $_POST);
}

/* --------------------------------------------------------------------------
   Load for display
   -------------------------------------------------------------------------- */
$categories = db_all('SELECT id, name_fa FROM categories ORDER BY sort_order, id');
$images     = $isNew ? [] : db_all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$id]);
$flavors    = $isNew ? [] : db_all('SELECT * FROM product_flavors WHERE product_id = ? ORDER BY sort_order, id', [$id]);
$sizes      = $isNew ? [] : db_all('SELECT * FROM product_sizes  WHERE product_id = ? ORDER BY sort_order, id', [$id]);
$nutrition  = json_decode_array($product['nutrition_rows'] ?? null);
$features   = $product ? json_decode_array($product['features'] ?? null) : [];

/** Field value, preferring what was just posted. */
function v(?array $product, string $key, $default = ''): string
{
    if ($product === null) { return (string) $default; }
    return (string) ($product[$key] ?? $default);
}

$badgeList = $product ? explode(',', (string) ($product['badges'] ?? '')) : [];

admin_head($isNew ? 'محصول جدید' : 'ویرایش: ' . v($product, 'name_fa'), ['user' => $user]);
?>

<?php if (!empty($errors['_'])): ?>
  <div class="flash flash--error"><?= e($errors['_']) ?></div>
<?php endif; ?>

<form method="post" action="product-edit.php<?= $isNew ? '' : '?id=' . $id ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <div class="card">
    <h2 class="card__title">مشخصات اصلی</h2>
    <div class="form-grid">
      <div class="field">
        <label for="name_fa">نام فارسی *</label>
        <input type="text" id="name_fa" name="name_fa" required value="<?= e(v($product, 'name_fa')) ?>">
        <?php if (!empty($errors['name_fa'])): ?><span class="err"><?= e($errors['name_fa']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="name_en">نام انگلیسی</label>
        <input type="text" id="name_en" name="name_en" dir="ltr" value="<?= e(v($product, 'name_en')) ?>">
        <span class="hint">در کارت محصول زیر نام فارسی نمایش داده می‌شود.</span>
      </div>

      <div class="field">
        <label for="brand">برند</label>
        <input type="text" id="brand" name="brand" dir="ltr" value="<?= e(v($product, 'brand')) ?>">
      </div>

      <div class="field">
        <label for="category_id">دسته</label>
        <select id="category_id" name="category_id">
          <option value="">بدون دسته</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>"
              <?= (string) v($product, 'category_id') === (string) $c['id'] ? ' selected' : '' ?>>
              <?= e($c['name_fa']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="price">قیمت (تومان) *</label>
        <input type="text" id="price" name="price" inputmode="numeric" required
               value="<?= e(v($product, 'price')) ?>">
        <?php if (!empty($errors['price'])): ?><span class="err"><?= e($errors['price']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="compare_at">قیمت قبل از تخفیف</label>
        <input type="text" id="compare_at" name="compare_at" inputmode="numeric"
               value="<?= e(v($product, 'compare_at')) ?>">
        <span class="hint">خالی بگذارید تا محصول بدون تخفیف نمایش داده شود.</span>
        <?php if (!empty($errors['compare_at'])): ?><span class="err"><?= e($errors['compare_at']) ?></span><?php endif; ?>
      </div>

      <div class="field field--wide" style="border-block-start:1px solid var(--line);padding-block-start:16px">
        <label>حالت قیمت‌گذاری</label>
        <label class="check">
          <input type="radio" name="price_mode" value="manual"
            <?= v($product, 'price_mode', 'manual') !== 'aed' ? ' checked' : '' ?>>
          <span>قیمت را دستی وارد می‌کنم</span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="radio" name="price_mode" value="aed"
            <?= v($product, 'price_mode', 'manual') === 'aed' ? ' checked' : '' ?>>
          <span>بر اساس نرخ درهم حساب شود</span>
        </label>
        <span class="hint">
          با انتخاب حالت درهمی، قیمت این محصول از فرمول
          <span class="num">(خرید به درهم × نرخ) + سود</span>
          ساخته می‌شود و در صفحه <a href="pricing.php" style="text-decoration:underline">قیمت‌گذاری</a>
          با هر تغییر نرخ به‌روز می‌شود. قیمت بالا دیگر دستی ویرایش نمی‌شود.
        </span>
      </div>

      <div class="field">
        <label for="cost_aed">قیمت خرید (درهم)</label>
        <input type="text" id="cost_aed" name="cost_aed" dir="ltr" inputmode="decimal"
               placeholder="42.50" value="<?= e(v($product, 'cost_aed')) ?>">
        <span class="hint">اعشار هم می‌پذیرد. همان عددی که در دبی پرداخت کرده‌اید.</span>
        <?php if (!empty($errors['cost_aed'])): ?><span class="err"><?= e($errors['cost_aed']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="commission_percent">درصد سهم همکاری (اختیاری)</label>
        <input type="text" id="commission_percent" name="commission_percent" dir="ltr"
               inputmode="decimal" placeholder="پیش‌فرض فروشگاه"
               value="<?= e(v($product, 'commission_percent')) ?>">
        <span class="hint">
          <strong>خالی بگذارید</strong> مگر اینکه این محصول عمداً استثنا باشد.
          چون سهم روی <em>سود</em> حساب می‌شود، محصول کم‌حاشیه خودش سهم کمتری می‌دهد
          و لازم نیست درصدش را دستی پایین بیاورید.
          <br>
          <span class="num">0</span> یعنی این محصول اصلاً سهمی ندارد —
          با خالی بودن فرق دارد. هر تغییر این عدد در تاریخچه ثبت می‌شود.
        </span>
        <?php if (!empty($errors['commission_percent'])): ?>
          <span class="err"><?= e($errors['commission_percent']) ?></span>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="profit_toman">سود شما روی این محصول (تومان)</label>
        <input type="text" id="profit_toman" name="profit_toman" inputmode="numeric"
               placeholder="1750000" value="<?= e(v($product, 'profit_toman')) ?>">
        <span class="hint">
          مبلغ ثابتی که می‌خواهید روی هر عدد سود کنید. هزینه حمل و ترخیص را
          هم در همین عدد یا در قیمت خرید حساب کنید.
        </span>
        <?php if (!empty($errors['profit_toman'])): ?><span class="err"><?= e($errors['profit_toman']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="variant_label">برچسب کوتاه</label>
        <input type="text" id="variant_label" name="variant_label"
               placeholder="مثلاً: وانیل · 900 گرم" value="<?= e(v($product, 'variant_label')) ?>">
        <span class="hint">زیر نام محصول در کارت دیده می‌شود.</span>
      </div>

      <div class="field">
        <label for="slug">آدرس صفحه (slug)</label>
        <input type="text" id="slug" name="slug" dir="ltr" value="<?= e(v($product, 'slug')) ?>">
        <span class="hint">
          خالی بگذارید تا خودکار از نام ساخته شود.
          تغییر آن، لینک‌هایی که قبلاً از این محصول به اشتراک گذاشته شده را می‌شکند.
        </span>
      </div>

      <div class="field field--wide">
        <label for="short">توضیح کوتاه</label>
        <textarea id="short" name="short" rows="2"><?= e(v($product, 'short')) ?></textarea>
        <span class="hint">یک جمله؛ در نتایج جستجو و بالای صفحه محصول دیده می‌شود.</span>
      </div>

      <div class="field">
        <label class="check">
          <input type="checkbox" name="in_stock" value="1"
            <?= ($isNew || (int) v($product, 'in_stock', '1') === 1) ? ' checked' : '' ?>>
          <span>موجود است</span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="checkbox" name="is_active" value="1"
            <?= ($isNew || (int) v($product, 'is_active', '1') === 1) ? ' checked' : '' ?>>
          <span>در سایت نمایش داده شود</span>
        </label>
      </div>

      <div class="field">
        <label>نشان‌ها</label>
        <label class="check">
          <input type="checkbox" name="badge_bestseller" value="1"
            <?= in_array('bestseller', $badgeList, true) ? ' checked' : '' ?>>
          <span>پرفروش</span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="checkbox" name="badge_new" value="1"
            <?= in_array('new', $badgeList, true) ? ' checked' : '' ?>>
          <span>جدید</span>
        </label>
      </div>

      <div class="field">
        <label for="sort_order">ترتیب نمایش</label>
        <input type="number" id="sort_order" name="sort_order" value="<?= e(v($product, 'sort_order', '0')) ?>">
        <span class="hint">عدد کوچک‌تر، بالاتر.</span>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">تصاویر</h2>

    <?php if ($isNew): ?>
      <p class="u-dim" style="margin-block-end:12px">
        بعد از ذخیره اولیه می‌توانید تصاویر بیشتری اضافه یا حذف کنید.
      </p>
    <?php elseif ($images): ?>
      <div class="gallery">
        <?php foreach ($images as $img): ?>
          <div class="gallery__item">
            <img src="<?= e($img['path']) ?>" alt="" loading="lazy">
            <?php if ($img['path'] === v($product, 'image')): ?>
              <span class="gallery__main">اصلی</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="hint" style="margin-block-start:8px">
        برای حذف یا تغییر تصویر اصلی، از دکمه‌های پایین صفحه استفاده کنید.
      </p>
    <?php endif; ?>

    <div class="field" style="margin-block-start:16px">
      <label for="images">افزودن تصویر</label>
      <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
      <span class="hint">
        JPG، PNG یا WebP — حداکثر ۶ مگابایت برای هر تصویر، تا ۸ تصویر در هر بار.
        تصاویر خودکار به حداکثر ۱۴۰۰ پیکسل کوچک و فشرده می‌شوند.
      </span>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">توضیحات</h2>
    <div class="form-grid">
      <div class="field field--wide">
        <label for="description">توضیح کامل</label>
        <textarea id="description" name="description" rows="6"><?= e(v($product, 'description')) ?></textarea>
      </div>
      <div class="field field--wide">
        <label for="features">ویژگی‌ها</label>
        <textarea id="features" name="features" rows="5"><?= e(array_to_lines($features)) ?></textarea>
        <span class="hint">هر ویژگی در یک خط جدا.</span>
      </div>
      <div class="field field--wide">
        <label for="ingredients">ترکیبات</label>
        <textarea id="ingredients" name="ingredients" rows="3"><?= e(v($product, 'ingredients')) ?></textarea>
      </div>
      <div class="field field--wide">
        <label for="usage_text">روش مصرف</label>
        <textarea id="usage_text" name="usage_text" rows="3"><?= e(v($product, 'usage_text')) ?></textarea>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">طعم‌ها</h2>
    <div class="rows" data-repeat="flavor">
      <div class="rows__row rows__row--3 rows__head">
        <span>شناسه (اختیاری)</span><span>نام طعم</span><span></span>
      </div>
      <?php
      $flavorRows = $flavors ?: [['ext_id' => '', 'name' => '']];
      foreach ($flavorRows as $f): ?>
        <div class="rows__row rows__row--3" data-row>
          <input type="text" name="flavor_id[]" dir="ltr" placeholder="vanilla" value="<?= e($f['ext_id']) ?>">
          <input type="text" name="flavor_name[]" placeholder="وانیل" value="<?= e($f['name']) ?>">
          <button class="rows__del" type="button" data-del aria-label="حذف">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn--ghost btn--sm" type="button" data-add="flavor" style="margin-block-start:10px">
      افزودن طعم
    </button>
  </div>

  <div class="card">
    <h2 class="card__title">اندازه‌ها و قیمت هرکدام</h2>
    <div class="rows" data-repeat="size">
      <div class="rows__row rows__row--5 rows__head">
        <span>شناسه</span><span>عنوان</span><span>سروینگ</span>
        <span>خرید (درهم)</span><span>سود (تومان)</span><span>قیمت فروش</span><span></span>
      </div>
      <?php
      $sizeRows = $sizes ?: [['ext_id' => '', 'label' => '', 'servings' => '', 'price' => '',
                             'compare_at' => '', 'cost_aed' => '', 'profit_toman' => '']];
      foreach ($sizeRows as $s): ?>
        <div class="rows__row rows__row--5" data-row>
          <input type="text" name="size_id[]" dir="ltr" placeholder="900" value="<?= e($s['ext_id']) ?>">
          <input type="text" name="size_label[]" placeholder="900 گرم" value="<?= e($s['label']) ?>">
          <input type="text" name="size_servings[]" inputmode="numeric" value="<?= e((string) $s['servings']) ?>">
          <input type="text" name="size_cost_aed[]" dir="ltr" inputmode="decimal"
                 placeholder="42.50" value="<?= e((string) ($s['cost_aed'] ?? '')) ?>">
          <input type="text" name="size_profit[]" inputmode="numeric"
                 placeholder="1750000" value="<?= e((string) ($s['profit_toman'] ?? '')) ?>">
          <input type="text" name="size_price[]" inputmode="numeric" value="<?= e((string) $s['price']) ?>">
          <input type="hidden" name="size_compare[]" value="<?= e((string) $s['compare_at']) ?>">
          <button class="rows__del" type="button" data-del aria-label="حذف">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn--ghost btn--sm" type="button" data-add="size" style="margin-block-start:10px">
      افزودن اندازه
    </button>
    <p class="hint" style="margin-block-start:10px">
      اگر محصول فقط یک اندازه دارد، این بخش را خالی بگذارید — قیمت اصلی محصول استفاده می‌شود.
      <br>
      در حالت درهمی، قیمت فروش هر اندازه از قیمت خرید و سود همان اندازه
      ساخته می‌شود — دستی واردش نکنید.
      خالی گذاشتن خرید یا سود، یعنی از مقدار خود محصول استفاده شود.
    </p>
  </div>

  <div class="card">
    <h2 class="card__title">جدول ارزش غذایی</h2>
    <div class="field" style="max-width:340px;margin-block-end:14px">
      <label for="nutrition_serving">اندازه هر سروینگ</label>
      <input type="text" id="nutrition_serving" name="nutrition_serving"
             placeholder="هر پیمانه 30 گرم" value="<?= e(v($product, 'nutrition_serving')) ?>">
    </div>

    <div class="rows" data-repeat="nutrition">
      <div class="rows__row rows__row--3 rows__head">
        <span>عنوان</span><span>مقدار</span><span></span>
      </div>
      <?php
      $nutritionRows = $nutrition ?: [['label' => '', 'value' => '']];
      foreach ($nutritionRows as $n): ?>
        <div class="rows__row rows__row--3" data-row>
          <input type="text" name="nutrition_label[]" placeholder="پروتئین" value="<?= e($n['label'] ?? '') ?>">
          <input type="text" name="nutrition_value[]" dir="ltr" placeholder="24 g" value="<?= e($n['value'] ?? '') ?>">
          <button class="rows__del" type="button" data-del aria-label="حذف">×</button>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn--ghost btn--sm" type="button" data-add="nutrition" style="margin-block-start:10px">
      افزودن ردیف
    </button>
  </div>

  <div class="form-foot">
    <button class="btn btn--primary" type="submit">
      <?= $isNew ? 'ساخت محصول' : 'ذخیره تغییرات' ?>
    </button>
    <a class="btn btn--ghost" href="products.php">انصراف</a>
    <span class="spacer"></span>
    <?php if (!$isNew): ?>
      <a class="btn btn--ghost" target="_blank" rel="noopener"
         href="../product.html?p=<?= e(rawurlencode(v($product, 'slug'))) ?>">
        مشاهده در سایت
      </a>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew && $images): ?>
  <div class="card">
    <h2 class="card__title">مدیریت تصاویر</h2>
    <div class="gallery">
      <?php foreach ($images as $img): ?>
        <div class="gallery__item">
          <img src="<?= e($img['path']) ?>" alt="" loading="lazy">
          <form method="post" action="product-edit.php?id=<?= $id ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="del_image">
            <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
            <button class="gallery__del" type="submit" data-confirm="این تصویر حذف شود؟" aria-label="حذف تصویر">×</button>
          </form>
          <?php if ($img['path'] === v($product, 'image')): ?>
            <span class="gallery__main">اصلی</span>
          <?php else: ?>
            <form method="post" action="product-edit.php?id=<?= $id ?>"
                  style="position:absolute;inset-block-end:6px;inset-inline-start:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="main_image">
              <input type="hidden" name="image_id" value="<?= (int) $img['id'] ?>">
              <button class="btn btn--ghost btn--sm" type="submit">اصلی کن</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<script>
/* Add / remove repeating rows. A new row is cloned from the last one and
   cleared, so its field names and layout classes can never drift out of step
   with the markup PHP rendered. */
(function () {
  document.querySelectorAll('[data-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = document.querySelector('[data-repeat="' + btn.dataset.add + '"]');
      var rows  = group.querySelectorAll('[data-row]');
      var last  = rows[rows.length - 1];
      var clone = last.cloneNode(true);
      clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      group.appendChild(clone);
      var first = clone.querySelector('input');
      if (first) { first.focus(); }
    });
  });

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-del]');
    if (!btn) { return; }
    var group = btn.closest('[data-repeat]');
    var row   = btn.closest('[data-row]');
    /* Always leave one row standing — an empty group with no visible row and
       no way to get one back is a dead end. Clear it instead. */
    if (group.querySelectorAll('[data-row]').length > 1) {
      row.remove();
    } else {
      row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    }
  });
})();
</script>

<?php admin_foot(); ?>
