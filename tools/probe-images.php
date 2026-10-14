<?php

declare(strict_types=1);

/* ===========================================================================
   Can this server fetch the catalogue's product photographs?
   ---------------------------------------------------------------------------
   The 124-product catalogue comes with 206 image URLs across 46 foreign hosts —
   brand sites and their CDNs. Building a bulk importer that downloads them
   server-side only makes sense if the server can reach them, and this host is
   inside Iran: a great deal is reachable, a great deal is not, and which is
   which cannot be worked out from anywhere else. The same reason tools/
   probe-host.php existed for the Telegram API.

   Twelve hosts, one URL each, covering 138 of the 206 links. Reads nothing,
   writes nothing, holds no credential — it fetches a few pictures and reports
   what happened.

   CLI only, and DELETE IT when you have the answer. Everything under tools/ is
   a one-off diagnostic, not part of the shop.
   =========================================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** One representative URL per host, in descending order of how many use it. */
const PROBE_TARGETS = [
    'cdn.shopify.com'          => 'https://cdn.shopify.com/s/files/1/0454/0871/4919/files/BCAA6K300Capsules.webp?v=1750947830',
    'appliednutrition.uk'      => 'https://appliednutrition.uk/cdn/shop/files/Critical_Whey_Professional_2kg_-_Vanilla_Ice_Cream_1.webp?v=1781767209',
    'optimumnutrition.com'     => 'https://www.optimumnutrition.com/cdn/shop/files/US_GS_Casein_4lb_Chocolate_FOP.png?v=1780494368&width=2500',
    'muscletech.com'           => 'https://www.muscletech.com/cdn/shop/files/mt-nitro-tech-100-whey-gold-french-vanilla-2lb_5eb1c01a-5fe3-44fd-8754-a06f77818fae.png?v=1753903525',
    'allmaxnutrition.com'      => 'https://www.allmaxnutrition.com/cdn/shop/files/isoflex-5lb-allmax-chocolate-usa_1.webp',
    'dymatize.imgix.net'       => 'https://dymatize.imgix.net/production/products/ELITE_100_WHEY_COOKIES_CREAM_Product_Thumbnail_Product_Detail_Page_540x678.jpg',
    'qntsport.com'             => 'https://www.qntsport.com/cdn/shop/files/Meta_Whey_Belgian_Chocolate_2kg.webp?v=1768900410&width=1200',
    'evogennutrition.com'      => 'https://www.evogennutrition.com/cdn/shop/files/IsoJect_renders_NO_BAND_2026_Chocolate_Cake_front.png?v=1780496263&width=1080',
    'animalpak.com'            => 'https://www.animalpak.com/cdn/shop/files/Creatine_500g_Unflavored_1200x1200_96a0f2dc-3eaf-4be6-8a47-4e94d58c8f93_1200x1200.jpg?v=1762196608',
    'trec.co.uk'               => 'https://trec.co.uk/cdn/shop/products/cm3-powder-500g-jar_1.jpg?v=1764273372',
    'ronniecoleman.net'        => 'https://ronniecoleman.net/cdn/shop/files/ronnie-coleman-signature-series-creatine-xs-120-serving-essentials-1222237573.png?v=1771942208&width=2417',
    'za.usn.global'            => 'https://za.usn.global/cdn/shop/files/usn-sa-pure-creatine-300g.webp?v=1776060966&width=1946',
];

const PROBE_TIMEOUT = 12;

/**
 * Fetch one URL and describe what came back.
 *
 * Both branches check the STATUS, not merely whether bytes arrived. An earlier
 * probe in this project reported a 404 page as a success because it only asked
 * whether the body was non-empty, and a wrong answer from a diagnostic is worse
 * than no answer.
 *
 * @return array{ok:bool,status:int|null,bytes:int,ms:int,type:string,note:string}
 */
function probe_fetch(string $url): array
{
    $t0 = microtime(true);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => PROBE_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            /* Some brand CDNs answer a bare request with 403. This is the same
               user agent a visitor's browser sends, and the question being
               asked is whether the picture is fetchable at all. */
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SuppexImageProbe/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err    = curl_error($ch);
        curl_close($ch);

        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($body === false) {
            return ['ok' => false, 'status' => null, 'bytes' => 0, 'ms' => $ms,
                    'type' => '', 'note' => $err !== '' ? $err : 'no answer'];
        }
        return [
            'ok'     => $status >= 200 && $status < 300 && strlen($body) > 1024,
            'status' => $status,
            'bytes'  => strlen($body),
            'ms'     => $ms,
            'type'   => $type,
            'note'   => '',
        ];
    }

    /* No curl. fopen wrappers, with the status read out of the response header
       array rather than inferred from a non-empty body. */
    $ctx = stream_context_create(['http' => [
        'timeout'       => PROBE_TIMEOUT,
        'user_agent'    => 'Mozilla/5.0 (compatible; SuppexImageProbe/1.0)',
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $ms   = (int) round((microtime(true) - $t0) * 1000);

    $status = null;
    $type   = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $status = (int) $m[1]; }
        if (stripos($h, 'content-type:') === 0) { $type = trim(substr($h, 13)); }
    }

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'bytes' => 0, 'ms' => $ms,
                'type' => '', 'note' => 'no answer'];
    }
    return [
        'ok'     => $status !== null && $status >= 200 && $status < 300 && strlen($body) > 1024,
        'status' => $status,
        'bytes'  => strlen($body),
        'ms'     => $ms,
        'type'   => $type,
        'note'   => '',
    ];
}

echo "SUPPEX — can this server fetch product photographs?\n";
echo str_repeat('=', 66) . "\n";
printf("php %s   curl %s\n\n", PHP_VERSION,
    function_exists('curl_init') ? 'yes' : 'NO (falling back to fopen)');

$good = 0;
$bad  = [];

foreach (PROBE_TARGETS as $host => $url) {
    $r = probe_fetch($url);
    if ($r['ok']) {
        $good++;
        printf("  OK    %-24s %3d  %6.0f KB  %5d ms  %s\n",
            $host, $r['status'], $r['bytes'] / 1024, $r['ms'], $r['type']);
    } else {
        $bad[] = $host;
        printf("  FAIL  %-24s %s  %s\n", $host,
            $r['status'] === null ? ' — ' : sprintf('%3d', $r['status']),
            $r['note'] !== '' ? $r['note'] : 'unusable answer');
    }
}

echo "\n" . str_repeat('-', 66) . "\n";
printf("  reachable: %d of %d hosts\n", $good, count(PROBE_TARGETS));
if ($bad) {
    echo "  unreachable: " . implode(', ', $bad) . "\n";
}
echo "\n";
echo $good === count(PROBE_TARGETS)
    ? "  Everything answered. A bulk importer can download these on the server.\n"
    : ($good === 0
        ? "  Nothing answered. The pictures will have to be downloaded elsewhere\n"
        . "  and uploaded, rather than fetched by the server.\n"
        : "  Mixed. Some can be fetched here and the rest cannot; whichever tool\n"
        . "  gets built has to cope with both.\n");
echo "\n  Delete this file when you have the answer.\n";
