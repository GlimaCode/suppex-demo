<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
$pass = 0; $fail = 0;
function ok(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}
$original = [
    'free_shipping_threshold' => setting('free_shipping_threshold', '1500000'),
    'shipping_flat_rate' => setting('shipping_flat_rate', '65000'),
];
try {
    foreach (json_decode(file_get_contents(__DIR__ . '/shipping-copy.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
        settings_save(['free_shipping_threshold' => (string) $case['threshold'], 'shipping_flat_rate' => (string) $case['flat']]);
        ok('announcement matches settings ' . $case['threshold'] . '/' . $case['flat'],
            strip_tags(storefront_shipping_html()) === $case['announcement']);
        ok('policy matches settings ' . $case['threshold'] . '/' . $case['flat'],
            strip_tags(storefront_shipping_html(true)) === $case['policy']);
    }
    settings_save(['free_shipping_threshold' => '3500000', 'shipping_flat_rate' => '65000']);
    ob_start();
    include SUPPEX_ROOT . '/index.php';
    $home = ob_get_clean();
    ok('home announcement arrives from PHP before JavaScript',
        str_contains($home, '<span data-shipping-announcement>' . storefront_shipping_html() . '</span>'));
    ok('home promo arrives with the same current settings',
        str_contains($home, '<span data-shipping-policy>' . storefront_shipping_html(true) . '</span>'));
    ob_start(); include SUPPEX_ROOT . '/partials/chrome-top.php'; $top = ob_get_clean();
    ok('shared header also uses current settings',
        str_contains($top, '<span data-shipping-announcement>' . storefront_shipping_html() . '</span>'));
} finally { settings_save($original); }
echo "\npassed: $pass    failed: $fail\n";
exit($fail ? 1 : 0);
