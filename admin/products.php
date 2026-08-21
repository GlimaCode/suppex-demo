<?php
/* SUPPEX admin — product list. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/images.php';
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

$sql = 'SELECT p.id, p.slug, p.name_fa, p.name_en, p.brand, p.price, p.compare_at,
               p.in_stock, p.is_active, p.image, c.name_fa AS category_name
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

$products   = db_all($sql, $params);
$categories = db_all('SELECT id, name_fa FROM categories ORDER BY sort_order, id');

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
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
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
        <?php foreach ($products as $p): ?>
          <tr>
            <td>
              <?php if ($p['image'] !== ''): ?>
                <img class="t-thumb" src="<?= e($p['image']) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="t-thumb"></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="t-title"><?= e($p['name_fa']) ?></div>
              <div class="t-sub lat"><?= e($p['name_en']) ?></div>
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
              <span class="pill <?= (int) $p['in_stock'] === 1 ? 'pill--paid' : 'pill--cancelled' ?>">
                <?= (int) $p['in_stock'] === 1 ? 'موجود' : 'ناموجود' ?>
              </span>
            </td>
            <td>
              <span class="pill <?= (int) $p['is_active'] === 1 ? 'pill--new' : 'pill--off' ?>">
                <?= (int) $p['is_active'] === 1 ? 'در سایت' : 'پنهان' ?>
              </span>
            </td>
            <td>
              <div class="t-actions">
                <a class="btn btn--ghost btn--sm" href="product-edit.php?id=<?= (int) $p['id'] ?>">ویرایش</a>
                <form method="post" action="products.php" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn btn--danger btn--sm" type="submit"
                          data-confirm="محصول «<?= e($p['name_fa']) ?>» و همه تصاویرش حذف شود؟ این کار برگشت‌پذیر نیست.">
                    حذف
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php admin_foot(); ?>
