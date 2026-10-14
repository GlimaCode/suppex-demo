<?php
/* ===========================================================================
   SUPPEX — the data a page needs, sent with the page
   ---------------------------------------------------------------------------
   The storefront draws itself from ?r= API calls (assets/js/repo.js). On this
   host that is the slowest thing it does: every call is its own PHP process
   behind the firewall, and the host runs only a few at once. Measured from
   outside Iran, five calls fired together - what the home page does - came
   back in 3.5 s, 3.6 s, 4.9 s, 18.8 s and 19.0 s. PageSpeed, which runs on
   Google's own machines, gave up on four of them, so Google's renderer saw a
   home page with no products, no deals and no brands. Every page also asked
   for ?r=config twice.

   So the page carries the answers. boot_script() works out exactly what each
   of those calls would return - the same library functions api/index.php
   calls, with the same arguments - and writes them into the page as
   window.SUPPEX_BOOT, keyed by route and parameters. repo.js answers from it
   before it thinks of the network. Nothing about the API changes: a call the
   page did not prepare for still goes to the server as it always did.

   The key is the route and its parameters, empty ones left out, sorted by
   name: «products?badge=topdeal», «products?category=protein&limit=60». The
   same rule lives in repo.js (bootKey); tests/boot-keys.json is one table
   both are held to - boot_key() by tests/test-speed.php, bootKey() by
   tests/test-speed.mjs - because a key that differs by one character is a
   page that quietly goes back to the network.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/products.php';

/** The key repo.js looks an answer up by. */
function boot_key(string $route, array $params = []): string
{
    $kept = [];
    foreach ($params as $k => $v) {
        if ($v === null || $v === '' || $v === false) {
            continue;
        }
        $kept[(string) $k] = is_bool($v) ? '1' : (string) $v;
    }
    if (!$kept) {
        return $route;
    }
    ksort($kept, SORT_STRING);
    $pairs = [];
    foreach ($kept as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return $route . '?' . implode('&', $pairs);
}

/**
 * What api/index.php would answer for one GET route - null for a route that
 * is not prepared here, which leaves it to the network.
 */
function boot_answer(string $route, array $params)
{
    switch ($route) {
        case 'config':
            return storefront_config();
        case 'categories':
            return categories_list();
        case 'brands':
            return array_map('brand_api', brands_list());
        case 'products':
            return products_list([
                'category'    => $params['category']    ?? null,
                'notCategory' => $params['notCategory'] ?? null,
                'badge'       => $params['badge']       ?? null,
                'exclude'     => $params['exclude']     ?? null,
                'brand'       => $params['brand']       ?? null,
                'limit'       => $params['limit']       ?? null,
            ]);
        case 'product':
            return product_get(clean_text((string) ($params['slug'] ?? ''), 120));
    }
    return null;
}

/**
 * <script>window.SUPPEX_BOOT = {...}</script> for these calls.
 *
 * Every page gets the shop's settings; a page adds what it asks for with
 * [route, params] pairs in $requests - or [route, params, answer] when the
 * page has already worked the answer out for its own markup, so nothing is
 * queried twice. A call that fails here is simply left out - the browser then
 * asks the API, exactly as before this existed.
 *
 * @param array<int,array{0:string,1?:array,2?:mixed}> $requests
 */
function boot_script(array $requests = []): string
{
    array_unshift($requests, ['config', []]);

    $data = [];
    foreach ($requests as $req) {
        $route  = (string) ($req[0] ?? '');
        $params = (array) ($req[1] ?? []);
        $key    = boot_key($route, $params);
        if ($route === '' || array_key_exists($key, $data)) {
            continue;
        }
        try {
            $answer = array_key_exists(2, $req) ? $req[2] : boot_answer($route, $params);
        } catch (Throwable $e) {
            error_log('[suppex] boot ' . $key . ': ' . $e->getMessage());
            continue;
        }
        /* A product that is not there is the API's 404, not a null the page
           would draw as an empty product. */
        if ($answer === null) {
            continue;
        }
        $data[$key] = $answer;
    }

    if (!$data) {
        return '';
    }

    /* The HEX flags make «</script>» and friends inside a product name
       harmless in a script element; JSON.parse gives the text back exactly. */
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) {
        return '';
    }

    return '<script>window.SUPPEX_BOOT=' . $json . ';</script>';
}
