<?php
/* ===========================================================================
   SUPPEX — Excel (.xlsx) files, read and written without any extension
   ---------------------------------------------------------------------------
   The catalogue lives in Excel, and «Save As → CSV UTF-8» was the step that
   kept going wrong: the other CSV in Excel's list drops the Persian «ی», and a
   Persian locale writes semicolons. So the importer reads the .xlsx itself,
   and the template and the export are real .xlsx files with a right-to-left
   sheet, a frozen header and a hint on every column.

   An .xlsx is a ZIP of XML parts. ZipArchive is missing on plenty of shared
   hosts - and on this project's own local PHP - so the ZIP is read and written
   here with zlib's raw deflate, which every PHP build carries. The XML is read
   with patterns rather than an XML extension for the same reason: what Excel,
   LibreOffice and Google Sheets write is machine-made and regular, and the
   reader only needs the cells of one sheet as text.

   Deliberately small: no formulas are evaluated (Excel stores the last value
   beside every formula, and that is what is read), no dates, no merged cells,
   no styles on the way in.
   =========================================================================== */

declare(strict_types=1);

/** One inflated part may not exceed this - a ZIP bomb stops here, not in RAM. */
const XLSX_MAX_PART_BYTES = 40 * 1024 * 1024;
const XLSX_MAX_ENTRIES    = 5000;

/* --------------------------------------------------------------------------
   ZIP
   -------------------------------------------------------------------------- */

/**
 * The files of a ZIP archive held in memory, inflated.
 *
 * Read from the central directory, which is the archive's own table of
 * contents; the local headers can carry zero sizes when the writer streamed
 * them, and the directory never does.
 *
 * @param callable|null $want  fn(string $name): bool - which entries to inflate
 * @return array<string,string>|string  the files by name, or what went wrong
 */
function zip_extract(string $zip, ?callable $want = null)
{
    $len = strlen($zip);
    if ($len < 22 || substr($zip, 0, 4) !== "PK\x03\x04") {
        return 'not-zip';
    }

    /* The end record sits in the last 22 bytes, or further up by the length of
       an archive comment, which can be at most 65535 bytes. */
    $from = max(0, $len - 65557);
    $eocd = strrpos(substr($zip, $from), "PK\x05\x06");
    if ($eocd === false || $from + $eocd + 22 > $len) {
        return 'broken';
    }
    $eocd += $from;
    $end = unpack('vdisk/vcdisk/vcount/vtotal/Vsize/Voffset', substr($zip, $eocd + 4, 16));
    $total  = (int) $end['total'];
    $offset = (int) $end['offset'];
    /* ZIP64 marks its overflow with all-ones; nothing a catalogue sheet needs. */
    if ($total === 0xFFFF || $offset === 0xFFFFFFFF || $total > XLSX_MAX_ENTRIES) {
        return 'unsupported';
    }

    $out = [];
    $p   = $offset;
    for ($n = 0; $n < $total; $n++) {
        if ($p + 46 > $len || substr($zip, $p, 4) !== "PK\x01\x02") {
            return 'broken';
        }
        $c = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Vlocal',
                    substr($zip, $p + 4, 42));
        $name = substr($zip, $p + 46, (int) $c['nlen']);
        $p   += 46 + (int) $c['nlen'] + (int) $c['elen'] + (int) $c['clen'];

        if ($want !== null && !$want($name)) {
            continue;
        }
        if ((int) $c['usize'] > XLSX_MAX_PART_BYTES || (int) $c['csize'] > XLSX_MAX_PART_BYTES) {
            return 'too-big';
        }

        $l = (int) $c['local'];
        if ($l + 30 > $len || substr($zip, $l, 4) !== "PK\x03\x04") {
            return 'broken';
        }
        $h    = unpack('vnlen/velen', substr($zip, $l + 26, 4));
        $data = substr($zip, $l + 30 + (int) $h['nlen'] + (int) $h['elen'], (int) $c['csize']);

        if ((int) $c['usize'] === 0) {
            $raw = '';
        } elseif ((int) $c['method'] === 0) {
            $raw = $data;
        } elseif ((int) $c['method'] === 8) {
            /* The declared size is the ceiling: a part that inflates past what
               the directory promised is corrupt or hostile either way. */
            $raw = @gzinflate($data, (int) $c['usize']);
        } else {
            return 'unsupported';
        }
        if (!is_string($raw) || strlen($raw) !== (int) $c['usize']
            || (crc32($raw) & 0xFFFFFFFF) !== ((int) $c['crc'] & 0xFFFFFFFF)) {
            return 'broken';
        }
        $out[$name] = $raw;
    }
    return $out;
}

