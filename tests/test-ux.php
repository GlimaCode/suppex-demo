<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* The pages set their own status - a missing goal is a 404 - which PHP
   refuses once anything has been written; so the report waits in a buffer. */
ob_start();
/* ===========================================================================
   Shopping by goal, the phone's buy bar, search, and the card's key fact
   ---------------------------------------------------------------------------
   The owner's review of the storefront (2026-09-30) asked for four things,
   and these are they:

     - «هدف شما چیست؟» on the home page and a page per goal (lib/goals.php,
       goal.php) - only goals with something on sale, and in their words only
       what the category guides already say;
     - on a phone, a bar with the price and the buy button once the real one
       has scrolled away, pressing the real one;
     - a search that reads Persian as it is typed - either alphabet, with or
       without half-spaces - and knows «گینر» is «افزایش وزن»;
     - one fact on each product card, from the product's own features.
   =========================================================================== */
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/goals.php';
require_once SUPPEX_ROOT . '/lib/pages.php';
require_once SUPPEX_ROOT . '/lib/seo.php';

$pass = 0; $fail = 0;
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $l\n"; }
    else { $fail++; echo "  FAIL $l" . ($x ? "  --  $x" : '') . "\n"; }
}
function head(string $t): void { echo "\n== $t ==\n"; }
function ux_page(string $file, array $get = []): string {
    $_GET = $get;
    ob_start();
    include SUPPEX_ROOT . '/' . $file;
    return (string) ob_get_clean();
}
function ux_boot(string $html): array {
    return preg_match('~<script>window\.SUPPEX_BOOT=(.*?);</script>~s', $html, $m)
        ? (array) json_decode($m[1], true) : [];
}
$slugs = static fn(array $list): array => array_map(static fn(array $p): string => (string) $p['slug'], $list);

/* ------------------------------------------------------------------------ */
head('the goals');
$all = goals_all();
ok('five goals, in the agreed order', array_keys($all) === ['muscle', 'weight-gain', 'strength', 'recovery', 'health']);
$complete = true;
foreach ($all as $slug => $g) {
    $complete = $complete && $g['name'] !== '' && $g['title'] !== '' && $g['lead'] !== '' && $g['icon'] !== ''
        && $g['categories'] !== [];
    foreach ($g['categories'] as $c) {
        /* Each line on a goal page is its category guide's summary. */
        $guide = category_guide($c);
        ok("  $slug: «{$c}» has a guide with a summary to show", $guide !== null && $guide['summary'] !== '');
    }
}
ok('each has a name, a title, a lead, an icon and its categories', $complete);
ok('the mapping is the agreed one',
   $all['muscle']['categories'] === ['protein', 'creatine', 'amino']
   && $all['weight-gain']['categories'] === ['mass-gainer', 'protein']
   && $all['strength']['categories'] === ['creatine', 'pre-workout']
   && $all['recovery']['categories'] === ['protein', 'amino']
   && $all['health']['categories'] === ['vitamins']);
ok('no lead promises a result', !array_filter($all, static fn(array $g): bool =>
    (bool) preg_match('/تضمین|قطعاً|صددرصد|۱۰۰٪|معجزه/u', $g['lead'])));

$fake = [
    ['id' => 'protein', 'name' => 'پروتئین', 'count' => 5],
    ['id' => 'creatine', 'name' => 'کراتین', 'count' => 5],
    ['id' => 'amino', 'name' => 'آمینو', 'count' => 1],
    ['id' => 'mass-gainer', 'name' => 'افزایش وزن', 'count' => 0],
    ['id' => 'pre-workout', 'name' => 'قبل تمرین', 'count' => 0],
    ['id' => 'vitamins', 'name' => 'ویتامین', 'count' => 0],
];
$live = goals_live($fake);
ok('a goal with nothing on sale is not shown - the live site today', array_keys($live) === ['muscle', 'weight-gain', 'strength', 'recovery'],
   implode(',', array_keys($live)));
