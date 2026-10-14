<?php
/* ===========================================================================
   SUPPEX — build assets/css/site.css
   ---------------------------------------------------------------------------
   php tools/build-css.php

   Run after editing any of tokens.css, base.css, components.css, layout.css.
   The pages link only the bundle (partials/head-assets.php), so an edit to a
   source does nothing on the site until this has run and site.css has been
   uploaded with it. tests/test-speed.php fails while the bundle is stale.

   Command line only: there is nothing here a browser should be able to start.
   =========================================================================== */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!defined('SUPPEX_ROOT')) {
    define('SUPPEX_ROOT', dirname(__DIR__));
}
require_once SUPPEX_ROOT . '/lib/assets.php';

$bundle = asset_css_bundle();
$target = SUPPEX_ROOT . '/' . ASSET_CSS_BUNDLE;
$before = is_file($target) ? (string) file_get_contents($target) : '';

if ($before === $bundle) {
    echo "site.css is up to date (" . strlen($bundle) . " bytes)\n";
    exit(0);
}

file_put_contents($target, $bundle);
$sources = 0;
foreach (ASSET_CSS_SOURCES as $src) {
    $sources += (int) filesize(SUPPEX_ROOT . '/' . $src);
}
printf("site.css written: %d bytes from %d bytes of sources (%.0f%%)\n",
       strlen($bundle), $sources, 100 * strlen($bundle) / max(1, $sources));
