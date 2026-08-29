<?php
/* ===========================================================================
   SUPPEX — Persian text for GD
   ---------------------------------------------------------------------------
   GD draws glyphs. It does not shape them.

   Hand a Persian string to imagettftext() and every letter comes out in its
   isolated form, left to right — «سلام» renders as four separate shapes in the
   wrong order, which a Persian reader does not read as a word at all. Browsers
   do this work in the text engine; a PNG has no text engine.

   So the string is shaped here before it is drawn: each letter is replaced by
   the contextual form its neighbours call for, and the result is reversed so
   that drawing it left to right puts it on the page right to left.

   This is deliberately NOT a full bidirectional algorithm. It handles the one
   case this codebase needs — a product name, which is Persian with occasional
   Latin words and digits embedded — and it does that correctly. Anything
   beyond that (nested direction changes, Arabic diacritics, kashida
   justification) is out of scope and would be the wrong thing to grow here.
   =========================================================================== */

declare(strict_types=1);

/* Every letter, with the four forms it can take:
   [isolated, final, initial, medial].

   A letter that never joins to its left — the seven "non-joiners" ا د ذ ر ز ژ و
   plus ة ء — has only two forms, so its initial and medial entries repeat the
   isolated and final ones. That is what makes «ابر» three separate shapes and
   «بابا» two joined pairs. */
const ARABIC_FORMS = [
    "\u{0627}" => ["\u{FE8D}", "\u{FE8E}", "\u{FE8D}", "\u{FE8E}"], // ا
    "\u{0628}" => ["\u{FE8F}", "\u{FE90}", "\u{FE91}", "\u{FE92}"], // ب
    "\u{067E}" => ["\u{FB56}", "\u{FB57}", "\u{FB58}", "\u{FB59}"], // پ
    "\u{062A}" => ["\u{FE95}", "\u{FE96}", "\u{FE97}", "\u{FE98}"], // ت
    "\u{062B}" => ["\u{FE99}", "\u{FE9A}", "\u{FE9B}", "\u{FE9C}"], // ث
    "\u{062C}" => ["\u{FE9D}", "\u{FE9E}", "\u{FE9F}", "\u{FEA0}"], // ج
    "\u{0686}" => ["\u{FB7A}", "\u{FB7B}", "\u{FB7C}", "\u{FB7D}"], // چ
    "\u{062D}" => ["\u{FEA1}", "\u{FEA2}", "\u{FEA3}", "\u{FEA4}"], // ح
    "\u{062E}" => ["\u{FEA5}", "\u{FEA6}", "\u{FEA7}", "\u{FEA8}"], // خ
    "\u{062F}" => ["\u{FEA9}", "\u{FEAA}", "\u{FEA9}", "\u{FEAA}"], // د
    "\u{0630}" => ["\u{FEAB}", "\u{FEAC}", "\u{FEAB}", "\u{FEAC}"], // ذ
    "\u{0631}" => ["\u{FEAD}", "\u{FEAE}", "\u{FEAD}", "\u{FEAE}"], // ر
    "\u{0632}" => ["\u{FEAF}", "\u{FEB0}", "\u{FEAF}", "\u{FEB0}"], // ز
    "\u{0698}" => ["\u{FB8A}", "\u{FB8B}", "\u{FB8A}", "\u{FB8B}"], // ژ
    "\u{0633}" => ["\u{FEB1}", "\u{FEB2}", "\u{FEB3}", "\u{FEB4}"], // س
    "\u{0634}" => ["\u{FEB5}", "\u{FEB6}", "\u{FEB7}", "\u{FEB8}"], // ش
    "\u{0635}" => ["\u{FEB9}", "\u{FEBA}", "\u{FEBB}", "\u{FEBC}"], // ص
    "\u{0636}" => ["\u{FEBD}", "\u{FEBE}", "\u{FEBF}", "\u{FEC0}"], // ض
    "\u{0637}" => ["\u{FEC1}", "\u{FEC2}", "\u{FEC3}", "\u{FEC4}"], // ط
    "\u{0638}" => ["\u{FEC5}", "\u{FEC6}", "\u{FEC7}", "\u{FEC8}"], // ظ
    "\u{0639}" => ["\u{FEC9}", "\u{FECA}", "\u{FECB}", "\u{FECC}"], // ع
    "\u{063A}" => ["\u{FECD}", "\u{FECE}", "\u{FECF}", "\u{FED0}"], // غ
    "\u{0641}" => ["\u{FED1}", "\u{FED2}", "\u{FED3}", "\u{FED4}"], // ف
    "\u{0642}" => ["\u{FED5}", "\u{FED6}", "\u{FED7}", "\u{FED8}"], // ق
    "\u{06A9}" => ["\u{FB8E}", "\u{FB8F}", "\u{FB90}", "\u{FB91}"], // ک (Persian keheh)
    "\u{0643}" => ["\u{FED9}", "\u{FEDA}", "\u{FEDB}", "\u{FEDC}"], // ك (Arabic kaf)
    "\u{06AF}" => ["\u{FB92}", "\u{FB93}", "\u{FB94}", "\u{FB95}"], // گ
    "\u{0644}" => ["\u{FEDD}", "\u{FEDE}", "\u{FEDF}", "\u{FEE0}"], // ل
    "\u{0645}" => ["\u{FEE1}", "\u{FEE2}", "\u{FEE3}", "\u{FEE4}"], // م
    "\u{0646}" => ["\u{FEE5}", "\u{FEE6}", "\u{FEE7}", "\u{FEE8}"], // ن
    "\u{0648}" => ["\u{FEED}", "\u{FEEE}", "\u{FEED}", "\u{FEEE}"], // و
    "\u{0647}" => ["\u{FEE9}", "\u{FEEA}", "\u{FEEB}", "\u{FEEC}"], // ه
    "\u{06CC}" => ["\u{FBFC}", "\u{FBFD}", "\u{FBFE}", "\u{FBFF}"], // ی (Persian yeh)
    "\u{064A}" => ["\u{FEF1}", "\u{FEF2}", "\u{FEF3}", "\u{FEF4}"], // ي (Arabic yeh)
    "\u{0649}" => ["\u{FEEF}", "\u{FEF0}", "\u{FEEF}", "\u{FEF0}"], // ى
    "\u{0622}" => ["\u{FE81}", "\u{FE82}", "\u{FE81}", "\u{FE82}"], // آ
    "\u{0623}" => ["\u{FE83}", "\u{FE84}", "\u{FE83}", "\u{FE84}"], // أ
    "\u{0625}" => ["\u{FE87}", "\u{FE88}", "\u{FE87}", "\u{FE88}"], // إ
    "\u{0624}" => ["\u{FE85}", "\u{FE86}", "\u{FE85}", "\u{FE86}"], // ؤ
    "\u{0626}" => ["\u{FE89}", "\u{FE8A}", "\u{FE8B}", "\u{FE8C}"], // ئ
    "\u{0629}" => ["\u{FE93}", "\u{FE94}", "\u{FE93}", "\u{FE94}"], // ة
    "\u{0621}" => ["\u{FE80}", "\u{FE80}", "\u{FE80}", "\u{FE80}"], // ء
];

