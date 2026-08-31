<?php
/* Bring the configured database up to the current schema.

   run.php calls this before the suites, because the test database drifts
   exactly the way a production one does. Adding orders.items_hash broke
   eighteen checks in test-pricing.php with "Unknown column" - the same class of
   failure the migration mechanism exists to prevent, arriving from the one
   direction nobody watches.

   Its own file rather than `php -r`: on Windows escapeshellarg wraps the code
   in double quotes and the quotes inside it do not survive, so the -r version
   died with a parse error and reported nothing while looking like it had run. */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/bootstrap.php';
require_once SUPPEX_ROOT . '/lib/migrate.php';

$log = [];
migrate_run($log);

foreach ($log as [$state, $label]) {
    if ($state !== 'skip') {
        echo $state, ' ', $label, PHP_EOL;
    }
}

/* A failed migration must stop the run: every suite after it would fail with
   something misleading. */
foreach ($log as [$state, $label]) {
    if ($state === 'fail') { exit(1); }
}
