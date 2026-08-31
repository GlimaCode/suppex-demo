<?php
/* One child of the race check in test-stock.php: place a single order and say
   whether it worked. Run six of these at once against three tubs and exactly
   three must print OK. Not reachable over HTTP, like every file in here. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/orders.php';

$phone = (string) ($argv[1] ?? '09129990000');
$slug  = (string) ($argv[2] ?? 'creatine-mono');

$r = order_create([
    'customer' => ['name' => 'مسابقه', 'phone' => $phone,
                   'address' => 'تهران، خیابان نمونه، پلاک ۱', 'postal' => '1234567890'],
    'items'    => [['slug' => $slug, 'qty' => 1]],
    'channel'  => 'telegram',
]);

echo ($r['ok'] ?? false) ? "OK\n" : "NO\n";
