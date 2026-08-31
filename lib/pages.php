<?php
/* ===========================================================================
   SUPPEX — The standing pages: shipping, returns, FAQ, terms, privacy
   ---------------------------------------------------------------------------
   Every link to these in the footer was href="#" on all five templates, which
   is the worst of the three options. A shop that asks a stranger to transfer
   money card-to-card and then has nothing to say about returns is asking for
   trust it has not offered any reason for; a shop whose returns link goes
   nowhere has offered a reason and then withdrawn it.

   The renderer is lib/journal.php's, deliberately. That code escapes every
   character before it adds any markup, so nothing in a content file can ever
   become a tag - and these files will be edited in a cPanel file manager by
   someone who is not thinking about HTML, which is exactly the case that rule
   exists for. A second parser would be a second chance to get it wrong.

   What differs from an article is only what the front matter carries and what
   the page around it looks like: a policy has no read time, no category, no
   sources and no hero image. It has a title, a one-line summary and a date it
   was last changed - and that date matters, because a policy with no date is a
   policy nobody can tell has been kept up.
   =========================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/journal.php';

const PAGES_DIR = SUPPEX_ROOT . '/content/pages';

/* The order they appear in a footer and in each other's "see also" list.
   Explicit rather than alphabetical or filesystem order: shipping before
   returns before the rest is the order a customer actually meets them in, and
   glob() order is not a decision anybody made. */
const PAGE_ORDER = ['shipping', 'returns', 'faq', 'terms', 'privacy'];

/**
 * One standing page, or null.
 *
 * @return array{slug:string,title:string,summary:string,updated:string,body:string}|null
 */
function page_get(string $slug): ?array
{
    /* Same slug rule as the journal: these are file names, so they may only be
       what a file name may be. */
    if (!journal_slug_ok($slug)) {
        return null;
    }

    /* realpath before is_file, so a slug that satisfied the pattern still
       cannot resolve to something outside the content directory. */
    $real = realpath(PAGES_DIR . '/' . $slug . '.md');
    $base = realpath(PAGES_DIR);
    if ($real === false || $base === false || strpos($real, $base) !== 0) {
        return null;
    }

    $raw = @file_get_contents($real);
    if ($raw === false) {
        return null;
    }

    [$front, $body] = journal_split($raw);
    $title = trim((string) ($front['title'] ?? ''));
    if ($title === '') {
        return null;               // a file with no title is not a page
    }

    return [
        'slug'    => $slug,
        'title'   => $title,
        'summary' => trim((string) ($front['summary'] ?? '')),
        /* Free text on purpose. It is written in the file as a Jalali date and
           shown as typed; parsing it would mean a Gregorian round-trip to
           display something that was already correct. */
        'updated' => trim((string) ($front['updated'] ?? '')),
        'body'    => $body,
    ];
}

/**
 * Every page that exists, in PAGE_ORDER.
 *
 * Built from the order list rather than from glob(), so a stray file dropped
 * into the directory does not appear in the footer of every page on the site
 * before anybody has decided it should.
 *
 * @return array<int,array{slug:string,title:string,summary:string,updated:string,body:string}>
 */
function pages_all(): array
{
    $out = [];
    foreach (PAGE_ORDER as $slug) {
        $p = page_get($slug);
        if ($p !== null) {
            $out[] = $p;
        }
    }
    return $out;
}

/** The href for one page. One function, so a rename is one edit. */
function page_url(string $slug): string
{
    return 'page.php?p=' . rawurlencode($slug);
}