ok('  each keeps only the categories that have products, in its order',
   array_column($live['weight-gain']['live'], 'id') === ['protein']
   && array_column($live['muscle']['live'], 'id') === ['protein', 'creatine', 'amino']);
ok('  and counts their products once', $live['muscle']['count'] === 11 && $live['strength']['count'] === 5);
ok('a category leads to the goals it serves', array_column(goals_for_category('protein', $live), 'slug') === ['muscle', 'weight-gain', 'recovery']
   && goals_for_category('vitamins', $live) === []);
$card = goal_card_html($live['muscle']);
ok('a goal card: its page, its name, its categories, its count',
   strpos($card, 'href="goal.php?g=muscle"') !== false && strpos($card, '>عضله‌سازی<') !== false
   && strpos($card, 'پروتئین · کراتین · آمینو') !== false && strpos($card, '<span class="num">11</span>&nbsp;محصول') !== false, $card);

/* ------------------------------------------------------------------------ */
head('the home page offers them');
$home = ux_page('index.php');
$liveNow = goals_live();
ok('the section is shown when there are goals', strpos($home, 'data-goals-section>') !== false && $liveNow);
ok('  one card for each live goal', substr_count($home, '<a class="goal" href="goal.php?g=') === count($liveNow));
ok('  above the categories', strpos($home, 'id="goals"') < strpos($home, 'id="categories"'));
ok('«از کجا شروع کنم؟» leads to them', strpos($home, '<a class="btn btn--ghost" href="#goals">از کجا شروع کنم؟</a>') !== false);
$homeText = (string) preg_replace('/<!--.*?-->/s', '', $home);
ok('the categories no longer claim to be «بر اساس هدفتان»', mb_strpos($homeText, 'بر اساس هدفتان') === false
   && mb_strpos($homeText, 'یا بر اساس نوع مکمل') !== false);
$static = (string) file_get_contents(SUPPEX_ROOT . '/index.html');
ok('the static page keeps the section hidden - it has no goal pages',
   strpos($static, 'data-goals-section hidden>') !== false && strpos($static, '<div class="goals" data-goals></div>') !== false);

/* ------------------------------------------------------------------------ */
head('a goal page');
$muscle = $liveNow['muscle'] ?? null;
ok('muscle is live in the test catalogue', $muscle !== null);
$gp = ux_page('goal.php', ['g' => 'muscle']);
ok('the title says what it is and what it holds', preg_match('~<title>مکمل عضله‌سازی: [^<]+ \| ~u', $gp) === 1);
ok('the h1 is the goal', strpos($gp, '<h1 class="u-h2" style="margin-top:8px">عضله‌سازی</h1>') !== false);
ok('canonical and indexable', strpos($gp, '<link rel="canonical" href="https://suppex.ir/goal.php?g=muscle">') !== false
   && strpos($gp, '<meta name="robots" content="index, follow">') !== false);
$boot = ux_boot($gp);
foreach ((array) ($muscle['live'] ?? []) as $c) {
    $summary = (string) (category_guide($c['id'])['summary'] ?? '');
    ok("  «{$c['name']}»: its guide's summary, word for word", $summary !== '' && strpos($gp, e($summary)) !== false);
    ok('    a link to the whole guide', strpos($gp, 'href="category.php?c=' . rawurlencode($c['id']) . '">راهنمای کامل') !== false);
    $list = products_list(['category' => $c['id'], 'limit' => 60]);
    ok('    its products, carried for app.js', json_encode($boot[boot_key('products', ['category' => $c['id'], 'limit' => 60])] ?? null)
       === json_encode($list) && $list);
    ok('    and linked for a crawler', strpos($gp, 'href="product.php?p=' . rawurlencode((string) $list[0]['slug']) . '"') !== false);
}
ok('a breadcrumb, and no Product markup on a listing', strpos($gp, '"@type": "BreadcrumbList"') !== false
   && strpos($gp, '"@type": "Product"') === false);
