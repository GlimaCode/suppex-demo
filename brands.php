<?php
/* ===========================================================================
   Every brand the shop carries - «برندها»
   ---------------------------------------------------------------------------
   The whole logo wall. The home page shows the first BRANDS_HOME_MAX and
   links here; so do the menus. Drawn in full on the server: it is short, it
   is the page a crawler follows to reach every brand page, and it needs no
   JavaScript at all.

   Which brands, and in what order, is lib/brands.php's: the ones with
   something on sale that the owner has not hidden, in the owner's order and
   then by how many products each has.
   =========================================================================== */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/seo.php';

$brands = brands_list();
$shop   = setting('shop_name', 'SUPPEX');
$title  = 'برندها';
/* The tab and the search result say what the list is of; the breadcrumb
   and the page keep the one word. */
$docTitle = 'برندهای مکمل ورزشی';
$names  = array_map(static fn(array $b): string => $b['nameFa'] !== '' ? $b['nameFa'] : $b['name'], $brands);
$desc   = $brands
    ? seo_clip('برندهایی که در ' . $shop . ' محصول دارند: ' . implode('، ', $names) . '.')
    : 'برندهای محصولات ' . $shop . '.';
$url    = seo_origin() . '/brands.php';

$jsonld = json_encode([
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => $shop, 'item' => seo_origin() . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $url],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#131210">

<?php include __DIR__ . '/partials/head-assets.php'; ?>


<title><?= e($docTitle) ?> | <?= e($shop) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<!-- An empty wall is not a page anybody should land on from a search. -->
<meta name="robots" content="<?= $brands ? 'index, follow' : 'noindex, follow' ?>">
<link rel="canonical" href="<?= e($url) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($shop) ?>">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="<?= e($docTitle) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:image" content="<?= e(seo_cover()) ?>">
<meta name="twitter:card" content="summary_large_image">

<script type="application/ld+json">
<?= $jsonld ?>

</script>
</head>
<?php include __DIR__ . '/partials/chrome-top.php'; ?>

<main id="main">
  <section class="section">
    <div class="container">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="./">خانه</a>
        <span aria-hidden="true">/</span>
        <span><?= e($title) ?></span>
      </nav>

      <div class="section__head" style="margin-block-start:var(--sp-6)">
        <div>
          <p class="u-eyebrow">برندها</p>
          <h1 class="u-h2" style="margin-top:8px">خرید بر اساس برند</h1>
          <p class="u-sm u-muted" style="max-width:56ch;margin-top:12px;line-height:1.95">
            روی هر برند بزنید تا همه محصولاتی را که از آن داریم ببینید.
          </p>
        </div>
      </div>

      <?php if (!$brands): ?>
        <div class="empty" style="margin-block-start:var(--sp-8)">فعلاً برندی برای نمایش نیست.</div>
      <?php else: ?>
        <div class="brands" style="margin-block-start:var(--sp-8)">
          <?php foreach ($brands as $b): ?>
            <?= brand_card_html($b) ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
