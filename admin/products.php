<?php
/* SUPPEX admin — product list. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/images.php';
require_once SUPPEX_ROOT . '/lib/stock.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* --- Delete ---------------------------------------------------------------
   A POST, never a GET link. A destructive action behind a plain <a> is
   triggered by anything that follows links — a prefetching browser, a chat
   client generating a preview, a crawler that got hold of the URL. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_check();
    $pid = (int) ($_POST['id'] ?? 0);

    $paths = array_column(
        db_all('SELECT path FROM product_images WHERE product_id = ?', [$pid]),
        'path'
    );
    $main = (string) db_value('SELECT image FROM products WHERE id = ?', [$pid]);
    if ($main !== '') {
        $paths[] = $main;
    }

    /* Rows go first. If unlinking a file fails the product is still gone and
       an orphaned image is only wasted disk; the reverse — a product whose
       images have vanished — leaves broken pictures on the storefront. */
    db_query('DELETE FROM products WHERE id = ?', [$pid]);
    foreach (array_unique($paths) as $path) {
        image_delete($path);
    }

    flash('ok', 'محصول حذف شد.');
    header('Location: products.php');
    exit;
}

$q      = clean_text($_GET['q'] ?? '', 80);
$catId  = (int) ($_GET['cat'] ?? 0);

/* Carried through every redirect, so saving one row on a filtered list does
   not dump you back at the unfiltered top of sixty products. Rebuilt from the
   parsed values rather than echoing $_SERVER['QUERY_STRING'], which would
   reflect whatever else somebody appended to the URL. */
$qsParams = array_filter(['q' => $q, 'cat' => $catId > 0 ? $catId : null],
                         static fn($v): bool => $v !== null && $v !== '' && $v !== 0);
$qs = $qsParams ? '?' . http_build_query($qsParams) : '';

/* --- Quick edit -----------------------------------------------------------
   The fields somebody changes without wanting the whole editor. Applied for
   ONE product - the id named in the submit button - even though the form
   carries every visible row's inputs, so editing row three cannot write row
   two. */
/* action="quick:<id>". A submit button carries a name and a value and only
   the clicked one submits, so the id rides on the button rather than on a
   hidden input - one per row would collide on the name and the last in the
   document would win. This is correct with JavaScript off, which matters on a
   page whose whole job is editing. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && strpos((string) ($_POST['action'] ?? ''), 'quick:') === 0) {
    csrf_check();
    $qid = (int) substr((string) $_POST['action'], 6);
    $row = $qid > 0 ? db_one('SELECT id, price_mode FROM products WHERE id = ?', [$qid]) : null;

    if ($row === null) {
        flash('error', 'این محصول وجود ندارد.');
    } else {
        $f = (array) ($_POST['q'][$qid] ?? []);

        $catId = (int) ($f['category_id'] ?? 0);
        $fields = [
            'compare_at'  => trim((string) ($f['compare_at'] ?? '')) === ''
                ? null : parse_money($f['compare_at']),
            'category_id' => $catId > 0 ? $catId : null,
            'sort_order'  => (int) digits_only((string) ($f['sort_order'] ?? '0')),
            'in_stock'    => empty($f['in_stock']) ? 0 : 1,
            'is_active'   => empty($f['is_active']) ? 0 : 1,
        ];

        /* A dirham-priced product's price is derived and would be overwritten
           by the next pricing run, so the form does not offer it and this
           refuses it even if the field is forged. */
        if ((string) $row['price_mode'] !== 'aed') {
            $price = parse_money((string) ($f['price'] ?? '0'));
            if ($price > 0) { $fields['price'] = $price; }
        }

        /* compare_at below price is not a discount, it is a mistake - the
           storefront would render a negative saving. Same rule the full editor
           applies. */
        $effective = $fields['price'] ?? (int) db_value('SELECT price FROM products WHERE id = ?', [$qid]);
        if ($fields['compare_at'] !== null && $fields['compare_at'] <= $effective) {
            $fields['compare_at'] = null;
        }

        $set = implode(', ', array_map(static fn(string $k): string => $k . ' = ?', array_keys($fields)));
        db_query('UPDATE products SET ' . $set . ' WHERE id = ?',
                 array_merge(array_values($fields), [$qid]));

        /* Stock lives on its own page because the order flow moves it, so it
           is written separately and only when it actually changed - the same
           rule stock.php uses, for the same reason. */
        $wasRaw = trim(to_latin_digits((string) ($f['stock_was'] ?? '')));
        $nowRaw = trim(to_latin_digits((string) ($f['stock_qty'] ?? '')));
        if ($wasRaw !== $nowRaw) {
            db_query('UPDATE products SET stock_qty = ? WHERE id = ?',
                [$nowRaw === '' ? null : (int) $nowRaw, $qid]);
        }

        flash('ok', 'محصول به‌روز شد.');
    }
    header('Location: products.php' . $qs);
    exit;
}

