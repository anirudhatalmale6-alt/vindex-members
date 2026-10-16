<?php
/**
 * One-time web installer.
 *
 * Upload the files, then open  /members/install.php  in your browser. It walks
 * through two steps and nothing needs editing by hand:
 *
 *   1. Database — enter the MySQL details from your hosting control panel. They
 *      are tested before anything is saved, then written to
 *      private/vindex-secrets.php together with a freshly generated encryption
 *      key. That folder is shut to the web by its own .htaccess.
 *   2. Administrator — create the account that manages users and content.
 *
 * When it finishes it locks itself and tries to delete this file. If the
 * hosting does not allow that, it says so and you delete install.php yourself.
 */
require_once __DIR__ . '/lib/auth.php';
boot_session();

const PRIVATE_DIR  = __DIR__ . '/private';
const SECRETS_FILE = PRIVATE_DIR . '/vindex-secrets.php';
const LOCK_FILE    = PRIVATE_DIR . '/installed.lock';

$step = '';
$msg = '';
$deleted = null;

/** Try a connection with the given details. Returns '' on success, or the reason. */
function try_connect(string $host, string $port, string $name, string $user, string $pass): string {
    try {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                     PDO::ATTR_TIMEOUT => 8]);
        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

/** A plain-language reading of the usual connection errors. */
function explain_db_error(string $raw): string {
    $r = strtolower($raw);
    if (str_contains($r, 'access denied'))            return 'The username or password was not accepted.';
    if (str_contains($r, 'unknown database'))         return 'No database with that name exists. Check the name is copied exactly.';
    if (str_contains($r, 'getaddrinfo') || str_contains($r, 'name or service not known')
        || str_contains($r, 'no such host'))          return 'That database server address could not be found. Check the host is copied exactly.';
    if (str_contains($r, 'timed out') || str_contains($r, 'refused'))
                                                      return 'The database server did not answer. Check the host is copied exactly.';
    return 'The database could not be reached.';
}

// ---------------------------------------------------------------------------
// Already finished? Stop here, whatever else is true.
// ---------------------------------------------------------------------------
$dbOk = false;
$dbError = '';
if (SECRETS_LOADED) {
    try { db(); $dbOk = true; } catch (Throwable $e) { $dbError = $e->getMessage(); }
}

$installed = is_file(LOCK_FILE);
if (!$installed && $dbOk) {
    try {
        $installed = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;
    } catch (Throwable $e) { /* tables not created yet */ }
}

if ($installed) {
    $step = 'installed';
}

// ---------------------------------------------------------------------------
// Step 1 — database details. Only offered while the app has no working
// connection, so once it is set up nobody can point it somewhere else.
// ---------------------------------------------------------------------------
elseif (!$dbOk) {
    $step = 'db';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'db') {
        csrf_check();
        $host    = trim(post('db_host'));
        // Some panels show the host as "server:port". Accept that as written.
        $port    = '3306';
        if (preg_match('/^([^:\s]+):(\d{2,5})$/', $host, $m)) { $host = $m[1]; $port = $m[2]; }
        $name    = trim(post('db_name'));
        $user    = trim(post('db_user'));
        $pass    = $_POST['db_pass'] ?? '';
        $contact = strtolower(trim(post('contact_email')));

        if ($host === '' || $name === '' || $user === '') {
            $msg = 'Please fill in the database host, name and username.';
        } elseif ($contact !== '' && !filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $msg = 'The enquiries email address does not look right.';
        } elseif (($err = try_connect($host, $port, $name, $user, $pass)) !== '') {
            $msg = explain_db_error($err) . ' Nothing has been saved.';
        } elseif (!is_dir(PRIVATE_DIR) || !is_writable(PRIVATE_DIR)) {
            $msg = 'The connection works, but the "private" folder could not be written to. '
                 . 'Check the private folder was uploaded and that it is writable.';
        } else {
            $values = [
                'db_host'       => $host,
                'db_port'       => $port,
                'db_name'       => $name,
                'db_user'       => $user,
                'db_pass'       => $pass,
                'app_key'       => bin2hex(random_bytes(32)),
                'contact_email' => $contact,
            ];
            $php = "<?php\n"
                 . "// Written by install.php on " . gmdate('Y-m-d H:i') . " UTC.\n"
                 . "// Holds the database password and encryption key. Never share this file.\n"
                 . "// If the encryption key is changed, anything already encrypted with it can no longer be read.\n"
                 . "return " . var_export($values, true) . ";\n";
            if (file_put_contents(SECRETS_FILE, $php, LOCK_EX) === false) {
                $msg = 'The connection works, but the settings file could not be saved.';
            } else {
                @chmod(SECRETS_FILE, 0600);
                redirect('install.php');   // reload so step 2 runs with the saved settings
            }
        }
    } elseif (SECRETS_LOADED && $dbError !== '') {
        // Settings exist but no longer connect — say why, and let them be replaced.
        $msg = explain_db_error($dbError) . ' Enter the details again below.';
    }
}

