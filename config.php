<?php
/**
 * Vindex members platform — configuration
 * ---------------------------------------
 * On one.com: open your control panel, create a MySQL database, and copy the
 * database name / username / password / host it gives you into the values below.
 * Everything else can stay as-is. After the site is running, delete install.php.
 *
 * (These read from environment variables first so the same file works in
 *  local testing and on the live host without edits — on one.com just fill in
 *  the four DB_* values directly.)
 */

define('DB_HOST',   getenv('VDX_DB_HOST')   ?: 'localhost');
define('DB_PORT',   getenv('VDX_DB_PORT')   ?: '3306');
define('DB_NAME',   getenv('VDX_DB_NAME')   ?: 'vindex');
define('DB_USER',   getenv('VDX_DB_USER')   ?: 'root');
define('DB_PASS',   getenv('VDX_DB_PASS') !== false ? getenv('VDX_DB_PASS') : '');
define('DB_SOCKET', getenv('VDX_DB_SOCKET') ?: ''); // leave empty on one.com

define('APP_NAME', 'Vindex');
