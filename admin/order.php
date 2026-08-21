<?php
/* SUPPEX admin — one order: what was bought, where it goes, and its history. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

$id    = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? order_get($id) : null;

if ($order === null) {
    http_response_code(404);
    admin_head('سفارش پیدا نشد', ['user' => $user]);
    echo '<div class="card"><div class="empty">این سفارش وجود ندارد.</div></div>';
    admin_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $status = (string) ($_POST['status'] ?? '');
    $note   = (string) ($_POST['note'] ?? '');

    if (order_set_status($id, $status, $user, $note)) {
        if ($note !== '') {
            db_query('UPDATE orders SET receipt_note = ? WHERE id = ?', [clean_multiline($note, 1000), $id]);
        }
        flash('ok', 'وضعیت سفارش به «' . (ORDER_STATUS_FA[$status] ?? $status) . '» تغییر کرد.');
    } else {
        flash('error', 'وضعیت انتخاب‌شده معتبر نیست.');
    }

    /* Redirect after POST so a refresh does not resubmit the status change and
       write a duplicate row into the audit trail. */
    header('Location: order.php?id=' . $id);
    exit;
}

/* Rebuilds the message the customer would have sent, so it can be copied into
   a chat when the paste never happened at their end. */
function order_as_text(array $order): string
{
    $lines = ['سفارش ' . $order['code'], ''];
    foreach ($order['items'] as $i => $item) {
        $lines[] = ($i + 1) . ') ' . $item['name_fa'];
        if ($item['variant_label'] !== '') {
            $lines[] = '   ' . $item['variant_label'];
        }
        $lines[] = '   ' . $item['qty'] . ' × ' . money((int) $item['unit_price'])
                 . ' = ' . money((int) $item['line_total']) . ' تومان';
    }
    $lines[] = '';
    $lines[] = 'مبلغ قابل پرداخت: ' . money((int) $order['total']) . ' تومان';
    $lines[] = '';
    $lines[] = 'گیرنده: ' . $order['customer_name'];
    $lines[] = 'تماس: ' . $order['phone'];
    $lines[] = 'آدرس: ' . $order['address'];
    $lines[] = 'کد پستی: ' . $order['postal'];
    return implode("\n", $lines);
}

admin_head('سفارش ' . $order['code'], ['user' => $user]);
?>

