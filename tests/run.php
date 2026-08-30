<?php
/* ===========================================================================
   The whole suite, one command:   php tests/run.php
   ---------------------------------------------------------------------------
   Every suite is run as its own process. They share a database and several of
   them truncate tables and reseed, so running them in one process would let an
   earlier suite's leftovers decide a later suite's result - which is the class
   of bug this suite exists to catch, not to contain.

   test-migrate.php is the exception that needs its own config: it drops and
   rebuilds a database from db/schema.sql to prove a fresh install works, so it
   must not be pointed at the one every other suite is using.
   =========================================================================== */

/* A run takes minutes and several suites take over a minute each. Without an
   explicit flush the whole report arrives at the end, and a run that is merely
   slow is indistinguishable from one that has hung. */
ob_implicit_flush(true);

$root = __DIR__;

/* [file, config] - config is the file the suite's SUPPEX_CONFIG must point at. */
$php = [
    ['test-preflight.php', 'test-config.php'],
    ['test-backend.php',   'test-config.php'],
    ['test-pricing.php',   'test-config.php'],
    ['test-import.php',    'test-config.php'],
    ['test-margins.php',   'test-config.php'],
    ['test-seo.php',       'test-config.php'],
    ['test-og.php',        'test-config.php'],
    ['test-fixes.php',     'test-config.php'],
    ['test-backup.php',    'test-config.php'],
    ['test-journal.php',   'test-config.php'],
    /* Rebuilds a database from nothing; needs the throwaway one. */
    ['test-migrate.php',   'fresh-config.php'],
];
$node = ['test-herolabels.mjs', 'test-quickspec.mjs'];

$only = $argv[1] ?? null;          // php tests/run.php pricing  -> just that one
$pass = 0; $fail = 0; $skipped = [];

function run(string $cmd, array $env): array
{
    /* getenv(), not $_ENV. On Windows the CLI's variables_order usually omits
       'E', so $_ENV is empty - and a child process handed an environment with
       no SystemRoot or PATH does not fail, it hangs. */
    $base = getenv();

    /* One pipe, with stderr folded into it by the shell. With two pipes this
       deadlocks: stream_get_contents() blocks until stdout reaches EOF, and a
       suite that meanwhile fills the stderr buffer blocks writing to it, so
       neither side can move. It only shows up on the suites that emit PHP
       warnings, which is why a suite can pass alone and hang in a sequence. */
    $desc = [1 => ['pipe', 'w']];
    $p = proc_open($cmd . ' 2>&1', $desc, $pipes, null, array_merge($base, $env));
    if (!is_resource($p)) { return ['', 1]; }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [$out, proc_close($p)];
}

function tally(string $out): array
{
    return [
        preg_match_all('/^  ok   /m', $out),
        preg_match_all('/^  FAIL /m', $out),
    ];
}

echo "\n";

foreach ($php as [$file, $config]) {
    if ($only !== null && strpos($file, $only) === false) { continue; }
    [$out, $code] = run(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/$file"),
        ['SUPPEX_CONFIG' => "$root/$config"]
    );
    [$p, $f] = tally($out);
    /* A suite that dies half way through prints no FAIL line - it just stops.
       test-seo.php once went from 75 checks to 42 that way and still read as a
       pass. A failing suite exits 1 and a PHP fatal exits 255; both are
       failures and neither used to be looked at. */
    if ($code !== 0 && $f === 0) {
        $f = 1;
        $out .= "
  FAIL suite exited with code $code after $p checks";
    }
    $pass += $p; $fail += $f;
    printf("  %-22s %4d ok  %2d fail%s\n", $file, $p, $f, $f ? '   <<<' : '');
    flush();
    if ($f) { foreach (preg_split('/\R/', $out) as $l) { if (str_starts_with($l, '  FAIL')) { echo "      $l\n"; } } }
    /* A suite that dies mid-run reports few checks and no failures, which would
       otherwise read as a pass. */
    if ($p === 0) { echo "      (no checks ran - suite errored)\n"; $fail++; }
}

$hasNode = false;
foreach ([['node --version', 'node']] as [$probe, $bin]) {
    [, $code] = run($probe, []);
    if ($code === 0) { $hasNode = true; }
}

foreach ($node as $file) {
    if ($only !== null && strpos($file, $only) === false) { continue; }
    if (!$hasNode) { $skipped[] = $file; continue; }
    [$out, ] = run('node ' . escapeshellarg("$root/$file"), []);
    [$p, $f] = tally($out);
    $pass += $p; $fail += $f;
    printf("  %-22s %4d ok  %2d fail%s\n", $file, $p, $f, $f ? '   <<<' : '');
    if ($f) { foreach (preg_split('/\R/', $out) as $l) { if (str_starts_with($l, '  FAIL')) { echo "      $l\n"; } } }
}

echo "\n  " . str_repeat('-', 42) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
foreach ($skipped as $s) { echo "  skipped (node not found): $s\n"; }
echo "\n";

exit($fail > 0 ? 1 : 0);
