<?php
/* ===========================================================================
   One standing page, rendered from content/pages/<slug>.md
   ---------------------------------------------------------------------------
   Shipping, returns, FAQ, terms, privacy. Every link to these was href="#" on
   every template since the project began.

   Server-rendered and indexable, unlike track.php. These pages are the answer
   to the question a first-time visitor actually has about a shop with no
   payment gateway - "what happens if this goes wrong" - and that answer is
   worth nothing if it only exists after JavaScript runs, because the person
   asking it is often reading a search result or a link preview.

   The chrome comes from partials/, so the footer links on this page are the
   same footer links as everywhere else by construction rather than by someone
   remembering.
   =========================================================================== */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/lib/seo.php';

$slug = clean_text($_GET['p'] ?? '', 64);
$page = $slug === '' ? null : page_get($slug);

/* A missing page answers 404 rather than 200 with an empty frame: a soft 404
   gets indexed as a real page. */
if ($page === null) {
    http_response_code(404);
}

$title = $page === null ? 'صفحه پیدا نشد' : $page['title'];
$desc  = $page === null
    ? 'این صفحه وجود ندارد یا نشانی آن تغییر کرده است.'
    : seo_clip($page['summary'] !== '' ? $page['summary'] : $page['title']);
$url      = seo_origin() . '/' . page_url($slug);
$image    = seo_absolute('assets/images/og-cover.png');
$bodyHtml = $page === null ? '' : journal_render($page['body']);

/* WebPage, not Article. These are not editorial: they are the terms the shop
   trades under, and labelling them as articles would put them in the wrong
   place in every tool that reads the markup. */
$jsonld = '';
if ($page !== null) {
    $jsonld = json_encode([
        '@context'    => 'https://schema.org',
        '@type'       => 'WebPage',
        'name'        => $page['title'],
        'description' => $page['summary'],
        'inLanguage'  => 'fa-IR',
        '@id'         => $url,
        'url'         => $url,
        'isPartOf'    => [
            '@type' => 'WebSite',
            'name'  => setting('shop_name', 'SUPPEX'),
            'url'   => seo_origin() . '/',
        ],
        'publisher'   => [
            '@type' => 'Organization',
            'name'  => setting('shop_name', 'SUPPEX'),
            'logo'  => ['@type' => 'ImageObject', 'url' => seo_absolute('assets/icons/logo.svg')],
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

<title><?= e($title) ?> | <?= e(setting('shop_name', 'SUPPEX')) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="<?= $page === null ? 'noindex, follow' : 'index, follow' ?>">
<link rel="canonical" href="<?= e($url) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(setting('shop_name', 'SUPPEX')) ?>">
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
<?php if ($page === null): ?>
  <section class="section">
    <div class="container">
      <div class="notfound">
        <!-- h1, like 404.html and product.php. A page with no h1 gives a
             screen-reader user nothing to jump to and tells a crawler the page
             has no subject - and an error page is exactly where somebody is
             already lost. article.php had this as a <p> and page.php inherited
             it by being written from the same shape. -->
        <h1 class="notfound__title">صفحه پیدا نشد</h1>
        <p class="u-sm u-dim">ممکن است نشانی را اشتباه وارد کرده باشید یا این صفحه دیگر وجود نداشته باشد.</p>
        <p style="margin-top:18px"><a class="btn btn--primary" href="index.html">بازگشت به فروشگاه</a></p>
      </div>
    </div>
  </section>
<?php else: ?>
  <article class="article">
    <div class="container container--narrow">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="index.html">خانه</a>
        <span aria-hidden="true">/</span>
        <span><?= e($page['title']) ?></span>
      </nav>

      <header class="article__head">
        <h1 class="article__title"><?= e($page['title']) ?></h1>
        <?php if ($page['summary'] !== ''): ?>
          <p class="article__lede"><?= e($page['summary']) ?></p>
        <?php endif; ?>
        <?php if ($page['updated'] !== ''): ?>
          <!-- A policy with no date is a policy nobody can tell has been kept
               up, and on the page where someone is deciding whether to trust
               the shop with a bank transfer that matters more than it looks. -->
          <p class="u-xs u-dim" style="margin-block-start:10px">
            آخرین به‌روزرسانی: <span class="num"><?= e($page['updated']) ?></span>
          </p>
        <?php endif; ?>
      </header>

      <div class="article__body">
        <?= $bodyHtml ?>
      </div>

      <!-- The other four, from wherever you landed. Someone reading the
           returns policy is usually one question away from the shipping one. -->
      <nav class="page-more" aria-labelledby="more-h">
        <h2 id="more-h" class="u-eyebrow">صفحه‌های دیگر</h2>
        <ul>
          <?php foreach (pages_all() as $other): ?>
            <?php if ($other['slug'] === $page['slug']) { continue; } ?>
            <li><a href="<?= e(page_url($other['slug'])) ?>"><?= e($other['title']) ?></a></li>
          <?php endforeach; ?>
          <li><a href="track.php">پیگیری سفارش</a></li>
        </ul>
      </nav>

    </div>
  </article>
<?php endif; ?>
</main>

<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
