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

/**
 * Send plain-http visitors to the https address.
 *
 * Done here rather than in .htaccess on purpose: the host sits behind a
 * Varnish proxy, and a rewrite rule that misreads the scheme there loops
 * forever. is_https() is the check already proven on the live host (it is
 * what marks the session cookie Secure), so the redirect cannot disagree
 * with it. Local development hosts are left alone.
 */
function force_https(): void {
    if (PHP_SAPI === 'cli' || is_https()) return;
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]'
        || str_ends_with($host, '.local') || str_ends_with($host, '.test')) return;
    if (!preg_match('/^[a-z0-9.\-]+$/', $host)) return;   // never build a redirect from a junk Host header
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '' || $uri[0] !== '/') $uri = '/';
    // Never cached: a stored redirect served back to an https request would loop.
    header('Cache-Control: no-store, private');
    header('Location: https://' . $host . $uri, true, 301);
    exit;
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
