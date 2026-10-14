<?php
/* ===========================================================================
   SUPPEX admin — one payment receipt, as an image.
   ---------------------------------------------------------------------------
   Receipts are kept outside the web root (lib/receipts.php), so this is the
   only way to see one, and it asks for a login first. Cache-Control is
   private, no-store: a receipt is a card number and a name, and a shared
   computer's browser cache must not keep a copy.
   =========================================================================== */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/auth.php';
require_once SUPPEX_ROOT . '/lib/receipts.php';

auth_require();

$row  = receipts_ready() ? receipt_get((int) ($_GET['id'] ?? 0)) : null;
$path = $row !== null ? receipt_path((string) $row['file']) : null;

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'رسید پیدا نشد.';
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="receipt-' . (int) $row['id'] . '.jpg"');
readfile($path);
