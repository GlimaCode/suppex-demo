<?php
/* One request to og.php, in its own process, captured to a file.

   Two reasons this is not simply piped:

   The suite has already echoed by the time it gets here, so headers cannot be
   sent and every header() call would warn straight into the output - which is
   indistinguishable from the endpoint returning nonsense.

   And on Windows a pipe is read in text mode, which stops at 0x1A. A PNG's
   signature is 89 50 4E 47 0D 0A 1A 0A: byte seven. Piping a PNG through
   shell_exec() therefore returns exactly five bytes, and the first three of
   them spell "PNG", so a naive check passes while the image is gone.

   The capture happens at shutdown because og.php answers a missing product
   with exit(), which would otherwise skip any code written after the require.

   argv[1] = slug, argv[2] = where to write the body. */

$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["HTTP_HOST"]   = "suppex.ir";
$_GET = ["p" => (string) ($argv[1] ?? "")];

$OUT = (string) ($argv[2] ?? "");

ob_start();
register_shutdown_function(static function () use ($OUT): void {
    $body = "";
    while (ob_get_level() > 0) {
        $body = (string) ob_get_clean() . $body;
    }
    if ($OUT !== "") {
        file_put_contents($OUT, $body);
    }
});

require __DIR__ . "/../og.php";
