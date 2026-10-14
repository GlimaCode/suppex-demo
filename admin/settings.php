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
require_once SUPPEX_ROOT . '/lib/notify.php';
require_once __DIR__ . '/partials/layout.php';

$user   = auth_require();
$errors = [];
/* What «پیدا کردن گروه» found, shown on this same page rather than carried
   through a redirect. */
$tgGroups = null;
$tgNote   = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $tgAction = (string) ($_POST['action'] ?? '');
    $tgBot    = suppex_config()['telegram_bot'] ?? [];
    $tgToken  = !empty($tgBot['enabled']) ? (string) ($tgBot['token'] ?? '') : '';

    if (isset($_POST['tg_pick']) || in_array($tgAction, ['tg_save', 'tg_test', 'tg_find'], true)) {
        /* The group where receipts are confirmed. The id is not a secret -
           the token is, and it stays in suppex-config.php - so it lives here,
           where the owner can change it without touching a file. */
        $gid = trim(to_latin_digits((string) ($_POST['tg_pick'] ?? $_POST['telegram_group_id'] ?? '')));
        if ($tgAction === 'tg_find') {
            if ($tgToken === '') {
                $tgNote = 'ربات تلگرام در suppex-config.php روشن نیست یا توکن ندارد.';
            } else {
                $tgGroups = telegram_recent_groups($tgToken);
                if ($tgGroups === null) {
                    $tgNote = 'تلگرام جواب نداد. چند دقیقه بعد دوباره امتحان کنید.';
                } elseif (!$tgGroups) {
                    $tgNote = 'گروهی پیدا نشد. ربات را به گروه اضافه کنید، یک پیام در گروه بفرستید و دوباره امتحان کنید.';
                }
            }
        } elseif ($gid !== '' && !preg_match('/\A-?\d{5,20}\z/', $gid)) {
            flash('error', 'شناسهٔ گروه فقط رقم است و معمولاً با -100 شروع می‌شود.');
            header('Location: settings.php#telegram');
            exit;
        } elseif ($tgAction === 'tg_test') {
            if ($tgToken === '' || $gid === '') {
                flash('error', 'اول ربات و شناسهٔ گروه باید تنظیم شده باشند.');
            } else {
                settings_save(['telegram_group_id' => $gid]);
                $sentTest = telegram_send_text($tgToken, $gid,
                    'پیام آزمایشی ساپکس ✅' . "\n" . 'رسیدهای واریز مشتری‌ها از این به بعد در این گروه هم می‌آید.');
                if ($sentTest) {
                    flash('ok', 'پیام آزمایشی به گروه رسید.');
                } else {
                    flash('error', 'پیام به گروه نرسید. ربات عضو گروه است؟ شناسه درست است؟');
                }
            }
            header('Location: settings.php#telegram');
            exit;
        } else {
            settings_save(['telegram_group_id' => $gid]);
            flash('ok', $gid === '' ? 'گروه تلگرام برداشته شد.' : 'گروه تلگرام ذخیره شد.');
            header('Location: settings.php#telegram');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'password') {
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
            /* The shop-wide rate goes in the same append-only log as the
               per-product overrides, so the two can be read as one history
               rather than as two places to look. */
            $previousRate = (float) setting('commission_percent', '0');
            if (abs($previousRate - $commission) > 0.001) {
                db_query(
                    'INSERT INTO commission_log
                        (scope, scope_id, label, old_percent, new_percent, admin_id, admin_name)
                     VALUES ("shop", NULL, ?, ?, ?, ?, ?)',
                    ['درصد کلی فروشگاه', $previousRate, $commission, $user['id'], $user['name']]
                );
            }

            settings_save([
                'commission_basis'        => ($_POST['commission_basis'] ?? 'goods') === 'profit'
                                              ? 'profit' : 'goods',
                'shop_name'               => clean_text($_POST['shop_name'] ?? '', 80),
                /* Without this every canonical, og:url and sitemap <loc> follows
                   the Host header, so the same catalogue gets indexed under
                   every hostname cPanel answers on. Normalised here rather than
                   trusted: no trailing slash, and a bare domain gets https://. */
                'site_url'                => settings_normalise_site_url($_POST['site_url'] ?? ''),
                'shop_phone'              => digits_only((string) ($_POST['shop_phone'] ?? '')),
                'free_shipping_threshold' => parse_money($_POST['free_shipping_threshold'] ?? 0),
                'shipping_flat_rate'      => parse_money($_POST['shipping_flat_rate'] ?? 0),
                'order_hold_minutes'      => max(5, min((int) digits_only((string) ($_POST['order_hold_minutes'] ?? '30')), 180)),
                /* Clamped the same way stock_low_threshold() clamps it, so the
                   field and the code cannot disagree about what is allowed. */
                'low_stock_threshold'     => max(1, min((int) digits_only((string) ($_POST['low_stock_threshold'] ?? '3')), 50)),
                'card_number'             => $card,
                'card_holder'             => clean_text($_POST['card_holder'] ?? '', 120),
                'bank_name'               => clean_text($_POST['bank_name'] ?? '', 80),
                'commission_percent'      => number_format($commission, 2, '.', ''),
                'shop_email'              => trim((string) ($_POST['shop_email'] ?? '')),
                'instagram_url'           => trim((string) ($_POST['instagram_url'] ?? '')),
                'aparat_url'              => trim((string) ($_POST['aparat_url'] ?? '')),
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

      <div class="field">
        <label for="order_hold_minutes">مهلت پرداخت (دقیقه)</label>
        <input type="text" id="order_hold_minutes" name="order_hold_minutes" inputmode="numeric"
               value="<?= e(s('order_hold_minutes', '30')) ?>">
        <span class="hint">
          قیمت هر سفارش تا این مدت قفل می‌ماند؛ بعد از آن سفارش «منقضی» می‌شود.
          بین ۵ تا ۱۸۰ دقیقه. پیشنهاد: ۳۰ دقیقه — برای مشتری کافی است
          و آنقدر طولانی نیست که نرخ درهم جابه‌جا شود.
          سفارش منقضی حذف نمی‌شود — از صفحه خودش قابل برگرداندن است.
        </span>
      </div>

      <div class="field">
        <label for="low_stock_threshold">هشدار «رو به اتمام» از (عدد)</label>
        <input type="text" id="low_stock_threshold" name="low_stock_threshold" inputmode="numeric"
               value="<?= e(s('low_stock_threshold', '3')) ?>">
        <span class="hint">
          وقتی موجودی یک محصول به این عدد یا کمتر برسد، در سایت نوشته می‌شود
          «۲ عدد مانده» و در داشبورد هم هشدار می‌گیرید.
          عددهای بالاتر از این در سایت نمایش داده نمی‌شوند — مشتری با شنیدن
          «۴۷ عدد مانده» کار متفاوتی نمی‌کند، و انبار شما هم اطلاعات عمومی نیست.
          بین ۱ تا ۵۰.
        </span>
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
        <label for="shop_email">ایمیل فروشگاه</label>
        <input type="email" id="shop_email" name="shop_email" dir="ltr"
               placeholder="info@suppex.ir" value="<?= e(s('shop_email')) ?>">
        <span class="hint">
          خالی بگذارید تا در سایت نمایش داده نشود. فقط وقتی پرش کنید که واقعاً
          صندوقش را می‌خوانید — نشانی‌ای که کسی جواب نمی‌دهد از نبودنش بدتر است.
        </span>
      </div>

      <div class="field">
        <label for="instagram_url">لینک اینستاگرام</label>
        <input type="url" id="instagram_url" name="instagram_url" dir="ltr"
               placeholder="https://instagram.com/username" value="<?= e(s('instagram_url')) ?>">
        <span class="hint">آیکن اینستاگرام در فوتر تا وقتی این خالی است دیده نمی‌شود.</span>
      </div>

      <div class="field">
        <label for="aparat_url">لینک آپارات</label>
        <input type="url" id="aparat_url" name="aparat_url" dir="ltr"
               placeholder="https://aparat.com/username" value="<?= e(s('aparat_url')) ?>">
        <span class="hint">اگر کانالی ندارید خالی بگذارید؛ آیکنش نمایش داده نمی‌شود.</span>
      </div>

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
        <label for="site_url">نشانی اصلی سایت</label>
        <input type="url" id="site_url" name="site_url" dir="ltr"
               placeholder="https://suppex.ir" value="<?= e(s('site_url')) ?>">
        <p class="field__hint">
          نشانی‌ای که می‌خواهید گوگل نشان بدهد. تا وقتی خالی باشد، سایت هر نامی را
          که مرورگر بفرستد به‌عنوان نشانی رسمی اعلام می‌کند و یک فروشگاه چند بار
          جداگانه ایندکس می‌شود.
        </p>
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

<?php
  $tgBotCfg = suppex_config()['telegram_bot'] ?? [];
  $tgBotOn  = !empty($tgBotCfg['enabled']) && !empty($tgBotCfg['token']);
  $tgChatOn = trim((string) ($tgBotCfg['chat_id'] ?? '')) !== '';
?>
<div class="card" id="telegram">
  <h2 class="card__title">تلگرام: سفارش‌ها و رسیدها</h2>
  <p class="hint" style="margin-block-end:16px">
    <?php if (!$tgBotOn): ?>
      ربات تلگرام خاموش است یا توکن ندارد؛ آن را در فایل suppex-config.php روشن کنید.
    <?php else: ?>
      ربات روشن است<?= $tgChatOn ? ' و سفارش‌ها و رسیدها به گفتگوی شما می‌رسد' : '؛ شناسهٔ گفتگوی شما (chat_id) در suppex-config.php خالی است' ?>.
    <?php endif; ?>
    رسید واریزی که مشتری از سایت می‌فرستد، در گروهی که اینجا مشخص کنید هم می‌آید.
  </p>
  <form method="post" action="settings.php#telegram">
    <?= csrf_field() ?>
    <div class="field">
      <label for="telegram_group_id">شناسهٔ گروه تأیید سفارش‌ها</label>
      <input type="text" id="telegram_group_id" name="telegram_group_id" dir="ltr" inputmode="numeric"
             placeholder="-1001234567890" value="<?= e((string) setting('telegram_group_id', '')) ?>">
      <span class="hint">
        ۱) ربات را به گروه اضافه کنید. ۲) یک پیام در گروه بفرستید. ۳) «پیدا کردن گروه» را بزنید
        و گروه را انتخاب کنید. ۴) با «پیام آزمایشی» مطمئن شوید پیام می‌رسد. خالی بگذارید تا رسیدها
        فقط به گفتگوی خودتان برود.
      </span>
    </div>
    <?php if ($tgNote !== ''): ?>
      <div class="flash flash--info" style="margin-block-start:12px"><?= e($tgNote) ?></div>
    <?php endif; ?>
    <?php if ($tgGroups): ?>
      <div class="tg-groups">
        <?php foreach ($tgGroups as $g): ?>
          <div class="tg-groups__row">
            <span><?= e($g['title'] !== '' ? $g['title'] : 'گروه بی‌نام') ?>
              <span class="num u-dim" dir="ltr"><?= e($g['id']) ?></span></span>
            <button class="btn btn--ghost btn--sm" type="submit" name="tg_pick" value="<?= e($g['id']) ?>">انتخاب</button>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="form-foot" style="gap:8px;flex-wrap:wrap">
      <button class="btn btn--primary" type="submit" name="action" value="tg_save">ذخیره</button>
      <button class="btn btn--ghost" type="submit" name="action" value="tg_find">پیدا کردن گروه</button>
      <button class="btn btn--ghost" type="submit" name="action" value="tg_test">پیام آزمایشی به گروه</button>
    </div>
  </form>
</div>

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
