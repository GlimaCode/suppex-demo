<?php
/* ===========================================================================
   One category — its products, and what the thing actually is
   ---------------------------------------------------------------------------
   Until now a category was a card on the home page that filtered a grid in
   place. It had no URL, so it could not be linked to, shared, bookmarked or
   indexed, and «دسته‌بندی‌ها» in the nav went to an anchor.

   WHAT THIS PAGE IS FOR, said honestly. It will not outrank Digikala for
   «خرید پروتئین وی» — those sites have hundreds of SKUs, an e-namad and years
   of authority, and pretending otherwise would be the same self-flattery this
   codebase spent a commit removing. What it can do is answer the question the
   product grid cannot: what this category does, what dose is effective, and
   who does not need it. That is a page worth having whether or not anybody
   ever finds it through a search engine, because the customer who is already
   here is the one it helps.

   WHAT IS DELIBERATELY NOT HERE
     - No CollectionPage or ItemList markup. schema.org defines CollectionPage
       in one sentence with no properties and no consuming feature; ItemList
       feeds a Product carousel that is limited to the EEA, Turkey and South
       Africa. Neither does anything for a shop in Iran. BreadcrumbList is the
       one thing Google actually consumes here, so that is what is emitted.
     - No Product markup. Structured data must describe what is visibly
       rendered, and the rich cards on this page are painted by app.js from the
       API. The products have their own pages, which carry their own Product
       schema built from the database.
     - No pagination and no rel=next/prev. There is no page two for a
       three-product category, and rel=next/prev has been unused by Google
       since 2019.
   =========================================================================== */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/lib/seo.php';

$slug     = clean_text($_GET['c'] ?? '', 80);
$category = $slug === '' ? null : category_get($slug);

/* The gate is category_get(), not a product count: products_list() filters on
   c.slug alone and never looks at c.is_active, so a category hidden in the
   panel would otherwise still publish its products. */
if ($category === null) {
    http_response_code(404);
}

$products = $category === null ? [] : products_list(['category' => $slug, 'limit' => 60]);
$guide    = $category === null ? null : category_guide($slug);
$others   = $category === null ? [] : array_values(array_filter(
    categories_list(),
    static fn(array $c): bool => $c['id'] !== $slug
));

$shop  = setting('shop_name', 'SUPPEX');
$name  = $category === null ? '' : (string) $category['name'];
$blurb = $category === null ? '' : trim((string) $category['blurb']);

/* «خرید و قیمت X» is the pattern every Persian shop ranking for these terms
   uses, and it matches what people type. What is NOT copied from them is the
   authenticity vocabulary they all pile on — «اصل»، «اورجینال»، «ضمانت اصالت» —
   because a title is not the place to make a claim the shop has just finished
   removing from everywhere else. */
$title = $category === null
    ? 'دسته‌بندی پیدا نشد'
    : 'خرید و قیمت ' . $name;

$desc = $category === null
    ? 'این دسته‌بندی وجود ندارد یا دیگر نمایش داده نمی‌شود.'
    : seo_clip($blurb !== '' ? $blurb : ($guide['summary'] ?? $name));

$url   = seo_category_url($slug);
$image = seo_absolute($category !== null && $category['image'] !== ''
    ? $category['image'] : 'assets/images/og-cover.png');

/* An empty category is noindex, on Google's own explicit instruction: "If a
   category has no items, use a noindex robots meta tag". sitemap.php drops it
   in the same breath, so the two cannot disagree. */
$isEmpty = $category !== null && count($products) === 0;
$robots  = $category === null ? 'noindex, follow'
         : ($isEmpty ? 'noindex, follow' : 'index, follow');

/* BreadcrumbList is the only structured data on this page, because it is the
   only kind Google actually consumes for a listing. */
