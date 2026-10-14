<?php
/* ===========================================================================
   One journal article, rendered from content/journal/<slug>.md
   ---------------------------------------------------------------------------
   Server-rendered for the same reasons product.php is: Telegram and Instagram
   preview bots read og: tags and never run JavaScript, and an article whose
   text only exists after hydration is an article the AI crawlers and the
   search index never see. Here it matters more than on a product page - the
   whole value of an article is its text.

   The shell below is a copy of product.php's, which is itself a copy of
   index.html's. That is the existing shape of this codebase and not something
   this file invented, but four copies of a header is a maintenance debt and
   should become one include.
   =========================================================================== */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/journal.php';
require_once __DIR__ . '/lib/seo.php';

$slug    = clean_text($_GET['a'] ?? '', 64);
$article = $slug === '' ? null : journal_get($slug);

/* A missing article answers 404 rather than 200 with an empty frame: a soft
   404 gets indexed as a real page. */
if ($article === null) {
    http_response_code(404);
}

$title = $article === null ? 'مقاله پیدا نشد' : $article['title'];
$desc  = $article === null
    ? 'این مقاله وجود ندارد یا حذف شده است.'
    : seo_clip($article['summary'] !== '' ? $article['summary'] : $article['title']);
$url   = seo_origin() . '/article.php?a=' . rawurlencode($slug);
/* The articles' artwork is SVG, which preview bots and Google's article
   results do not draw; the cover stands in for it. */
$image = $article === null || str_ends_with(strtolower((string) $article['image']), '.svg')
    ? seo_cover() : seo_absolute($article['image']);
$bodyHtml = $article === null ? '' : journal_render($article['body']);

/* Article schema, not Product: this is editorial, and mislabelling it invites
   Search Console to reject the lot. No author name is invented - the
   organisation is the author, which is both true and what Google accepts. */
$jsonld = '';
if ($article !== null) {
    $jsonld = json_encode([
        '@context'         => 'https://schema.org',
        '@type'            => 'Article',
        'headline'         => $article['title'],
        'description'      => $article['summary'],
        'inLanguage'       => 'fa-IR',
        'articleSection'   => $article['category'],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'image'            => $image,
        'author'           => ['@type' => 'Organization', 'name' => setting('shop_name', 'SUPPEX')],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => setting('shop_name', 'SUPPEX'),
            'logo'  => ['@type' => 'ImageObject', 'url' => seo_absolute('assets/icons/logo.svg')],
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



<title><?= e($title) ?> | <?= e(setting('shop_name', 'SUPPEX')) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="robots" content="<?= $article === null ? 'noindex, follow' : 'index, follow, max-image-preview:large' ?>">
<link rel="canonical" href="<?= e($url) ?>">

<meta property="og:type" content="article">
<meta property="og:site_name" content="<?= e(setting('shop_name', 'SUPPEX')) ?>">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:image" content="<?= e($image) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= e($title) ?>">
<meta name="twitter:card" content="summary_large_image">

<?php if ($jsonld !== ''): ?>
<script type="application/ld+json">
<?= $jsonld ?>

</script>
<?php endif; ?>
</head>

<?php include __DIR__ . '/partials/chrome-top.php'; ?>

<main id="main">
<?php if ($article === null): ?>
  <section class="section">
    <div class="container">
      <div class="notfound">
        <!-- h1, like 404.html and product.php. A page with no h1 gives a
             screen-reader user nothing to jump to and tells a crawler the page
             has no subject - and an error page is exactly where somebody is
             already lost. article.php had this as a <p> and page.php inherited
             it by being written from the same shape. -->
        <h1 class="notfound__title">مقاله پیدا نشد</h1>
        <p class="u-sm u-dim">ممکن است این مقاله حذف شده باشد یا نشانی را اشتباه وارد کرده باشید.</p>
        <p style="margin-top:18px"><a class="btn btn--primary" href="./">بازگشت به فروشگاه</a></p>
      </div>
    </div>
  </section>
<?php else: ?>
  <article class="article">
    <div class="container container--narrow">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="./">خانه</a>
        <span aria-hidden="true">/</span>
        <a href="./#journal">مجله</a>
      </nav>

      <?php /* The header panel wears the cover's colour and its diagonal lines
               (layout.css, .article__head--cover), so the page reads as the
               card it was opened from. */
      $tone = journal_cover_tone($article['image']); ?>
      <header class="article__head article__head--cover"<?= $tone !== '' ? ' style="--tone:' . $tone . '"' : '' ?>>
        <div class="article__meta">
          <span class="u-eyebrow u-eyebrow--bare"><?= e($article['category']) ?></span>
          <span class="u-xs u-dim"><span class="num"><?= (int) $article['readTime'] ?></span> دقیقه مطالعه</span>
        </div>
        <h1 class="article__title"><?= e($article['title']) ?></h1>
        <?php if ($article['summary'] !== ''): ?>
          <p class="article__lede"><?= e($article['summary']) ?></p>
        <?php endif; ?>
      </header>

      <div class="article__body">
        <?= $bodyHtml ?>
      </div>

      <?php if ($article['sources']): ?>
        <section class="article__sources" aria-labelledby="sources-h">
          <h2 id="sources-h">منابع</h2>
          <!-- Every one of these was retrieved and checked before the article
               was written. They are listed so a reader can check them too. -->
          <ol>
            <?php foreach ($article['sources'] as $s): ?>
              <li><?= e($s) ?></li>
            <?php endforeach; ?>
          </ol>
        </section>
      <?php endif; ?>

      <p class="article__note">
        این متن آموزشی است و جای مشورت با پزشک یا متخصص تغذیه را نمی‌گیرد.
      </p>

    </div>
  </article>
<?php endif; ?>
</main>


<!-- ══════════════════════════════════════════ Footer ═══ -->
<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
