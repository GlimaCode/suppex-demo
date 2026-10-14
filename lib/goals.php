<?php
/* ===========================================================================
   SUPPEX — shopping by goal
   ---------------------------------------------------------------------------
   A shopper usually knows what they are after - more muscle, more weight, a
   heavier session - before they know which supplement serves it. The home
   page offers the goals and each has a page (goal.php) listing the categories
   that serve it, each with the one-line summary from its own guide
   (content/categories/*.md): the vetted, sourced text the category page
   already carries. A goal page makes no claim the shop has not made already.

   A goal is shown only while one of its categories has something on sale.
   «سلامت عمومی» with no vitamins would be a heading over nothing, which reads
   as a shop that failed; it comes back by itself when there are some.

   The five goals and their categories were agreed with the owner
   (2026-09-30). Categories are named by slug: one renamed or hidden in the
   panel simply drops out of the goals that name it.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/products.php';

/**
 * Every goal, in the order the home page shows them.
 *
 * lead is the page's opening paragraph; it introduces the categories below
 * it and leaves the claims to their guides.
 *
 * @return array<string,array{name:string,title:string,lead:string,categories:list<string>,icon:string}>
 */
function goals_all(): array
{
    return [
        'muscle' => [
            'name'       => 'عضله‌سازی',
            'title'      => 'مکمل عضله‌سازی',
            'lead'       => 'عضله را تمرین مقاومتی و غذای کافی می‌سازد و مکمل جای هیچ‌کدام را نمی‌گیرد؛ فقط کمک می‌کند به آن‌ها برسید. دسته‌هایی که به این هدف مربوط‌اند، با آنچه پژوهش‌ها درباره‌شان می‌گویند:',
            'categories' => ['protein', 'creatine', 'amino'],
            'icon'       => '<path d="M6.5 6.5v11M17.5 6.5v11M3.5 9.5v5M20.5 9.5v5M6.5 12h11"/>',
        ],
        'weight-gain' => [
            'name'       => 'افزایش وزن',
            'title'      => 'مکمل افزایش وزن',
            'lead'       => 'وزن گرفتن یعنی بیشتر از مصرف روزانهٔ بدن کالری خوردن. اگر با غذای معمولی به آن نمی‌رسید، این دسته‌ها کمک می‌کنند:',
            'categories' => ['mass-gainer', 'protein'],
            'icon'       => '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
        ],
        'strength' => [
            'name'       => 'قدرت و انرژی تمرین',
            'title'      => 'مکمل قدرت و انرژی تمرین',
            'lead'       => 'برای جلسه‌های سنگین و کوتاه. اینکه هر دسته چه می‌کند و چقدر:',
            'categories' => ['creatine', 'pre-workout'],
            'icon'       => '<path d="M13 2L4.5 13.5H11L10 22l8.5-11.5H12L13 2z"/>',
        ],
        'recovery' => [
            'name'       => 'ریکاوری',
            'title'      => 'مکمل ریکاوری',
            'lead'       => 'ریکاوری بیش از هر چیز به خواب، غذای کافی و فاصلهٔ درست بین تمرین‌ها بستگی دارد. سهم مکمل در آن:',
            'categories' => ['protein', 'amino'],
            'icon'       => '<path d="M20 12a8 8 0 1 1-2.35-5.65"/><path d="M20 4v5h-5"/>',
        ],
        'health' => [
            'name'       => 'سلامت عمومی',
            'title'      => 'ویتامین و مکمل برای سلامت عمومی',
            'lead'       => 'ویتامین‌ها برای جبران کمبودند، نه جایگزین غذا. اگر دارو مصرف می‌کنید یا بیماری خاصی دارید، پیش از مصرف با پزشک مشورت کنید.',
            'categories' => ['vitamins'],
            'icon'       => '<path d="M12 20s-7-4.35-9-9.1C1.7 7.4 4 4 7.3 4c1.9 0 3.5 1 4.7 2.7C13.2 5 14.8 4 16.7 4 20 4 22.3 7.4 21 10.9 19 15.65 12 20 12 20z"/>',
        ],
    ];
}

function goal_url(string $slug): string
{
    return 'goal.php?g=' . rawurlencode($slug);
}

/**
 * The goals there is something to buy for, keyed by slug, in order: each
 * with the categories of its own that have products (in the goal's order,
 * with their names and counts) and how many products those hold between them.
 * A product is in one category, so the sum counts nothing twice.
 *
 * @param list<array>|null $categories categories_list(), when the caller has it
 * @return array<string,array>
 */
function goals_live(?array $categories = null): array
{
    $categories = $categories ?? categories_list();
    $byId = [];
    foreach ($categories as $c) {
        $byId[(string) $c['id']] = $c;
    }
    $out = [];
    foreach (goals_all() as $slug => $g) {
        $live  = [];
        $count = 0;
        foreach ($g['categories'] as $id) {
            $n = isset($byId[$id]) ? (int) $byId[$id]['count'] : 0;
            if ($n > 0) {
                $live[] = ['id' => $id, 'name' => (string) $byId[$id]['name'], 'count' => $n,
                           /* For the goal's tile: a photograph from this shelf. */
                           'photo' => (string) ($byId[$id]['photo'] ?? '')];
                $count += $n;
            }
        }
        if ($live) {
            $out[$slug] = $g + ['slug' => $slug, 'live' => $live, 'count' => $count];
        }
    }
    return $out;
}

/**
 * The live goals a category serves - the category page's links onward.
 *
 * @return list<array>
 */
