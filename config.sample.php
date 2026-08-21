<?php
/* ===========================================================================
   SUPPEX — Server configuration
   ---------------------------------------------------------------------------
   COPY THIS FILE to  suppex-config.php  and place that copy ONE LEVEL ABOVE
   public_html, next to it — never inside public_html itself:

       /home/USERNAME/suppex-config.php     ← the real file, not web-reachable
       /home/USERNAME/public_html/          ← the site

   Why it lives outside the web root: if PHP ever stops executing (a bad
   .htaccess, a failed upgrade, a misconfigured handler), every .php file in
   public_html is served as plain text — including the database password. A
   file outside the web root has no URL at all, so there is nothing to serve.

   Nothing else in the project holds a credential.
   =========================================================================== */

return [

  /* --- Database (cPanel → MySQL® Databases) -------------------------------
     cPanel prefixes both the database and the user with your account name,
     e.g. account "supp" creates "supp_shop" and "supp_admin". Use the full
     prefixed names here, and remember to ADD THE USER TO THE DATABASE with
     ALL PRIVILEGES — creating both without linking them is the single most
     common reason a fresh cPanel install cannot connect. */
  'db' => [
    'host'     => 'localhost',
    'name'     => 'CPANELUSER_suppex',
    'user'     => 'CPANELUSER_suppex',
    'pass'     => '',
    'charset'  => 'utf8mb4',
  ],

  /* --- Where uploaded product images are written --------------------------
     An absolute filesystem path inside public_html. The directory must be
     writable by PHP (755 is normally enough on cPanel; 777 is not required
     and should be avoided). */
  'uploads_dir' => __DIR__ . '/public_html/uploads',
  'uploads_url' => '/uploads',           // how the same directory is reached over HTTP

  /* --- Session ------------------------------------------------------------ */
  'session_name' => 'suppex_admin',

  /* Set to true once the domain has a working SSL certificate. It marks the
     admin session cookie "Secure", so the browser refuses to send it over
     plain HTTP. Leave false until HTTPS actually works or you will be locked
     out of the panel; turn it on the same day the certificate is installed.
     cPanel issues a free AutoSSL certificate — there is no reason to run the
     admin panel over HTTP for long. */
  'https_only' => false,

  /* --- Admin login throttle ---------------------------------------------- */
  'login' => [
    'max_attempts'   => 8,      // per IP and per username
    'window_minutes' => 15,
  ],

  /* --- Order notifications (optional) -------------------------------------
     Telegram Bot API is the cheapest way to get an instant push the moment an
     order is placed: create a bot with @BotFather, send it one message, then
     read the chat id from
     https://api.telegram.org/bot<TOKEN>/getUpdates

     This runs SERVER-side, which is the entire point — the token is never sent
     to a browser, so it cannot be read out of the page source and abused. */
  'telegram_bot' => [
    'enabled' => false,
    'token'   => '',
    'chat_id' => '',
  ],

  /* --- SMS (optional) -----------------------------------------------------
     Same reasoning: the provider key stays here, server-side. Fill in when a
     panel is bought (Kavenegar, SMS.ir, Melipayamak — all similar). */
  'sms' => [
    'enabled'  => false,
    'provider' => '',
    'api_key'  => '',
    'sender'   => '',
    'to'       => '',
  ],

  /* --- Environment -------------------------------------------------------- */
  /* 'production' hides error details from visitors and logs them instead.
     Only use 'development' while setting the site up. */
  'env' => 'production',
];
