<?php
/* ===========================================================================
   The journal: three Markdown files, read from disk and rendered on request.
   ---------------------------------------------------------------------------
   The alternative was to pre-render the articles to static HTML. Rejected: the
   shop would then have no way to fix a typo without someone re-running a build
   that only exists on a developer's machine. These files are editable in the
   cPanel file manager and the page updates.

   The renderer below handles exactly the Markdown these articles contain -
   `##` headings, `-` lists, `**bold**`, and blank-line-separated paragraphs.
   Nothing else. That is not laziness: a general Markdown parser is a large
   surface that emits HTML, and this needs to emit HTML from files that will one
   day be edited by someone who is not thinking about HTML.

   The safety rule is one line and it is the whole design: EVERY character is
   HTML-escaped before any markup is added. Escaping first and marking up second
   means nothing in an article file can ever become a tag - if a future author
   writes <script> in a paragraph, the reader sees the text <script>.
   =========================================================================== */

const JOURNAL_DIR = SUPPEX_ROOT . '/content/journal';

/** Slugs are file names, so they may only be what a file name may be. */
function journal_slug_ok(string $slug): bool
{
    return $slug !== '' && (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug);
}

/**
 * One article, or null.
 *
 * @return array{slug:string,title:string,category:string,readTime:int,
 *               summary:string,sources:string[],body:string}|null
 */
function journal_get(string $slug): ?array
{
    if (!journal_slug_ok($slug)) {
        return null;
    }
    $path = JOURNAL_DIR . '/' . $slug . '.md';
    /* realpath before is_file: a slug that passed the pattern still must not
       resolve outside the content directory. */
    $real = realpath($path);
    $base = realpath(JOURNAL_DIR);
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
        return null;                    // a file with no title is not an article
    }

    return [
        'slug'     => $slug,
        'title'    => $title,
        'category' => trim((string) ($front['category'] ?? '')),
        'readTime' => max(1, (int) ($front['readTime'] ?? 1)),
        'summary'  => trim((string) ($front['summary'] ?? '')),
        /* Falls back rather than 404s: a missing image is a cosmetic
           problem, not a reason to hide an article. */
        'image'    => trim((string) ($front['image'] ?? 'assets/images/journal/j1.svg')),
        'sources'  => is_array($front['sources'] ?? null) ? $front['sources'] : [],
        'body'     => $body,
    ];
}

/** Every article, newest-first order is not meaningful here so it is by name. */
function journal_all(): array
{
    $out = [];
    foreach (glob(JOURNAL_DIR . '/*.md') ?: [] as $file) {
        $slug = basename($file, '.md');
        $a = journal_get($slug);
        if ($a !== null) {
            $out[] = $a;
        }
    }
    return $out;
}

/**
 * Split the front matter from the body.
 *
 * Deliberately not a YAML parser. The front matter is `key: value`, plus one
 * list form - a bare `key:` followed by indented `- item` lines. Anything else
 * in the block is ignored rather than guessed at.
 *
 * @return array{0:array<string,mixed>,1:string}
 */
function journal_split(string $raw): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $eol = '(?:\r\n|\n|\r)';
    if (!preg_match('/^---' . $eol . '(.*?)' . $eol . '---' . $eol . '(.*)$/s', $raw, $m)) {
        return [[], $raw];
    }

    $front = [];
    $listKey = null;
    foreach (preg_split('/' . $eol . '/', $m[1]) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        /* An indented "- item" continues the list opened by the last bare key. */
        if ($listKey !== null && preg_match('/^\s+-\s*(.*)$/', $line, $li)) {
            $front[$listKey][] = journal_unquote($li[1]);
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $kv)) {
            $key = $kv[1];
            $val = trim($kv[2]);
            if ($val === '') {
                $front[$key] = [];
                $listKey = $key;
            } else {
                $front[$key] = journal_unquote($val);
                $listKey = null;
            }
        }
    }

    return [$front, $m[2]];
}

/** Strip one layer of matching quotes, if present. */
function journal_unquote(string $v): string
{
    $v = trim($v);
    $len = strlen($v);
    if ($len >= 2) {
        $a = $v[0];
        if (($a === '"' || $a === "'") && $v[$len - 1] === $a) {
            return substr($v, 1, $len - 2);
        }
    }
    return $v;
}

/**
 * The body, as HTML.
 *
 * Escapes first, marks up second. Nothing in the source can produce a tag.
 */
function journal_render(string $body): string
{
    $eol   = '(?:\r\n|\n|\r)';
    $lines = preg_split('/' . $eol . '/', trim($body)) ?: [];

    $out  = [];
    $para = [];
    $list = [];

    $flushPara = static function () use (&$para, &$out): void {
        if ($para) {
            $out[] = '<p>' . journal_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $flushList = static function () use (&$list, &$out): void {
        if ($list) {
            $items = '';
            foreach ($list as $li) {
                $items .= '<li>' . journal_inline($li) . '</li>';
            }
            $out[] = '<ul class="article__list">' . $items . '</ul>';
            $list = [];
        }
    };

    foreach ($lines as $line) {
        $t = rtrim($line);

        if (trim($t) === '') {
            $flushPara();
            $flushList();
            continue;
        }
        if (preg_match('/^(#{2,4})\s+(.*)$/', $t, $h)) {
            $flushPara();
            $flushList();
            /* h1 belongs to the page, not to the body: the article's own title
               is already the h1, so `##` in the file becomes h2 and the outline
               stays legal. */
            $level = min(4, strlen($h[1]));
            $out[] = '<h' . $level . '>' . journal_inline(trim($h[2])) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $t, $li)) {
            $flushPara();
            $list[] = trim($li[1]);
            continue;
        }
        $flushList();
        $para[] = trim($t);
    }
    $flushPara();
    $flushList();

    return implode("\n", $out);
}

/**
 * Inline markup, applied to already-escaped text.
 *
 * Only **bold**, and only the Latin digit runs the rest of the site isolates.
 * The .num treatment is why this exists at all: without it a price or a dose
 * inside Persian reorders, which the site fixed everywhere else already.
 */
function journal_inline(string $text): string
{
    $safe = e($text);
    $safe = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $safe);
    /* Latin digit runs, with their decimals, percent sign and unit-joining
       characters, wrapped so they read left-to-right inside Persian. */
    $safe = preg_replace('/(?<![\w>])((?:\d[\d,.\/]*)(?:\s*%)?)/u', '<span class="num">$1</span>', $safe);
    return $safe;
}