$jsonld = '';
if ($category !== null) {
    $jsonld = json_encode([
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $shop,
             'item' => seo_origin() . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $name,
             'item' => $url],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#131210">

<link rel="icon" href="assets/icons/favicon.svg" type="image/svg+xml">

<link rel="stylesheet" href="assets/css/tokens.css">
<link rel="stylesheet" href="assets/css/base.css">
<link rel="stylesheet" href="assets/css/components.css">
<link rel="stylesheet" href="assets/css/layout.css">

<title><?= e($title) ?> | <?= e($shop) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="<?= e($robots) ?>">
<link rel="canonical" href="<?= e($url) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($shop) ?>">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:image" content="<?= e($image) ?>">
<meta name="twitter:card" content="summary_large_image">

<?php if ($jsonld !== ''): ?>
<script type="application/ld+json">
<?= $jsonld ?>

</script>
<?php endif; ?>
</head>
<?php include __DIR__ . '/partials/chrome-top.php'; ?>

<main id="main">
<?php if ($category === null): ?>
  <section class="section">
    <div class="container">
      <div class="notfound">
        <h1 class="notfound__title">دسته‌بندی پیدا نشد</h1>
        <p class="u-sm u-dim">ممکن است نشانی را اشتباه وارد کرده باشید یا این دسته دیگر نمایش داده نمی‌شود.</p>
        <p style="margin-top:18px"><a class="btn btn--primary" href="index.html#categories">دیدن همه دسته‌بندی‌ها</a></p>
      </div>
    </div>
  </section>
<?php else: ?>
  <section class="section">
    <div class="container">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="index.html">خانه</a>
        <span aria-hidden="true">/</span>
        <span><?= e($name) ?></span>
      </nav>

      <div class="section__head" style="margin-block-start:var(--sp-6)">
        <div>
          <p class="u-eyebrow">دسته‌بندی</p>
          <!-- Short, and just the name. The full «خرید و قیمت …» belongs in the
               title tag; repeating it as an h1 reads as a page talking to a
               search engine rather than to the person on it. -->
          <h1 class="u-h2" style="margin-top:8px"><?= e($name) ?></h1>
          <?php if ($blurb !== ''): ?>
            <!-- The blurb has been in the categories table, seeded, editable in
                 the panel and returned by the API since the beginning, and no
                 template ever rendered it. It is already written and already
                 vetted, which beats inventing a lede here. -->
            <p class="u-sm u-muted" style="max-width:56ch;margin-top:12px;line-height:1.95"><?= e($blurb) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isEmpty): ?>
        <div class="empty" style="margin-block-start:var(--sp-8)">
          فعلاً محصولی در این دسته موجود نیست.
        </div>
      <?php else: ?>
        <!-- Server-rendered links, replaced by app.js with the full cards.
             Not decoration: these are the only product links a crawler or a
             reader without JavaScript gets, and the rich card markup lives in
             ui.js where it belongs rather than being copied into PHP to drift. -->
        <div class="grid grid--4" data-cat-products style="margin-block-start:var(--sp-8)">
          <?php foreach ($products as $p): ?>
            <article class="pcard">
              <a class="media media--1x1 media--lit" href="<?= e('product.php?p=' . rawurlencode((string) $p['slug'])) ?>">
                <img src="<?= e((string) $p['image']) ?>" alt="<?= e((string) $p['nameFa']) ?>" loading="lazy" width="400" height="400">
              </a>
              <div class="pcard__body">
                <div class="pcard__brand lat"><?= e((string) $p['brand']) ?></div>
                <h2 class="pcard__title">
                  <a href="<?= e('product.php?p=' . rawurlencode((string) $p['slug'])) ?>"><?= e((string) $p['nameFa']) ?></a>
                </h2>
                <div class="pcard__foot">
                  <span class="price"><span class="num"><?= money((int) $p['price']) ?></span> تومان</span>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($guide !== null): ?>
        <!-- Below the grid on purpose. Somebody who came to buy scrolls past
             it; somebody deciding whether they need this at all reads it. The
             short lede above the grid is the blurb. -->
        <div class="article" style="margin-block-start:var(--sp-10)">
          <div class="container container--narrow" style="padding:0">
            <?php if ($guide['heading'] !== ''): ?>
              <h2 class="u-h2" style="font-size:clamp(1.4rem,1.1rem+1.2vw,1.9rem)"><?= e($guide['heading']) ?></h2>
            <?php endif; ?>
            <div class="article__body"><?= journal_render($guide['body']) ?></div>

            <?php if ($guide['sources']): ?>
              <section class="article__sources" aria-labelledby="cat-sources">
                <h2 id="cat-sources">منابع</h2>
                <!-- Every one was checked against Crossref before it was
                     printed. They are listed so a reader can check them too. -->
                <ol>
                  <?php foreach ($guide['sources'] as $s): ?>
                    <li><?= e((string) $s) ?></li>
                  <?php endforeach; ?>
                </ol>
              </section>
            <?php endif; ?>

            <p class="article__note">
              این متن آموزشی است و جای مشورت با پزشک یا متخصص تغذیه را نمی‌گیرد.
            </p>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($others): ?>
        <nav class="page-more" aria-labelledby="other-cats" style="margin-block-start:var(--sp-10)">
          <h2 id="other-cats" class="u-eyebrow">دسته‌بندی‌های دیگر</h2>
          <ul>
            <?php foreach ($others as $o): ?>
              <li>
                <a href="<?= e('category.php?c=' . rawurlencode((string) $o['id'])) ?>"><?= e((string) $o['name']) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </nav>
      <?php endif; ?>

    </div>
  </section>
<?php endif; ?>
</main>

<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
