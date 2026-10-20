<?php
/* ===========================================================================
   SUPPEX admin — page chrome
   ---------------------------------------------------------------------------
   Two functions rather than a template engine. The panel is a dozen pages of
   forms and tables; anything more elaborate would be scaffolding around
   scaffolding, and this has to stay editable by whoever maintains the shop.
   =========================================================================== */

declare(strict_types=1);

/* One-shot messages that survive the redirect after a POST. Without them,
   "saved successfully" would have to be a query parameter, which stays in the
   address bar and reappears on every refresh. */
function flash(string $type, string $message): void
{
    auth_start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    auth_start_session();
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

/* The menu's groups, in order. Sixteen links in one column read as a wall;
   five short groups with a word over each are scanned, not read. */
const ADMIN_NAV_GROUPS = [
    ''        => '',
    'sales'   => 'فروش',
    'catalog' => 'محصولات',
    'prices'  => 'قیمت و انبار',
    'system'  => 'گزارش و تنظیمات',
];

function admin_nav_items(): array
{
    $items = [
        ['href' => 'index.php',    'label' => 'داشبورد',  'icon' => 'grid', 'group' => ''],
        /* With how many are waiting, so a new one is seen without opening it. */
        ['href' => 'orders.php',   'label' => 'سفارش‌ها', 'icon' => 'box', 'group' => 'sales',
         'badge' => admin_orders_waiting()],
        ['href' => 'reviews.php',  'label' => 'نظرات', 'icon' => 'star', 'group' => 'sales',
         'badge' => admin_reviews_waiting()],
        ['href' => 'products.php', 'label' => 'همهٔ محصولات', 'icon' => 'tag', 'group' => 'catalog'],
        ['href' => 'import.php',   'label' => 'ورود از اکسل', 'icon' => 'upload', 'group' => 'catalog'],
        ['href' => 'categories.php', 'label' => 'دسته‌ها', 'icon' => 'layers', 'group' => 'catalog'],
        ['href' => 'brands.php',   'label' => 'برندها',     'icon' => 'award', 'group' => 'catalog'],
        ['href' => 'images.php',   'label' => 'عکس‌ها',      'icon' => 'image', 'group' => 'catalog'],
        ['href' => 'pricing.php',  'label' => 'قیمت‌گذاری', 'icon' => 'coins', 'group' => 'prices'],
        ['href' => 'margins.php',  'label' => 'حاشیه سود',  'icon' => 'scale', 'group' => 'prices'],
        ['href' => 'stock.php',    'label' => 'موجودی انبار', 'icon' => 'boxes', 'group' => 'prices'],
        ['href' => 'inquiries.php', 'label' => 'استعلام موجودی', 'icon' => 'boxes', 'group' => 'prices',
         'badge' => admin_inquiries_waiting()],
        ['href' => 'report.php',   'label' => 'گزارش مالی', 'icon' => 'chart', 'group' => 'system'],
        ['href' => 'settings.php', 'label' => 'تنظیمات',  'icon' => 'gear', 'group' => 'system'],
        ['href' => 'users.php',    'label' => 'کاربران پنل', 'icon' => 'users', 'group' => 'system',
         'capability' => 'maintenance'],
        /* Maintenance. Hidden from «مدیرکل» - and hiding is only the polite
           half, because a hidden link is still a working URL. Each of these
           pages refuses on its own with auth_require_can(). */
        ['href' => 'backup.php',   'label' => 'پشتیبان‌گیری', 'icon' => 'save', 'group' => 'system',
         'capability' => 'maintenance'],
        ['href' => 'preflight.php','label' => 'آمادگی راه‌اندازی', 'icon' => 'check', 'group' => 'system',
         'capability' => 'maintenance'],
    ];

    return array_values(array_filter($items, static function (array $i): bool {
        return !isset($i['capability']) || auth_can($i['capability']);
    }));
}

function admin_icon(string $name): string
{
    $paths = [
        /* A floppy disk: still the one shape everybody reads as "save a
           copy", whatever their age. */
        'save'   => '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h8V3"/><rect x="8" y="13" width="8" height="6"/>',
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'    => '<path d="M12 3l8 4v10l-8 4-8-4V7z"/><path d="M4 7l8 4 8-4M12 11v10"/>',
        /* Stacked crates: one box is an order, several are a shelf. */
        'users'  => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.2 2.7-5 6-5s6 1.8 6 5"/><path d="M16.5 5.6a3.2 3.2 0 0 1 0 5.8"/><path d="M18 14.6c2 .7 3 2.3 3 4.4"/>',
        'boxes'  => '<rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/><rect x="8" y="3" width="8" height="8" rx="1.5"/>',
        'tag'    => '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.4"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
        'chart'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'scale'  => '<path d="M12 4v16M7 20h10"/><path d="M5 8h14"/><path d="M5 8l-2.5 5.5a3 3 0 0 0 5 0z"/><path d="M19 8l2.5 5.5a3 3 0 0 1-5 0z"/>',
        'upload' => '<path d="M12 16V4"/><path d="M7.5 8.5L12 4l4.5 4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
    'image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M4 17l5-4 3 2.2L16 11l4 4"/>',
        'coins'  => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'gear'   => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'back'   => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'trash'  => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13"/>',
        'check'  => '<path d="M20 6L9 17l-5-5"/>',
        'star'   => '<path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.4l6.5-.9z"/>',
        'download' => '<path d="M12 4v12"/><path d="M7.5 11.5L12 16l4.5-4.5"/><path d="M4 18v1a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1"/>',
        /* A rosette: a maker's mark, which is what a brand is on a shelf. */
        'award'  => '<circle cx="12" cy="9" r="5.5"/><path d="M8.6 13.4L7 21l5-2.6 5 2.6-1.6-7.6"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'alert'  => '<path d="M12 3.5l9.5 16.5h-19z"/><path d="M12 10v4.5"/><path d="M12 17.6v.1"/>',
        'clock'  => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'arrow'  => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $body . '</svg>';
}

/**
 * A product image path, resolvable from anywhere under /admin/.
 *
 * Uploads are stored absolute; the seeded catalogue is stored relative to the
 * site root, which the admin is not. Left alone, a relative path resolves
 * against /admin/ and 404s.
 */
function admin_img(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if ($path[0] === '/' || preg_match('~^(https?:)?//~', $path) === 1) {
        return $path;
    }
    return '../' . $path;
}

function admin_head(string $title, array $opts = []): void
{
    $user    = $opts['user'] ?? auth_user();
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#131210">
<title><?= e($title) ?> — پنل مدیریت SUPPEX</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<a class="skip" href="#main">رفتن به محتوا</a>

<div class="shell">
  <aside class="side">
    <div class="side__brand">
      <span class="side__logo">SUPPEX</span>
      <span class="side__sub">پنل مدیریت</span>
    </div>
    <nav class="side__nav" aria-label="منوی پنل">
      <?php $group = null; ?>
      <?php foreach (admin_nav_items() as $item): ?>
        <?php if (($item['group'] ?? '') !== $group): $group = $item['group'] ?? ''; ?>
          <?php if (($label = ADMIN_NAV_GROUPS[$group] ?? '') !== ''): ?>
            <p class="side__group"><?= e($label) ?></p>
          <?php endif; ?>
        <?php endif; ?>
        <a class="side__link<?= $current === $item['href'] ? ' is-active' : '' ?>"
           href="<?= e($item['href']) ?>"<?= $current === $item['href'] ? ' aria-current="page"' : '' ?>>
          <?= admin_icon($item['icon']) ?><span><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?>
            <span class="side__count" aria-label="<?= e(fa_digits((string) $item['badge'])) ?> مورد منتظر">
              <?= e(fa_digits((string) $item['badge'])) ?>
            </span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side__foot">
      <div class="side__user"><?= e($user['name'] ?? '') ?></div>
      <a class="side__logout" href="logout.php"><?= admin_icon('out') ?> خروج</a>
    </div>
  </aside>

  <main class="main" id="main">
    <header class="topbar">
      <button class="topbar__menu" type="button" data-menu aria-label="منو">
        <span></span><span></span><span></span>
      </button>
      <div class="topbar__heading">
        <h1 class="topbar__title"><?= e($title) ?></h1>
        <?php if (!empty($opts['sub'])): ?>
          <p class="topbar__sub"><?= e($opts['sub']) ?></p>
        <?php endif; ?>
      </div>
      <?php if (!empty($opts['action'])): ?>
        <a class="btn btn--primary" href="<?= e($opts['action']['href']) ?>">
          <?= admin_icon('plus') ?> <?= e($opts['action']['label']) ?>
        </a>
      <?php endif; ?>
    </header>

    <div class="content">
      <?php foreach (flash_take() as $f): ?>
        <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
<?php
}

function admin_foot(): void
{
    admin_catch_up_pictures();
    ?>
    </div>
  </main>
</div>

<script>
/* The sidebar is a slide-over below 900px. Nothing else on these pages needs
   JavaScript — every form is a plain POST, so the panel keeps working if a
   script fails to load on a slow connection. */
(function () {
  var btn = document.querySelector('[data-menu]');
  if (btn) {
    btn.addEventListener('click', function () {
      document.body.classList.toggle('is-nav-open');
    });
  }
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-confirm]');
    if (t && !window.confirm(t.getAttribute('data-confirm'))) {
      ev.preventDefault();
    }
  });

  /* Long help text under a field folds to its first line, and opens on a
     click or Enter. Every word is still there, for the one time it is
     needed; the other ninety-nine times the form is a form, not an essay. */
  Array.prototype.forEach.call(document.querySelectorAll('.field .hint, .card > p.hint'), function (h) {
    if (h.textContent.replace(/\s+/g, ' ').trim().length < 110 || h.querySelector('a, input, button')) { return; }
    h.classList.add('hint--fold');
    h.setAttribute('role', 'button');
    h.setAttribute('tabindex', '0');
    h.setAttribute('aria-expanded', 'false');
    h.title = 'برای دیدن همه، بزنید';
    var flip = function () {
      var open = h.classList.toggle('is-open');
      h.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    h.addEventListener('click', flip);
    h.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flip(); }
    });
  });

  /* A page with a bar of its sections (the product form) lights the one in
     view, and keeps that pill visible in a bar too wide for a phone. */
  var nav = document.querySelector('[data-formnav]');
  if (nav && 'IntersectionObserver' in window) {
    var pills = {};
    Array.prototype.forEach.call(nav.querySelectorAll('a[href^="#"]'), function (a) {
      pills[a.getAttribute('href').slice(1)] = a;
    });
    var seen = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting || !pills[en.target.id]) { return; }
        Object.keys(pills).forEach(function (k) {
          pills[k].classList.toggle('is-here', k === en.target.id);
        });
        var a = pills[en.target.id];
        nav.scrollTo({ left: a.offsetLeft - (nav.clientWidth - a.offsetWidth) / 2, behavior: 'smooth' });
      });
    }, { rootMargin: '-40% 0px -55% 0px' });
    Array.prototype.forEach.call(document.querySelectorAll('[data-formnav-target]'), function (sec) {
      seen.observe(sec);
    });
  }
})();
</script>
</body>
</html>
<?php
}