ok('the note every guide carries', mb_strpos($gp, 'جای مشورت با پزشک یا متخصص تغذیه را نمی‌گیرد') !== false);
ok('the way to the other goals', strpos($gp, 'aria-labelledby="other-goals"') !== false);
$none = ux_page('goal.php', ['g' => 'no-such-goal']);
ok('an unknown goal is a 404 page, not indexed', http_response_code() === 404
   && strpos($none, 'noindex') !== false && strpos($none, 'rel="canonical"') === false);
http_response_code(200);

$cp = ux_page('category.php', ['c' => 'protein']);
ok('a category page leads to its goals', strpos($cp, 'aria-labelledby="cat-goals"') !== false
   && strpos($cp, 'href="goal.php?g=muscle"') !== false);
ob_start(); include SUPPEX_ROOT . '/sitemap.php'; $sm = (string) ob_get_clean();
$inMap = true;
foreach ($liveNow as $g) {
    $inMap = $inMap && strpos($sm, '<loc>https://suppex.ir/goal.php?g=' . $g['slug'] . '</loc>') !== false;
}
ok('the sitemap lists every live goal', $inMap && $liveNow);
ob_start(); include SUPPEX_ROOT . '/llms.php'; $llms = (string) ob_get_clean();
ok('llms.txt names them', strpos($llms, "## خرید بر اساس هدف\n") !== false
   && strpos($llms, '](https://suppex.ir/goal.php?g=muscle): ') !== false);

/* ------------------------------------------------------------------------ */
head('the key fact on a card');
$facts = json_decode((string) file_get_contents(__DIR__ . '/key-facts.json'), true);
ok('the shared table is there', is_array($facts) && count($facts) >= 10);
foreach ((array) $facts as $c) {
    $got = product_key_fact(['features' => $c['features']]);
    ok('  ' . $c['why'], $got === $c['fact'], json_encode($got, JSON_UNESCAPED_UNICODE));
}
$withFact = array_values(array_filter(products_list(['limit' => 60]), static fn(array $p): bool => product_key_fact($p) !== ''));
ok('the test catalogue has products with a fact to show', $withFact !== []);
if ($withFact) {
    $p0   = $withFact[0];
    $line = '<p class="pcard__fact">' . e(product_key_fact($p0)) . '</p>';
    ok('  the server\'s cards show it: the category page', strpos(ux_page('category.php', ['c' => (string) $p0['category']]), $line) !== false);
    ok('  the goal pages', strpos(ux_page('goal.php', ['g' => 'muscle']) . ux_page('goal.php', ['g' => 'weight-gain'])
       . ux_page('goal.php', ['g' => 'strength']) . ux_page('goal.php', ['g' => 'health']), $line) !== false);
    $featured = products_list(['limit' => 12]);
    if (in_array($p0['slug'], $slugs($featured), true)) {
        ok('  and the home page', strpos($home, $line) !== false);
    }
}

/* ------------------------------------------------------------------------ */
head('search, as people type');
ok('Arabic letters read as Persian', search_normalize('پروتئين ايزوله كراتين') === search_normalize('پروتئین ایزوله کراتین'));
ok('the hamza and the hurried spelling are one word', search_normalize('پروتئین') === search_normalize('پروتیین'));
ok('half-spaces, punctuation and case', search_normalize("پری\u{200C}ورک‌اوت (Pre-Workout)") === 'پری ورک اوت pre workout');
ok('Persian and Arabic digits are digits', search_normalize('۱۰۰ ٪ ٢٥') === '100 25');
ok('madda and diacritics', search_normalize('آمینوً') === 'امینو');
$same = $slugs(products_search('پروتئین'));
ok('«پروتئين» from an Arabic keyboard finds what «پروتئین» does', $same && $slugs(products_search('پروتئين')) === $same);
ok('  and «پروتیین» typed in a hurry', $slugs(products_search('پروتیین')) === $same);
$vitamins = $slugs(products_list(['category' => 'vitamins', 'limit' => 60]));
ok('«وی» is whey, not the start of «ویتامین»', !array_intersect($slugs(products_search('وی')), $vitamins) && products_search('وی'));
$pre = $slugs(products_list(['category' => 'pre-workout', 'limit' => 60]));
ok('«پریورک» without spaces finds the pre-workouts', $pre && !array_diff($pre, $slugs(products_search('پریورک'))));
ok('  and so does «قبل تمرین»', $pre && !array_diff($pre, $slugs(products_search('قبل تمرین'))));
$gainers = $slugs(products_list(['category' => 'mass-gainer', 'limit' => 60]));
ok('«گینر» finds the category called «افزایش وزن»', $gainers && !array_diff($gainers, $slugs(products_search('گینر'))));
$amino = $slugs(products_list(['category' => 'amino', 'limit' => 60]));
$bcaa  = $slugs(products_search('bcaa'));
ok('«bcaa» finds the amino products and nothing else', $amino && !array_diff($amino, $bcaa) && !array_diff($bcaa, $amino),
   implode(',', $bcaa));
