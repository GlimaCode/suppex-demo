<?php
/* ===========================================================================
   SUPPEX — the social preview card for one product
   ---------------------------------------------------------------------------
   Fetched by Telegram, WhatsApp and Instagram when somebody shares a product
   link. Never by a person, so there is no HTML here and no error page: a bot
   that cannot get an image needs a status code, not an explanation.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/products.php';
require_once SUPPEX_ROOT . '/lib/ogimage.php';

$slug    = clean_text($_GET['p'] ?? '', 120);
$product = $slug === '' ? null : product_get($slug);

if ($product === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not Found\n");
}

$card = og_card($product);
if ($card === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not Found\n");
}

/* Cached hard and forever, because the fingerprint is in the URL: a card whose
   price changed is a different URL, so this one can never go stale. That is
   the only way to beat WhatsApp, which caches a preview per URL with no way to
   invalidate it. */
header('Content-Type: image/png');
header('Content-Length: ' . strlen($card['bytes']));
header('Cache-Control: public, max-age=31536000, immutable');

echo $card['bytes'];
