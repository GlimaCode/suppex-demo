<?php
/* ===========================================================================
   One goal — the categories that serve it, and their products
   ---------------------------------------------------------------------------
   «عضله‌سازی», «افزایش وزن» and the rest (lib/goals.php): what a shopper
   knows they want before they know which supplement it is. The page is the
   goal's short lead, then one section per category that serves it and has
   something on sale: the category's one-line summary from its own guide - the
   vetted, sourced text of the category page, never a new claim - a link to
   that guide, and the category's products.

   It is also a page people search for: «مکمل عضله‌سازی», «مکمل افزایش وزن».
   The title says so; the h1 is the goal as the home page names it.

   Structured data is the breadcrumb alone, for the reason category.php gives:
   it is the one kind Google consumes on a listing, and the products carry
   their own on their own pages.

   The product cards are plain links, replaced by app.js with the full cards
   (initGoalPage()), each section from the same ?r=products call the category
   page makes - sent with the page (lib/boot.php), so nothing is fetched.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/products.php';
require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/lib/goals.php';
require_once __DIR__ . '/lib/seo.php';

$slug  = clean_text($_GET['g'] ?? '', 40);
$live  = goals_live();
$goal  = $live[$slug] ?? null;

/* A goal with nothing on sale is not a page: the home page does not link it
   and the sitemap does not list it, so an address to it is a stale one. */
if ($goal === null) {
    http_response_code(404);
}

$sections     = [];
$bootRequests = [];
if ($goal !== null) {
    foreach ($goal['live'] as $c) {
        $list = products_list(['category' => $c['id'], 'limit' => 60]);
        if (!$list) {
            continue;
        }
        $guide      = category_guide($c['id']);
        $sections[] = ['id' => $c['id'], 'name' => $c['name'], 'products' => $list,
                       'summary' => $guide !== null ? (string) $guide['summary'] : ''];
        $bootRequests[] = ['products', ['category' => $c['id'], 'limit' => 60], $list];
    }
}

$shop  = setting('shop_name', 'SUPPEX');
$names = implode('، ', array_column($sections, 'name'));
/* «مکمل عضله‌سازی: پروتئین، کراتین و آمینو» - the goal as people search for
   it, and what the page actually holds. */
$lastComma = mb_strrpos($names, '، ');
$listed    = $lastComma === false ? $names : mb_substr($names, 0, $lastComma) . ' و ' . mb_substr($names, $lastComma + 2);
$title = $goal === null ? 'این صفحه پیدا نشد' : $goal['title'] . ($listed !== '' ? ': ' . $listed : '');
$desc  = $goal === null ? 'این هدف در فروشگاه نیست یا فعلاً محصولی برایش موجود نیست.' : seo_clip((string) $goal['lead']);
$url   = seo_origin() . '/' . goal_url($slug);
$image = seo_cover();

