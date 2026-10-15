<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP.
   bootstrap falls back to the real suppex-config.php when SUPPEX_CONFIG
   is unset - which is exactly what a web request has - so one GET to an
   uploaded tests/ folder could empty the live database. The .htaccess
   beside this file is process; this is code. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* ===========================================================================
   Harness: one save through admin/product-edit.php with exactly the fields
   given - what the browser posts after somebody has typed into the form.
   ---------------------------------------------------------------------------
   tests/editsave.php posts what the page would post if nothing were touched;
   this posts whatever the suite hands it, which is what a test of typing
   needs. Its own process, because a successful save ends in header() + exit.

   argv[1] = product id, or 0 for a new product
   argv[2] = base64 of a JSON object of POST fields - base64, because on
             Windows escapeshellarg() turns every double quote into a space and
             the JSON arrives as nothing at all - or the word «view», which
             opens the page instead of saving it

   Prints one line of JSON: the id saved under, the flash messages, the error
   lines a refused save drew, and for «view» the page itself.
   =========================================================================== */

$view = ($argv[2] ?? '') === 'view';

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'suppex.ir';
$_SERVER['REQUEST_METHOD'] = $view ? 'GET' : 'POST';
$_SERVER['REQUEST_URI']    = '/admin/product-edit.php';
$_SERVER['SCRIPT_NAME']    = '/admin/product-edit.php';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';

$admin = db_one('SELECT id, name, role FROM admins ORDER BY id LIMIT 1');
auth_start_session();
$_SESSION['admin_id']   = (int) ($admin['id'] ?? 1);
$_SESSION['admin_name'] = (string) ($admin['name'] ?? 'tester');
$_SESSION['admin_role'] = (string) ($admin['role'] ?? 'support');
require_once __DIR__ . '/admin-session.php'; test_admin_session();
$_SESSION['csrf']       = 't';
unset($_SESSION['flash']);

$id   = (int) ($argv[1] ?? 0);
$_GET = $id > 0 ? ['id' => (string) $id] : [];
if ($view) {
    $_POST = [];
} else {
    $given = json_decode((string) base64_decode((string) ($argv[2] ?? ''), true), true);
    $_POST = array_merge(['action' => 'save', '_csrf' => 't'], is_array($given) ? $given : []);
}

/* A save that works exits from inside the page, so the report is written on
   the way out. $id is the page's own variable by then - a new product's comes
   back from its INSERT. */
register_shutdown_function(static function () use ($view): void {
    /* Shutdown functions run before PHP flushes the buffers, so this one
       closes them itself: otherwise the page's HTML would not have been
       collected yet, and the report below would land in the buffer that
       swallows everything. */
    while (ob_get_level() > 0) { ob_end_flush(); }
    $html = (string) ($GLOBALS['formpostHtml'] ?? '');
    preg_match_all('~<span class="err">(.*?)</span>|<div class="flash flash--error">(.*?)</div>~su',
                   $html, $m);
    $errs = array_values(array_filter(array_map(
        static fn($a, $b) => trim(html_entity_decode(strip_tags($a . $b))), $m[1], $m[2])));
    echo json_encode([
        'id'     => (int) ($GLOBALS['id'] ?? 0),
        'saved'  => !$view && $html === '',
        'flash'  => $_SESSION['flash'] ?? [],
        'errors' => $errs,
        'html'   => $view ? $html : '',
    ], JSON_UNESCAPED_UNICODE), "\n";
});

ob_start(static function (string $buf): string {
    $GLOBALS['formpostHtml'] = ($GLOBALS['formpostHtml'] ?? '') . $buf;
    return '';
});
include SUPPEX_ROOT . '/admin/product-edit.php';
ob_end_flush();
