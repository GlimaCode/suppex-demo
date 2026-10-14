<?php
/* A test suite that can DROP TABLE must never be reachable over HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/* receipt_notify() for one receipt, in its own process, so that it runs
   under tests/test-config-tg.php - Telegram on, pointed at tests/faketg.php:

     SUPPEX_CONFIG=tests/test-config-tg.php php notifyonce.php <receipt id>

   Prints what receipt_notify() returned, as JSON. */

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST']   = 'suppex.ir';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/orders.php';
require_once SUPPEX_ROOT . '/lib/receipts.php';

if (stripos((string) (suppex_config()['telegram_api'] ?? ''), '127.0.0.1') === false) {
    /* Never the real Telegram, whatever the environment says. */
    echo json_encode(['error' => 'not pointed at the stand-in']);
    exit(1);
}
$receipt = receipt_get((int) ($argv[1] ?? 0));
$order   = $receipt ? db_one('SELECT * FROM orders WHERE id = ?', [(int) $receipt['order_id']]) : null;
echo json_encode($receipt && $order ? receipt_notify($order, $receipt, 'https://suppex.ir') : ['error' => 'no receipt']);