/** Letters that accept a join on their right — i.e. the previous letter may
    connect forward into them. Every letter in the table does. */
const ARABIC_JOINS_BEFORE = true;

/* لا and its variants are a required ligature: rendering ل and ا separately is
   not merely ugly, it is wrong — no Persian font draws them apart. */
const ARABIC_LAM_ALEF = [
    "\u{0644}\u{0627}" => ["\u{FEFB}", "\u{FEFC}"],
    "\u{0644}\u{0622}" => ["\u{FEF5}", "\u{FEF6}"],
    "\u{0644}\u{0623}" => ["\u{FEF7}", "\u{FEF8}"],
    "\u{0644}\u{0625}" => ["\u{FEF9}", "\u{FEFA}"],
];

/** True when this letter can join forward, to the letter that follows it. */
function arabic_joins_forward(string $ch): bool
{
    if (!isset(ARABIC_FORMS[$ch])) {
        return false;
    }
    /* The non-joiners are exactly those whose initial form equals their
       isolated form — which is how the table above encodes them. */
    return ARABIC_FORMS[$ch][2] !== ARABIC_FORMS[$ch][0];
}

/**
 * Shape a Persian string and put it in visual order.
 *
 * The result is meant only for drawing left to right into a bitmap. It is not
 * text any more: the code points are presentation forms, and the order is
 * reversed. Never store it, never index on it, never hand it back to a browser.
 */