/**
 * A ZIP archive of the given files, deflated where that helps.
 *
 * @param array<string,string> $files  name => contents
 */
function zip_build(array $files, ?int $time = null): string
{
    $t       = getdate($time ?? time());
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

    $body    = '';
    $central = '';
    $count   = 0;
    foreach ($files as $name => $data) {
        $name   = (string) $name;
        $data   = (string) $data;
        $crc    = crc32($data) & 0xFFFFFFFF;
        $packed = gzdeflate($data, 6);
        $method = 8;
        if ($packed === false || strlen($packed) >= strlen($data)) {
            $method = 0;
            $packed = $data;
        }
        /* Bit 11: the names are UTF-8. Every name written here is ASCII, but
           saying so costs nothing and keeps a future Persian name readable. */
        $flags  = 0x0800;
        $offset = strlen($body);
        $body  .= pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate,
                       $crc, strlen($packed), strlen($data), strlen($name), 0)
                . $name . $packed;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, $dosTime, $dosDate,
                         $crc, strlen($packed), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset)
                  . $name;
        $count++;
    }
    return $body . $central
         . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
}

/* --------------------------------------------------------------------------
   Reading
   -------------------------------------------------------------------------- */

/** «AB» → 27 (1-based), the column part of a cell reference. */
function xlsx_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n;
}

/** 0 → «A», 26 → «AA». */
function xlsx_col_letter(int $index): string
{
    $s = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

/** The attributes of one XML start tag, with any namespace prefix dropped. */
function xlsx_attrs(string $tag): array
{
    $out = [];
    if (preg_match_all('/([\w.:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/u', $tag, $m, PREG_SET_ORDER)) {
        foreach ($m as $a) {
            $key = $a[1];
            $pos = strrpos($key, ':');
            /* r:id keeps its prefix, so it cannot be mistaken for a plain id. */
            if ($pos !== false && substr($key, 0, $pos) !== 'r' && substr($key, 0, 5) !== 'xmlns') {
                $key = substr($key, $pos + 1);
            }
            $out[$key] = xlsx_text($a[2] !== '' || !isset($a[3]) ? $a[2] : $a[3]);
        }
    }
    return $out;
}

/** XML character data as plain text: entities, and Excel's own _xHHHH_ escapes. */
function xlsx_text(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    if (strpos($s, '_x') !== false) {
        /* Excel writes a carriage return inside a cell as «_x000D_». A literal
           «_x» is itself escaped as «_x005F_x», which this also undoes. */
        $s = (string) preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', static function ($m) {
            return mb_chr((int) hexdec($m[1]), 'UTF-8') ?: '';
        }, $s);
    }
    /* One kind of line break, whichever program saved the file. */
    return str_replace(["\r\n", "\r"], "\n", $s);
}

/** The text of a <si> or <is> run: every <t>, phonetic guides left out. */
function xlsx_runs(string $xml): string
{
    $xml = (string) preg_replace('~<(\w+:)?rPh\b.*?</(\w+:)?rPh>~s', '', $xml);
    /* (?<!/) keeps an empty <t/> from swallowing the runs after it. */
    if (!preg_match_all('~<(?:\w+:)?t\b[^>]*(?<!/)>(.*?)</(?:\w+:)?t>~s', $xml, $m)) {
        return '';
    }
    return xlsx_text(implode('', $m[1]));
}

