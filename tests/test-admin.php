<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* ===========================================================================
   Roles, quick edit, and bulk actions.
   ---------------------------------------------------------------------------
   The checks that matter here are the ones about what somebody is allowed to
   do and what a write is allowed to touch:

     - «مدیرکل» must not reach the backup, which streams every customer's name,
       phone and address and every admin password hash. Hiding the nav link is
       not the test; refusing the URL is;
     - a quick edit must write exactly ONE product, because every visible row's
       inputs travel in the same form;
     - and it must refuse to set the price of a dirham-priced product, whose
       price is derived and would be silently discarded at the next pricing run.
   =========================================================================== */

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/admin/products.php';

$pass = 0; $fail = 0; $out = [];
function ok(string $l, bool $c, ?string $x = ''): void {
    global $pass, $fail, $out;
    if ($c) { $pass++; $out[] = "  ok   $l"; }
    else { $fail++; $out[] = "  FAIL $l" . ($x ? "  --  $x" : ''); }
}
function head(string $t): void { global $out; $out[] = "\n== $t =="; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/admin/partials/layout.php';   // admin_nav_items()

/* --- roles ---------------------------------------------------------------- */
head('the two roles, and what each may do');
ok('there are exactly two', count(ROLES) === 2, implode(',', array_keys(ROLES)));
ok('support is «پشتیبان»', (ROLES['support'] ?? '') === 'پشتیبان');
ok('manager is «مدیرکل»', (ROLES['manager'] ?? '') === 'مدیرکل');

$as = static function (string $role): void {
    auth_start_session();
    $_SESSION['admin_id']   = 1;
    $_SESSION['admin_name'] = 't';
    $_SESSION['admin_role'] = $role;
    require_once __DIR__ . '/admin-session.php'; test_admin_session();
};

$as('support');
ok('support may do maintenance', auth_can('maintenance') === true);
$as('manager');
ok('manager may not', auth_can('maintenance') === false);
/* A role hand-edited in the database to something misspelled must get nothing
   extra, not everything. */

ok('a mis-cased role gets nothing', auth_role_can('Support', 'maintenance') === false);

ok('an empty role gets nothing', auth_role_can('', 'maintenance') === false);
/* The upgrade window: new code is uploaded, the migration that renames the
   role has not run yet, and the person who has to go and run it is signed in
   as the old value. Without this the panel silently drops three menu items at
   exactly that moment - including the page that would explain why. */
$as('support');
ok('the legacy role keeps its access until the migration renames it',
   auth_role_can('owner', 'maintenance') === true);
/* admin/migrate.php runs ALTER TABLE, so it is now behind the same capability
   as backup and preflight. That is only safe because of the legacy entry just
   asserted above: the page that RENAMES the old role must be reachable BY the
   old role, or the upgrade locks itself out of the panel it is upgrading.

   So the check is no longer "this page is ungated" - it is that the gate
   exists AND that the pre-migration role passes it. */
$migrateSrc = (string) file_get_contents(SUPPEX_ROOT . '/admin/migrate.php');
ok('admin/migrate.php refuses on its own',
   strpos($migrateSrc, "auth_require_can('maintenance')") !== false);
ok('  and the pre-migration role can still get through it',
   auth_can('maintenance') === true);
$as('manager');
ok('  while مدیرکل cannot', auth_can('maintenance') === false);
$as('support');

head('the nav advertises only what the role can open');
$as('support');
$navSupport = array_column(admin_nav_items(), 'href');
$as('manager');
$navManager = array_column(admin_nav_items(), 'href');
ok('support sees the backup', in_array('backup.php', $navSupport, true));
ok('support sees the readiness page', in_array('preflight.php', $navSupport, true));
ok('support sees the users page', in_array('users.php', $navSupport, true));
ok('manager sees none of the three',
   !in_array('backup.php', $navManager, true)
   && !in_array('preflight.php', $navManager, true)
   && !in_array('users.php', $navManager, true));
/* The shop still has to be runnable by the person running it. */
foreach (['index.php', 'orders.php', 'products.php', 'stock.php', 'pricing.php',
          'report.php', 'settings.php', 'categories.php', 'import.php'] as $href) {
    ok("  manager keeps $href", in_array($href, $navManager, true));
}

head('hiding a link is not access control');
/* The URL still works whatever the nav shows, so each gated page has to refuse
   on its own. This asserts the refusal is IN the file rather than trusting the
   menu. */
foreach (['backup.php', 'preflight.php', 'users.php', 'migrate.php'] as $f) {
    $src = (string) file_get_contents(SUPPEX_ROOT . '/admin/' . $f);
    ok("$f refuses on its own", strpos($src, "auth_require_can('maintenance')") !== false);
}

/* --- quick edit and bulk, driven through the real page -------------------- */
$harness = __DIR__ . '/productpost.php';
ok('the product-post harness exists', is_file($harness), $harness);

$post = static function (array $fields, string $role = 'support') use ($harness): array {
    $out = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' '
        . escapeshellarg(base64_encode((string) json_encode($fields))) . ' '
        . escapeshellarg($role) . ' 2>&1');
    $line = trim((string) strrchr("\n" . trim($out), "\n"));
    $rows = json_decode($line, true);
    if (!is_array($rows)) { return ['_raw' => $out]; }
    $byId = [];
    foreach ($rows as $r) { $byId[(int) $r['id']] = $r; }
    return $byId;
};

