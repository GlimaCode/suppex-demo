<?php
/* ===========================================================================
   The <head> lines every storefront page shares: the icon, the fonts the
   first paint needs, the one stylesheet.

   The fonts are preloaded because PageSpeed measured them moving the page:
   with font-display: swap the text is drawn in a fallback face first and
   jumps when Vazirmatn arrives, which on a phone was a layout shift of 0.14 -
   past Google's 0.1 limit for «good». Asked for at the same moment as the
   stylesheet, they are usually there before the first paint and nothing
   jumps. Three only: the two weights of the Persian text and Archivo Black, the
   display face (the logo was set in it until 2026-10-05; it is drawn now). The URLs are the
   ones the stylesheet asks for, byte for byte, or the browser would download
   each font twice. crossorigin is required for a font preload even on the
   same site; without it the preload is thrown away.

   index.php puts these same lines into index.html's head, so the home page
   and every other page stay one design.
   =========================================================================== */
if (!defined('SUPPEX_ROOT')) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/assets.php';
?>
<?php /* Versioned like the stylesheet: a browser keeps a tab icon long after it
         changes, and the 30 days .htaccess gives an svg would keep the old logo. */ ?>
<link rel="icon" href="<?= e(asset('assets/icons/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('assets/icons/apple-touch-icon.png')) ?>">
<link rel="preload" href="assets/fonts/Vazirmatn-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/Vazirmatn-Bold.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/archivoblack-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset(ASSET_CSS_BUNDLE)) ?>">
