<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/inquiries.php';
if (db_value('SELECT DATABASE()') !== 'suppex_test') { throw new RuntimeException('Disposable database required'); }
if (($argv[1] ?? '') === 'create') {
    $_SERVER['REMOTE_ADDR'] = $argv[3] ?? '127.0.0.22';
    echo json_encode(inquiry_create(json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR)));
} elseif (($argv[1] ?? '') === 'notify') {
    if (!str_starts_with((string) (suppex_config()['telegram_api'] ?? ''), 'http://127.0.0.1:')) { throw new RuntimeException('Fake Telegram required'); }
    echo json_encode(['sent' => inquiry_notify((int) $argv[2])]);
} else { echo json_encode(inquiry_decide((int) $argv[1], (string) $argv[2], 'آزمون هم‌زمان')); }