/**
 * Make a few missing lighter copies of product photographs, after the page.
 *
 * The photographs uploaded before the copies existed, or attached by the bulk
 * importer, get them here without anybody pressing anything: once this admin
 * page has been sent and the connection closed, up to eight seconds of work,
 * then again on a later page while any remain. With nothing left it looks
 * once every ten minutes. Where the server cannot close the connection first
 * it does nothing - the owner must never wait for this.
 */
function admin_catch_up_pictures(): void
{
    if (!function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request')) {
        return;
    }
    if (time() - (int) setting('image_variants_at', '0') < 600) {
        return;
    }
    register_shutdown_function(static function (): void {
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            fastcgi_finish_request();
        }
        try {
            require_once SUPPEX_ROOT . '/lib/images.php';
            $r = image_variants_backfill(8.0);
            settings_save(['image_variants_at' => (string) ($r['pending'] > 0 ? 0 : time())]);
        } catch (Throwable $e) {
            error_log('[suppex] picture catch-up: ' . $e->getMessage());
        }
    });
}

/** How many orders wait to be looked at - the «ثبت شده» ones. */
function admin_orders_waiting(): int
{
    try {
        return (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'new'");
    } catch (Throwable $e) {
        return 0;
    }
}

function admin_inquiries_waiting(): int
{
    try {
        require_once SUPPEX_ROOT . '/lib/inquiries.php';
        return inquiries_waiting();
    } catch (Throwable $e) { return 0; }
}

/** How many reviews wait for the owner - 0 until the table exists. */
function admin_reviews_waiting(): int
{
    try {
        require_once SUPPEX_ROOT . '/lib/reviews.php';
        return reviews_pending_count();
    } catch (Throwable $e) {
        return 0;
    }
}