function arabic_shape(string $text): string
{
    /* Zero-width non-joiner does exactly one thing here — it stops the join,
       which is the whole point of «می‌خواهم» — and must not be drawn. */
    $zwnj = "\u{200C}";

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $n     = count($chars);

    /* --- lam-alef first, so the pair is one unit for the joining pass ---- */
    $units = [];
    for ($i = 0; $i < $n; $i++) {
        $pair = $chars[$i] . ($chars[$i + 1] ?? '');
        if (isset(ARABIC_LAM_ALEF[$pair])) {
            $units[] = ['lam_alef', ARABIC_LAM_ALEF[$pair]];
            $i++;
            continue;
        }
        $units[] = ['char', $chars[$i]];
    }

    $out = [];
    $m   = count($units);

    for ($i = 0; $i < $m; $i++) {
        [$kind, $val] = $units[$i];

        /* What sits before, skipping ZWNJ only when deciding whether to join —
           the ZWNJ itself breaks the join, so it is NOT skipped. */
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            [$k, $v] = $units[$j];
            $prev = ($k === 'lam_alef') ? null : $v;   // alef never joins forward
            break;
        }
        $next = null;
        for ($j = $i + 1; $j < $m; $j++) {
            [$k, $v] = $units[$j];
            $next = ($k === 'lam_alef') ? "\u{0644}" : $v;
            break;
        }

        $joinedBefore = $prev !== null && $prev !== $zwnj && arabic_joins_forward($prev);
        $joinedAfter  = $next !== null && $next !== $zwnj && isset(ARABIC_FORMS[$next]);

        if ($kind === 'lam_alef') {
            /* Lam-alef never joins forward, so only what precedes it matters. */
            $out[] = $val[$joinedBefore ? 1 : 0];
            continue;
        }

        if ($val === $zwnj) {
            continue;                       // it did its work; it is not a glyph
        }

        if (!isset(ARABIC_FORMS[$val])) {
            $out[] = $val;                  // Latin, digits, punctuation
            continue;
        }

        $canJoinAfter = $joinedAfter && arabic_joins_forward($val);

        if ($joinedBefore && $canJoinAfter)      { $form = 3; }   // medial
        elseif ($joinedBefore)                   { $form = 1; }   // final
        elseif ($canJoinAfter)                   { $form = 2; }   // initial
        else                                     { $form = 0; }   // isolated

        $out[] = ARABIC_FORMS[$val][$form];
    }

    return arabic_visual_order($out);
}

/**
 * Put shaped glyphs in the order they must be drawn.
 *
 * Persian runs right to left, so the array is reversed — but a Latin word or a
 * number embedded in it runs left to right and must survive the reversal
 * intact. Those runs are therefore reversed twice: once with everything else,
 * once on their own.
 *
 * @param array<int,string> $glyphs
 */
function arabic_visual_order(array $glyphs): string
{
    $isLtr = static function (string $c): bool {
        /* Latin letters, digits and the punctuation that travels with them.
           A space is neutral and belongs to whichever run it sits in, so it is
           not counted as LTR on its own. */
        return preg_match('/^[0-9A-Za-z%\\.\\,\\-\\+\\/&\'"()\\[\\]:]$/u', $c) === 1;
    };

    $out = [];
    $n   = count($glyphs);
    $i   = 0;

    while ($i < $n) {
        if (!$isLtr($glyphs[$i])) {
            $out[] = $glyphs[$i];
            $i++;
            continue;
        }

        /* Take the whole Latin run, including single spaces inside it, so
           "Gold Standard" does not come apart. */
        $run = [];
        while ($i < $n) {
            if ($isLtr($glyphs[$i])) {
                $run[] = $glyphs[$i];
                $i++;
                continue;
            }
            /* A space only stays in the run if Latin continues after it. */
            if ($glyphs[$i] === ' ' && isset($glyphs[$i + 1]) && $isLtr($glyphs[$i + 1])) {
                $run[] = ' ';
                $i++;
                continue;
            }
            break;
        }
        /* Reversed here so the outer reverse below puts it back the right way. */
        $out = array_merge($out, array_reverse($run));
    }

    return implode('', array_reverse($out));
}

/** True when a string contains any Arabic-script letter. */
function arabic_has_persian(string $text): bool
{
    return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
}