/**
 * A number cell as the text a person typed.
 *
 * Excel keeps numbers as doubles, so 42.5 can come back as 42.499999999999993
 * and a million as 1E6. Whole numbers are written without a decimal point;
 * anything else to six places, trailing zeros trimmed.
 */
function xlsx_number(string $v): string
{
    $v = trim($v);
    if ($v === '' || !is_numeric($v)) {
        return $v;
    }
    $f = (float) $v;
    if (!is_finite($f)) {
        return $v;
    }
    if (abs($f) < 1e15 && abs($f - round($f)) < 1e-6) {
        return (string) (int) round($f);
    }
    $s = rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

/** The target of a relationship, as a path inside the archive. */
function xlsx_part_path(string $target, string $base = 'xl/'): string
{
    $target = str_replace('\\', '/', $target);
    $path   = $target[0] === '/' ? ltrim($target, '/') : $base . $target;
    $parts  = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') { array_pop($parts); }
        elseif ($seg !== '' && $seg !== '.') { $parts[] = $seg; }
    }
    return implode('/', $parts);
}

/**
 * The cells of one sheet of an .xlsx file, as text.
 *
 * The sheet is the first one whose name is in $prefer, else the first sheet
 * of the workbook. Rows are keyed by the sheet's own row numbers, so an error
 * can name the row the person sees in Excel; cells by 0-based column.
 *
 * @param array<int,string> $prefer
 * @return array{rows:array<int,array<int,string>>,sheet:string,sheets:array<int,string>,error:?string}
 */
