<?php
/* SUPPEX admin — one order: what was bought, where it goes, and its history. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/receipts.php';
require_once SUPPEX_ROOT . '/lib/seo.php';
require_once SUPPEX_ROOT . '/lib/order_messages.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

orders_expire_due();

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

    /* A receipt that did not get through to Telegram, sent again. */
    if (($_POST['action'] ?? '') === 'receipt_resend') {
        $receipt = receipts_ready() ? receipt_get((int) ($_POST['receipt_id'] ?? 0)) : null;
        if ($receipt === null || (int) $receipt['order_id'] !== $id) {
            flash('error', 'این رسید پیدا نشد.');
        } else {
            /* Only to where it did not arrive: receipt_notify() skips every
               chat that already has it, so this never puts a second copy in
               the group. */
            $sent = receipt_notify($order, $receipt, seo_origin());
            if ($sent['busy']) {
                flash('error', 'این رسید همین حالا در حال ارسال به تلگرام است. چند ثانیه بعد صفحه را تازه کنید.');
            } elseif ($sent['chat'] === null && $sent['group'] === null) {
                flash('error', 'ربات تلگرام تنظیم نشده است؛ رسید فقط همین‌جا دیده می‌شود.');
            } elseif ($sent['chat'] === false || $sent['group'] === false) {
                flash('error', 'ارسال به تلگرام انجام نشد. چند دقیقه بعد دوباره امتحان کنید.');
            } elseif ($sent['sent'] === 0) {
                flash('ok', 'این رسید قبلاً به تلگرام رسیده بود؛ دوباره فرستاده نشد.');
            } else {
                flash('ok', 'رسید به تلگرام فرستاده شد.');
            }
        }
        header('Location: order.php?id=' . $id);
        exit;
    }

    $status = (string) ($_POST['status'] ?? '');
    $note   = (string) ($_POST['note'] ?? '');

    /* Saved whatever the status change does, and before it: the tracking
       number is a fact about the parcel, not about the workflow, and it should
       not be lost because the status happened to be rejected. */
    $trackingBefore = (string) $order['tracking_code'];
    order_set_tracking($id, (string) ($_POST['tracking_code'] ?? ''));
    $trackingAfter  = (string) db_value('SELECT tracking_code FROM orders WHERE id = ?', [$id]);

    /* A status change moves goods on and off a shelf, and the badge on its
       own does not say so. Measured either side rather than returned by
       order_set_status(), which has one job and should keep it. */
    $heldBefore = (int) db_value(
        'SELECT COALESCE(SUM(stock_held), 0) FROM order_items WHERE order_id = ?', [$id]);

    if (order_set_status($id, $status, $user, $note)) {
        if ($note !== '') {
            db_query('UPDATE orders SET receipt_note = ? WHERE id = ?', [clean_multiline($note, 1000), $id]);
        }
        $heldAfter = (int) db_value(
            'SELECT COALESCE(SUM(stock_held), 0) FROM order_items WHERE order_id = ?', [$id]);

        $msg = 'وضعیت سفارش به «' . (ORDER_STATUS_FA[$status] ?? $status) . '» تغییر کرد.';
        if ($heldAfter < $heldBefore) {
            $msg .= ' ' . fa_digits((string) ($heldBefore - $heldAfter)) . ' عدد به موجودی برگشت.';
        } elseif ($heldAfter > $heldBefore) {
            $msg .= ' ' . fa_digits((string) ($heldAfter - $heldBefore)) . ' عدد دوباره از موجودی کم شد.';
        }
        if ($trackingAfter !== $trackingBefore) {
            $msg .= $trackingAfter === ''
                ? ' کد رهگیری پاک شد.'
                : ' کد رهگیری ثبت شد و برای مشتری قابل دیدن است.';
        }
        flash('ok', $msg);

        /* Reopening is allowed to oversell — the operator knows things the
           database does not. Silence would be the mistake. */
        $over = stock_oversold_for_order($id);
        if ($over) {
            flash('error', 'موجودی این‌ها حالا منفی است: ' . implode('، ', $over)
                . '. یعنی بیشتر از چیزی که دارید فروخته شده — در «موجودی انبار» درستش کنید.');
        }
    } else {
        flash('error', 'وضعیت انتخاب‌شده معتبر نیست.');
    }

    /* Redirect after POST so a refresh does not resubmit the status change and
       write a duplicate row into the audit trail. */
    header('Location: order.php?id=' . $id);
    exit;
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
          <?php /* order_message_details(): signed «SUPPEX — suppex.ir», like
                   everything the shop sends (lib/order_messages.php). */ ?>
          <textarea rows="8" readonly onclick="this.select()"><?= e(order_message_details($order)) ?></textarea>
          <span class="hint">
            اگر مشتری متن سفارش را در گفتگو نفرستاده، از اینجا کپی کنید.
            روی کادر کلیک کنید تا کل متن انتخاب شود.
          </span>
        </label>
      </div>

      <?php /* The label for the parcel and the invoice for the box, on a page
               of their own that prints as just those two (order-print.php). */ ?>
      <p style="margin-block-start:16px">
        <a class="btn btn--ghost" href="order-print.php?id=<?= (int) $order['id'] ?>" target="_blank" rel="noopener">
          چاپ برچسب پستی و فاکتور
        </a>
      </p>
    </div>

    <?php
      /* The same number's other orders: a returning customer, an order placed
         twice, or somebody else's number typed by mistake - each worth
         knowing before confirming this one. */
      $otherOrders = db_all(
          'SELECT id, code, status, total, created_at FROM orders
            WHERE phone = ? AND id <> ? ORDER BY created_at DESC, id DESC LIMIT 8',
          [(string) $order['phone'], (int) $order['id']]);
      $otherCount = (int) db_value('SELECT COUNT(*) FROM orders WHERE phone = ? AND id <> ?',
          [(string) $order['phone'], (int) $order['id']]);
    ?>
    <?php if ($otherOrders): ?>
      <div class="card">
        <h2 class="card__title">سفارش‌های دیگر این شماره</h2>
        <div class="table-wrap" style="border:0">
          <table class="table table--compact">
            <tbody>
              <?php foreach ($otherOrders as $oo): ?>
                <tr>
                  <td class="num u-nowrap"><a href="order.php?id=<?= (int) $oo['id'] ?>"><?= e($oo['code']) ?></a></td>
                  <td class="num u-nowrap u-dim"><?= e(date('Y/m/d', strtotime((string) $oo['created_at']))) ?></td>
                  <td class="num u-nowrap"><?= money((int) $oo['total']) ?></td>
                  <td>
                    <span class="pill pill--<?= e($oo['status']) ?>">
                      <?= e(ORDER_STATUS_FA[$oo['status']] ?? $oo['status']) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($otherCount > count($otherOrders)): ?>
          <p style="margin-block-start:12px">
            <a href="orders.php?q=<?= e(rawurlencode((string) $order['phone'])) ?>">
              همهٔ <span class="num"><?= $otherCount ?></span> سفارش دیگر این شماره
            </a>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <?php
      $receipts = receipts_for_order((int) $order['id']);
      $bot      = suppex_config()['telegram_bot'] ?? [];
      $botOn    = !empty($bot['enabled']) && !empty($bot['token']);
      $groupOn  = trim((string) setting('telegram_group_id', '')) !== '';
    ?>
    <!-- Above the status, because it is what the status is decided on. -->
    <div class="card">
      <h2 class="card__title">رسید واریز</h2>
      <?php if (!receipts_ready()): ?>
        <p class="hint">
          برای دریافت رسید از سایت، یک بار «به‌روزرسانی دیتابیس» را اجرا کنید.
        </p>
      <?php elseif (!$receipts): ?>
        <p class="hint">
          مشتری هنوز از سایت رسیدی نفرستاده است. اگر در گفتگو فرستاده باشد، همان‌جاست.
        </p>
      <?php else: ?>
        <div class="receipts">
          <?php foreach ($receipts as $r): ?>
            <figure class="receipt">
              <a href="receipt.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener">
                <img src="receipt.php?id=<?= (int) $r['id'] ?>" loading="lazy"
                     alt="رسید واریز سفارش <?= e($order['code']) ?>">
              </a>
              <figcaption>
                <span class="num"><?= e(date('Y/m/d H:i', strtotime((string) $r['created_at']))) ?></span>
                <?php if (!$botOn): ?>
                  <span>ربات تلگرام تنظیم نشده است</span>
                <?php else: ?>
                  <span class="<?= (int) $r['sent_chat'] ? '' : 'receipt__miss' ?>">
                    گفتگوی شما: <?= (int) $r['sent_chat'] ? 'رسید' : 'نرسید' ?>
                  </span>
                  <?php if ($groupOn): ?>
                    <span class="<?= (int) $r['sent_group'] ? '' : 'receipt__miss' ?>">
                      گروه: <?= (int) $r['sent_group'] ? 'رسید' : 'نرسید' ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!(int) $r['sent_chat'] || ($groupOn && !(int) $r['sent_group'])): ?>
                    <form method="post" action="order.php?id=<?= (int) $order['id'] ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="receipt_resend">
                      <input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>">
                      <button class="btn btn--ghost btn--sm" type="submit">ارسال دوباره به تلگرام</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 class="card__title">وضعیت</h2>

      <p style="margin-block-end:16px">
        <span class="pill pill--<?= e($order['status']) ?>">
          <?= e(ORDER_STATUS_FA[$order['status']] ?? $order['status']) ?>
        </span>
      </p>

      <?php if ($order['status'] === 'new' && $order['expires_at'] !== null): ?>
        <?php $left = strtotime($order['expires_at']) - time(); ?>
        <p class="hint" style="margin-block-end:16px">
          مهلت پرداخت این سفارش
          <?php if ($left > 0): ?>
            تا <strong class="num"><?= e(date('H:i', strtotime($order['expires_at']))) ?></strong>
            است (<span class="num"><?= (int) ceil($left / 60) ?></span> دقیقه دیگر).
          <?php else: ?>
            گذشته است و در بازدید بعدی منقضی می‌شود.
          <?php endif; ?>
        </p>
      <?php elseif ($order['status'] === 'expired'): ?>
        <div class="flash flash--info" style="margin-block-end:16px">
          مشتری در مهلت تعیین‌شده پرداخت نکرد.
          <strong>قیمت‌های این سفارش برای نرخ آن روز است</strong> —
          اگر می‌خواهید دوباره فعالش کنید، اول قیمت‌ها را با نرخ امروز مقایسه کنید.
          با انتخاب «ثبت شده» مهلت از نو شروع می‌شود.
        </div>
      <?php endif; ?>

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
          <!-- In the same form as the status, deliberately. The moment the
               barcode is in hand is the moment the order is being marked
               shipped, and a second screen for it is a second thing to forget. -->
          <label for="tracking_code">کد رهگیری مرسوله (تیپاکس یا پست)</label>
          <input type="text" id="tracking_code" name="tracking_code" dir="ltr"
                 inputmode="numeric" value="<?= e((string) $order['tracking_code']) ?>">
          <span class="hint">
            بعد از ثبت، مشتری آن را در صفحه پیگیری سفارش خودش می‌بیند و لازم
            نیست از شما بپرسد. فقط رقم‌ها ذخیره می‌شود. خالی بگذارید تا نمایش
            داده نشود. برای پیک، تحویل را با گیرنده هماهنگ کنید؛ این کادر مخصوص کد مرسوله است.
          </span>
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

    <?php
      /* What to send the customer about this order, signed «SUPPEX — suppex.ir»
         (lib/order_messages.php). The one for where the order stands now comes
         first: change the status, and the message about it is at the top. */
      $messages = order_messages($order);
    ?>
    <div class="card">
      <h2 class="card__title">پیام آماده برای مشتری</h2>
      <?php foreach ($messages as $i => $msg): ?>
        <div class="field" style="gap:6px;<?= $i > 0 ? 'margin-block-start:14px' : '' ?>">
          <span style="display:flex;align-items:center;justify-content:space-between;gap:8px">
            <span style="font-size:.8125rem;font-weight:700;color:var(--text-muted)">
              <?= e($msg['label']) ?>
              <?php if ($msg['now']): ?>
                <span class="pill pill--new" style="margin-inline-start:6px">همین مرحله</span>
              <?php endif; ?>
            </span>
            <button class="btn btn--ghost btn--sm" type="button" data-copy-msg="msg-<?= e($msg['key']) ?>">کپی</button>
          </span>
          <textarea id="msg-<?= e($msg['key']) ?>" rows="<?= $msg['key'] === 'new' ? 8 : 6 ?>" readonly
                    onclick="this.select()"><?= e($msg['text']) ?></textarea>
        </div>
      <?php endforeach; ?>
      <span class="hint" style="display:block;margin-block-start:10px">
        در گفتگوی مشتری بچسبانید و بفرستید. پایان همه پیام‌ها
        «<?= e(brand_signature()) ?>» است تا مشتری نشانی سایت را داشته باشد.
      </span>
      <script>
      /* Copy a message; «کپی شد» for a moment, then the button's own word. */
      document.querySelectorAll('[data-copy-msg]').forEach(function (btn) {
        btn.setAttribute('data-label', btn.textContent);
        btn.addEventListener('click', function () {
          var box = document.getElementById(btn.getAttribute('data-copy-msg'));
          if (!box) { return; }
          var done = function () {
            btn.textContent = 'کپی شد';
            clearTimeout(btn.copyTimer);
            btn.copyTimer = setTimeout(function () { btn.textContent = btn.getAttribute('data-label'); }, 1500);
          };
          var fallback = function () {
            box.focus();
            box.select();
            try { if (document.execCommand('copy')) { done(); } } catch (e) { /* the text stays selected to copy by hand */ }
          };
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(box.value).then(done, fallback);
          } else {
            fallback();
          }
        });
      });
      </script>
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
