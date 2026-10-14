<?php
/* ===========================================================================
   SUPPEX — Asset URLs and the one stylesheet
   ---------------------------------------------------------------------------
   Two jobs, both for speed on a slow connection and on a host that answers
   every request through a web-application firewall:

   asset()   A URL that names the bytes it points at, «site.css?v=<mtime>».
             Uploading a file changes its time and so its URL, which is what
             lets the browser keep a copy for a year instead of asking the
             server about it on every page (.htaccess gives these long
             lifetimes; see «Caching» there). Until now CSS and JS were
             revalidated on every page view, because nothing fingerprinted
             them and index.html was a static file that could not.

   The bundle  tokens.css, base.css, components.css and layout.css, in the
             order every page linked them, as one file with the comments and
             the runs of whitespace taken out. Four render-blocking requests
             were the largest item in PageSpeed's report (about 1.3 s of the
             first paint on a phone), and the comments - which are most of
             these files - were a sixth of the bytes.

             The four files stay the source: edit them, then run
             tools/build-css.php. tests/test-speed.php rebuilds the bundle and
             fails while the file on disk is stale, so a forgotten rebuild is a
             red test rather than a site that silently ignores an edit.
   =========================================================================== */

declare(strict_types=1);

/** The sources, in link order - later rules win, so the order is the design. */
const ASSET_CSS_SOURCES = [
    'assets/css/tokens.css',
    'assets/css/base.css',
    'assets/css/components.css',
    'assets/css/layout.css',
];

const ASSET_CSS_BUNDLE = 'assets/css/site.css';

/**
 * The URL of a file under the site root, carrying its version.
 *
 * The file's modification time, in base 36 to keep it short. A file that is
 * not there gets its bare path, which is no worse than before.
 */
function asset(string $path): string
{
    static $memo = [];
    if (!isset($memo[$path])) {
        $mtime = @filemtime(SUPPEX_ROOT . '/' . ltrim($path, '/'));
        $memo[$path] = $mtime ? $path . '?v=' . base_convert((string) $mtime, 10, 36) : $path;
    }
    return $memo[$path];
}

/**
 * Comments out, whitespace down to what the grammar needs.
 *
 * Deliberately conservative. It never touches the inside of a quoted string,
 * never removes a space that could be a descendant combinator or a separator
 * inside calc(), and only tightens around the four characters where a space
 * can never mean anything: { } ; and ,. What a real minifier would save beyond
 * that is a few hundred bytes; what it can break is the page.
 */
function asset_css_minify(string $css): string
{
    /* Pass one: strings copied as they are; comments become one space;
       every run of whitespace becomes one space. */
    $out   = '';
    $n     = strlen($css);
    $quote = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $css[$i];
        if ($quote !== null) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $n) {
                $out .= $css[++$i];
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
            $out  .= $c;
            continue;
        }
        if ($c === '/' && $i + 1 < $n && $css[$i + 1] === '*') {
            $end  = strpos($css, '*/', $i + 2);
            $i    = $end === false ? $n : $end + 1;
            /* A comment is not whitespace: «.a/**\/.b» is «.a.b». It becomes a
               space only where dropping it would fuse two words into one -
               «1px/**\/solid» must not turn into a unit called «pxsolid». */
            $prev = $out === '' ? '' : $out[strlen($out) - 1];
            $next = $i + 1 < $n ? $css[$i + 1] : '';
            if (preg_match('/\w/', $prev) && preg_match('/\w/', $next)) {
                $out .= ' ';
            }
            continue;
        }
        if (ctype_space($c)) {
            while ($i + 1 < $n && ctype_space($css[$i + 1])) {
                $i++;
            }
            $out .= ' ';
            continue;
        }
        $out .= $c;
    }

    /* Pass two, again outside strings: no space beside { } ; , and no ; just
       before a }. */
    $tight = '';
    $n     = strlen($out);
    $quote = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $out[$i];
        if ($quote !== null) {
            $tight .= $c;
            if ($c === '\\' && $i + 1 < $n) {
                $tight .= $out[++$i];
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote  = $c;
            $tight .= $c;
            continue;
        }
        if ($c === ' ') {
            $prev = $tight === '' ? '' : $tight[strlen($tight) - 1];
            $next = $i + 1 < $n ? $out[$i + 1] : '';
            if ($prev === '' || strpos('{};,', $prev) !== false || strpos('{};,', $next) !== false) {
                continue;
            }
        }
        if ($c === '}' && $tight !== '' && $tight[strlen($tight) - 1] === ';') {
            $tight = substr($tight, 0, -1);
        }
        $tight .= $c;
    }

    return trim($tight);
}

/** The bundle as it should be on disk, built from the sources. */
function asset_css_bundle(): string
{
    $out = '/* SUPPEX - built by tools/build-css.php from '
         . implode(', ', array_map('basename', ASSET_CSS_SOURCES))
         . '. Edit those and rebuild; never this file. */' . "\n";
    foreach (ASSET_CSS_SOURCES as $src) {
        $out .= asset_css_minify((string) file_get_contents(SUPPEX_ROOT . '/' . $src)) . "\n";
    }
    return $out;
}