/* Two products of this suite's own, differing in the one way that matters.

   Borrowing «the first product with price_mode = manual» is what this used to
   do, and it passed alone and failed in the runner: the suites share one
   database and several truncate and reseed it, so what is lying around depends
   on which suite ran last. */
db_query("DELETE FROM products WHERE slug IN ('zz-admin-manual', 'zz-admin-aed')");
$manualId = db_insert(
    "INSERT INTO products (slug, name_fa, name_en, price, price_mode, is_active, in_stock)
     VALUES ('zz-admin-manual', 'آزمون دستی', 'Admin Manual', 2450000, 'manual', 1, 1)");
$aedId = db_insert(
    "INSERT INTO products (slug, name_fa, name_en, price, price_mode, is_active, in_stock)
     VALUES ('zz-admin-aed', 'آزمون درهمی', 'Admin Aed', 5690000, 'aed', 1, 1)");
ok('a manual-priced product to test with', $manualId > 0);
ok('a dirham-priced product to test with', $aedId > 0 && $aedId !== $manualId);

$before = db_all('SELECT id, price FROM products WHERE id IN (?, ?)', [$manualId, $aedId]);
$priceOf = [];
foreach ($before as $r) { $priceOf[(int) $r['id']] = (int) $r['price']; }

head('quick edit writes one product, not the form');
/* Both rows' inputs are posted, as the browser does - only the id named in the
   submit button may be written. */
$rows = $post([
    'action' => 'quick:' . $manualId,
    'q' => [
        $manualId => ['price' => '777000', 'sort_order' => '42', 'is_active' => '1', 'in_stock' => '1'],
        $aedId    => ['price' => '999000', 'sort_order' => '99', 'is_active' => '1', 'in_stock' => '1'],
    ],
]);
ok('the named product is written', (int) ($rows[$manualId]['price'] ?? 0) === 777000,
   (string) ($rows[$manualId]['price'] ?? 'none'));
ok('  and its sort order', (int) ($rows[$manualId]['sort_order'] ?? 0) === 42);
/* The row whose inputs travelled alongside must be untouched. */
ok('the other row in the same form is untouched',
   (int) ($rows[$aedId]['price'] ?? 0) === $priceOf[$aedId],
   (string) ($rows[$aedId]['price'] ?? 'none'));
ok('  including its sort order', (int) ($rows[$aedId]['sort_order'] ?? -1) !== 99);

head('a derived price cannot be set by hand');
/* price_mode = aed means the price is computed from the dirham rate; a value
   accepted here would be discarded at the next pricing run, which is worse
   than refusing it. */
$rows = $post([
    'action' => 'quick:' . $aedId,
    'q' => [$aedId => ['price' => '123000', 'sort_order' => '7', 'is_active' => '1', 'in_stock' => '1']],
]);
ok('the price is refused', (int) ($rows[$aedId]['price'] ?? 0) === $priceOf[$aedId],
   (string) ($rows[$aedId]['price'] ?? 'none'));
/* But the rest of the row still saves - refusing one field must not refuse the
   edit. */
ok('  while the rest of the row still saves', (int) ($rows[$aedId]['sort_order'] ?? 0) === 7);

head('a was-price below the price is not a discount');
$rows = $post([
    'action' => 'quick:' . $manualId,
    'q' => [$manualId => ['price' => '500000', 'compare_at' => '400000',
                          'is_active' => '1', 'in_stock' => '1']],
]);
ok('it is dropped rather than stored', $rows[$manualId]['compare_at'] === null,
   (string) ($rows[$manualId]['compare_at'] ?? 'null'));
$rows = $post([
    'action' => 'quick:' . $manualId,
    'q' => [$manualId => ['price' => '500000', 'compare_at' => '600000',
                          'is_active' => '1', 'in_stock' => '1']],
]);
ok('a real one is kept', (int) ($rows[$manualId]['compare_at'] ?? 0) === 600000);

head('the switches are checkboxes, so absent means off');
$rows = $post(['action' => 'quick:' . $manualId,
               'q' => [$manualId => ['price' => '500000']]]);
ok('an unchecked «در سایت» hides it', (int) ($rows[$manualId]['is_active'] ?? 1) === 0);
ok('an unchecked «موجود است» clears it', (int) ($rows[$manualId]['in_stock'] ?? 1) === 0);

head('bulk applies to the checked rows and nothing else');
/* Three more of this suite's own, so «the rows that were NOT checked» is a
   set this test controls rather than whatever else is in the table. */
db_query("DELETE FROM products WHERE slug LIKE 'zz-admin-bulk%'");
$bulkIds = [];
for ($i = 1; $i <= 3; $i++) {
    $bulkIds[] = db_insert(
        "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock, badges)
         VALUES (?, ?, 100000, 'manual', 1, 1, '')",
        ['zz-admin-bulk' . $i, 'آزمون گروهی ' . $i]);
}
$all  = array_merge([$manualId, $aedId], $bulkIds);
$two  = array_slice($bulkIds, 0, 2);
$rest = array_slice($bulkIds, 2);
$post(['action' => 'bulk', 'bulk_op' => 'activate', 'ids' => $all]);
$rows = $post(['action' => 'bulk', 'bulk_op' => 'deactivate', 'ids' => $two]);
foreach ($two as $id) { ok("  the checked #$id was hidden", (int) $rows[$id]['is_active'] === 0); }
foreach ($rest as $id) {
    ok("  the unchecked #$id was left alone", (int) $rows[$id]['is_active'] === 1);
}

head('bulk badges do not corrupt the comma list');
$post(['action' => 'bulk', 'bulk_op' => 'badge_add', 'bulk_badge' => 'bestseller', 'ids' => $two]);
$rows = $post(['action' => 'bulk', 'bulk_op' => 'badge_add', 'bulk_badge' => 'new', 'ids' => $two]);
foreach ($two as $id) {
    $b = (string) $rows[$id]['badges'];
    ok("  #$id has both", strpos($b, 'bestseller') !== false && strpos($b, 'new') !== false, $b);
    ok("  #$id has no empty entry", strpos($b, ',,') === false && $b[0] !== ',', $b);
}
/* Adding the same badge twice must not duplicate it. */
$rows = $post(['action' => 'bulk', 'bulk_op' => 'badge_add', 'bulk_badge' => 'new', 'ids' => $two]);
ok('adding a badge twice keeps one', substr_count((string) $rows[$two[0]]['badges'], 'new') === 1,
   (string) $rows[$two[0]]['badges']);
$rows = $post(['action' => 'bulk', 'bulk_op' => 'badge_remove', 'bulk_badge' => 'new', 'ids' => $two]);
foreach ($two as $id) {
    $b = (string) $rows[$id]['badges'];
    ok("  #$id lost «new» and kept «bestseller»",
       strpos($b, 'new') === false && strpos($b, 'bestseller') !== false, $b);
    ok("  #$id has no trailing comma", $b === '' || substr($b, -1) !== ',', $b);
}

head('«تخفیف برتر» is a badge the panel sets and the hero asks for');
/* The home page's hero shows up to three products ticked «تخفیف برتر». It is
   stored as the badge `topdeal`, beside «پرفروش» and «جدید», so the whole
   chain is: the panel can set it, the storefront list can filter on it, and
   an ordinary save cannot quietly drop it. */
require_once SUPPEX_ROOT . '/lib/products.php';
$tdId = $bulkIds[2];     // still active: the deactivate above took the other two
$rows = $post(['action' => 'bulk', 'bulk_op' => 'badge_add', 'bulk_badge' => 'topdeal',
               'ids' => [$tdId]]);
ok('bulk «افزودن نشان» accepts topdeal',
   in_array('topdeal', explode(',', (string) $rows[$tdId]['badges']), true),
   (string) $rows[$tdId]['badges']);

$tdSlugs = array_column(products_list(['badge' => 'topdeal']), 'slug');
ok('the storefront list filtered on it returns the ticked product',
   in_array('zz-admin-bulk3', $tdSlugs, true), implode(', ', $tdSlugs));
ok('  and none of the unticked ones',
   !in_array('zz-admin-bulk1', $tdSlugs, true) && !in_array('zz-admin-bulk2', $tdSlugs, true),
   implode(', ', $tdSlugs));

/* Through the real product form, in its own process because a successful
   save ends in header() + exit. If 'topdeal' were missing from the form's
   badge list, pressing save on a ticked product would untick it. */
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/editsave.php')
           . ' ' . $tdId . ' 2>&1');
$tdAfter = (string) db_value('SELECT badges FROM products WHERE id = ?', [$tdId]);
ok('a save through the product form that changed nothing keeps the tick',
   in_array('topdeal', explode(',', $tdAfter), true), $tdAfter);

$rows = $post(['action' => 'bulk', 'bulk_op' => 'badge_add', 'bulk_badge' => 'featured',
               'ids' => [$tdId]]);
ok('a badge that is not on the list is still refused',
   strpos((string) $rows[$tdId]['badges'], 'featured') === false,
   (string) $rows[$tdId]['badges']);

head('the image order the owner arranges is the order the site shows');
/* «اصلی» in the panel and the first photo on the product page used to be
   two different things - the page opened on whichever photo was uploaded
   first. Now the panel arranges them, the ordinary save keeps the order, the
   first is the main image, and the product page leads with it. */
db_query("DELETE FROM product_images WHERE path LIKE 'uploads/zz-order/%'");
db_query("DELETE FROM products WHERE slug = 'zz-img-order'");
$ioId = db_insert(
    "INSERT INTO products (slug, name_fa, price, price_mode, is_active, in_stock, image)
     VALUES ('zz-img-order', 'آزمون ترتیب تصویر', 100000, 'manual', 1, 1, 'uploads/zz-order/a.webp')");
$imgA = db_insert("INSERT INTO product_images (product_id, path, sort_order) VALUES (?, 'uploads/zz-order/a.webp', 0)", [$ioId]);
$imgB = db_insert("INSERT INTO product_images (product_id, path, sort_order) VALUES (?, 'uploads/zz-order/b.webp', 1)", [$ioId]);
$imgC = db_insert("INSERT INTO product_images (product_id, path, sort_order) VALUES (?, 'uploads/zz-order/c.webp', 2)", [$ioId]);
/* Somebody else's photo, to prove it cannot be dragged into this product. */
$imgX = db_insert("INSERT INTO product_images (product_id, path, sort_order) VALUES (?, 'uploads/zz-order/x.webp', 0)", [$tdId]);

$saveOrder = static function (int $pid, array $ids): void {
    shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/editsave.php')
               . ' ' . $pid . ' ' . escapeshellarg(implode(',', $ids)) . ' 2>&1');
};
$orderOf = static fn(int $pid): array => array_map('intval', array_column(
    db_all('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$pid]), 'id'));

$saveOrder($ioId, [$imgC, $imgA, $imgX, $imgB]);
ok('the save keeps the arranged order', $orderOf($ioId) === [$imgC, $imgA, $imgB],
   implode(',', $orderOf($ioId)));
ok('  and the first becomes the main image',
   (string) db_value('SELECT image FROM products WHERE id = ?', [$ioId]) === 'uploads/zz-order/c.webp',
   (string) db_value('SELECT image FROM products WHERE id = ?', [$ioId]));
ok('another product\'s photo cannot be pulled in by its id',
   (int) db_value('SELECT product_id FROM product_images WHERE id = ?', [$imgX]) === $tdId
   && (int) db_value('SELECT sort_order FROM product_images WHERE id = ?', [$imgX]) === 0);

require_once SUPPEX_ROOT . '/lib/products.php';
$pg = product_get('zz-img-order');
ok('the product page shows them in the same order',
   ($pg['gallery'] ?? []) === ['uploads/zz-order/c.webp', 'uploads/zz-order/a.webp', 'uploads/zz-order/b.webp'],
   implode(', ', $pg['gallery'] ?? []));

/* A photo left out of the post is kept, after the ones that were sent. C is
   left out on purpose: its old position (0) collides with B's new one, so
   without the rewrite it would sort back in between B and A. Posting only B
   did not tell the two apart - the stale values happened to tie the right way. */
$saveOrder($ioId, [$imgB, $imgA]);
ok('a photo missing from the post keeps its place after the rest',
   $orderOf($ioId) === [$imgB, $imgA, $imgC], implode(',', $orderOf($ioId)));

/* A product nobody has re-saved since: main marked, but sorted third. */
db_query('UPDATE products SET image = ? WHERE id = ?', ['uploads/zz-order/a.webp', $ioId]);
$pg = product_get('zz-img-order');
ok('a product not re-saved still leads with the photo marked main',
   ($pg['gallery'][0] ?? '') === 'uploads/zz-order/a.webp'
   && count($pg['gallery']) === 3, implode(', ', $pg['gallery'] ?? []));

db_query("DELETE FROM product_images WHERE path LIKE 'uploads/zz-order/%'");
db_query("DELETE FROM products WHERE slug = 'zz-img-order'");

head('an unknown bulk operation changes nothing');
$countBefore = (int) db_value('SELECT COUNT(*) FROM products');
$post(['action' => 'bulk', 'bulk_op' => 'drop-everything', 'ids' => $all]);
ok('every product survives',
   (int) db_value('SELECT COUNT(*) FROM products') === $countBefore,
   (string) db_value('SELECT COUNT(*) FROM products'));

head('bulk delete removes exactly what was checked');
$doomed = [$bulkIds[0]];
$post(['action' => 'bulk', 'bulk_op' => 'delete', 'ids' => $doomed]);
ok('the checked one is gone',
   db_value('SELECT id FROM products WHERE id = ?', [$doomed[0]]) === null);
ok('  and the others are not',
   (int) db_value('SELECT COUNT(*) FROM products WHERE id IN (?, ?)',
                  [$bulkIds[1], $bulkIds[2]]) === 2);

/* --- clean up ------------------------------------------------------------- */
db_query("DELETE FROM products WHERE slug LIKE 'zz-admin%'");
ok('the suite cleaned up after itself',
   (int) db_value("SELECT COUNT(*) FROM products WHERE slug LIKE 'zz-admin%'") === 0);

echo implode("\n", $out);
echo "\n\n----------------------------------------\npassed: $pass    failed: $fail\n";
exit($fail > 0 ? 1 : 0);
