<?php
/* ===========================================================================
   SUPPEX admin — dirham rate and catalogue re-pricing
   ---------------------------------------------------------------------------
   Enter today's rate, look at what it does to every price, then apply it.
   The preview between those two steps is the entire point of the page: it is
   the only thing standing between a mistyped rate and a catalogue that is
   suddenly priced at ten times, or a tenth of, what it should be.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';
require_once SUPPEX_ROOT . '/lib/rate.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/* The pricing columns arrive with db/migrate.php. Without them every query on
   this page is a fatal error, so say so plainly instead. */
$ready = db_value(
    'SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "products" AND COLUMN_NAME = "price_mode"'
) !== null;

if (!$ready) {
    admin_head('قیمت‌گذاری', ['user' => $user]);
    echo '<div class="flash flash--error">'
       . 'ساختار دیتابیس هنوز به‌روز نشده است. '
       . '<a href="migrate.php" style="text-decoration:underline">اجرای به‌روزرسانی</a>'
       . '</div>';
    admin_foot();
    exit;
}

$errors  = [];
$notice  = null;
$rateIn  = pricing_rate();
$preview = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    $posted = (float) to_latin_digits(str_replace(',', '', (string) ($_POST['rate'] ?? '')));

    if ($posted <= 0) {
        $errors['rate'] = 'نرخ درهم را وارد کنید.';
    }

    if (!$errors && $action === 'preview') {
        $rateIn  = $posted;
        $preview = pricing_preview($posted);
        $notice  = pricing_rate_warning($posted);
    }

    if (!$errors && $action === 'apply') {
        /* Re-confirmed, not trusted from the preview round-trip: the preview
           was rendered from a rate in a hidden field, and between then and now
           a product could have been edited in another tab. Recomputing here
           means what gets written is derived from the database as it is at the
           moment of writing. */
        $onlyKeys = array_values(array_filter(
            array_map('strval', (array) ($_POST['ids'] ?? [])),
            /* Combinations too: every product converted to them is priced
               on its combinations (pricing_units()), and leaving «variant:»
               out of here dropped every one of their rows - a manual apply on
               a converted catalogue changed nothing and said «nothing was
               ticked». */
            static fn(string $k): bool => (bool) preg_match('/^(product|size|variant):[0-9]+$/', $k)
        ));

        /* Nothing ticked is an instruction, not an oversight: refuse rather
           than guessing that they meant all of them. */
        if (!$onlyKeys) {
            flash('error', 'هیچ موردی انتخاب نشده بود؛ هیچ قیمتی تغییر نکرد.');
            header('Location: pricing.php');
            exit;
        }

        try {
            $result = pricing_apply($posted, $user, $onlyKeys);
            $msg = 'قیمت ' . $result['applied'] . ' محصول با نرخ ' . number_format($posted) . ' به‌روز شد.';
            if ($result['skipped'] > 0) {
                $msg .= ' ' . $result['skipped'] . ' مورد به دلیل نداشتن سود اعمال نشد.';
            }
            flash('ok', $msg);
        } catch (Throwable $e) {
            flash('error', 'اعمال قیمت‌ها ناموفق بود. هیچ قیمتی تغییر نکرد.');
        }

        header('Location: pricing.php');
        exit;
    }
}

/* Default view: preview at the stored rate, so opening the page already
   answers "are my prices current?" without anyone typing anything. */
if (!$preview && $rateIn !== null) {
    $preview = pricing_preview($rateIn);
}

$stale   = pricing_staleness();
$history = db_all('SELECT * FROM rate_history ORDER BY id DESC LIMIT 10');

/* The automatic side. Guarded on the table existing, because this page has to
   keep working on a database that has not been migrated yet — the alternative
   is that the one screen explaining how to fix the migration is itself broken
   by the missing migration. */
$autoOn = db_value(
    'SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "rate_polls"') !== null;
$rs     = $autoOn ? rate_status() : null;
$pollLog = $autoOn ? rate_poll_log(36) : [];
$manualCount = (int) db_value(
    'SELECT COUNT(*) FROM products WHERE is_active = 1 AND (price_mode <> "aed" OR cost_aed IS NULL OR cost_aed <= 0)'
);

$changed = array_values(array_filter($preview, static fn(array $r) => $r['delta'] !== 0));
$noMargin = array_values(array_filter($preview, static fn(array $r) => $r['no_margin']));
$odd      = array_values(array_filter($preview, static fn(array $r) => $r['implausible'] && !$r['no_margin']));

admin_head('قیمت‌گذاری بر اساس درهم', ['user' => $user]);
?>