function xlsx_read(string $path, array $prefer = []): array
{
    $fail = static fn(string $why): array => ['rows' => [], 'sheet' => '', 'sheets' => [], 'error' => $why];

    $zip = @file_get_contents($path);
    if (!is_string($zip) || $zip === '') {
        return $fail('unreadable');
    }
    $parts = zip_extract($zip, static function (string $name): bool {
        return $name === 'xl/workbook.xml' || $name === 'xl/_rels/workbook.xml.rels'
            || $name === 'xl/sharedStrings.xml' || strncmp($name, 'xl/worksheets/', 14) === 0;
    });
    unset($zip);
    if (is_string($parts)) {
        return $fail($parts);
    }
    if (!isset($parts['xl/workbook.xml'])) {
        return $fail('not-xlsx');
    }

    /* The sheets in workbook order, each with the part its relationship names. */
    $rels = [];
    if (preg_match_all('~<(?:\w+:)?Relationship\b([^>]*)/?>~', $parts['xl/_rels/workbook.xml.rels'] ?? '', $m)) {
        foreach ($m[1] as $tag) {
            $a = xlsx_attrs($tag);
            if (isset($a['Id'], $a['Target'])) {
                $rels[$a['Id']] = xlsx_part_path($a['Target']);
            }
        }
    }
    $sheets = [];
    if (preg_match_all('~<(?:\w+:)?sheet\b([^>]*)/?>~', $parts['xl/workbook.xml'], $m)) {
        foreach ($m[1] as $tag) {
            $a  = xlsx_attrs($tag);
            $id = $a['r:id'] ?? '';
            if ($id === '') {
                foreach ($a as $k => $v) {
                    if (preg_match('/(^|:)id$/', $k) && $k !== 'sheetId') { $id = $v; }
                }
            }
            $sheets[] = ['name' => (string) ($a['name'] ?? ''), 'part' => $rels[$id] ?? '',
                         'hidden' => ($a['state'] ?? '') !== '' && ($a['state'] ?? '') !== 'visible'];
        }
    }
    if (!$sheets) {
        return $fail('no-sheet');
    }

    $squash = static fn(string $s): string => preg_replace('/[\s\x{200C}]+/u', '', strtr($s, ['ي' => 'ی', 'ك' => 'ک'])) ?? $s;
    $chosen = null;
    foreach ($prefer as $want) {
        foreach ($sheets as $s) {
            if ($squash($s['name']) === $squash($want)) { $chosen = $s; break 2; }
        }
    }
    if ($chosen === null) {
        foreach ($sheets as $s) {
            if (!$s['hidden']) { $chosen = $s; break; }
        }
    }
    $chosen = $chosen ?? $sheets[0];
    $xml    = $parts[$chosen['part']] ?? null;
    if ($xml === null) {
        return $fail('no-sheet');
    }

    $shared = [];
    if (isset($parts['xl/sharedStrings.xml'])
        && preg_match_all('~<(?:\w+:)?si\b[^>]*?(?:/>|>(.*?)</(?:\w+:)?si>)~s', $parts['xl/sharedStrings.xml'], $m)) {
        foreach ($m[1] as $si) {
            $shared[] = xlsx_runs((string) $si);
        }
    }
    unset($parts);

    $rows   = [];
    $rowNum = 0;
    if (preg_match_all('~<(?:\w+:)?row\b([^>]*?)(?:/>|>(.*?)</(?:\w+:)?row>)~s', $xml, $rm, PREG_SET_ORDER)) {
        foreach ($rm as $row) {
            $ra     = xlsx_attrs($row[1]);
            $rowNum = isset($ra['r']) && ctype_digit($ra['r']) ? (int) $ra['r'] : $rowNum + 1;
            $cells  = [];
            $col    = -1;
            if (isset($row[2]) && $row[2] !== ''
                && preg_match_all('~<(?:\w+:)?c\b([^>]*?)(?:/>|>(.*?)</(?:\w+:)?c>)~s', $row[2], $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $ca  = xlsx_attrs($c[1]);
                    $col = (isset($ca['r']) && preg_match('/^([A-Za-z]+)/', $ca['r'], $lm))
                        ? xlsx_col_index($lm[1]) - 1 : $col + 1;
                    $inner = $c[2] ?? '';
                    $type  = $ca['t'] ?? 'n';
                    $v     = preg_match('~<(?:\w+:)?v\b[^>]*>(.*?)</(?:\w+:)?v>~s', $inner, $vm) ? $vm[1] : '';
                    switch ($type) {
                        case 's':
                            $val = $shared[(int) $v] ?? '';
                            break;
                        case 'inlineStr':
                            $val = xlsx_runs($inner);
                            break;
                        case 'str':
                            $val = xlsx_text($v);
                            break;
                        case 'b':
                            $val = trim($v) === '1' ? '1' : '0';
                            break;
                        case 'e':
                            $val = '';
                            break;
                        default:
                            $val = xlsx_number(xlsx_text($v));
                    }
                    if ($val !== '') {
                        $cells[$col] = $val;
                    }
                }
            }
            if ($cells) {
                ksort($cells);
                $rows[$rowNum] = $cells;
            }
        }
    }

    return ['rows' => $rows, 'sheet' => $chosen['name'],
            'sheets' => array_column($sheets, 'name'), 'error' => null];
}

/* --------------------------------------------------------------------------
   Writing
   -------------------------------------------------------------------------- */

