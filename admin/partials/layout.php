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

function admin_nav_items(): array
{
    return [
        ['href' => 'index.php',    'label' => 'داشبورد',  'icon' => 'grid'],
        ['href' => 'orders.php',   'label' => 'سفارش‌ها', 'icon' => 'box'],
        ['href' => 'products.php', 'label' => 'محصولات',  'icon' => 'tag'],
        ['href' => 'categories.php', 'label' => 'دسته‌ها', 'icon' => 'layers'],
        ['href' => 'pricing.php',  'label' => 'قیمت‌گذاری', 'icon' => 'coins'],
        ['href' => 'margins.php',  'label' => 'حاشیه سود',  'icon' => 'scale'],
        ['href' => 'report.php',   'label' => 'گزارش مالی', 'icon' => 'chart'],
        ['href' => 'settings.php', 'label' => 'تنظیمات',  'icon' => 'gear'],
    ];
}

function admin_icon(string $name): string
{
    $paths = [
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'box'    => '<path d="M12 3l8 4v10l-8 4-8-4V7z"/><path d="M4 7l8 4 8-4M12 11v10"/>',
        'tag'    => '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.4"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
        'chart'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'scale'  => '<path d="M12 4v16M7 20h10"/><path d="M5 8h14"/><path d="M5 8l-2.5 5.5a3 3 0 0 0 5 0z"/><path d="M19 8l2.5 5.5a3 3 0 0 1-5 0z"/>',
        'coins'  => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'gear'   => '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'back'   => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'trash'  => '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13h10l1-13"/>',
        'check'  => '<path d="M20 6L9 17l-5-5"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $body . '</svg>';
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
<title><?= e($title) ?> — پنل مدیریت SUPPEX</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700;800;900&family=Archivo:wght@400;600;700;800&family=Archivo+Black&display=swap">
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
    <nav class="side__nav">
      <?php foreach (admin_nav_items() as $item): ?>
        <a class="side__link<?= $current === $item['href'] ? ' is-active' : '' ?>"
           href="<?= e($item['href']) ?>">
          <?= admin_icon($item['icon']) ?><span><?= e($item['label']) ?></span>
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
      <h1 class="topbar__title"><?= e($title) ?></h1>
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
})();
</script>
</body>
</html>
<?php
}
