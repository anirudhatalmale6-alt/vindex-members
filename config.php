<?php
/**
 * Vindex members platform — configuration
 * ---------------------------------------
 * On one.com: open your control panel, create a MySQL database, and copy the
 * database name / username / password / host it gives you into the values below.
 * After the site is running, delete install.php.
 *
 * KEEPING SECRETS OUT OF THE WEB ROOT (recommended)
 * -------------------------------------------------
 * Instead of writing your passwords into this file (which lives inside the web
 * space), you can put them in a file called  vindex-secrets.php  placed ONE LEVEL
 * ABOVE this folder — i.e. above public_html, where the web server will never
 * serve it. Copy vindex-secrets.sample.php up there, rename it, and fill it in.
 * Any value found there (or in an environment variable) overrides the defaults
 * below. If you'd rather keep it simple, just edit the DB_* values here directly.
 */

// --- load the secrets file ----------------------------------------------------
// Looked for in this order, first one found wins:
//   1. wherever VDX_SECRETS_FILE points
//   2. one folder ABOVE this one — the best place, if your hosting lets you
//      put files outside the web space
//   3. private/vindex-secrets.php — what install.php writes for you. That
//      folder is shut to the web by its own .htaccess, and the file is PHP
//      that only returns values, so even if it were requested it would show
//      a blank page rather than its contents.
$__secrets = [];
$__candidates = array_filter([
    getenv('VDX_SECRETS_FILE') ?: null,
    __DIR__ . '/../vindex-secrets.php',
    __DIR__ . '/private/vindex-secrets.php',
]);
foreach ($__candidates as $__secretsFile) {
    if (is_string($__secretsFile) && is_file($__secretsFile)) {
        $__loaded = require $__secretsFile;
        if (is_array($__loaded)) { $__secrets = $__loaded; break; }
    }
}
define('SECRETS_LOADED', !empty($__secrets));
// env var first, then secrets file, then the literal default given here
$__sec = function (string $key, string $envKey, $default) use ($__secrets) {
    $env = getenv($envKey);
    if ($env !== false && $env !== '') return $env;
    if (array_key_exists($key, $__secrets) && $__secrets[$key] !== '') return $__secrets[$key];
    return $default;
};

// --- database -----------------------------------------------------------------
define('DB_HOST',   $__sec('db_host',   'VDX_DB_HOST',   'localhost'));
define('DB_PORT',   $__sec('db_port',   'VDX_DB_PORT',   '3306'));
define('DB_NAME',   $__sec('db_name',   'VDX_DB_NAME',   'vindex'));
define('DB_USER',   $__sec('db_user',   'VDX_DB_USER',   'root'));
define('DB_PASS',   $__sec('db_pass',   'VDX_DB_PASS',   ''));
define('DB_SOCKET', $__sec('db_socket', 'VDX_DB_SOCKET', '')); // leave empty on one.com

// --- application key (used for AES-256 encryption of any sensitive field) ------
// Generate a long random value, e.g. run in a terminal:  php -r "echo bin2hex(random_bytes(32));"
// Best kept in the vindex-secrets.php file above the web root.
define('APP_KEY', $__sec('app_key', 'VDX_APP_KEY', ''));

// --- analytics (optional; leave blank to disable) ------------------------------
// GoatCounter: your counter URL, e.g. https://vindex.goatcounter.com/count
define('GOATCOUNTER_CODE', $__sec('goatcounter', 'VDX_GOATCOUNTER', ''));
// Matomo: your instance URL (e.g. https://vindex.matomo.cloud/) and the numeric site id
define('MATOMO_URL',     $__sec('matomo_url',  'VDX_MATOMO_URL',  ''));
define('MATOMO_SITE_ID', $__sec('matomo_site', 'VDX_MATOMO_SITE', '1'));

define('APP_NAME', 'Vindex');