<?php if ($notice !== null): ?>
  <div class="flash flash--error"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($stale['stale']): ?>
  <div class="flash flash--error">
    <?php if ($stale['days'] !== null && $stale['days'] >= 1): ?>
      نرخ درهم
      <strong><span class="num"><?= e((string) (int) $stale['days']) ?></span> روز</strong>
      است که به‌روز نشده است.
    <?php endif; ?>
    <?php if ($stale['behind'] > 0): ?>
      <span class="num"><?= $stale['behind'] ?></span> از
      <span class="num"><?= $stale['total'] ?></span>
      قلم هنوز با نرخ قبلی قیمت‌گذاری شده‌اند.
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat">
    <div class="stat__label">نرخ فعلی درهم</div>
    <div class="stat__value num"><?= $rateIn === null ? '—' : money((int) $rateIn) ?>
      <span class="stat__unit">تومان</span></div>
  </div>
  <div class="stat">
    <div class="stat__label">آخرین به‌روزرسانی</div>
    <div class="stat__value" style="font-size:1rem">
      <?php $when = (string) setting('aed_rate_updated_at', ''); ?>
      <?= $when === '' ? '—' : e(date('Y/m/d H:i', strtotime($when))) ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat__label">اقلام قابل خرید</div>
    <div class="stat__value num"><?= count($preview) ?></div>
    <span class="stat__unit">هر اندازه، یک قلم</span>
  </div>
  <div class="stat<?= $changed ? ' stat--accent' : '' ?>">
    <div class="stat__label">نیازمند تغییر قیمت</div>
    <div class="stat__value num"><?= count($changed) ?></div>
  </div>
</div>

<div class="card">
  <h2 class="card__title">نرخ امروز</h2>
  <form method="post" action="pricing.php">
    <?= csrf_field() ?>
    <div class="filters" style="margin:0">
      <div class="field" style="min-width:220px">
        <label for="rate">هر ۱ درهم چند تومان؟</label>
        <input type="text" id="rate" name="rate" dir="ltr" inputmode="numeric"
               value="<?= e($rateIn === null ? '' : (string) (int) $rateIn) ?>"
               placeholder="مثلاً 24500">
        <?php if (!empty($errors['rate'])): ?>
          <span class="err"><?= e($errors['rate']) ?></span>
        <?php endif; ?>
      </div>
      <button class="btn btn--ghost" type="submit" name="action" value="preview">
        محاسبه قیمت‌ها
      </button>
    </div>
    <p class="hint" style="margin-block-start:12px">
      نرخ <strong>بازار آزاد</strong> را وارد کنید — همان نرخی که واقعاً با آن خرید می‌کنید،
      نه نرخ رسمی بانک مرکزی. تا وقتی دکمه «اعمال» را نزنید، هیچ قیمتی روی سایت تغییر نمی‌کند.
    </p>
  </form>
</div>

<?php if ($noMargin): ?>
  <div class="flash flash--error">
    <strong><span class="num"><?= count($noMargin) ?></span> محصول</strong>
    با این نرخ هیچ سودی ندارند و اعمال نمی‌شوند —
    مقدار سود آن محصول‌ها را در صفحه ویرایش وارد کنید.
  </div>
<?php endif; ?>

<?php if ($odd): ?>
  <div class="flash flash--error">
    <strong><span class="num"><?= count($odd) ?></span> محصول</strong>
    قیمت جدیدشان بیش از ۵ برابر با قیمت فعلی فاصله دارد.
    تقریباً همیشه یعنی قیمت خرید به جای درهم، به تومان وارد شده است.
    تیکشان برداشته شده — اگر واقعاً درست است، دستی تیک بزنید.
  </div>
<?php endif; ?>

<?php if (!$preview): ?>
  <div class="card">
    <div class="empty">
      هنوز محصولی به درهم قیمت‌گذاری نشده است.
      <p class="hint" style="margin-block-start:12px;max-width:460px;margin-inline:auto">
        در صفحه ویرایش هر محصول، حالت قیمت را روی «بر اساس درهم» بگذارید و
        قیمت خرید به درهم و سود مورد نظر را وارد کنید.
      </p>
      <div style="margin-block-start:16px">
        <a class="btn btn--primary" href="products.php">رفتن به محصولات</a>
      </div>
    </div>
  </div>
