<?php
/** Escape for safe HTML output. Use everywhere untrusted text is printed. */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Are we being served over HTTPS? (used for secure session cookies) */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') == '443') return true;
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    return false;
}

/** Redirect helper. */
function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

/** Send a JSON response and stop. */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read a POST field as trimmed string. */
function post(string $k, string $default = ''): string {
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default;
}
function get(string $k, string $default = ''): string {
    return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $default;
}
