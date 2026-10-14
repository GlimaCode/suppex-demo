<?php
/* ===========================================================================
   One brand - the products the shop carries from it
   ---------------------------------------------------------------------------
   Reached from the logo wall at the foot of the home page, from brands.php,
   and from the brand's name on a product page. What makes a brand is in
   lib/brands.php: the text on the products, every spelling of it together,
   with the logo and the Persian name the owner added in the panel.

   The rest is the category page's reasoning, and the same conclusions:
     - BreadcrumbList is the only structured data. A Brand or Organization
       block here would be the shop describing a manufacturer it is not.
     - The product links are drawn here, and app.js swaps them for the full
       cards. They are what a crawler, and a reader without JavaScript, gets.
     - Unknown, hidden, or with nothing on sale is a 404, and sitemap.php
       leaves out exactly the same brands, through the same brands_list().
   =========================================================================== */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/seo.php';

$brand = brand_get(clean_text($_GET['b'] ?? '', 120));
if ($brand === null) {
    http_response_code(404);
}

$products = $brand === null ? [] : products_list(['brand' => $brand['slug'], 'limit' => 60]);

/* The list initBrandPage() asks for, sent with the page (lib/boot.php). Keyed
   by the ?b= the script reads, so only when that is the brand's own slug; any
   other spelling is left to the API, which answers it as it always did. */
$bootRequests = ($brand !== null && (string) ($_GET['b'] ?? '') === (string) $brand['slug'])
    ? [['products', ['brand' => $brand['slug'], 'limit' => 60], $products]]
    : [];
$others   = $brand === null ? [] : array_values(array_filter(
    brands_list(),
    static fn(array $b): bool => $b['slug'] !== $brand['slug']
));

$shop   = setting('shop_name', 'SUPPEX');
$name   = $brand === null ? '' : $brand['name'];
$nameFa = $brand === null ? '' : $brand['nameFa'];
/* What a Persian reader calls it, when the owner has said. */
$label  = $nameFa !== '' ? $nameFa : $name;
$count  = count($products);

/* The category page's «خرید و قیمت …», for the same reason: it is what people
   type. No «اصل» or «اورجینال» - see category.php. */
$title = $brand === null ? 'برند پیدا نشد' : 'خرید و قیمت محصولات ' . $label;
/* The owner's blurb when there is one. Otherwise what the page actually
   lists: «محصولات دایماتیز (Dymatize) در SUPPEX.» told a search result
   nothing a reader could choose by, and the product names are what they
   were searching for. */
$desc  = $brand === null
    ? 'این برند در فروشگاه نیست یا دیگر نمایش داده نمی‌شود.'
    : seo_clip($brand['blurb'] !== ''
        ? $brand['blurb']
        : 'محصولات ' . $label . ($nameFa !== '' ? ' (' . $name . ')' : '') . ' در ' . $shop
          . ($products
              ? ': ' . implode('، ', array_map(static fn(array $p): string => (string) $p['nameFa'],
                                               array_slice($products, 0, 6))) . '.'
              : '.'));

/* The normalised slug: ?b=MuscleTech and ?b=muscletech are one page, and this
   says which address is its own. */
$url = $brand === null ? seo_origin() . '/brands.php' : seo_origin() . '/' . brand_url($brand['slug']);