<div class="split">
  <div>
    <div class="card">
      <h2 class="card__title">اقلام سفارش</h2>
      <div class="table-wrap" style="border:0">
        <table style="min-width:0">
          <thead>
            <tr><th>کالا</th><th>تعداد</th><th>قیمت واحد</th><th class="u-right">جمع</th></tr>
          </thead>
          <tbody>
            <?php foreach ($order['items'] as $item): ?>
              <tr>
                <td>
                  <div class="t-title"><?= e($item['name_fa']) ?></div>
                  <?php if ($item['variant_label'] !== ''): ?>
                    <div class="t-sub"><?= e($item['variant_label']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="num"><?= (int) $item['qty'] ?></td>
                <td class="num u-nowrap"><?= money((int) $item['unit_price']) ?></td>
                <td class="num u-nowrap u-right"><?= money((int) $item['line_total']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="totals">
        <div class="totals__row">
          <span class="u-dim">جمع کالاها</span>
          <span class="num"><?= money((int) $order['subtotal']) ?> تومان</span>
        </div>
        <div class="totals__row">
          <span class="u-dim">هزینه ارسال</span>
          <span class="num"><?= (int) $order['shipping'] === 0
            ? 'رایگان' : money((int) $order['shipping']) . ' تومان' ?></span>
        </div>
        <div class="totals__row totals__row--grand">
          <span>مبلغ قابل پرداخت</span>
          <span class="num"><?= money((int) $order['total']) ?> تومان</span>
        </div>
        <?php if ((float) $order['commission_percent'] > 0): ?>
          <div class="totals__row totals__row--commission">
            <span>سهم همکاری (<span class="num"><?= rtrim(rtrim(number_format((float) $order['commission_percent'], 2), '0'), '.') ?></span>٪)</span>
            <span class="num"><?= money((int) $order['commission_amount']) ?> تومان</span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2 class="card__title">مشخصات گیرنده</h2>
      <dl class="kv">
        <dt>نام</dt>          <dd><?= e($order['customer_name']) ?></dd>
        <dt>تماس</dt>         <dd class="num"><a href="tel:<?= e($order['phone']) ?>"><?= e($order['phone']) ?></a></dd>
        <dt>آدرس</dt>         <dd style="white-space:pre-line"><?= e($order['address']) ?></dd>
        <dt>کد پستی</dt>      <dd class="num"><?= e($order['postal']) ?></dd>
        <?php if (trim((string) $order['note']) !== ''): ?>
          <dt>توضیحات</dt>    <dd style="white-space:pre-line"><?= e($order['note']) ?></dd>
        <?php endif; ?>
        <dt>راه ارسال</dt>    <dd><?= e($order['channel'] !== '' ? $order['channel'] : '—') ?></dd>
      </dl>

      <div style="margin-block-start:20px">
        <label class="field" style="gap:6px">
          <span style="font-size:.8125rem;font-weight:700;color:var(--text-muted)">
            متن سفارش برای ارسال در چت
          </span>
          <textarea rows="8" readonly onclick="this.select()"><?= e(order_as_text($order)) ?></textarea>
          <span class="hint">
            اگر مشتری متن سفارش را در گفتگو نفرستاده، از اینجا کپی کنید.
            روی کادر کلیک کنید تا کل متن انتخاب شود.
          </span>
        </label>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <h2 class="card__title">وضعیت</h2>

      <p style="margin-block-end:16px">
        <span class="pill pill--<?= e($order['status']) ?>">
          <?= e(ORDER_STATUS_FA[$order['status']] ?? $order['status']) ?>
        </span>
      </p>

      <form method="post" action="order.php?id=<?= (int) $order['id'] ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label for="status">تغییر وضعیت به</label>
          <select id="status" name="status">
            <?php foreach (ORDER_STATUSES as $s): ?>
              <option value="<?= e($s) ?>"<?= $order['status'] === $s ? ' selected' : '' ?>>
                <?= e(ORDER_STATUS_FA[$s]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="margin-block-start:12px">
          <label for="note">یادداشت</label>
          <textarea id="note" name="note" rows="3"
                    placeholder="مثلاً: رسید واریز در تلگرام دریافت شد"><?= e((string) $order['receipt_note']) ?></textarea>
          <span class="hint">در تاریخچه سفارش ثبت می‌شود و قابل حذف نیست.</span>
        </div>
        <button class="btn btn--primary btn--block" type="submit" style="margin-block-start:16px">
          ثبت تغییر
        </button>
      </form>
    </div>

    <div class="card">
      <h2 class="card__title">تاریخچه</h2>
      <div class="timeline">
        <?php foreach (array_reverse($order['events']) as $ev): ?>
          <div class="timeline__item">
            <span class="timeline__dot"></span>
            <div>
              <div>
                <?= $ev['from_status'] === ''
                  ? 'ثبت سفارش'
                  : e((ORDER_STATUS_FA[$ev['from_status']] ?? $ev['from_status'])
                      . ' ← ' . (ORDER_STATUS_FA[$ev['to_status']] ?? $ev['to_status'])) ?>
                <?php if ($ev['admin_name'] !== ''): ?>
                  <span class="u-dim">— <?= e($ev['admin_name']) ?></span>
                <?php endif; ?>
              </div>
              <?php if ($ev['note'] !== ''): ?>
                <div class="u-dim"><?= e($ev['note']) ?></div>
              <?php endif; ?>
              <div class="timeline__when num"><?= e(date('Y/m/d H:i', strtotime($ev['created_at']))) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<p style="margin-block-start:20px">
  <a class="btn btn--ghost" href="orders.php"><?= admin_icon('back') ?> بازگشت به سفارش‌ها</a>
</p>

<?php admin_foot(); ?>