function goals_for_category(string $categoryId, ?array $live = null): array
{
    $live = $live ?? goals_live();
    return array_values(array_filter($live, static fn(array $g): bool =>
        in_array($categoryId, array_column($g['live'], 'id'), true)));
}

/** The goal's icon, drawn like the site's other line icons (ui.icon()). */
function goal_icon(array $g): string
{
    return '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor"'
         . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"'
         . ' focusable="false">' . $g['icon'] . '</svg>';
}

/**
 * One card of the home page's «هدف شما چیست؟»: the goal, the categories it
 * leads to and how many products are behind it. The count is written like
 * the category cards' beside it.
 */
function goal_card_html(array $g): string
{
    /* A real photograph from the goal's own shelves - the first of its
       categories that has one - standing in the tile's corner, in the goal's
       colour (data-goal, in layout.css). The tile reads without it. */
    $photo = '';
    foreach ($g['live'] as $c) {
        if ((string) ($c['photo'] ?? '') !== '') {
            $photo = (string) $c['photo'];
            break;
        }
    }
    $img = '';
    if ($photo !== '') {
        $set = function_exists('image_srcset_attr') ? image_srcset_attr($photo, '(min-width: 980px) 260px, 40vw') : '';
        $img = '<img class="goal__photo" src="' . e($photo) . '"' . $set
             . ' alt="" loading="lazy" decoding="async" width="320" height="320">';
    }
    return '<a class="goal" href="' . e(goal_url((string) $g['slug'])) . '" data-goal="' . e((string) $g['slug']) . '">'
        . '<span class="goal__icon">' . goal_icon($g) . '</span>'
        . '<span class="goal__name">' . e((string) $g['name']) . '</span>'
        . '<span class="goal__cats">' . e(implode(' · ', array_column($g['live'], 'name'))) . '</span>'
        . '<span class="goal__count"><span class="num">' . (int) $g['count'] . '</span>&nbsp;محصول</span>'
        . $img
        . '<span class="goal__go" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M15 6l-6 6 6 6"/></svg></span>'
        . '</a>';
}

/* --- the header's two lists -----------------------------------------------
   «هدف‌ها» and «راهنماها» in the menu on every page (partials/chrome-top.php
   on a desktop, chrome-foot.php's panel on a phone; index.php writes the same
   into index.html). A link to each goal and each category guide from every
   page is also how a crawler learns they matter. */

/**
 * The «هدف‌ها» list: each goal with something on sale, then the section on
 * the home page. $home is how the page links home - «./» on the PHP pages.
 *
 * @return list<array{href:string,label:string,icon:string}>
 */
function nav_goal_items(?array $live = null, string $home = './'): array
{
    $items = [];
    foreach ($live ?? goals_live() as $g) {
        $items[] = ['href' => goal_url((string) $g['slug']), 'label' => (string) $g['name'], 'icon' => goal_icon($g)];
    }
    if ($items) {
        $items[] = ['href' => $home . '#goals', 'label' => 'همه هدف‌ها', 'icon' => ''];
    }
    return $items;
}

/**
 * The «راهنماها» list: the guide on each category's page - what it does, how
 * much, and who does not need it (content/categories/) - then the journal.
 *
 * @return list<array{href:string,label:string,icon:string}>
 */
function nav_guide_items(?array $categories = null): array
{
    $items = [];
    foreach ($categories ?? categories_list() as $c) {
        $items[] = ['href' => 'category.php?c=' . rawurlencode((string) $c['id']) . '#guide',
                    'label' => 'راهنمای ' . (string) $c['name'], 'icon' => ''];
    }
    $items[] = ['href' => 'journal.php', 'label' => 'مجله ساپکس', 'icon' => ''];
    return $items;
}

/** A desktop list's links (.nav__menu). */
function nav_menu_links(array $items): string
{
    $html = '';
    foreach ($items as $i => $it) {
        $last = $i === count($items) - 1;
        $html .= '<a class="nav__menu-link' . ($last ? ' nav__menu-link--all' : '') . '" href="' . e($it['href']) . '">'
            . ($it['icon'] !== '' ? '<span class="nav__menu-icon">' . $it['icon'] . '</span>' : '')
            . e($it['label']) . '</a>';
    }
    return $html;
}

/** The phone panel's links for one list (.mobile-nav__sub). */
function nav_panel_links(array $items): string
{
    $html = '';
    foreach ($items as $it) {
        $html .= '<a class="mobile-nav__sublink" href="' . e($it['href']) . '" data-close>' . e($it['label']) . '</a>';
    }
    return $html;
}

/**
 * One of the header's lists on a desktop: the button that opens it and its
 * links, one line of markup. CSS opens it on hover; app.js (initNavMenus())
 * on a tap or the keyboard, and keeps aria-expanded true to what is shown.
 * Nothing at all for an empty list.
 */
function nav_dropdown_html(string $id, string $label, array $items): string
{
    if (!$items) {
        return '';
    }
    return '<li class="nav__item">'
        . '<button class="nav__link nav__toggle" type="button" aria-expanded="false" aria-controls="' . e($id) . '" data-nav-toggle>'
        . e($label) . '<svg class="nav__chev" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg>'
        . '</button><div class="nav__menu" id="' . e($id) . '">' . nav_menu_links($items) . '</div></li>';
}

/** The same list in the phone's panel: a heading that opens it in place. */
function nav_panel_group_html(string $label, array $items): string
{
    if (!$items) {
        return '';
    }
    return '<details class="mobile-nav__group"><summary class="mobile-nav__link">' . e($label) . '</summary>'
        . '<div class="mobile-nav__sub">' . nav_panel_links($items) . '</div></details>';
}
