<?php
/* SUPPEX admin — categories. Few enough that one page does list and edit. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once __DIR__ . '/partials/layout.php';

$user   = auth_require();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $cid = (int) ($_POST['id'] ?? 0);
        /* Products keep existing and fall back to "no category" — the foreign
           key is ON DELETE SET NULL. Deleting a category must never take the
           shop's products with it. */
        db_query('DELETE FROM categories WHERE id = ?', [$cid]);
        flash('ok', 'دسته حذف شد. محصولات آن بدون دسته باقی ماندند.');
        header('Location: categories.php');
        exit;
    }

    if ($action === 'save') {
        $cid    = (int) ($_POST['id'] ?? 0);
        $nameFa = clean_text($_POST['name_fa'] ?? '', 120);

        if ($nameFa === '') {
            $errors['name_fa'] = 'نام دسته لازم است.';
        } else {
            $slugInput = clean_text($_POST['slug'] ?? '', 80);
            $slugBase  = $slugInput !== '' ? slugify($slugInput) : slugify($nameFa);
            $slug      = unique_slug($slugBase, 'categories', $cid > 0 ? $cid : null);

            $params = [
                $slug,
                $nameFa,
                clean_text($_POST['name_latin'] ?? '', 120),
                clean_text($_POST['blurb'] ?? '', 255),
                (int) ($_POST['sort_order'] ?? 0),
                empty($_POST['is_active']) ? 0 : 1,
            ];

            if ($cid > 0) {
                db_query('UPDATE categories SET slug = ?, name_fa = ?, name_latin = ?,
                                 blurb = ?, sort_order = ?, is_active = ? WHERE id = ?',
                    array_merge($params, [$cid]));
                flash('ok', 'دسته ویرایش شد.');
            } else {
                db_insert('INSERT INTO categories (slug, name_fa, name_latin, blurb, sort_order, is_active)
                           VALUES (?, ?, ?, ?, ?, ?)', $params);
                flash('ok', 'دسته ساخته شد.');
            }
            header('Location: categories.php');
            exit;
        }
    }
}

$editId   = (int) ($_GET['edit'] ?? 0);
$editing  = $editId > 0 ? db_one('SELECT * FROM categories WHERE id = ?', [$editId]) : null;
$rows     = db_all('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
                      FROM categories c ORDER BY c.sort_order, c.id');

admin_head('دسته‌ها', ['user' => $user]);
?>

<div class="split">
  <div>
    <?php if (!$rows): ?>
      <div class="card"><div class="empty">هنوز دسته‌ای ساخته نشده است.</div></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>نام</th><th>slug</th><th>تعداد محصول</th><th>نمایش</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $c): ?>
              <tr>
                <td>
                  <div class="t-title"><?= e($c['name_fa']) ?></div>
                  <div class="t-sub lat"><?= e($c['name_latin']) ?></div>
                </td>
                <td class="lat u-dim"><?= e($c['slug']) ?></td>
                <td class="num"><?= (int) $c['product_count'] ?></td>
                <td>
                  <span class="pill <?= (int) $c['is_active'] === 1 ? 'pill--new' : 'pill--off' ?>">
                    <?= (int) $c['is_active'] === 1 ? 'فعال' : 'پنهان' ?>
                  </span>
                </td>
                <td>
                  <div class="t-actions">
                    <a class="btn btn--ghost btn--sm" href="?edit=<?= (int) $c['id'] ?>">ویرایش</a>
                    <form method="post" action="categories.php" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <button class="btn btn--danger btn--sm" type="submit"
                        data-confirm="دسته «<?= e($c['name_fa']) ?>» حذف شود؟ محصولات آن حذف نمی‌شوند اما بدون دسته می‌مانند.">
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
  </div>

  <div>
    <div class="card">
      <h2 class="card__title"><?= $editing ? 'ویرایش دسته' : 'دسته جدید' ?></h2>
      <form method="post" action="categories.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

        <div class="field">
          <label for="name_fa">نام فارسی *</label>
          <input type="text" id="name_fa" name="name_fa" required
                 value="<?= e($editing['name_fa'] ?? '') ?>">
          <?php if (!empty($errors['name_fa'])): ?><span class="err"><?= e($errors['name_fa']) ?></span><?php endif; ?>
        </div>

        <div class="field" style="margin-block-start:12px">
          <label for="name_latin">نام انگلیسی</label>
          <input type="text" id="name_latin" name="name_latin" dir="ltr"
                 value="<?= e($editing['name_latin'] ?? '') ?>">
        </div>

        <div class="field" style="margin-block-start:12px">
          <label for="blurb">توضیح کوتاه</label>
          <textarea id="blurb" name="blurb" rows="2"><?= e($editing['blurb'] ?? '') ?></textarea>
        </div>

        <div class="field" style="margin-block-start:12px">
          <label for="slug">slug</label>
          <input type="text" id="slug" name="slug" dir="ltr" value="<?= e($editing['slug'] ?? '') ?>">
          <span class="hint">خالی بگذارید تا خودکار ساخته شود.</span>
        </div>

        <div class="field" style="margin-block-start:12px">
          <label for="sort_order">ترتیب</label>
          <input type="number" id="sort_order" name="sort_order"
                 value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
        </div>

        <label class="check" style="margin-block-start:14px">
          <input type="checkbox" name="is_active" value="1"
            <?= (!$editing || (int) $editing['is_active'] === 1) ? ' checked' : '' ?>>
          <span>در سایت نمایش داده شود</span>
        </label>

        <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:18px">
          <?= $editing ? 'ذخیره' : 'ساخت دسته' ?>
        </button>
        <?php if ($editing): ?>
          <a class="btn btn--ghost btn--block" href="categories.php" style="margin-block-start:8px">انصراف</a>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