/** Text safe for XML character data: escaped, and stripped of what XML forbids. */
function xlsx_xml(string $s): string
{
    $s = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * The style names a cell may ask for, in the order of styles.xml's cellXfs.
 * Index 0 is Excel's default.
 */
const XLSX_STYLES = ['default', 'head', 'req', 'wrap', 'money', 'title', 'note', 'cell', 'label', 'example'];

function xlsx_styles_xml(): string
{
    /* Tahoma: on a Persian Windows it is always there and draws Persian well;
       Excel's default Calibri falls back for Persian glyphs anyway. */
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        .   '<font><sz val="10"/><color rgb="FF1F1F1F"/><name val="Tahoma"/><family val="2"/></font>'
        .   '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Tahoma"/><family val="2"/></font>'
        .   '<font><b/><sz val="13"/><color rgb="FF1C1A17"/><name val="Tahoma"/><family val="2"/></font>'
        .   '<font><sz val="9"/><color rgb="FF6B6B6B"/><name val="Tahoma"/><family val="2"/></font>'
        .   '<font><b/><sz val="10"/><color rgb="FF1C1A17"/><name val="Tahoma"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="5">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF2A2622"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF25914"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF3EB"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right>'
        .     '<top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        .   '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
        .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
        .   '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        .   '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * One worksheet's XML.
 *
 * A cell is a scalar, or ['v' => scalar, 's' => style name]. A row may also
 * be ['cells' => [...], 'height' => points]. Strings are written inline, so
 * nothing in a product name can ever be read by Excel as a formula.
 *
 * @param array{rows:array,rtl?:bool,widths?:array<int,float>,freeze?:int,
 *              validations?:array<int,array>,tab?:string,selected?:bool} $sheet
 */
function xlsx_sheet_xml(array $sheet): string
{
    $styleIndex = array_flip(XLSX_STYLES);
    $maxCol = 0;
    $rowsXml = '';
    $r = 0;
    foreach ($sheet['rows'] as $row) {
        $r++;
        $height = null;
        if (isset($row['cells'])) {
            $height = $row['height'] ?? null;
            $row    = $row['cells'];
        }
        $cellsXml = '';
        foreach (array_values($row) as $i => $cell) {
            $style = 0;
            if (is_array($cell)) {
                $style = $styleIndex[$cell['s'] ?? 'default'] ?? 0;
                $cell  = $cell['v'] ?? null;
            }
            if ($cell === null || $cell === '') {
                if ($style !== 0) {
                    $cellsXml .= '<c r="' . xlsx_col_letter($i) . $r . '" s="' . $style . '"/>';
                }
                continue;
            }
            $maxCol = max($maxCol, $i + 1);
            $ref = xlsx_col_letter($i) . $r;
            $s   = $style !== 0 ? ' s="' . $style . '"' : '';
            if (is_int($cell) || is_float($cell)) {
                $cellsXml .= '<c r="' . $ref . '"' . $s . '><v>' . xlsx_number((string) $cell) . '</v></c>';
            } else {
                $text = (string) $cell;
                $keep = preg_match('/^\s|\s$|\n/u', $text) ? ' xml:space="preserve"' : '';
                $cellsXml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t' . $keep . '>'
                           . xlsx_xml($text) . '</t></is></c>';
            }
        }
        $rowsXml .= '<row r="' . $r . '"'
                  . ($height !== null ? ' ht="' . (float) $height . '" customHeight="1"' : '')
                  . '>' . $cellsXml . '</row>';
    }

    $view = '<sheetView' . (!empty($sheet['rtl']) ? ' rightToLeft="1"' : '')
          . (!empty($sheet['selected']) ? ' tabSelected="1"' : '') . ' workbookViewId="0">';
    $freeze = (int) ($sheet['freeze'] ?? 0);
    if ($freeze > 0) {
        $top   = 'A' . ($freeze + 1);
        $view .= '<pane ySplit="' . $freeze . '" topLeftCell="' . $top . '" activePane="bottomLeft" state="frozen"/>'
               . '<selection pane="bottomLeft" activeCell="' . $top . '" sqref="' . $top . '"/>';
    }
    $view .= '</sheetView>';

    $cols = '';
    foreach ((array) ($sheet['widths'] ?? []) as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
    }

    /* A hint shown when a cell of the column is selected, and for some
       columns a list to pick from. Excel caps the hint at 255 characters and
       its title at 32. */
    $dv = '';
    $dvCount = 0;
    foreach ((array) ($sheet['validations'] ?? []) as $v) {
        $attrs = '';
        if (!empty($v['list'])) {
            $attrs .= ' type="list" showErrorMessage="' . (!empty($v['strict']) ? '1' : '0') . '"';
        }
        $attrs .= ' allowBlank="1"';
        if (!empty($v['prompt'])) {
            $attrs .= ' showInputMessage="1" promptTitle="' . xlsx_xml(mb_substr((string) ($v['title'] ?? ''), 0, 32))
                    . '" prompt="' . xlsx_xml(mb_substr((string) $v['prompt'], 0, 255)) . '"';
        }
        if (!empty($v['strict'])) {
            $attrs .= ' errorTitle="' . xlsx_xml(mb_substr((string) ($v['title'] ?? ''), 0, 32)) . '"'
                    . ' error="' . xlsx_xml('یکی از گزینه‌های فهرست را انتخاب کنید.') . '"';
        }
        $dv .= '<dataValidation' . $attrs . ' sqref="' . xlsx_xml((string) $v['sqref']) . '">'
             . (!empty($v['list']) ? '<formula1>' . xlsx_xml((string) $v['list']) . '</formula1>' : '')
             . '</dataValidation>';
        $dvCount++;
    }

    $dimension = $maxCol > 0 ? 'A1:' . xlsx_col_letter($maxCol - 1) . max(1, $r) : 'A1';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . (!empty($sheet['tab']) ? '<sheetPr><tabColor rgb="' . xlsx_xml((string) $sheet['tab']) . '"/></sheetPr>' : '')
        . '<dimension ref="' . $dimension . '"/>'
        . '<sheetViews>' . $view . '</sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . ($dvCount ? '<dataValidations count="' . $dvCount . '">' . $dv . '</dataValidations>' : '')
        . '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
        . '</worksheet>';
}

/**
 * A whole .xlsx file.
 *
 * @param array<int,array{name:string,rows:array,hidden?:bool}> $sheets  in tab order
 */
function xlsx_build(array $sheets, ?int $time = null): string
{
    $ns  = 'http://schemas.openxmlformats.org/';
    $wbSheets = '';
    $wbRels   = '';
    $types    = '';
    $files    = [];
    $active   = 0;
    foreach (array_values($sheets) as $i => $sheet) {
        $n = $i + 1;
        if (!empty($sheet['selected'])) { $active = $i; }
        /* Excel refuses names over 31 characters or with any of : \ / ? * [ ] */
        $name = mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', (string) $sheet['name']), 0, 31);
        $wbSheets .= '<sheet name="' . xlsx_xml($name) . '" sheetId="' . $n . '"'
                   . (!empty($sheet['hidden']) ? ' state="hidden"' : '') . ' r:id="rId' . $n . '"/>';
        $wbRels   .= '<Relationship Id="rId' . $n . '" Type="' . $ns . 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $types    .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $files['xl/worksheets/sheet' . $n . '.xml'] = xlsx_sheet_xml($sheet);
    }
    $styles = count($sheets) + 1;
    $wbRels .= '<Relationship Id="rId' . $styles . '" Type="' . $ns . 'officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    return zip_build([
        '[Content_Types].xml' => $head
            . '<Types xmlns="' . $ns . 'package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $types . '</Types>',
        '_rels/.rels' => $head
            . '<Relationships xmlns="' . $ns . 'package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => $head
            . '<workbook xmlns="' . $ns . 'spreadsheetml/2006/main" xmlns:r="' . $ns . 'officeDocument/2006/relationships">'
            . '<workbookPr/><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="28800" windowHeight="15000" activeTab="' . $active . '"/></bookViews>'
            . '<sheets>' . $wbSheets . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $head
            . '<Relationships xmlns="' . $ns . 'package/2006/relationships">' . $wbRels . '</Relationships>',
        'xl/styles.xml' => xlsx_styles_xml(),
    ] + $files, $time);
}