// ---------------------------------------------------------------------------
// Step 2 — first administrator.
// ---------------------------------------------------------------------------
else {
    require_once __DIR__ . '/lib/seed_data.php';
    $step = 'admin';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'admin') {
        csrf_check();
        $name  = post('name');
        $email = strtolower(post('email'));
        $pass  = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            $msg = 'Please enter a name, a valid email, and a password of at least 8 characters.';
        } else {
            run_schema();
            seed_user($name, $email, $pass, 'admin');
            if (isset($_POST['seed'])) { $ids = seed_sections(); seed_demo_content($ids); }

            // Lock first, then try to remove the installer. The lock is what
            // actually stops it running again; deleting the file is tidiness.
            @file_put_contents(LOCK_FILE, gmdate('c') . "\n");
            $deleted = @unlink(__FILE__);
            $step = 'done';
        }
    }
}
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Vindex — Install</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<style>
  .hint{font-size:13px;color:var(--muted);margin-top:4px;line-height:1.45}
  .auth-ok{background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.35);color:var(--text);
           border-radius:10px;padding:12px 14px;font-size:14px;line-height:1.5;margin-bottom:16px}
  .steps{display:flex;gap:8px;margin-bottom:14px;font-size:12px;letter-spacing:.06em;text-transform:uppercase}
  .steps span{color:var(--muted)} .steps span.on{color:var(--accent,#22d3ee);font-weight:600}
</style>
</head>
<body class="auth-body">
<div class="auth-topbar"><a class="brand" href="#"><span class="dot"></span>VINDEX</a><span class="badge">Installer</span></div>
<main class="auth-wrap"><div class="auth-card">

<?php if ($step === 'installed'): ?>
  <span class="eyebrow">Already installed</span><div class="accent-rule"></div>
  <h1>You're set up</h1>
  <p class="auth-sub">The installer has already been run, so it will not run again. For tidiness, delete <b>install.php</b> from the server if it is still there.</p>
  <a class="btn btn-primary" href="login.php" style="width:100%;justify-content:center">Go to login</a>

<?php elseif ($step === 'db'): ?>
  <div class="steps"><span class="on">1 · Database</span><span>›</span><span>2 · Administrator</span></div>
  <h1>Connect the database</h1>
  <p class="auth-sub">Copy these from the MySQL / database section of your hosting control panel. They are tested before anything is saved.</p>
  <?php if ($msg): ?><div class="auth-error"><?= e($msg) ?></div><?php endif; ?>
  <form method="post" class="auth-form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="db">
    <div class="field"><label>Database host</label><input name="db_host" required value="<?= e(post('db_host')) ?>" placeholder="exactly as your control panel shows it">
      <div class="hint">Often not "localhost" on shared hosting — copy it exactly. If it shows a port (like <b>server:3306</b>), include it.</div></div>
    <div class="field"><label>Database name</label><input name="db_name" required value="<?= e(post('db_name')) ?>"></div>
    <div class="field"><label>Database username</label><input name="db_user" required value="<?= e(post('db_user')) ?>"></div>
    <div class="field"><label>Database password</label><input type="password" name="db_pass" autocomplete="new-password"></div>
    <div class="field"><label>Where should website enquiries go? <span style="color:var(--muted)">(optional)</span></label>
      <input type="email" name="contact_email" value="<?= e(post('contact_email')) ?>" placeholder="you@yourdomain.dk">
      <div class="hint">The contact form on the public site sends to this address. You can leave it empty and add it later.</div></div>
    <button class="btn btn-primary" style="width:100%;justify-content:center">Test &amp; save</button>
  </form>

<?php elseif ($step === 'admin'): ?>
  <div class="steps"><span>1 · Database ✓</span><span>›</span><span class="on">2 · Administrator</span></div>
  <h1>Create your admin</h1>
  <p class="auth-sub">The database is connected. This is the account that manages users and all content — use a password you don't use anywhere else.</p>
  <?php if ($msg): ?><div class="auth-error"><?= e($msg) ?></div><?php endif; ?>
  <form method="post" class="auth-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="admin">
    <div class="field"><label>Your name</label><input name="name" required value="<?= e(post('name')) ?>"></div>
    <div class="field"><label>Email</label><input type="email" name="email" required value="<?= e(post('email')) ?>"></div>
    <div class="field"><label>Password (at least 8 characters — longer is better)</label><input type="password" name="password" required minlength="8" autocomplete="new-password"></div>
    <label style="display:flex;gap:9px;align-items:flex-start;font-size:14px;color:var(--muted)"><input type="checkbox" name="seed" value="1" checked style="width:auto;margin-top:3px"> Load the starter section list and a short welcome guide — you can rename, edit or delete any of it</label>
    <button class="btn btn-primary" style="width:100%;justify-content:center">Create admin &amp; finish</button>
  </form>

<?php elseif ($step === 'done'): ?>
  <span class="eyebrow">Done</span><div class="accent-rule"></div>
  <h1>Installation complete</h1>
  <?php if ($deleted): ?>
    <div class="auth-ok">The installer has locked itself and deleted its own file — nothing more to clean up.</div>
  <?php else: ?>
    <div class="auth-ok">The installer has locked itself so it cannot run again. The hosting did not let it delete its own file, so please delete <b>install.php</b> in File Manager when you have a moment.</div>
  <?php endif; ?>
  <a class="btn btn-primary" href="login.php" style="width:100%;justify-content:center">Go to login</a>
<?php endif; ?>

</div></main>
</body>
</html>
