<?php
/**
 * SAMPLE secrets file.
 *
 * To use it: rename this to  vindex-secrets.php  and MOVE IT ONE FOLDER ABOVE
 * your web root (above public_html) so the web server can never serve it.
 * config.php automatically looks for ../vindex-secrets.php and uses whatever it
 * finds here, so your real passwords never sit inside the public web space.
 *
 * Delete any lines you don't need — anything left out just falls back to config.php.
 */
return [
    // Database (from your one.com control panel)
    'db_host'    => 'localhost',
    'db_name'    => 'your_db_name',
    'db_user'    => 'your_db_user',
    'db_pass'    => 'your_db_password',

    // Encryption key for AES-256. Generate one with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'app_key'    => '',

    // Analytics (optional)
    'goatcounter' => '', // e.g. https://vindex.goatcounter.com/count
    'matomo_url'  => '', // e.g. https://vindex.matomo.cloud/
    'matomo_site' => '1',
];
