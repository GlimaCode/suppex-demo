# SUPPEX project instructions

## Workspace and delivery

- The user's working source is `C:\Users\Asus\Desktop\UI Workout`.
- Repository: `https://github.com/GlimaCode/suppex-demo`, branch `main`.
- The user has requested that project changes be pushed to this repository.
- Production uses cPanel, PHP and MySQL/MariaDB. Deliver requested website changes as a ZIP with paths relative to `public_html`, accompanied by concise Persian extraction/migration instructions. A Git push does not deploy the website.
- Preserve existing work and Git history. Inspect status before editing or committing; stage explicit relevant paths rather than the entire workspace.
- Communicate with the user in Persian unless they ask otherwise.
- Read `PROJECT_MAP.md` for the current architecture and deployment boundaries. Parts of `README.md` describe earlier prototype phases and are historical.

## Implementation

- Plain PHP 8.0+ and browser JavaScript under `window.SUPPEX`; no framework, npm build, Composer or ES-module conversion is needed.
- `index.html` is the home template. `index.php` fills exact markers with server-rendered content and boot data. Keep both rendering paths consistent; marker edits can disable server rendering.
- Shared storefront markup lives in `partials/`. Public PHP pages use `partials/head-assets.php` and `asset()` for versioned assets.
- CSS sources are `assets/css/tokens.css`, `base.css`, `components.css`, `layout.css`, in that order. After CSS changes run `php tools/build-css.php` and include generated `assets/css/site.css` in the release. Never edit the bundle directly.
- Use `assets/js/repo.js` for frontend data access, `store.js` for cart state, `ui.js` for shared rendering, and `app.js` for controllers/events.
- `lib/boot.php::boot_key()` and `repo.js::bootKey()` must agree. Maintain their shared fixture in `tests/boot-keys.json` when changing the protocol.
- Prices are integer toman. Storefront display uses Persian digits; admin/accounting paths can use Latin digits. Preserve RTL and mixed Persian/Latin text handling.
- The deployed frontend currently uses `api.baseUrl: 'api/index.php'` and `productPage: 'product.php'`. Do not switch them to static demo settings for a production release.
- Payment is currently card-to-card with order tracking and receipt upload. A receipt is evidence awaiting review, not an automatic payment confirmation.
- Server-side price calculation, stock reservation and release, quote expiry, idempotent order retries, and historical profit/commission snapshots must remain authoritative.
- Use parameterized database queries and existing escaping helpers. Protect admin mutations with authentication, the relevant capability and CSRF checks.
- Database upgrades belong in idempotent `lib/migrate.php` steps and the fresh-install schema when applicable. Never replace an existing shop database with seed data or rerun the installer as an upgrade.

## Local runtime and validation

- PHP executable on this computer: `C:\Users\Asus\Desktop\SUPPEX-local\php\php.exe`.
- MariaDB tools: `C:\Users\Asus\Desktop\SUPPEX-local\mariadb\bin\`.
- `php` is not currently on PATH. Use its full path in PowerShell with `&`, or set a process-local PATH when required.
- The existing development launcher is `C:\Users\Asus\Desktop\SUPPEX-local\RUN-SUPPEX.bat`; inspect it before launching because it opens services/browser windows. For background helpers use hidden windows.
- Run relevant existing suites for a change, and the complete suite for changes spanning checkout, pricing, stock, migrations or shared data contracts: `php tests/run.php`.
- Test databases are destructive disposable databases named `suppex_test` and `suppex_fresh`. Inspect configs and confirm isolation before running. `SUPPEX_DB_PORT` selects the local MariaDB port; defaults to 3399. `SUPPEX_CONFIG` is a process environment override, not request input.
- A fresh test instance needs the base schema and seed before `tests/run.php`, because the runner applies migrations before running suites.
- The PHP runner currently does not validate Node child exit codes. Confirm each Node suite actually ran and printed checks; investigate any stderr or missing output.
- PHP's built-in web server does not process `.htaccess`. Local HTTP checks do not establish Apache/LiteSpeed rewrite or protection behavior.

## Secrets, media and release ZIPs

- Never commit, print, or package the real `suppex-config.php`, credentials, customer/receipt data, SQL backups, commercial spreadsheets or raw product-import research.
- On cPanel the real config lives beside `public_html`. Receipt images live outside it, normally in `suppex-private/receipts`; support an explicit `private_dir` config override.
- Keep runtime `uploads/` media out of Git; preserve `uploads/.htaccess` and `uploads/products/.gitkeep`. Back up media separately and do not overwrite/delete existing host uploads during a code update.
- Incremental update ZIPs should include changed application files plus necessary shared/generated dependencies. Put `assets/...`, `lib/...`, etc. directly at the archive root; do not wrap them in an extra project directory.
- Exclude `.git`, `.claude`, `.codex`, `tests`, `tools`, documentation, configs, archives, spreadsheets, website snapshots, design explorations and runtime caches from web-root releases. A cron script is a separate home-directory deployment, not a public_html file.
- Include hidden `.htaccess` files when a change requires them. Include `admin/migrate.php`/`lib/migrate.php` and explain the migration when the schema changes. Do not include `setup.php` or `admin-reset.php` in a routine update ZIP.
- Before deployment instructions: inspect ZIP member paths and contents, explain target extraction directory, any required migration, and any separately deployed cron file. Website deployment itself requires access/authorization for that operation.
