<?php
/* ===========================================================================
   The journal index — every article, in one place
   ---------------------------------------------------------------------------
   «همه مقاله‌ها» on the home page and «مجله ساپکس» in the footer of every
   template both pointed at href="#". The articles existed and were reachable
   only from three cards on the home page, which meant an article was one
   home-page redesign away from being unreachable and had no page that could
   accumulate links.

   Worth its own page for a second reason. This shop sells supplements with
   evidence-based copy, and the articles are the only part of the site that is
   not asking for money — they are what a person searching «پنجره طلایی
   پروتئین» finds, and the index is what ties them together for a crawler that
   arrives at one of them.
   =========================================================================== */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/journal.php';
require_once __DIR__ . '/lib/seo.php';

$articles = journal_all();

$title = 'مجله ساپکس';
$desc  = seo_clip('مقاله‌های ساپکس درباره مکمل، تمرین و تغذیه — با ارجاع به مطالعه‌های اصلی، '
                . 'نه توصیه‌های بی‌منبع.');
$url   = seo_origin() . '/journal.php';
$image = seo_absolute('assets/images/og-cover.png');

/* An ItemList, which is what this is: a list of things that live elsewhere.
   Marking it up as a Blog would claim the articles are hosted on this URL. */
$jsonld = json_encode([
    '@context'        => 'https://schema.org',
    '@type'           => 'CollectionPage',
    'name'            => $title,
    'description'     => $desc,
    'inLanguage'      => 'fa-IR',
    '@id'             => $url,
    'url'             => $url,
    'isPartOf'        => [
        '@type' => 'WebSite',
        'name'  => setting('shop_name', 'SUPPEX'),
        'url'   => seo_origin() . '/',
    ],
    'mainEntity' => [
        '@type'           => 'ItemList',
        'numberOfItems'   => count($articles),
        'itemListElement' => array_values(array_map(
            static fn(int $i, array $a): array => [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'url'      => seo_origin() . '/article.php?a=' . rawurlencode($a['slug']),
                'name'     => $a['title'],
            ],
            array_keys($articles),
            $articles
        )),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= e($url) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(setting('shop_name', 'SUPPEX')) ?>">
<meta property="og:locale" content="fa_IR">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:image" content="<?= e($image) ?>">
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
        <a href="index.html">خانه</a>
        <span aria-hidden="true">/</span>
        <span>مجله</span>
      </nav>

      <div class="section__head" style="margin-block-start:var(--sp-6)">
        <div>
          <p class="u-eyebrow">مجله ساپکس</p>
          <h1 class="u-h2" style="margin-top:8px">چیزهایی که خوب است بدانید</h1>
          <p class="u-sm u-muted" style="max-width:56ch;margin-top:12px;line-height:1.95">
            این‌ها مقاله تبلیغاتی نیستند. هر ادعایی که در آن‌ها هست به مطالعه‌ای
            ارجاع دارد که در پایین همان مقاله فهرست شده و می‌توانید خودتان بخوانیدش —
            حتی وقتی نتیجه‌اش این باشد که به مکملی نیاز ندارید.
          </p>
        </div>
      </div>

      <?php if (!$articles): ?>
        <div class="empty" style="margin-block-start:var(--sp-8)">هنوز مقاله‌ای منتشر نشده است.</div>
      <?php else: ?>
        <div class="grid grid--3" style="margin-block-start:var(--sp-8)">
          <?php foreach ($articles as $a): ?>
            <?php $href = 'article.php?a=' . rawurlencode($a['slug']); ?>
            <!-- Deliberately the same markup ui.js emits for the three
                 cards on the home page, so the index and the home page cannot
                 drift into looking like two different sites. One <a> around
                 the whole card, with no second link inside it. -->
            <a class="pcard reveal" href="<?= e($href) ?>">
              <div class="media media--16x10">
                <img src="<?= e($a['image']) ?>" alt="" loading="lazy" width="480" height="300">
              </div>
              <div class="pcard__body">
                <div class="row" style="gap:8px">
                  <span class="u-eyebrow u-eyebrow--bare"><?= e($a['category']) ?></span>
                  <span class="u-xs u-dim"><span class="num"><?= (int) $a['readTime'] ?></span> دقیقه مطالعه</span>
                </div>
                <h2 class="u-h3" style="font-size:1.15rem;line-height:1.5"><?= e($a['title']) ?></h2>
                <p class="u-sm u-muted" style="margin-block-start:8px"><?= e($a['summary']) ?></p>
                <?php if ($a['sources']): ?>
                  <!-- The source count, printed because it is the whole
                       difference between these and the supplement blogging
                       that surrounds them. -->
                  <p class="u-xs u-dim" style="margin-block-start:10px">
                    <span class="num"><?= count($a['sources']) ?></span> منبع
                  </p>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </section>
</main>

<?php include __DIR__ . '/partials/chrome-foot.php'; ?>