ok('  «امینو» without the madda too', $slugs(products_search('امینو')) === $bcaa);
$muscleHits = $slugs(products_search('عضله'));
$muscleCats = array_merge($slugs(products_list(['category' => 'protein', 'limit' => 60])),
                          $slugs(products_list(['category' => 'creatine', 'limit' => 60])), $amino);
ok('a goal typed as a search finds its categories', $muscleHits && !array_diff($muscleCats, $muscleHits));
ok('  one way only: «پروتئین» does not find creatine', !array_intersect($same, $slugs(products_list(['category' => 'creatine', 'limit' => 60]))));
$both = $slugs(products_search('کراتین و پروتئین'));
ok('two things at once: those with either, when none has both', array_intersect($both, $same)
   && array_intersect($both, $slugs(products_list(['category' => 'creatine', 'limit' => 60]))));
ok('something the shop does not sell finds nothing', products_search('چربی سوز') === []);
ok('an empty query finds nothing', products_search('   ') === [] && products_search('؟!') === []);
ok('the name counts more than a feature', ($r = $slugs(products_search('کراتین'))) && strpos((string) products_search('کراتین')[0]['nameFa'], 'کراتین') !== false);
$api = (string) file_get_contents(SUPPEX_ROOT . '/api/index.php');
ok('the API still answers ?r=search with it', strpos($api, "case 'search':\n            api_send(products_search((string) (\$_GET['q'] ?? '')), 200, 30);") !== false
   || strpos($api, "api_send(products_search((string) (\$_GET['q'] ?? '')), 200, 30);") !== false);

/* ------------------------------------------------------------------------ */
head('the buy bar on a phone');
$pp = ux_page('product.php', ['p' => 'whey-original']);
ok('a product page has the bar, hidden until the script shows it',
   preg_match('~<div class="buybar" data-buybar hidden>.*?<span class="buybar__name">[^<]+</span>.*?data-buybar-price.*?data-buybar-add>افزودن به سبد</button>~s', $pp) === 1);
ok('  after the page\'s content, inside main', strpos($pp, 'data-buybar') < strpos($pp, '</main>')
   && strpos($pp, 'data-buybar') > strpos($pp, 'data-related'));
$gone = ux_page('product.php', ['p' => 'no-such-product-at-all']);
http_response_code(200);
ok('  and a missing product has none', strpos($gone, 'data-buybar') === false);
/* app.js has both line endings; the checks below read it with one. */
$app = str_replace("\r\n", "\n", (string) file_get_contents(SUPPEX_ROOT . '/assets/js/app.js'));
ok('its button presses the real one', strpos($app, "\$('[data-buybar-add]', buybar).addEventListener('click', function () { buyBtn.click(); });") !== false);
ok('  shown once the real one is above the screen, never over the unpaid-order bar',
   strpos($app, "var show = buyBtn.getBoundingClientRect().bottom < 0\n          && !document.querySelector('[data-resume-bar]');") !== false);
