<?php
/* ===========================================================================
   SUPPEX — Bootstrap
   ---------------------------------------------------------------------------
   Loaded first by every PHP entry point (public API and admin panel alike).
   Finds the configuration, opens the database lazily, and sets the error and
   encoding policy for the whole application.
   =========================================================================== */

declare(strict_types=1);

define('SUPPEX_ROOT', dirname(__DIR__));

/* --- Locate the configuration ---------------------------------------------
   Searched from the least-exposed location outwards, so a stray copy left
   inside the web root can never shadow the real one above it. */
function suppex_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $candidates = [
        dirname(SUPPEX_ROOT) . '/suppex-config.php',   // preferred: above public_html
        SUPPEX_ROOT . '/../suppex-config.php',
        SUPPEX_ROOT . '/suppex-config.php',            // fallback for local development
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $config = $loaded;
                return $config;
            }
        }
    }

    /* Failing loudly here is correct. A missing config means the site cannot
       possibly work, and a half-configured shop that appears to run is worse
       than one that plainly refuses to start. */
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("SUPPEX is not configured.\n\n"
       . "Copy config.sample.php to suppex-config.php and place it one level\n"
       . "above public_html, then fill in the database credentials.\n");
}

/* --- Error policy ---------------------------------------------------------
   Details go to the log, never to the visitor: a stack trace on a live page
   hands an attacker file paths, table names and library versions for free. */
$cfg = suppex_config();
$isDev = (($cfg['env'] ?? 'production') === 'development');

error_reporting(E_ALL);
ini_set('display_errors', $isDev ? '1' : '0');
ini_set('log_errors', '1');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tehran');

require_once SUPPEX_ROOT . '/lib/db.php';
require_once SUPPEX_ROOT . '/lib/util.php';
require_once SUPPEX_ROOT . '/lib/settings.php';
