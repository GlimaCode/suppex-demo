<?php
/* ===========================================================================
   SUPPEX admin — shop settings
   ---------------------------------------------------------------------------
   Card details, shipping rules, messaging links and the commission rate.
   Everything here is stored in the settings table, so none of it needs a code
   change or a redeploy.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once __DIR__ . '/partials/layout.php';

$user   = auth_require();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $hash = (string) db_value('SELECT password_hash FROM admins WHERE id = ?', [$user['id']]);

        /* The current password is required even though the session already
           proves who this is. It is what stops an unattended open laptop from
           becoming a permanent account takeover. */
        if (!password_verify($current, $hash)) {
            $errors['current_password'] = 'رمز فعلی درست نیست.';
        } elseif (mb_strlen($new) < 10) {
            /* Length beats character classes: "Aa1!x" satisfies every symbol
               rule and is trivially guessable, while a long passphrase is not. */
            $errors['new_password'] = 'رمز جدید باید حداقل ۱۰ کاراکتر باشد.';
        } elseif ($new !== $confirm) {
            $errors['confirm_password'] = 'تکرار رمز با رمز جدید یکی نیست.';
        } else {
            db_query('UPDATE admins SET password_hash = ? WHERE id = ?',
                [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash('ok', 'رمز عبور تغییر کرد.');
            header('Location: settings.php');
            exit;
        }
    } else {
        $card = digits_only((string) ($_POST['card_number'] ?? ''));

        /* A card number that fails Luhn is a typo in all but the rarest case,
           and the cost of accepting it is a customer transferring money to a
           stranger. Refusing to save is the kinder failure. */
        if ($card !== '' && !is_valid_card_number($card)) {
            $errors['card_number'] = 'این شماره کارت معتبر نیست. ۱۶ رقم را دوباره بررسی کنید.';
        }

        $commission = (float) to_latin_digits((string) ($_POST['commission_percent'] ?? '0'));
        if ($commission < 0 || $commission > 100) {
            $errors['commission_percent'] = 'درصد باید بین ۰ تا ۱۰۰ باشد.';
        }

        foreach (['telegram_url' => 'تلگرام', 'bale_url' => 'بله', 'whatsapp_url' => 'واتس‌اپ'] as $key => $label) {
            $url = trim((string) ($_POST[$key] ?? ''));
            if ($url !== '' && !preg_match('~^https://~i', $url)) {
                $errors[$key] = 'لینک ' . $label . ' باید با https:// شروع شود.';
            }
        }

        if (!$errors) {
            settings_save([
                'commission_basis'        => ($_POST['commission_basis'] ?? 'goods') === 'profit'
                                              ? 'profit' : 'goods',
                'shop_name'               => clean_text($_POST['shop_name'] ?? '', 80),
                'shop_phone'              => digits_only((string) ($_POST['shop_phone'] ?? '')),
                'free_shipping_threshold' => parse_money($_POST['free_shipping_threshold'] ?? 0),
                'shipping_flat_rate'      => parse_money($_POST['shipping_flat_rate'] ?? 0),
                'card_number'             => $card,
                'card_holder'             => clean_text($_POST['card_holder'] ?? '', 120),
                'bank_name'               => clean_text($_POST['bank_name'] ?? '', 80),
                'commission_percent'      => number_format($commission, 2, '.', ''),
                'telegram_url'            => trim((string) ($_POST['telegram_url'] ?? '')),
                'bale_url'                => trim((string) ($_POST['bale_url'] ?? '')),
                'whatsapp_url'            => trim((string) ($_POST['whatsapp_url'] ?? '')),
                'sale_mode'               => empty($_POST['sale_mode']) ? '0' : '1',
            ]);
            flash('ok', 'تنظیمات ذخیره شد.');
            header('Location: settings.php');
            exit;
        }
    }
}

