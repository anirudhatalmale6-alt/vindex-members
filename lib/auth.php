<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Start a hardened session. Call once at the top of every entry point. */
function boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
    session_name('vindex_sid');
    session_start();
}

/** CSRF token for this session (created on demand). */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden CSRF field for forms. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verify CSRF on a state-changing request, or abort. */
function csrf_check(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Session expired — please reload the page and try again.');
    }
}

/** Attempt login. Returns the user row on success, null on failure. */
function attempt_login(string $email, string $password): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    // Transparently upgrade old hashes if PHP's default changes.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $up->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    session_regenerate_id(true); // prevent session fixation
    $_SESSION['uid']  = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    return $user;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** The logged-in user row, or null. Cached per request. */
function current_user(): ?array {
    static $cached = false;
    static $user = null;
    if ($cached) return $user;
    $cached = true;
    if (empty($_SESSION['uid'])) return $user = null;
    $stmt = db()->prepare('SELECT id, name, email, role, lang FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function is_logged_in(): bool { return current_user() !== null; }
function is_admin(): bool { $u = current_user(); return $u && $u['role'] === 'admin'; }

/** Leadership + admin see everything; employees see only 'all' content. */
function can_see_leadership(): bool {
    $u = current_user();
    return $u && ($u['role'] === 'admin' || $u['role'] === 'leadership');
}

/** SQL visibility clause fragment for the current viewer. */
function visibility_sql(string $alias = ''): string {
    $col = ($alias ? $alias . '.' : '') . 'visibility';
    return can_see_leadership() ? '1=1' : "$col = 'all'";
}

function require_login(): void {
    if (!is_logged_in()) redirect('login.php');
}
function require_admin(): void {
    require_login();
    if (!is_admin()) { http_response_code(403); exit('Forbidden — admin only.'); }
}