ok('  read on scroll once a frame - a fling crosses nothing an observer would see',
   strpos($app, "window.addEventListener('scroll', queueBuybar, { passive: true });") !== false
   && strpos($app, 'window.requestAnimationFrame(paintBuybar);') !== false);
ok('  the price and the choice follow the selection', strpos($app, "set('[data-buybar-price]',") !== false
   && strpos($app, "set('[data-buybar-choice]', ui.esc(choiceParts().join(' · ')));") !== false);
ok('  sold out still says so alongside the inquiry state',
   strpos($app, "barBtn.textContent = state.inquiry ? 'استعلام موجودی' : (sold ? 'ناموجود' : 'افزودن به سبد');") !== false);
ok('the basket line is labelled by the same words', strpos($app, 'var parts = choiceParts();') !== false);
$css = (string) file_get_contents(SUPPEX_ROOT . '/assets/css/components.css');
ok('not on a desktop', preg_match('~@media \(min-width: 900px\) \{\s*\.buybar \{ display: none; \}~', $css) === 1);
ok('  room left under the page, the notices lifted above it',
   strpos($css, 'body.has-buybar { padding-block-end: 76px; }') !== false
   && strpos($css, 'body.has-buybar .toast-stack { inset-block-end: calc(76px + var(--sp-4)); }') !== false);
ok('  clear of an iPhone\'s home bar', strpos($css, 'env(safe-area-inset-bottom, 0px)') !== false);

head('the script side of the goal page and search');
ok('goal.php\'s cards are swapped for the full ones', strpos($app, 'function initGoalPage()') !== false
   && strpos($app, "initCategoryPage();\n      initGoalPage();") !== false);
ok('a slow answer to an old query cannot paint over a newer one',
   strpos($app, 'var mine = ++asked;') !== false && strpos($app, 'if (mine !== asked) { return; }') !== false);

/* ------------------------------------------------------------------------ */
head('the menu: goals and guides, on every page');
$top = (function (): string { ob_start(); include SUPPEX_ROOT . '/partials/chrome-top.php'; return (string) ob_get_clean(); })();
$goalsNow = goals_live();
ok('«هدف‌ها» opens a list of the goals with something on sale', strpos($top, 'aria-controls="nav-goals" data-nav-toggle>هدف‌ها') !== false
   && substr_count($top, 'class="nav__menu-link" href="goal.php?g=') === count($goalsNow));
ok('  and the section with all of them', strpos($top, 'href="./#goals">همه هدف‌ها</a>') !== false);
$cats = categories_list();
ok('«راهنماها» opens the category guides and the journal', strpos($top, 'aria-controls="nav-guides" data-nav-toggle>راهنماها') !== false
   && substr_count($top, 'href="category.php?c=') >= count($cats) && strpos($top, 'href="journal.php">مجله ساپکس</a>') !== false);
ok('  each guide lands on its guide, not the top of the page', strpos($top, 'category.php?c=protein#guide') !== false
   && strpos((string) file_get_contents(SUPPEX_ROOT . '/category.php'), '<div class="article" id="guide"') !== false);
ok('«محصولات» and «تخفیف‌ها» made room for them', strpos($top, '>محصولات</a>') === false && strpos($top, '>تخفیف‌ها</a>') === false
   && strpos($top, '>درباره ما</a>') !== false && strpos($top, '>برندها</a>') !== false);
ok('a goal without products is not in the menu', array_column(nav_goal_items(goals_live($fake)), 'label')
   === ['عضله‌سازی', 'افزایش وزن', 'قدرت و انرژی تمرین', 'ریکاوری', 'همه هدف‌ها']);
ok('  and an empty list draws nothing at all', nav_dropdown_html('x', 'y', []) === '' && nav_panel_group_html('y', []) === '');
$foot = (function (): string { ob_start(); include SUPPEX_ROOT . '/partials/chrome-foot.php'; return (string) ob_get_clean(); })();
ok('the phone\'s panel has the same two lists, opening in place', substr_count($foot, '<details class="mobile-nav__group">') === 2
   && substr_count($foot, 'class="mobile-nav__sublink" href="goal.php?g=') === count($goalsNow));