/* --- Bulk -----------------------------------------------------------------
   One operation over the checked rows. Everything here is reversible except
   the delete, which asks. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    csrf_check();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $op  = (string) ($_POST['bulk_op'] ?? '');

    if (!$ids) {
        flash('error', 'هیچ محصولی انتخاب نشده بود.');
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n  = count($ids);

        switch ($op) {
            case 'activate':
            case 'deactivate':
                db_query('UPDATE products SET is_active = ? WHERE id IN (' . $in . ')',
                    array_merge([$op === 'activate' ? 1 : 0], $ids));
                flash('ok', fa_digits((string) $n) . ' محصول '
                    . ($op === 'activate' ? 'در سایت نمایش داده می‌شود.' : 'از سایت پنهان شد.'));
                break;

            case 'instock':
            case 'outstock':
                db_query('UPDATE products SET in_stock = ? WHERE id IN (' . $in . ')',
                    array_merge([$op === 'instock' ? 1 : 0], $ids));
                flash('ok', fa_digits((string) $n) . ' محصول '
                    . ($op === 'instock' ? 'موجود شد.' : 'ناموجود شد.'));
                break;

            case 'category':
                $catId = (int) ($_POST['bulk_category'] ?? 0);
                db_query('UPDATE products SET category_id = ? WHERE id IN (' . $in . ')',
                    array_merge([$catId > 0 ? $catId : null], $ids));
                flash('ok', 'دستهٔ ' . fa_digits((string) $n) . ' محصول تغییر کرد.');
                break;

            case 'badge_add':
            case 'badge_remove':
                $badge = (string) ($_POST['bulk_badge'] ?? '');
                if (!in_array($badge, ['bestseller', 'new', 'topdeal'], true)) {
                    flash('error', 'نشان نامعتبر است.');
                    break;
                }
                /* Read-modify-write per row rather than a clever SQL string
                   operation: badges is a comma list, and FIND_IN_SET games
                   produce «bestseller,,new» the first time somebody removes
                   the middle one. */
                foreach (db_all('SELECT id, badges FROM products WHERE id IN (' . $in . ')', $ids) as $p) {
                    $have = array_values(array_filter(explode(',', (string) $p['badges'])));
                    $have = array_values(array_diff($have, [$badge]));
                    if ($op === 'badge_add') { $have[] = $badge; }
                    db_query('UPDATE products SET badges = ? WHERE id = ?',
                        [implode(',', $have), (int) $p['id']]);
                }
                flash('ok', 'نشان ' . fa_digits((string) $n) . ' محصول تغییر کرد.');
                break;

            case 'delete':
                /* Safe for order history specifically: order_items has no
                   foreign key to products and snapshots the name, slug and
                   prices at order time. Past orders keep reading correctly
                   after the product is gone. */
                $paths = array_column(
                    db_all('SELECT path FROM product_images WHERE product_id IN (' . $in . ')', $ids), 'path');
                foreach (db_all('SELECT image FROM products WHERE id IN (' . $in . ')', $ids) as $p) {
                    if ((string) $p['image'] !== '') { $paths[] = (string) $p['image']; }
                }
                db_query('DELETE FROM products WHERE id IN (' . $in . ')', $ids);
                foreach (array_unique($paths) as $path) { image_delete($path); }
                flash('ok', fa_digits((string) $n) . ' محصول حذف شد.');
                break;

            default:
                flash('error', 'کاری انتخاب نشده بود.');
        }
    }
    header('Location: products.php' . $qs);
    exit;
}