/** Posted value first, so a rejected form does not lose what was typed. */
function s(string $key, $default = '')
{
    if (isset($_POST[$key])) {
        return (string) $_POST[$key];
    }
    return (string) setting($key, $default);
}

admin_head('تنظیمات', ['user' => $user]);
?>

<form method="post" action="settings.php">
  <?= csrf_field() ?>

  <div class="card">
    <h2 class="card__title">اطلاعات پرداخت</h2>
    <p class="u-dim" style="margin-block-end:16px">
      این اطلاعات فقط بعد از ثبت سفارش به مشتری نشان داده می‌شود، نه در صفحات عمومی سایت.
    </p>
    <div class="form-grid">
      <div class="field">
        <label for="card_number">شماره کارت</label>
        <input type="text" id="card_number" name="card_number" dir="ltr" inputmode="numeric"
               placeholder="۱۶ رقم" value="<?= e(s('card_number')) ?>">
        <?php if (!empty($errors['card_number'])): ?>
          <span class="err"><?= e($errors['card_number']) ?></span>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="card_holder">نام صاحب حساب</label>
        <input type="text" id="card_holder" name="card_holder" value="<?= e(s('card_holder')) ?>">
        <span class="hint">دقیقاً همان‌طور که در بانک ثبت شده است.</span>
      </div>
      <div class="field">
        <label for="bank_name">نام بانک</label>
        <input type="text" id="bank_name" name="bank_name" value="<?= e(s('bank_name')) ?>">
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">ارسال</h2>
    <div class="form-grid">
      <div class="field">
        <label for="shipping_flat_rate">هزینه ارسال (تومان)</label>
        <input type="text" id="shipping_flat_rate" name="shipping_flat_rate" inputmode="numeric"
               value="<?= e(s('shipping_flat_rate', '65000')) ?>">
      </div>
      <div class="field">
        <label for="free_shipping_threshold">ارسال رایگان از (تومان)</label>
        <input type="text" id="free_shipping_threshold" name="free_shipping_threshold" inputmode="numeric"
               value="<?= e(s('free_shipping_threshold', '1500000')) ?>">
        <span class="hint">۰ یعنی ارسال رایگان هرگز اعمال نشود.</span>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">راه‌های دریافت سفارش</h2>
    <p class="u-dim" style="margin-block-end:16px">
      مشتری بعد از تکمیل اطلاعات، یکی از این‌ها را انتخاب می‌کند و سفارشش به همان‌جا فرستاده می‌شود.
      برای غیرفعال کردن هرکدام، کادر آن را خالی بگذارید.
    </p>
    <div class="form-grid">
      <div class="field">
        <label for="telegram_url">لینک تلگرام</label>
        <input type="url" id="telegram_url" name="telegram_url" dir="ltr"
               placeholder="https://t.me/username" value="<?= e(s('telegram_url')) ?>">
        <?php if (!empty($errors['telegram_url'])): ?><span class="err"><?= e($errors['telegram_url']) ?></span><?php endif; ?>
      </div>
      <div class="field">
        <label for="bale_url">لینک بله</label>
        <input type="url" id="bale_url" name="bale_url" dir="ltr"
               placeholder="https://ble.ir/username" value="<?= e(s('bale_url')) ?>">
        <?php if (!empty($errors['bale_url'])): ?><span class="err"><?= e($errors['bale_url']) ?></span><?php endif; ?>
      </div>
      <div class="field">
        <label for="whatsapp_url">لینک واتس‌اپ</label>
        <input type="url" id="whatsapp_url" name="whatsapp_url" dir="ltr"
               placeholder="https://wa.me/989xxxxxxxxx" value="<?= e(s('whatsapp_url')) ?>">
        <?php if (!empty($errors['whatsapp_url'])): ?><span class="err"><?= e($errors['whatsapp_url']) ?></span><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">فروشگاه</h2>
    <div class="form-grid">
      <div class="field">
        <label for="shop_name">نام فروشگاه</label>
        <input type="text" id="shop_name" name="shop_name" value="<?= e(s('shop_name', 'SUPPEX')) ?>">
      </div>
      <div class="field">
        <label for="shop_phone">شماره تماس فروشگاه</label>
        <input type="tel" id="shop_phone" name="shop_phone" dir="ltr" value="<?= e(s('shop_phone')) ?>">
      </div>
      <div class="field">
        <label>حالت تخفیف</label>
        <label class="check">
          <input type="checkbox" name="sale_mode" value="1"
            <?= setting_bool('sale_mode', true) ? ' checked' : '' ?>>
          <span>نمایش قیمت‌های خط‌خورده و درصد تخفیف</span>
        </label>
        <span class="hint">با خاموش کردن، همه محصولات بدون تخفیف نمایش داده می‌شوند.</span>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 class="card__title">سهم همکاری</h2>
    <div class="form-grid">
      <div class="field">
        <label for="commission_percent">درصد از فروش</label>
        <input type="text" id="commission_percent" name="commission_percent" dir="ltr"
               inputmode="decimal" value="<?= e(s('commission_percent', '0')) ?>">
        <?php if (!empty($errors['commission_percent'])): ?>
          <span class="err"><?= e($errors['commission_percent']) ?></span>
        <?php endif; ?>
        <span class="hint">
          این درصد در لحظه ثبت هر سفارش ذخیره می‌شود،
          بنابراین تغییر آن روی سفارش‌های گذشته اثری ندارد.
        </span>
      </div>

      <div class="field">
        <label>مبنای محاسبه</label>
        <label class="check">
          <input type="radio" name="commission_basis" value="goods"
            <?= setting('commission_basis', 'goods') !== 'profit' ? ' checked' : '' ?>>
          <span>درصدی از <strong>مبلغ کالاها</strong></span>
        </label>
        <label class="check" style="margin-block-start:8px">
          <input type="radio" name="commission_basis" value="profit"
            <?= setting('commission_basis', 'goods') === 'profit' ? ' checked' : '' ?>>
          <span>درصدی از <strong>سود خالص</strong></span>
        </label>
        <span class="hint">
          مبنای «سود خالص» فقط وقتی کار می‌کند که قیمت خرید محصولات وارد شده باشد.
          برای هر قلمی که قیمت خرید ندارد، سیستم خودکار به مبنای مبلغ کالاها برمی‌گردد
          — تا یک سفارش بدون قیمت خرید، سهم صفر ثبت نکند.
        </span>
      </div>
    </div>
  </div>

  <div class="form-foot">
    <button class="btn btn--primary" type="submit">ذخیره تنظیمات</button>
  </div>