$jsonld = '';
if ($brand !== null) {
    $jsonld = json_encode([
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $shop, 'item' => seo_origin() . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'برندها', 'item' => seo_origin() . '/brands.php'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $label, 'item' => $url],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#131210">

<?php include __DIR__ . '/partials/head-assets.php'; ?>


<title><?= e($title) ?> | <?= e($shop) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="<?= $brand === null ? 'noindex, follow' : 'index, follow' ?>">
<link rel="canonical" href="<?= e($url) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($shop) ?>">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<!-- The shop's cover, not the logo: a logo is a transparent PNG more often
     than not, and a link preview draws that on black. -->
<meta property="og:image" content="<?= e(seo_cover()) ?>">
<meta name="twitter:card" content="summary_large_image">

<?php if ($jsonld !== ''): ?>
<script type="application/ld+json">
<?= $jsonld ?>

</script>
<?php endif; ?>
</head>
<?php include __DIR__ . '/partials/chrome-top.php'; ?>

<main id="main">
<?php if ($brand === null): ?>
  <section class="section">
    <div class="container">
      <div class="notfound">
        <h1 class="notfound__title">برند پیدا نشد</h1>
        <p class="u-sm u-dim">ممکن است نشانی را اشتباه وارد کرده باشید یا محصولی از این برند دیگر در فروشگاه نباشد.</p>
        <p style="margin-top:18px"><a class="btn btn--primary" href="brands.php">دیدن همه برندها</a></p>
      </div>
    </div>
  </section>
<?php else: ?>
  <section class="section">
    <div class="container">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="./">خانه</a>
        <span aria-hidden="true">/</span>
        <a href="brands.php">برندها</a>
        <span aria-hidden="true">/</span>
        <span><?= e($label) ?></span>
      </nav>

      <header class="brand-head">
        <span class="brand-head__plate bcard__plate">
          <?php if ($brand['logo'] !== ''): ?>
            <img class="bcard__logo" src="<?= e($brand['logo']) ?>" alt="<?= e($name) ?>" width="240" height="120">
          <?php else: ?>
            <span class="bcard__word lat" dir="ltr"><?= e($name) ?></span>
          <?php endif; ?>
        </span>
        <div class="brand-head__text">
          <p class="u-eyebrow">برند</p>
          <h1 class="u-h2 brand-head__title"><?= e($label) ?></h1>
          <?php if ($nameFa !== ''): ?>
            <p class="brand-head__latin lat" dir="ltr"><?= e($name) ?></p>
          <?php endif; ?>
          <p class="brand-head__count"><span class="num"><?= e(fa_digits((string) $count)) ?></span>&nbsp;محصول</p>
          <?php if ($brand['blurb'] !== ''): ?>
            <p class="brand-head__blurb"><?= e($brand['blurb']) ?></p>
          <?php endif; ?>
        </div>
      </header>

      <!-- The same links the category page draws, and for the same reason:
           app.js replaces them with the full cards, and until then - or
           without JavaScript at all - these are the way to each product. -->
      <div class="grid grid--4" data-brand-products style="margin-block-start:var(--sp-8)">
        <?php foreach ($products as $p): ?>
          <article class="pcard">
            <a class="media media--1x1 media--lit" href="<?= e('product.php?p=' . rawurlencode((string) $p['slug'])) ?>">
              <img src="<?= e(product_image_or_placeholder((string) $p['image'])) ?>"<?= image_srcset_attr((string) $p['image'], '(min-width: 1100px) 270px, (min-width: 700px) 31vw, 47vw') ?> alt="<?= e((string) $p['nameFa']) ?>" loading="lazy" decoding="async" width="400" height="400">
            </a>
            <div class="pcard__body">
              <div class="pcard__brand lat"><?= e((string) $p['brand']) ?></div>
              <h2 class="pcard__title">
                <a href="<?= e('product.php?p=' . rawurlencode((string) $p['slug'])) ?>"><?= e((string) $p['nameFa']) ?></a>
              </h2>
              <?php if (($fact = product_key_fact($p)) !== ''): ?><p class="pcard__fact"><?= e($fact) ?></p><?php endif; ?>
              <div class="pcard__foot">
                <span class="price"><span class="price__now"><span class="num"><?= money_fa((int) $p['price']) ?></span>&nbsp;<?= price_unit_html() ?></span></span>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($others): ?>
        <nav class="brands-more" aria-labelledby="other-brands">
          <h2 id="other-brands" class="brands-more__title">برندهای دیگر</h2>
          <div class="brands brands--sm">
            <?php foreach ($others as $o): ?>
              <?= brand_card_html($o) ?>
            <?php endforeach; ?>
          </div>
        </nav>
      <?php endif; ?>

    </div>
  </section>
<?php endif; ?>
</main>

<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