$sql = 'SELECT p.id, p.slug, p.name_fa, p.name_en, p.brand, p.price, p.compare_at,
               p.in_stock, p.stock_qty, p.is_active, p.image, p.badges, p.sort_order,
               p.price_mode, p.category_id, c.name_fa AS category_name,
               (SELECT COUNT(*) FROM product_sizes s WHERE s.product_id = p.id) AS sizes_total,
               (SELECT MIN(s.stock_qty) FROM product_sizes s
                 WHERE s.product_id = p.id) AS sizes_lowest
          FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
         WHERE 1';
$params = [];

if ($q !== '') {
    $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
    $sql .= ' AND (p.name_fa LIKE ? OR p.name_en LIKE ? OR p.brand LIKE ?)';
    array_push($params, $needle, $needle, $needle);
}
if ($catId > 0) {
    $sql .= ' AND p.category_id = ?';
    $params[] = $catId;
}
$sql .= ' ORDER BY p.sort_order, p.id DESC LIMIT 300';

$products     = db_all($sql, $params);
$categories   = db_all('SELECT id, name_fa FROM categories ORDER BY sort_order, id');
$lowThreshold = stock_low_threshold();

admin_head('محصولات', [
    'user'   => $user,
    'action' => ['href' => 'product-edit.php', 'label' => 'محصول جدید'],
]);
?>

