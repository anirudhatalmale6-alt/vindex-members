<?php
require_once __DIR__ . '/../config.php';

/**
 * AES-256-GCM field encryption.
 *
 * Passwords are NEVER encrypted (they're one-way hashed — see auth.php). These
 * helpers are for any genuinely sensitive field you may want to store reversibly
 * later (e.g. a stored API token). Format returned: base64( iv[12] | tag[16] | ciphertext ).
 * The key comes from APP_KEY (best kept in vindex-secrets.php, outside the web root).
 */
function app_key_raw(): string {
    $k = defined('APP_KEY') ? (string)APP_KEY : '';
    if ($k === '') {
        // Fallback so the app still runs before a key is set — but a real random
        // APP_KEY should always be configured for production (README explains how).
        $k = 'vindex-fallback-key-' . (defined('DB_NAME') ? DB_NAME : '');
    }
    return hash('sha256', $k, true); // 32 raw bytes for AES-256
}

function encrypt_data(string $plain): string {
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', app_key_raw(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ct === false) return '';
    return base64_encode($iv . $tag . $ct);
}

function decrypt_data(string $blob): ?string {
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', app_key_raw(), OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}
