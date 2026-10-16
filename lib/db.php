<?php
require_once __DIR__ . '/../config.php';

/** Shared PDO connection (prepared statements, real not emulated). */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (DB_SOCKET !== '') {
        $dsn = 'mysql:unix_socket=' . DB_SOCKET . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // Give up on an unreachable database after 5 seconds. The public
        // pages read their text through here; without a cap, a hung MySQL
        // would hold every visitor's page open instead of falling back.
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    return $pdo;
}
