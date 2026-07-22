<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/** Send baseline security headers. Safe to call before any output. */
function send_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    if (is_https()) header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}

/** Preferred password hashing algorithm: Argon2id where the host supports it, else bcrypt. */
function pw_algo() {
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}
function pw_hash(string $password): string {
    return password_hash($password, pw_algo());
}

/** Start a hardened session. Call once at the top of every entry point. */
function boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    send_security_headers();
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
    // Transparently upgrade old hashes to the current preferred algorithm.
    if (password_needs_rehash($user['password_hash'], pw_algo())) {
        $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $up->execute([pw_hash($password), $user['id']]);
    }
    session_regenerate_id(true); // prevent session fixation
    $_SESSION['uid']  = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    return $user;
}

/* ---------------- Brute-force throttling ---------------- */
const LOGIN_WINDOW    = 900; // 15 minutes, in seconds
const LOGIN_THRESHOLD = 5;   // failed tries (per email OR IP) before lockout kicks in

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Record one login attempt. On success, clears that identity's failure history. */
function record_login_attempt(string $email, bool $success): void {
    $email = mb_substr(strtolower(trim($email)), 0, 190);
    try {
        db()->prepare('INSERT INTO login_attempts (email, ip, success) VALUES (?,?,?)')
            ->execute([$email, client_ip(), $success ? 1 : 0]);
        if ($success) {
            db()->prepare('DELETE FROM login_attempts WHERE success = 0 AND (email = ? OR ip = ?)')
                ->execute([$email, client_ip()]);
        }
    } catch (Throwable $e) { /* never let logging block the login flow */ }
}

/** Recent failed attempts for this email OR this IP within the window. */
function recent_failures(string $email): int {
    $email = strtolower(trim($email));
    try {
        $st = db()->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND (email = ? OR ip = ?)
               AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW . ' SECOND)');
        $st->execute([$email, client_ip()]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Seconds the caller must wait before another login attempt is allowed.
 * 0 = not locked. Lock grows progressively with repeated failures (60s, 120s,
 * 240s … capped at 1 hour), measured from the most recent failed attempt.
 */
function seconds_until_unblocked(string $email): int {
    $fails = recent_failures($email);
    if ($fails < LOGIN_THRESHOLD) return 0;
    $exp  = min(20, $fails - LOGIN_THRESHOLD);
    $lock = min(3600, 60 * (1 << $exp));
    try {
        $email = strtolower(trim($email));
        $st = db()->prepare(
            'SELECT UNIX_TIMESTAMP(MAX(attempted_at)) AS last, UNIX_TIMESTAMP(NOW()) AS now
             FROM login_attempts
             WHERE success = 0 AND (email = ? OR ip = ?)
               AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW . ' SECOND)');
        $st->execute([$email, client_ip()]);
        $row = $st->fetch();
        if (!$row || !$row['last']) return 0;
        return max(0, $lock - ((int)$row['now'] - (int)$row['last']));
    } catch (Throwable $e) { return 0; }
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

/** SQL visibility clause fragment for the current viewer (used for blocks). */
function visibility_sql(string $alias = ''): string {
    $col = ($alias ? $alias . '.' : '') . 'visibility';
    return can_see_leadership() ? '1=1' : "$col = 'all'";
}

/** Section ids explicitly granted to the current employee (empty for admin/leadership). */
function granted_section_ids(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $u = current_user();
    if (!$u || $u['role'] !== 'employee') return $cache = [];
    try {
        $st = db()->prepare('SELECT section_id FROM user_section_access WHERE user_id = ?');
        $st->execute([$u['id']]);
        return $cache = array_map('intval', array_column($st->fetchAll(), 'section_id'));
    } catch (Throwable $e) { return $cache = []; }
}

/**
 * SQL clause deciding which SECTIONS the current viewer may see.
 * Leadership/admin see all; an employee sees 'all' sections plus any individually
 * granted to them. (Block-level visibility inside a section stays role-based.)
 */
function visible_section_clause(string $alias = ''): string {
    if (can_see_leadership()) return '1=1';
    $p = $alias ? $alias . '.' : '';
    $clause = $p . "visibility = 'all'";
    $ids = granted_section_ids();
    if ($ids) {
        $clause = '(' . $clause . ' OR ' . $p . 'id IN (' . implode(',', $ids) . '))';
    }
    return $clause;
}

function require_login(): void {
    if (!is_logged_in()) redirect('login.php');
}
function require_admin(): void {
    require_login();
    if (!is_admin()) { http_response_code(403); exit('Forbidden — admin only.'); }
}