$homeNow = ux_page('index.php');
ok('the home page fills its own menu the same way', strpos($homeNow, 'aria-controls="nav-goals"') !== false
   && strpos($homeNow, 'aria-controls="nav-guides"') !== false && strpos($homeNow, '<!-- nav:') === false
   && strpos($homeNow, '<!-- panel:') === false && strpos($homeNow, 'href="#goals">همه هدف‌ها</a>') !== false);
$appNow = str_replace("\r\n", "\n", (string) file_get_contents(SUPPEX_ROOT . '/assets/js/app.js'));
ok('app.js opens them on a tap and Enter, closes them on Escape', strpos($appNow, 'function initNavMenus()') !== false
   && strpos($appNow, "initChrome();\n      initNavMenus();") !== false && strpos($appNow, "if (e.key !== 'Escape') { return; }") !== false);
$layoutCss = (string) file_get_contents(SUPPEX_ROOT . '/assets/css/layout.css');
ok('  CSS opens one under the pointer, or when app.js marks it open', strpos($layoutCss, ".nav__item:hover .nav__menu,\n.nav__item.is-open .nav__menu {") !== false
   || strpos($layoutCss, ".nav__item:hover .nav__menu,\r\n.nav__item.is-open .nav__menu {") !== false);

head('the trust section');
$ix = (string) file_get_contents(SUPPEX_ROOT . '/index.html');
$trust = (string) (preg_match('~aria-labelledby="why-title">.*?</section>~s', $ix, $tm) ? $tm[0] : '');
$trustText = (string) preg_replace('/<!--.*?-->/s', '', $trust);
ok('four promises, each a heading, a line and the detail', substr_count($trust, 'class="trust-card__title"') === 4
   && substr_count($trust, 'class="trust-card__lead"') === 4 && substr_count($trust, 'class="trust-card__more"') === 4);
ok('  the headings cover authenticity, delivery, pricing and support',
   mb_strpos($trust, '>اصل و پلمب‌شده<') !== false && mb_strpos($trust, '>ارسال با پیک و تیپاکس<') !== false
   && mb_strpos($trust, '>قیمت قفل‌شده<') !== false && mb_strpos($trust, '>مشاورهٔ رک<') !== false);
ok('  no unapproved absolute guarantees or express cut-off', preg_match('/تضمین|۱۰۰٪|100%|۲۴ ساعت|فوری/u', $trustText) === 0);
ok('  the flask of the lab tests the shop never ran is gone - the price card has a lock', strpos($trust, 'M10 3v6.2L4.6 18') === false
   && strpos($trust, 'M8.5 10.5V7.5a3.5 3.5 0 0 1 7 0v3') !== false);
ok('  one card to a row on a phone, the icon beside the words', strpos($layoutCss, '.trust-grid { grid-template-columns: minmax(0, 1fr); }') !== false
   && strpos($trust, 'class="grid grid--4 trust-grid"') !== false);

/* ------------------------------------------------------------------------ */
head('the visual pass (2026-10)');
$ask = (string) (preg_match('~<section[^>]*data-ask-band hidden>.*?</section>~s', $ix, $am) ? $am[0] : '');
ok('a closing «ask first» band, hidden until app.js knows the chat', $ask !== '' && strpos($ask, 'data-ask-cta') !== false);
$askLead = (string) (preg_match('~<p class="u-lead">(.*?)</p>~su', $ask, $lm) ? trim($lm[1]) : '');
$askSaid = array_values(array_filter(array_map('trim', preg_split('/(?<=\.)\s+/u', $askLead))));
$askOwn = $askSaid !== [];
foreach ($askSaid as $sentence) {
    $askOwn = $askOwn && mb_strpos($trustText, $sentence) !== false;
}
ok('  it promises nothing new: every sentence is a trust card\'s, word for word', $askOwn, $askLead);
ok('the goal tiles know how many there are, for the layout', strpos($home, '<div class="goals" data-goals data-count="' . count($liveNow) . '">') !== false);
ok('  each tile names its goal for its tint', substr_count($home, '" data-goal="') === count($liveNow));
/* The test catalogue wears drawn SVG tubs, which never count as a
   photograph; for the next checks the first protein product gets one. */
