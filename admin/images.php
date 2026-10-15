<?php
/* ===========================================================================
   SUPPEX admin — bulk product photographs
   ---------------------------------------------------------------------------
   The catalogue's pictures live on brand sites and their CDNs, and this server
   cannot fetch them: of twelve representative hosts it reached one — six timed
   out at the network layer and five answered 403 to an Iranian address. So the
   downloading happens elsewhere and the files arrive as a folder.

   Drop them into uploads/products/bulk/ with the file manager — one drag rather
   than a hundred and twenty-four browser uploads — and this page matches each
   file to a product, shows you what it matched, and attaches only what you tick.

   Never attaches on its own. The owner's words: none of the products currently
   have a picture that matches their name and model and type, and a wrong
   photograph on a supplement is a customer receiving something they did not
   order — in a shop with no card authorisation to reverse.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/images.php';
require_once __DIR__ . '/partials/layout.php';

$user = auth_require();

/** Where the folder is dropped. Relative to uploads/, so it sits beside the
    month folders the ordinary uploader writes into. */
const BULK_DIR = 'products/bulk';

$bulkPath = uploads_dir() . '/' . BULK_DIR;

/**
 * Is this a photograph the owner supplied, rather than a bundled placeholder?
 *
 * The same test lib/preflight.php applies, in one place so the two cannot
 * drift into disagreeing about what «has a picture» means.
 */
function image_is_real(string $path): bool
{
    $path = trim($path);
    return $path !== '' && !str_ends_with(strtolower($path), '.svg');
}

/**
 * A product name reduced to something two spellings of it still share.
 *
 * The map is typed from the catalogue sheet and the panel's own name may have
 * been edited since — a space added, a ك swapped for a ک. Comparing the raw
 * strings would report a mismatch for a pair a person reads as identical.
 */
function image_key(string $name): string
{
    /* Arabic kaf and yeh to their Persian forms, then every run of anything
       that is not a letter or a number collapses away entirely. */
    $name = strtr(trim($name), ['ك' => 'ک', 'ي' => 'ی', '‌' => '']);
    $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
    return mb_strtolower($name);
}

/* --------------------------------------------------------------------------
   Lighter copies of the photographs already on the site
   --------------------------------------------------------------------------
   A new upload gets them at once (image_store()); these are for what was
   there before, and for what the bulk import below attaches. Admin pages
   also catch up a few quietly after they have been sent (admin_foot()), so
   this button is only for not waiting. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'variants') {
    csrf_check();
    $made = image_variants_backfill(20.0);
    flash($made['pending'] > 0 ? 'info' : 'ok',
        'نسخه‌های سبک ' . $made['done'] . ' تصویر از ' . $made['total'] . ' آماده است'
        . ($made['pending'] > 0
            ? ' — ' . $made['pending'] . ' تصویر مانده؛ دکمه را یک بار دیگر بزنید.'
            : '.'));
    header('Location: images.php');
    exit;
}

/* --------------------------------------------------------------------------
   Applying
   -------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $picked = (array) ($_POST['attach'] ?? []);
    $done   = 0;
    $failed = 0;

    foreach ($picked as $file => $productId) {
        /* basename() and nothing else from the request reaches the filesystem:
           the file name arrives from a form and «../../» is a directory
           traversal waiting to be written to. */
        $file      = basename((string) $file);
        $productId = (int) $productId;
        if ($file === '' || $productId <= 0) { continue; }

        $from = $bulkPath . '/' . $file;
        if (!is_file($from)) { $failed++; continue; }

        /* Validated even though this folder was filled by hand: getimagesize()
           is what tells a picture from anything else somebody dropped in. */
        $info = @getimagesize($from);
        if ($info === false) { $failed++; continue; }

        $product = db_one('SELECT id, image FROM products WHERE id = ?', [$productId]);
        if ($product === null) { $failed++; continue; }

        /* Into the same month folders as every other upload, under a fresh
           random name. Keeping the incoming name would let two imports of the
           same catalogue overwrite each other's files. */
        $sub = date('Y/m');
        $dir = uploads_dir() . '/products/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            flash('error', 'پوشه آپلود قابل نوشتن نیست.');
            break;
        }

        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: 'webp';
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@copy($from, $dir . '/' . $name)) { $failed++; continue; }

        $rel  = 'uploads/products/' . $sub . '/' . $name;
        $sort = (int) db_value(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?',
            [$productId]);
        db_query('INSERT INTO product_images (product_id, path, sort_order) VALUES (?, ?, ?)',
                 [$productId, $rel, $sort]);

        /* The card image is set when there is no REAL one. A product somebody
           has already photographed keeps what they chose - but a bundled .svg
           placeholder is not a photograph, and leaving it in place would put a
           real picture in the gallery while the card and the catalogue went on
           showing the drawing. */
        if (!image_is_real((string) $product['image'])) {
            db_query('UPDATE products SET image = ? WHERE id = ?', [$rel, $productId]);
        }

        /* Taken out of the drop folder so a second visit does not offer it
           again — the file is now in the gallery, and a duplicate would be
           indistinguishable from a new one. */
        @unlink($from);
        $done++;
    }

    flash($failed ? 'error' : 'ok',
        $done . ' تصویر به محصولات وصل شد'
        . ($failed ? ' — ' . $failed . ' مورد ناموفق بود.' : '.'));
    header('Location: images.php');
    exit;
}

