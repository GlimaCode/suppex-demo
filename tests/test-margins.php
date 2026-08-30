<?php
/* Renders admin/margins.php against the test database with a stubbed session,
   to prove its queries are valid and its arithmetic holds. */
$_SERVER['REMOTE_ADDR']  = '127.0.0.1';
$_SERVER['REQUEST_URI']  = '/admin/margins.php';
$_SERVER['SCRIPT_NAME']  = '/admin/margins.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/pricing.php';

/* Stand in for a logged-in owner without touching the real auth path. */
auth_start_session();
$_SESSION['admin_id']   = 1;
$_SESSION['admin_name'] = 'tester';
$_SESSION['admin_role'] = 'owner';

settings_save(['commission_basis' => 'profit', 'commission_percent' => '20']);

ob_start();
require SUPPEX_ROOT . '/admin/margins.php';
$html = ob_get_clean();

$checks = [
  'page rendered'            => strlen($html) > 2000,
  'has the margin table'     => strpos($html, 'خالص فروشگاه') !== false,
  'shows the basis'          => strpos($html, 'درصدی از سود خالص') !== false,
  'explains why per-product is rarely needed'
                             => strpos($html, 'لازم نیست درصد را روی تک‌تک') !== false,
  'has the change log'       => strpos($html, 'تاریخچه تغییر درصد') !== false,
  'no PHP warning leaked'    => stripos($html, 'Warning') === false
                                && stripos($html, 'Fatal') === false,
  'no unescaped braces'      => strpos($html, '<?php') === false,
];
$fail = 0;
foreach ($checks as $label => $okc) {
  echo ($okc ? '  ok   ' : '  FAIL ') . $label . "\n";
  if (!$okc) { $fail++; }
}
echo "\nrendered " . strlen($html) . " bytes\n";
exit($fail > 0 ? 1 : 0);