$photoed = db_one("SELECT p.id, p.image FROM products p JOIN categories c ON c.id = p.category_id
                    WHERE c.slug = 'protein' AND p.is_active = 1
                    ORDER BY p.in_stock DESC, p.sort_order, p.id LIMIT 1");
ok('a protein product to photograph', $photoed !== null);
db_query('UPDATE products SET image = ? WHERE id = ?', ['uploads/products/test-ux-photo.webp', (int) ($photoed['id'] ?? 0)]);
$homeP = ux_page('index.php');
$gpP = ux_page('goal.php', ['g' => 'muscle']);
$cpP = ux_page('category.php', ['c' => 'protein']);
db_query('UPDATE products SET image = ? WHERE id = ?', [(string) ($photoed['image'] ?? ''), (int) ($photoed['id'] ?? 0)]);
$stack = (string) (preg_match('~<div class="promo__stack" data-promo-stack>(.*?)</div>~s', $homeP, $sm2) ? $sm2[1] : '');
ok('the promo\'s photographs are links to the three shelves it names, nothing else', $stack !== ''
   && preg_match_all('~<a class="promo__pick" href="category\.php\?c=(protein|creatine|vitamins)">~', $stack) === substr_count($stack, '<a '));
ok('  decorative - the names are written under them', substr_count($stack, ' alt=""') === substr_count($stack, '<img '));
$gpHead = (string) (preg_match('~<div class="section__head"[^>]*>.*?</h1>~s', $gpP, $gh) ? $gh[0] : '');
ok('a goal page\'s header has a photograph of its own, decorative', strpos($gpHead, '<img class="section__head-photo" ') !== false
   && strpos($gpHead, 'alt=""') !== false);
ok('  first in the text block, so a phone can float it beside the title',
   strpos($gpHead, 'section__head-photo') < strpos($gpHead, 'class="u-eyebrow"'));
ok('  and a category page\'s too', preg_match('~<div class="section__head"[^>]*>\s*<div>\s*<img class="section__head-photo" src="uploads/products/test-ux-photo\.webp"[^>]* alt=""~s', $cpP) === 1);
ok('  and never an empty one', strpos($gp . $cp . $gpP . $cpP, 'class="section__head-photo" src=""') === false);
ok('a product with no photograph shows the placeholder, not a black square',
   product_image_or_placeholder('') === PRODUCT_PLACEHOLDER && product_image_or_placeholder(' x.webp') === ' x.webp'
   && is_file(SUPPEX_ROOT . '/' . PRODUCT_PLACEHOLDER));
$uiJs = (string) file_get_contents(SUPPEX_ROOT . '/assets/js/ui.js');
ok('  and so does a card drawn by ui.js', strpos($uiJs, "IMAGE_PLACEHOLDER = '" . PRODUCT_PLACEHOLDER . "'") !== false);
$pdpPhp = (string) file_get_contents(SUPPEX_ROOT . '/product.php');
$pdpHtml = (string) file_get_contents(SUPPEX_ROOT . '/product.html');
ok('the price, the quantity and the button sit in one panel, in both product pages',
   substr_count($pdpPhp, '<div class="pdp__purchase">') === 1 && substr_count($pdpHtml, '<div class="pdp__purchase">') === 1);
$spot = (string) (preg_match('~function initSpotlight\(\) \{(.*?)\n  \}~s', (string) file_get_contents(SUPPEX_ROOT . '/assets/js/app.js'), $sp) ? $sp[1] : '');
ok('the light that follows the pointer is for a mouse, and stands still when asked',
   strpos($spot, "matchMedia('(hover: hover) and (pointer: fine)').matches") !== false
   && strpos($spot, "matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }") !== false);

echo "\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