/* --------------------------------------------------------------------------
   What is waiting in the folder, and what it matches
   -------------------------------------------------------------------------- */
$rows    = [];
$unknown = [];
$missing = [];

if (is_dir($bulkPath)) {
    /* Every product, keyed by the slug of its Persian name. Used when a file
       is named after the product it belongs to — which is what somebody
       photographing their own stock would naturally do. */
    $bySlug = [];
    $byName = [];
    foreach (db_all('SELECT id, name_fa, brand, image FROM products ORDER BY id') as $p) {
        $bySlug[slugify((string) $p['name_fa'])] = $p;
        $byName[image_key((string) $p['name_fa'])] = $p;
    }

    /* A map shipped beside the pictures wins over the filename.

       The catalogue's photographs arrive with names like p001.webp for a
       reason: a Persian filename does not survive the trip. cPanel's
       extractor ignored the zip's UTF-8 flag, decoded the names as a legacy
       codepage, and a hundred and nineteen files landed unreadable and
       matched nothing. An ASCII name plus a CSV cannot be mangled by
       anything between here and the browser. */
    $mapped  = [];
    $mapFile = $bulkPath . '/image-map.csv';
    if (is_file($mapFile) && ($fh = @fopen($mapFile, 'r')) !== false) {
        $head = fgetcsv($fh);
        if ($head && isset($head[0])) {
            $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
        }
        $cols = $head ? array_flip($head) : [];
        while (($r = fgetcsv($fh)) !== false) {
            $f = trim((string) ($r[$cols['file'] ?? 0] ?? ''));
            $n = trim((string) ($r[$cols['name_fa'] ?? 1] ?? ''));
            if ($f !== '' && $n !== '') { $mapped[$f] = $n; }
        }
        fclose($fh);
    }

    $seen = [];
    foreach (glob($bulkPath . '/*') ?: [] as $path) {
        if (!is_file($path)) { continue; }
        $file = basename($path);
        $stem = pathinfo($file, PATHINFO_FILENAME);

        /* The map itself is not a picture and must not be reported as junk. */
        if (strcasecmp($file, 'image-map.csv') === 0) { continue; }

        $info = @getimagesize($path);
        if ($info === false) {
            $unknown[] = ['file' => $file, 'why' => 'تصویر نیست'];
            continue;
        }

        $match = null;
        if (isset($mapped[$file])) {
            $match = $byName[image_key($mapped[$file])] ?? null;
            if ($match === null) {
                $unknown[] = ['file' => $file,
                              'why' => 'نقشه می‌گوید «' . $mapped[$file]
                                     . '» — محصولی با این نام در پنل نیست'];
                continue;
            }
        } else {
            $match = $bySlug[$stem] ?? null;
        }

        if ($match === null) {
            $unknown[] = ['file' => $file, 'why' => 'محصولی با این نام پیدا نشد'];
            continue;
        }

        $seen[(int) $match['id']] = true;
        $rows[] = [
            'file'    => $file,
            'w'       => $info[0],
            'h'       => $info[1],
            'kb'      => (int) round(filesize($path) / 1024),
            'product' => $match,
            /* A product that already has a REAL photograph is offered
               unticked: the owner chose that one, and replacing it has to be
               a decision rather than a default.

               A bundled .svg render is not one. lib/preflight.php draws the
               same line - «عکس واقعی محصول» counts an svg as missing - and if
               these two disagreed, every product still on a placeholder would
               arrive unticked and be skipped by an owner trusting the
               defaults. */
            'hasImage' => image_is_real((string) $match['image']),
        ];
    }

    /* Active products still without a photograph and without a file waiting —
       the gap this page cannot close, said plainly rather than left to be
       discovered on the shop. */
    foreach (db_all("SELECT id, name_fa FROM products
                      WHERE is_active = 1 AND (image = '' OR image LIKE '%.svg')
                      ORDER BY name_fa") as $p) {
        if (!isset($seen[(int) $p['id']])) { $missing[] = $p; }
    }
}

usort($rows, static fn($a, $b) => strcmp((string) $a['product']['name_fa'],
                                         (string) $b['product']['name_fa']));

$variantTotal   = 0;
$variantPending = 0;
foreach (image_originals() as $orig) {
    $variantTotal++;
    if (image_needs_variants($orig)) {
        $variantPending++;
    }
}

admin_head('تصاویر محصولات', ['user' => $user]);
?>

<div class="card">
  <h2 class="card__title">نسخه‌های سبک تصاویر</h2>
  <p class="hint">
    برای هر عکس محصول، نسخه‌های کوچک‌تر (WebP) ساخته می‌شود تا کارت‌ها و
    تصاویر کوچک صفحهٔ محصول به‌جای عکس اصلی ۲۰۰ کیلوبایتی، نسخهٔ چندکیلوبایتی
    را بارگیری کنند — که سرعت سایت و امتیاز آن در گوگل را بالا می‌برد.
    عکس‌های تازه خودشان نسخه می‌گیرند.
  </p>
  <p style="margin-block:12px">
    <?php if ($variantTotal === 0): ?>
      هنوز عکسی آپلود نشده.
    <?php elseif ($variantPending === 0): ?>
      <strong style="color:var(--success)">همهٔ <?= e(fa_digits((string) $variantTotal)) ?> عکس نسخهٔ سبک دارند.</strong>
    <?php else: ?>
      <strong><?= e(fa_digits((string) $variantPending)) ?></strong> عکس از
      <?= e(fa_digits((string) $variantTotal)) ?> هنوز نسخهٔ سبک ندارد.
    <?php endif; ?>
  </p>
  <?php if ($variantPending > 0): ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="variants">
      <button class="btn btn--primary" type="submit">ساختن نسخه‌های سبک</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2 class="card__title">ورود گروهی تصاویر</h2>

  <?php if (!is_dir($bulkPath)): ?>
    <p class="hint">
      پوشه <code dir="ltr">uploads/products/bulk/</code> هنوز ساخته نشده.
      در فایل‌منیجر سی‌پنل بسازیدش و تصویرها را داخلش بریزید.
    </p>
  <?php elseif (!$rows && !$unknown): ?>
    <p class="hint">
      پوشه <code dir="ltr">uploads/products/bulk/</code> خالی است.
      تصویرها را با فایل‌منیجر داخلش بریزید و همین صفحه را دوباره باز کنید.
    </p>
  <?php endif; ?>

  <p class="hint" style="margin-block-start:10px">
    نام هر فایل باید همان نام فارسی محصول باشد — فایل <code dir="ltr">image-map.csv</code>
    که همراه تصویرها می‌آید همین را می‌گوید. هیچ تصویری خودش وصل نمی‌شود؛
    فقط ردیف‌هایی که تیک بزنید.
  </p>
</div>

<?php if ($rows): ?>
<form method="post">
  <?= csrf_field() ?>
  <div class="card">
    <h2 class="card__title">
      <?= count($rows) ?> تصویر آماده است
    </h2>

    <p class="hint" style="margin-block-end:14px">
      تصویر را با نام محصول مقابلش مقایسه کنید: مدل و نوع و اندازه باید بخواند،
      نه فقط برند. هر ردیفی که مطمئن نیستید را تیکش را بردارید.
    </p>

    <table class="t">
      <thead>
        <tr>
          <th style="width:34px"></th>
          <th style="width:88px">تصویر</th>
          <th>محصول</th>
          <th>برند</th>
          <th class="num">ابعاد</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <input type="checkbox"
                   name="attach[<?= e($r['file']) ?>]"
                   value="<?= (int) $r['product']['id'] ?>"
                   <?= $r['hasImage'] ? '' : 'checked' ?>>
          </td>
          <td>
            <img src="<?= e(uploads_url() . '/' . BULK_DIR . '/' . rawurlencode($r['file'])) ?>"
                 alt="" width="72" height="72" loading="lazy"
                 style="width:72px;height:72px;object-fit:contain;border-radius:8px;
                        background:var(--surface-2)">
          </td>
          <td>
            <?= e((string) $r['product']['name_fa']) ?>
            <?php if ($r['hasImage']): ?>
              <br><span class="hint">از قبل تصویر دارد — تیک نخورده تا اشتباهی جایگزین نشود</span>
            <?php endif; ?>
          </td>
          <td class="u-dim"><?= e((string) $r['product']['brand']) ?></td>
          <td class="num u-nowrap"><?= (int) $r['w'] ?>×<?= (int) $r['h'] ?> · <?= (int) $r['kb'] ?>KB</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="row" style="gap:12px;margin-block-start:16px;align-items:center">
      <button class="btn btn--primary" type="submit">وصل کردن تیک‌خورده‌ها</button>
      <button class="btn btn--ghost btn--sm" type="button" data-tick="1">تیک همه</button>
      <button class="btn btn--ghost btn--sm" type="button" data-tick="0">برداشتن همه</button>
    </div>
  </div>
</form>
<?php endif; ?>

<?php if ($unknown): ?>
<div class="card">
  <h2 class="card__title"><?= count($unknown) ?> فایل بی‌صاحب</h2>
  <p class="hint" style="margin-block-end:12px">
    این‌ها در پوشه هستند ولی به محصولی نخوردند. نام فایل باید دقیقاً نام فارسی
    محصول باشد؛ اگر محصول را در پنل با نام دیگری ثبت کرده‌اید، نام فایل را
    عوض کنید یا نام محصول را.
  </p>
  <ul class="hint" style="line-height:2">
    <?php foreach ($unknown as $u): ?>
      <li><code dir="ltr"><?= e($u['file']) ?></code> — <?= e($u['why']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($missing): ?>
<div class="card">
  <h2 class="card__title"><?= count($missing) ?> محصول فعال بدون تصویر</h2>
  <p class="hint" style="margin-block-end:12px">
    برای این‌ها فایلی در پوشه نیست. تا وقتی تصویر نگیرند، روی سایت با طرح
    نمونه دیده می‌شوند.
  </p>
  <ul class="hint" style="line-height:2">
    <?php foreach ($missing as $m): ?>
      <li><?= e((string) $m['name_fa']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<script>
/* Tick all / none. A hundred and twenty-four checkboxes is not something to
   click one at a time when the answer is «all of these are fine». */
document.querySelectorAll('[data-tick]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var on = btn.dataset.tick === '1';
    document.querySelectorAll('input[type="checkbox"][name^="attach["]')
      .forEach(function (c) { c.checked = on; });
  });
});
</script>

<?php admin_foot(); ?>