</form>

<div class="card">
  <h2 class="card__title">تغییر رمز عبور</h2>
  <form method="post" action="settings.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <div class="form-grid">
      <div class="field">
        <label for="current_password">رمز فعلی</label>
        <input type="password" id="current_password" name="current_password"
               autocomplete="current-password" dir="ltr">
        <?php if (!empty($errors['current_password'])): ?>
          <span class="err"><?= e($errors['current_password']) ?></span>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="new_password">رمز جدید</label>
        <input type="password" id="new_password" name="new_password"
               autocomplete="new-password" dir="ltr">
        <span class="hint">حداقل ۱۰ کاراکتر. یک عبارت طولانی از چند کلمه، از یک رمز کوتاهِ پیچیده امن‌تر است.</span>
        <?php if (!empty($errors['new_password'])): ?>
          <span class="err"><?= e($errors['new_password']) ?></span>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="confirm_password">تکرار رمز جدید</label>
        <input type="password" id="confirm_password" name="confirm_password"
               autocomplete="new-password" dir="ltr">
        <?php if (!empty($errors['confirm_password'])): ?>
          <span class="err"><?= e($errors['confirm_password']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="form-foot">
      <button class="btn btn--primary" type="submit">تغییر رمز</button>
    </div>
  </form>
</div>

<?php admin_foot(); ?>