$jsonld = '';
if ($goal !== null) {
    $jsonld = json_encode([
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $shop, 'item' => seo_origin() . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $goal['name'], 'item' => $url],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
}
$others = array_values(array_filter($live, static fn(array $g): bool => $g['slug'] !== $slug));
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
<meta name="robots" content="<?= $goal === null ? 'noindex, follow' : 'index, follow' ?>">
<?php if ($goal !== null): ?>
<link rel="canonical" href="<?= e($url) ?>">
<?php endif; ?>

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
<?php if ($goal === null): ?>
  <section class="section">
    <div class="container">
      <div class="notfound">
        <h1 class="notfound__title">این صفحه پیدا نشد</h1>
        <p class="u-sm u-dim">ممکن است نشانی اشتباه باشد یا فعلاً محصولی برای این هدف موجود نباشد.</p>
        <p style="margin-top:18px"><a class="btn btn--primary" href="./#goals">دیدن همه هدف‌ها</a></p>
      </div>
    </div>
  </section>
<?php else: ?>
  <section class="section">
    <div class="container">

      <nav class="article__crumbs" aria-label="مسیر صفحه">
        <a href="./">خانه</a>
        <span aria-hidden="true">/</span>
        <span><?= e($goal['name']) ?></span>
      </nav>

      <div class="section__head" style="margin-block-start:var(--sp-6)">
        <div>
          <?php /* The goal's photograph, as on its home-page tile: from the first
                   of its shelves that has one (see category.php for why it
                   comes first). */
          $headPhoto = '';
          foreach ($goal['live'] as $c) {
              if ((string) ($c['photo'] ?? '') !== '') { $headPhoto = (string) $c['photo']; break; }
          } ?>
          <?php if ($headPhoto !== ''): ?>
            <img class="section__head-photo" src="<?= e($headPhoto) ?>"<?= image_srcset_attr($headPhoto, '(min-width: 700px) 260px, 34vw') ?> alt="" fetchpriority="high" width="300" height="300">
          <?php endif; ?>
          <p class="u-eyebrow">خرید بر اساس هدف</p>
          <h1 class="u-h2" style="margin-top:8px"><?= e($goal['name']) ?></h1>
          <p class="u-sm u-muted" style="max-width:60ch;margin-top:12px;line-height:1.95"><?= e($goal['lead']) ?></p>
        </div>
      </div>

      <?php foreach ($sections as $sec): ?>
        <section class="goal-cat" aria-labelledby="goal-cat-<?= e($sec['id']) ?>">
          <div class="goal-cat__head">
            <h2 class="u-h3" id="goal-cat-<?= e($sec['id']) ?>"><?= e($sec['name']) ?></h2>
            <?php if ($sec['summary'] !== ''): ?>
              <p class="u-sm u-muted"><?= e($sec['summary']) ?></p>
            <?php endif; ?>
            <a class="link-more" href="<?= e('category.php?c=' . rawurlencode($sec['id'])) ?>">راهنمای کامل <?= e($sec['name']) ?></a>
          </div>
          <!-- Plain links, replaced by app.js with the full cards - the
               same as a category page's. -->
          <div class="grid grid--4" data-goal-products data-category="<?= e($sec['id']) ?>">
            <?php foreach ($sec['products'] as $p): ?>
              <?php $fact = product_key_fact($p); ?>
              <article class="pcard">
                <a class="media media--1x1 media--lit" href="<?= e('product.php?p=' . rawurlencode((string) $p['slug'])) ?>">
                  <img src="<?= e(product_image_or_placeholder((string) $p['image'])) ?>"<?= image_srcset_attr((string) $p['image'], '(min-width: 1100px) 270px, (min-width: 700px) 31vw, 47vw') ?> alt="<?= e((string) $p['nameFa']) ?>" loading="lazy" decoding="async" width="400" height="400">
                </a>
                <div class="pcard__body">
                  <div class="pcard__brand lat"><?= e((string) $p['brand']) ?></div>
                  <h3 class="pcard__title">
                    <a href="<?= e('product.php?p=' . rawurlencode((string) $p['slug'])) ?>"><?= e((string) $p['nameFa']) ?></a>
                  </h3>
                  <?php if ($fact !== ''): ?><p class="pcard__fact"><?= e($fact) ?></p><?php endif; ?>
                  <div class="pcard__foot">
                    <span class="price"><span class="price__now"><span class="num"><?= money_fa((int) $p['price']) ?></span>&nbsp;<?= price_unit_html() ?></span></span>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <p class="article__note" style="margin-block-start:var(--sp-10)">
        این متن آموزشی است و جای مشورت با پزشک یا متخصص تغذیه را نمی‌گیرد.
      </p>

      <?php if ($others): ?>
        <nav class="page-more" aria-labelledby="other-goals" style="margin-block-start:var(--sp-8)">
          <h2 id="other-goals" class="u-eyebrow">هدف‌های دیگر</h2>
          <ul>
            <?php foreach ($others as $o): ?>
              <li><a href="<?= e(goal_url($o['slug'])) ?>"><?= e($o['name']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </nav>
      <?php endif; ?>

    </div>
  </section>
<?php endif; ?>
</main>

<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