<form class="filters" method="get" action="products.php">
  <div class="field">
    <label for="q">جستجو</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="نام یا برند محصول">
  </div>
  <div class="field">
    <label for="cat">دسته</label>
    <select id="cat" name="cat">
      <option value="">همه دسته‌ها</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>"<?= $catId === (int) $c['id'] ? ' selected' : '' ?>>
          <?= e($c['name_fa']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn--primary" type="submit">اعمال</button>
  <a class="btn btn--ghost" href="products.php">حذف فیلترها</a>
</form>

<?php if (!$products): ?>
  <div class="card">
    <div class="empty">
      محصولی پیدا نشد.
      <div style="margin-block-start:16px">
        <a class="btn btn--primary" href="product-edit.php">افزودن اولین محصول</a>
      </div>
    </div>
  </div>
<?php else: ?>
  <form method="post" action="products.php<?= $qs ?>" data-bulk-form>
    <?= csrf_field() ?>

    <!-- Appears once something is checked. Sticky, because on a list of sixty
         products the selection is made at the bottom and the action lives at
         the top. -->
    <div class="bulkbar" data-bulkbar hidden>
      <span class="bulkbar__count">
        <span class="num" data-bulk-count>0</span> محصول انتخاب شده
      </span>
      <select name="bulk_op" data-bulk-op>
        <option value="">یک کار انتخاب کنید…</option>
        <option value="activate">در سایت نمایش بده</option>
        <option value="deactivate">از سایت پنهان کن</option>
        <option value="instock">موجود کن</option>
        <option value="outstock">ناموجود کن</option>
        <option value="category">تغییر دسته</option>
        <option value="badge_add">افزودن نشان</option>
        <option value="badge_remove">برداشتن نشان</option>
        <option value="delete">حذف</option>
      </select>

      <select name="bulk_category" data-bulk-extra="category" hidden>
        <option value="0">بدون دسته</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= e($c['name_fa']) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="bulk_badge" data-bulk-extra="badge_add badge_remove" hidden>
        <option value="bestseller">پرفروش</option>
        <option value="new">جدید</option>
        <option value="topdeal">تخفیف برتر (بالای صفحهٔ اصلی)</option>
      </select>

      <button class="btn btn--primary btn--sm" type="submit" name="action" value="bulk"
              data-bulk-apply>اعمال</button>
      <button class="btn btn--ghost btn--sm" type="button" data-bulk-clear>لغو انتخاب</button>
    </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:2.2rem">
            <input type="checkbox" data-check-all aria-label="انتخاب همه">
          </th>
          <th></th>
          <th>نام</th>
          <th>دسته</th>
          <th>قیمت</th>
          <th>موجودی</th>
          <th>نمایش</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $p): $pid = (int) $p['id']; ?>
          <tr>
            <td>
              <input type="checkbox" name="ids[]" value="<?= $pid ?>" data-row-check
                     aria-label="انتخاب <?= e($p['name_fa']) ?>">
            </td>
            <td>
              <?php if ($p['image'] !== ''): ?>
                <img class="t-thumb" src="<?= e(admin_img($p['image'])) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="t-thumb"></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="t-title"><?= e($p['name_fa']) ?></div>
              <div class="t-sub lat"><?= e($p['name_en']) ?></div>
              <?php /* So the owner can see at a glance which products are
                       in the home page's hero without opening each one. */ ?>
              <?php if (in_array('topdeal', explode(',', (string) $p['badges']), true)): ?>
                <span class="pill pill--new" style="margin-block-start:4px">تخفیف برتر</span>
              <?php endif; ?>
            </td>
            <td class="u-dim"><?= e($p['category_name'] ?? '—') ?></td>
            <td class="num u-nowrap">
              <?= money((int) $p['price']) ?>
              <?php if ($p['compare_at'] !== null && (int) $p['compare_at'] > (int) $p['price']): ?>
                <div class="t-sub" style="text-decoration:line-through">
                  <?= money((int) $p['compare_at']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <?php
              /* The manual switch first, because it overrides everything. Then
                 the number, when there is one: "موجود" beside a count of zero
                 would be the panel disagreeing with the storefront. */
              if ((int) $p['in_stock'] !== 1): ?>
                <span class="pill pill--cancelled">ناموجود</span>
              <?php else:
                  $n = $p['stock_qty'] === null ? null : (int) $p['stock_qty'];
                  /* A product sold in sizes reports its emptiest size, since
                     that is the one about to embarrass somebody. */
                  if ((int) $p['sizes_total'] > 0 && $p['sizes_lowest'] !== null) {
                      $n = (int) $p['sizes_lowest'];
                  }
                  if ($n === null): ?>
                    <span class="pill pill--paid">موجود</span>
                  <?php elseif ($n <= 0): ?>
                    <span class="pill pill--cancelled">تمام شد</span>
                  <?php else: ?>
                    <span class="pill <?= $n <= $lowThreshold ? 'pill--new' : 'pill--paid' ?>">
                      <span class="num"><?= $n ?></span> عدد
                    </span>
                  <?php endif;
              endif; ?>
            </td>
            <td>
              <span class="pill <?= (int) $p['is_active'] === 1 ? 'pill--new' : 'pill--off' ?>">
                <?= (int) $p['is_active'] === 1 ? 'در سایت' : 'پنهان' ?>
              </span>
            </td>
            <td>
              <div class="t-actions">
                <!-- The delete form that used to sit here was a form inside a
                     form, which is invalid and which browsers resolve by
                     dropping one of them. Deleting is a bulk operation now -
                     check the row, choose «حذف». -->
                <button class="btn btn--ghost btn--sm" type="button"
                        data-quick-toggle="<?= $pid ?>"
                        aria-expanded="false" aria-controls="q<?= $pid ?>">ویرایش سریع</button>
                <a class="btn btn--ghost btn--sm" href="product-edit.php?id=<?= $pid ?>">ویرایش</a>
                <a class="btn btn--ghost btn--sm" href="import.php?export=xlsx&amp;product=<?= $pid ?>"
                   title="همین محصول در یک فایل اکسل"><?= admin_icon('download') ?> اکسل</a>
              </div>
            </td>
          </tr>

          <!-- Quick edit. Hidden until asked for, and its inputs are named
               q[id][…] so they can live inside the one bulk form without
               nesting - only the id in the submit button is applied. -->
          <tr class="quickrow" id="q<?= $pid ?>" hidden>
            <td colspan="8">
              <div class="quick">
                <?php $derived = (string) $p['price_mode'] === 'aed'; ?>

                <div class="quick__f">
                  <label for="qp<?= $pid ?>">قیمت</label>
                  <?php if ($derived): ?>
                    <!-- Derived from the dirham rate. A box here would take a
                         number and lose it at the next pricing run. -->
                    <div class="quick__ro">
                      <span class="num"><?= money((int) $p['price']) ?></span>
                      <a href="pricing.php" class="u-dim">از نرخ درهم — تغییر در قیمت‌گذاری</a>
                    </div>
                  <?php else: ?>
                    <input type="text" id="qp<?= $pid ?>" inputmode="numeric" dir="ltr"
                           name="q[<?= $pid ?>][price]" value="<?= (int) $p['price'] ?>">
                  <?php endif; ?>
                </div>

                <div class="quick__f">
                  <label for="qc<?= $pid ?>">قیمت قبل از تخفیف</label>
                  <input type="text" id="qc<?= $pid ?>" inputmode="numeric" dir="ltr"
                         name="q[<?= $pid ?>][compare_at]"
                         value="<?= $p['compare_at'] === null ? '' : (int) $p['compare_at'] ?>"
                         placeholder="بدون تخفیف">
                </div>

                <div class="quick__f">
                  <label for="qs<?= $pid ?>">موجودی</label>
                  <input type="text" id="qs<?= $pid ?>" inputmode="numeric" dir="ltr"
                         name="q[<?= $pid ?>][stock_qty]"
                         value="<?= $p['stock_qty'] === null ? '' : (int) $p['stock_qty'] ?>"
                         placeholder="شمرده نشده">
                  <input type="hidden" name="q[<?= $pid ?>][stock_was]"
                         value="<?= $p['stock_qty'] === null ? '' : (int) $p['stock_qty'] ?>">
                </div>

                <div class="quick__f">
                  <label for="qcat<?= $pid ?>">دسته</label>
                  <select id="qcat<?= $pid ?>" name="q[<?= $pid ?>][category_id]">
                    <option value="0">بدون دسته</option>
                    <?php foreach ($categories as $c): ?>
                      <option value="<?= (int) $c['id'] ?>"
                        <?= (int) $p['category_id'] === (int) $c['id'] ? ' selected' : '' ?>>
                        <?= e($c['name_fa']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="quick__f">
                  <label for="qo<?= $pid ?>">ترتیب</label>
                  <input type="text" id="qo<?= $pid ?>" inputmode="numeric" dir="ltr"
                         name="q[<?= $pid ?>][sort_order]" value="<?= (int) $p['sort_order'] ?>">
                </div>

                <div class="quick__f quick__f--checks">
                  <label class="check">
                    <input type="checkbox" name="q[<?= $pid ?>][in_stock]" value="1"
                      <?= (int) $p['in_stock'] === 1 ? ' checked' : '' ?>>
                    <span>موجود است</span>
                  </label>
                  <label class="check">
                    <input type="checkbox" name="q[<?= $pid ?>][is_active]" value="1"
                      <?= (int) $p['is_active'] === 1 ? ' checked' : '' ?>>
                    <span>در سایت</span>
                  </label>
                </div>

                <div class="quick__f quick__f--go">
                  <button class="btn btn--primary btn--sm" type="submit"
                          name="action" value="quick:<?= $pid ?>">ذخیره</button>
                  <button class="btn btn--ghost btn--sm" type="button"
                          data-quick-cancel="<?= $pid ?>">بستن</button>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </form>
<?php endif; ?>

<script>
/* Progressive: the markup works with this file absent. Without JavaScript every
   quick-edit row is visible and the bulk bar is always on screen, which is
   busier but entirely usable - so nothing here is load-bearing. */
(function () {
  var form = document.querySelector('[data-bulk-form]');
  if (!form) { return; }

  /* Hide the quick rows now rather than in the markup, so a browser with no
     JavaScript never gets a row it cannot open. */
  var quickRows = {};
  Array.prototype.forEach.call(form.querySelectorAll('.quickrow'), function (tr) {
    tr.hidden = true;
    quickRows[tr.id] = tr;
  });

  Array.prototype.forEach.call(form.querySelectorAll('[data-quick-toggle]'), function (btn) {
    btn.addEventListener('click', function () {
      var tr = quickRows['q' + btn.getAttribute('data-quick-toggle')];
      if (!tr) { return; }
      var open = tr.hidden;
      tr.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        var first = tr.querySelector('input:not([type=hidden]), select');
        if (first) { first.focus(); }
      }
    });
  });
  Array.prototype.forEach.call(form.querySelectorAll('[data-quick-cancel]'), function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-quick-cancel');
      var tr = quickRows['q' + id];
      if (tr) { tr.hidden = true; }
      var toggle = form.querySelector('[data-quick-toggle="' + id + '"]');
      if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.focus(); }
    });
  });

  /* --- selection --- */
  var bar = form.querySelector('[data-bulkbar]');
  var all = form.querySelector('[data-check-all]');
  var count = form.querySelector('[data-bulk-count]');
  var op = form.querySelector('[data-bulk-op]');
  var apply = form.querySelector('[data-bulk-apply]');
  var boxes = function () {
    return Array.prototype.slice.call(form.querySelectorAll('[data-row-check]'));
  };

  function paint() {
    var checked = boxes().filter(function (b) { return b.checked; });
    count.textContent = checked.length;
    bar.hidden = checked.length === 0;
    if (all) {
      all.checked = checked.length > 0 && checked.length === boxes().length;
      all.indeterminate = checked.length > 0 && checked.length < boxes().length;
    }
    apply.disabled = !op.value;
  }

  /* Only the extra control the chosen operation needs. A category select
     sitting next to "delete" invites the wrong click. */
  function paintExtras() {
    Array.prototype.forEach.call(form.querySelectorAll('[data-bulk-extra]'), function (el) {
      var forOps = el.getAttribute('data-bulk-extra').split(' ');
      el.hidden = forOps.indexOf(op.value) === -1;
    });
    apply.disabled = !op.value;
    apply.classList.toggle('btn--danger', op.value === 'delete');
    apply.classList.toggle('btn--primary', op.value !== 'delete');
  }

  if (all) {
    all.addEventListener('change', function () {
      boxes().forEach(function (b) { b.checked = all.checked; });
      paint();
    });
  }
  form.addEventListener('change', function (e) {
    if (e.target.matches('[data-row-check]')) { paint(); }
    if (e.target === op) { paintExtras(); }
  });
  var clear = form.querySelector('[data-bulk-clear]');
  if (clear) {
    clear.addEventListener('click', function () {
      boxes().forEach(function (b) { b.checked = false; });
      paint();
    });
  }

  /* Deleting is the one operation here that cannot be undone, and the count
     is the part people get wrong - so the confirmation says the number. */
  form.addEventListener('submit', function (e) {
    var btn = e.submitter;
    if (!btn || btn.getAttribute('value') !== 'bulk') { return; }
    if (op.value !== 'delete') { return; }
    var n = boxes().filter(function (b) { return b.checked; }).length;
    if (!window.confirm(n + ' محصول و همه تصاویرشان حذف شود؟ این کار برگشت‌پذیر نیست.')) {
      e.preventDefault();
    }
  });

  paint();
  paintExtras();
}());
</script>

<?php admin_foot(); ?>