<?php else: ?>
  <form method="post" action="pricing.php">
    <?= csrf_field() ?>
    <input type="hidden" name="rate" value="<?= e((string) (int) $rateIn) ?>">

    <div class="card">
      <h2 class="card__title">پیش‌نمایش قیمت‌ها</h2>

      <div class="table-wrap" style="border:0">
        <table>
          <thead>
            <tr>
              <th style="width:34px"></th>
              <th>محصول</th>
              <th>خرید (درهم)</th>
              <th>قیمت تمام‌شده</th>
              <th>سود</th>
              <th>قیمت فعلی</th>
              <th>قیمت جدید</th>
              <th>قبل از تخفیف</th>
              <th class="u-right">تغییر</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview as $row): ?>
              <?php $suspect = $row['no_margin'] || $row['implausible']; ?>
              <tr<?= $suspect ? ' style="background:rgba(226,86,74,.07)"' : '' ?>>
                <td>
                  <input type="checkbox" name="ids[]"
                         value="<?= e($row['kind'] . ':' . $row['id']) ?>"
                         <?= $row['no_margin'] ? 'disabled' : ($suspect ? '' : 'checked') ?>
                         style="width:17px;height:17px;accent-color:var(--accent)">
                </td>
                <td>
                  <div class="t-title"><?= e($row['label']) ?></div>
                  <?php if ($row['sub'] !== ''): ?>
                    <div class="t-sub"><?= e($row['sub']) ?></div>
                  <?php endif; ?>
                  <?php if ($row['no_margin']): ?>
                    <div class="t-sub" style="color:#f2a29a">بدون سود — اعمال نمی‌شود</div>
                  <?php elseif ($row['implausible']): ?>
                    <div class="t-sub" style="color:#f0c473">تغییر غیرعادی — قیمت خرید را بررسی کنید</div>
                  <?php endif; ?>
                </td>
                <td class="num u-nowrap"><?= e(rtrim(rtrim(number_format($row['cost_aed'], 2), '0'), '.')) ?></td>
                <td class="num u-nowrap u-dim"><?= money($row['cost']) ?></td>
                <?php /* 'margin', not 'profit': pricing_preview() has never emitted a
                         'profit' key, so this was an undefined-key warning followed by
                         money(null) throwing - the table stopped here and the page
                         ended mid-row, silently, because production hides errors. */ ?>
                <td class="num u-nowrap u-dim"><?= money($row['margin']) ?></td>
                <td class="num u-nowrap"><?= money($row['current']) ?></td>
                <td class="num u-nowrap"><strong><?= money($row['proposed']) ?></strong></td>
                <td class="num u-nowrap u-dim">
                  <?= $row['compare_at'] === null ? '—' : money($row['compare_at']) ?>
                </td>
                <td class="num u-nowrap u-right"
                    style="color:<?= $row['delta'] > 0 ? '#f0c473' : ($row['delta'] < 0 ? '#8fe0aa' : 'var(--text-dim)') ?>">
                  <?= $row['delta'] === 0 ? '—'
                      : ($row['delta'] > 0 ? '+' : '−') . money(abs($row['delta'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-foot">
        <button class="btn btn--primary" type="submit" name="action" value="apply"
                data-confirm="قیمت محصولات انتخاب‌شده با این نرخ روی سایت اعمال شود؟">
          اعمال روی سایت
        </button>
        <span class="spacer"></span>
        <span class="u-dim">
          تیک هر محصولی را که نمی‌خواهید تغییر کند بردارید.
        </span>
      </div>
    </div>
  </form>
<?php endif; ?>

<div class="split">
  <div>
    <?php if ($autoOn): ?>
    <div class="card">
      <h2 class="card__title">نرخ خودکار</h2>

      <?php
      /* The heartbeat is first because it is the only failure with no other
         symptom. Everything else at least writes a row somewhere. */
      $beat = $rs['cron_age_hours'];
      ?>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-block-end:14px">
        <div style="flex:1 1 190px;padding:10px 12px;border-radius:var(--r-md);
             background:var(--surface-2);
             border:1px solid <?= $rs['cron_healthy'] ? 'var(--success)' : 'var(--danger)' ?>">
          <div class="u-dim" style="font-size:.82rem">آخرین اجرای زمان‌بند</div>
          <div style="font-weight:600;margin-block-start:2px">
            <?php if ($beat === null): ?>
              هرگز اجرا نشده
            <?php elseif ($beat < 1.5): ?>
              همین ساعت
            <?php else: ?>
              <span class="num"><?= e(number_format($beat, 1)) ?></span> ساعت پیش
            <?php endif; ?>
          </div>
          <?php if (!$rs['cron_healthy']): ?>
            <div class="u-dim" style="font-size:.8rem;margin-block-start:4px">
              زمان‌بند باید هر ساعت اجرا شود. اگر بیش از سه ساعت گذشته،
              کرون در cPanel را بررسی کنید.
            </div>
          <?php endif; ?>
        </div>

        <div style="flex:1 1 190px;padding:10px 12px;border-radius:var(--r-md);
             background:var(--surface-2);
             border:1px solid <?= $rs['ready'] ? 'var(--success)' : 'var(--amber)' ?>">
          <div class="u-dim" style="font-size:.82rem">آمادگی قیمت‌گذاری</div>
          <div style="font-weight:600;margin-block-start:2px">
            <?php if ($rs['ready']): ?>
              آماده — برآورد <span class="num"><?= e(number_format((float) $rs['estimate'])) ?></span> تومان
            <?php else: ?>
              فعلاً نه
            <?php endif; ?>
          </div>
          <?php if (!$rs['ready']): ?>
            <div class="u-dim" style="font-size:.8rem;margin-block-start:4px">
              <?= e($rs['ready_reason']) ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="flex:1 1 150px;padding:10px 12px;border-radius:var(--r-md);
             background:var(--surface-2);border:1px solid var(--line)">
          <div class="u-dim" style="font-size:.82rem">خوانش ۲۴ ساعت اخیر</div>
          <div style="font-weight:600;margin-block-start:2px">
            <span class="num"><?= (int) $rs['accepted_24h'] ?></span>
            پذیرفته از
            <span class="num"><?= (int) $rs['polls_24h'] ?></span>
          </div>
        </div>
      </div>

      <?php
      /* What the hourly job does, and what it did at its last check - the
         owner asked on 2026-10-02 for every hourly reading to reach the
         prices there and then (tools/rate-cron.php). */
      /* In Persian digits, like the sentence they sit in: Latin ones with the
         Persian percent sign were drawn the wrong way round. */
      $bandFa   = static fn(float $pct): string =>
          fa_digits(str_replace('.', '٫', rtrim(rtrim(number_format($pct, 1), '0'), '.'))) . '٪';
      $bandUp   = $bandFa(RATE_DEADBAND_UP_PCT);
      $bandDown = $bandFa(RATE_DEADBAND_DOWN_PCT);
      ?>
      <div class="hint" style="margin-block-end:14px;line-height:2.1">
        هر ساعت نرخ تازه خوانده می‌شود و اگر از نرخی که قیمت‌ها با آن ساخته شده‌اند
        <?= e($bandUp) ?> بالاتر یا
        <?= e($bandDown) ?> پایین‌تر باشد،
        همان ساعت روی قیمت همهٔ محصولات درهمی اعمال می‌شود.
        <?php if ($rs['last_check'] !== null && $rs['last_note'] !== ''): ?>
          <br>
          آخرین بررسی
          <span class="num"><?= e(date('H:i', (int) $rs['last_check'])) ?></span>:
          <?= e($rs['last_note']) ?>
        <?php endif; ?>
      </div>

      <?php if (!$rs['armed']): ?>
        <div class="flash flash--warn" style="margin-block-end:12px">
          قیمت‌گذاری خودکار هنوز مسلح نشده. یک‌بار نرخ را از همین صفحه دستی
          اعمال کنید؛ اولین قیمت فروشگاه یک تصمیم است، نه یک خوانش.
        </div>
      <?php endif; ?>

      <?php
      /* A sparkline of the accepted readings, oldest to newest. Drawn inline
         rather than with a charting library: it is twenty numbers, and a
         dependency downloaded by every panel visit to draw twenty numbers is
         not a trade worth making. */
      $spark = [];
      foreach (array_reverse($pollLog) as $p) {
          if ((int) $p['accepted'] === 1 && $p['rate'] !== null) { $spark[] = (float) $p['rate']; }
      }
      if (count($spark) >= 3):
          $lo = min($spark); $hi = max($spark);
          $range = ($hi - $lo) > 0 ? ($hi - $lo) : 1.0;
          $w = 100.0; $h = 26.0;
          $pts = [];
          foreach ($spark as $i => $v) {
              $x = count($spark) > 1 ? ($i / (count($spark) - 1)) * $w : 0;
              $pts[] = round($x, 2) . ',' . round($h - (($v - $lo) / $range) * $h, 2);
          }
      ?>
        <div style="display:flex;align-items:center;gap:10px;margin-block-end:14px">
          <svg viewBox="0 0 100 26" preserveAspectRatio="none"
               style="flex:1;height:44px;direction:ltr" role="img"
               aria-label="روند نرخ درهم">
            <polyline points="<?= e(implode(' ', $pts)) ?>"
                      fill="none" stroke="var(--accent)" stroke-width="1.2"
                      vector-effect="non-scaling-stroke" />
          </svg>
          <div class="u-dim u-nowrap" style="font-size:.8rem;direction:ltr;text-align:left">
            <div class="num"><?= e(number_format($hi)) ?></div>
            <div class="num"><?= e(number_format($lo)) ?></div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!$pollLog): ?>
        <div class="empty">هنوز خوانشی ثبت نشده است.</div>
      <?php else: ?>
        <div class="table-wrap" style="border:0;max-height:340px;overflow:auto">
          <table style="min-width:0">
            <thead>
              <tr><th>زمان</th><th>نرخ</th><th>منبع دوم</th><th>وضعیت</th></tr>
            </thead>
            <tbody>
              <?php foreach ($pollLog as $p): ?>
                <tr>
                  <td class="num u-nowrap u-dim">
                    <?= e(date('m/d H:i', strtotime((string) $p['created_at']))) ?>
                  </td>
                  <td class="num u-nowrap">
                    <?= $p['rate'] === null ? '—' : money((int) $p['rate']) ?>
                  </td>
                  <td class="num u-nowrap u-dim">
                    <?= $p['cross_rate'] === null ? '—' : money((int) $p['cross_rate']) ?>
                  </td>
                  <td>
                    <?php if ((int) $p['accepted'] === 1): ?>
                      <span class="pill pill--new">پذیرفته</span>
                    <?php else: ?>
                      <span class="pill pill--off">رد</span>
                      <div class="u-dim" style="font-size:.78rem;margin-block-start:3px">
                        <?= e($p['reason']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="hint" style="margin-block-start:8px">
          خوانش‌های رد شده هم نگه داشته می‌شوند. بدون آن‌ها «نرخ ثابت مانده» و
          «منبع دو روز است جواب نمی‌دهد» از بیرون یکسان دیده می‌شوند.
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="card__title">تاریخچه نرخ</h2>
      <?php if (!$history): ?>
        <div class="empty">هنوز نرخی اعمال نشده است.</div>
      <?php else: ?>
        <div class="table-wrap" style="border:0">
          <table style="min-width:0">
            <thead>
              <tr><th>تاریخ</th><th>نرخ</th><th>تغییر</th><th>محصولات</th><th>توسط</th></tr>
            </thead>
            <tbody>
              <?php foreach ($history as $h): ?>
                <tr>
                  <td class="num u-nowrap u-dim"><?= e(date('Y/m/d H:i', strtotime($h['created_at']))) ?></td>
                  <td class="num u-nowrap"><?= money((int) $h['rate']) ?></td>
                  <td class="num u-nowrap u-dim">
                    <?php if ($h['previous'] === null): ?>—<?php else: ?>
                      <?php $d = (float) $h['rate'] - (float) $h['previous']; ?>
                      <?= ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . money((int) abs($d)) ?>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= (int) $h['applied_to'] ?></td>
                  <td class="u-dim">
                    <?= e($h['admin_name'] !== '' ? $h['admin_name'] : '—') ?>
                    <?php if (($h['source'] ?? 'manual') !== 'manual'): ?>
                      <span class="pill pill--off" style="margin-inline-start:4px"><?php
                        echo $h['source'] === 'auto-clamped' ? 'خودکار (محدودشده)' : 'خودکار';
                      ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card">
      <h2 class="card__title">چطور کار می‌کند</h2>
      <p style="color:var(--text-muted);line-height:2.1">
        قیمت هر محصول درهمی از این فرمول ساخته می‌شود:
      </p>
      <p style="background:var(--surface-2);padding:14px;border-radius:12px;
                text-align:center;margin-block:12px" class="num">
        (خرید به درهم × نرخ) + سود − تخفیف
      </p>
      <p style="color:var(--text-muted);line-height:2.1">
        هر <strong>اندازه</strong> یک قلم جداگانه است و قیمت خرید خودش را دارد —
        چون بستهٔ ۹۰۰ گرمی و ۲۲۷۰ گرمی دو خرید جدا با دو قیمت جدا هستند.
      </p>
      <p style="color:var(--text-muted);line-height:2.1">
        نتیجه رو به بالا به نزدیک‌ترین
        <span class="num"><?= money(pricing_step()) ?></span>
        تومان گرد می‌شود تا قیمت رُند بماند. گرد کردن همیشه رو به بالاست،
        چون گرد کردن به پایین گاهی سود را از چیزی که تعیین کرده‌اید کمتر می‌کند.
      </p>
      <?php if ($manualCount > 0): ?>
        <p style="color:var(--text-muted);line-height:2.1;margin-block-start:12px">
          <span class="num"><?= $manualCount ?></span>
          محصول فعال، قیمت دستی دارند و این نرخ روی آن‌ها اثری ندارد.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php admin_foot(); ?>
