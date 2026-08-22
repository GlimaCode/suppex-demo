<?php
/* SUPPEX admin — order list, filterable. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* Shared hosting has no cron worth relying on, so the sweep rides along with
   the page that reads the list. Doing it here rather than on write means an
   order expires on time even if nobody places another one. */
orders_expire_due();

$filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'q'      => (string) ($_GET['q'] ?? ''),
    'from'   => (string) ($_GET['from'] ?? ''),
    'to'     => (string) ($_GET['to'] ?? ''),
];

$perPage = 40;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$total   = orders_count($filters);
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min($page, $pages);

$orders = orders_list($filters + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]);

/** Keep the current filters when moving between pages. */
function page_link(int $page, array $filters): string
{
    return '?' . http_build_query(array_filter($filters + ['page' => $page], static fn($v) => $v !== ''));
}

admin_head('سفارش‌ها', ['user' => $user]);
?>

<form class="filters" method="get" action="orders.php">
  <div class="field">
    <label for="q">جستجو</label>
    <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>"
           placeholder="کد سفارش، نام یا شماره تماس">
  </div>
  <div class="field">
    <label for="status">وضعیت</label>
    <select id="status" name="status">
      <option value="">همه</option>
      <?php foreach (ORDER_STATUSES as $s): ?>
        <option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>>
          <?= e(ORDER_STATUS_FA[$s]) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="from">از تاریخ</label>
    <input type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
  </div>
  <div class="field">
    <label for="to">تا تاریخ</label>
    <input type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
  </div>
  <button class="btn btn--primary" type="submit">اعمال</button>
  <a class="btn btn--ghost" href="orders.php">حذف فیلترها</a>
</form>

<p class="u-dim" style="margin-block-end:12px">
  <span class="num"><?= $total ?></span> سفارش
</p>

<?php if (!$orders): ?>
  <div class="card"><div class="empty">سفارشی با این فیلترها پیدا نشد.</div></div>
<?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>کد</th>
          <th>مشتری</th>
          <th>اقلام</th>
          <th>مبلغ</th>
          <th>سهم همکاری</th>
          <th>وضعیت</th>
          <th>تاریخ</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <?php $itemCount = (int) db_value(
                  'SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id = ?',
                  [(int) $o['id']]); ?>
          <tr>
            <td class="num u-nowrap"><?= e($o['code']) ?></td>
            <td>
              <div class="t-title"><?= e($o['customer_name']) ?></div>
              <div class="t-sub num"><?= e($o['phone']) ?></div>
            </td>
            <td class="num"><?= $itemCount ?></td>
            <td class="num u-nowrap"><?= money((int) $o['total']) ?></td>
            <td class="num u-nowrap u-dim"><?= money((int) $o['commission_amount']) ?></td>
            <td>
              <span class="pill pill--<?= e($o['status']) ?>">
                <?= e(ORDER_STATUS_FA[$o['status']] ?? $o['status']) ?>
              </span>
            </td>
            <td class="num u-nowrap u-dim"><?= e(date('Y/m/d H:i', strtotime($o['created_at']))) ?></td>
            <td class="u-right">
              <a class="btn btn--ghost btn--sm" href="order.php?id=<?= (int) $o['id'] ?>">مشاهده</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php if ($page > 1): ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(page_link($page - 1, $filters)) ?>">قبلی</a>
      <?php endif; ?>
      <span class="btn btn--sm u-dim">
        <span class="num"><?= $page ?></span> از <span class="num"><?= $pages ?></span>
      </span>
      <?php if ($page < $pages): ?>
        <a class="btn btn--ghost btn--sm" href="<?= e(page_link($page + 1, $filters)) ?>">بعدی</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php admin_foot(); ?>
