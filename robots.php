<?php
/* ===========================================================================
   SUPPEX — robots.txt
   ---------------------------------------------------------------------------
   Generated, not static, for one reason: the sitemaps protocol requires the
   Sitemap directive to be a FULLY-QUALIFIED URL. A relative "/sitemap.xml" is
   silently ignored, and a hardcoded domain is wrong the moment the site is
   previewed on a staging subdomain or the domain changes.

   Served at /robots.txt through a rewrite in .htaccess.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/seo.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$origin = seo_origin();
?>
User-agent: *

# Not pages. A crawler that finds these wastes its budget at best.
Disallow: /admin/
Disallow: /setup.php
Disallow: /lib/
Disallow: /db/

# Search results are infinite and thin: every query string is a new URL whose
# content already exists on the pages it links to.
Disallow: /*?q=
Disallow: /*&q=

# Two things are deliberately NOT blocked, because blocking them is the usual
# way a shop like this quietly removes itself from search:
#
#   /uploads/ - blocking it strips every product photo out of image search AND
#               out of the link previews this shop actually sells through.
#   /api/     - the storefront hydrates from it. Google will not render
#               JavaScript whose fetches are robots-blocked, so disallowing it
#               erases the price, title and stock from the rendered page
#               entirely. It looks tidy and it is self-harm.

Sitemap: <?= $origin ?>/sitemap.xml
