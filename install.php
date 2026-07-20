<?php
/**
 * One-time web installer.
 * Open this in your browser once after uploading the files and filling in config.php.
 * It creates the database tables and your first administrator account.
 * DELETE this file once you're done.
 */
require_once __DIR__ . '/lib/helpers.php';

$step = 'form';
$msg = '';
$dbError = '';

// Try to connect early so we can show a helpful message if config is wrong.
try {
    require_once __DIR__ . '/lib/db.php';
    db();
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

if (!$dbError) {
    require_once __DIR__ . '/lib/seed_data.php';
    // Already installed?
    $installed = false;
    try {
        $installed = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;
    } catch (Throwable $e) { /* tables not created yet */ }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
        $name  = post('name');
        $email = strtolower(post('email'));
        $pass  = $_POST['password'] ?? '';
        $seed  = isset($_POST['seed']);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            $msg = 'Please enter a name, a valid email, and a password of at least 8 characters.';
        } else {
            run_schema();
            seed_user($name, $email, $pass, 'admin');
            if ($seed) { $ids = seed_sections(); seed_demo_content($ids); }
            $step = 'done';
        }
    } elseif ($installed) {
        $step = 'installed';
    }
}
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vindex — Install</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-body">
<div class="auth-topbar"><a class="brand" href="#"><span class="dot"></span>VINDEX</a><span class="badge">Installer</span></div>
<main class="auth-wrap"><div class="auth-card">
<?php if ($dbError): ?>
  <span class="eyebrow">Step 1</span><div class="accent-rule"></div>
  <h1>Connect the database</h1>
  <p class="auth-sub">I couldn't reach the database yet. Open <b>config.php</b> and enter the MySQL details from your one.com control panel, then reload this page.</p>
  <div class="auth-error"><?= e($dbError) ?></div>
<?php elseif ($step === 'installed'): ?>
  <span class="eyebrow">Already installed</span><div class="accent-rule"></div>
  <h1>You're set up</h1>
  <p class="auth-sub">An administrator already exists. For security, please delete <b>install.php</b> from the server.</p>
  <a class="btn btn-primary" href="login.php" style="width:100%;justify-content:center">Go to login</a>
<?php elseif ($step === 'done'): ?>
  <span class="eyebrow">Done</span><div class="accent-rule"></div>
  <h1>Installation complete</h1>
  <p class="auth-sub">Your administrator account is ready. Now delete <b>install.php</b> from the server, then log in.</p>
  <a class="btn btn-primary" href="login.php" style="width:100%;justify-content:center">Go to login</a>
<?php else: ?>
  <span class="eyebrow">Set up</span><div class="accent-rule"></div>
  <h1>Create your admin</h1>
  <p class="auth-sub">This is the account that manages users and all content.</p>
  <?php if ($msg): ?><div class="auth-error"><?= e($msg) ?></div><?php endif; ?>
  <form method="post" class="auth-form">
    <div class="field"><label>Your name</label><input name="name" required value="<?= e(post('name')) ?>"></div>
    <div class="field"><label>Email</label><input type="email" name="email" required value="<?= e(post('email')) ?>"></div>
    <div class="field"><label>Password (min 8 characters)</label><input type="password" name="password" required minlength="8"></div>
    <label style="display:flex;gap:9px;align-items:center;font-size:14px;color:var(--muted)"><input type="checkbox" name="seed" value="1" checked style="width:auto"> Load the starter section list to begin with</label>
    <button class="btn btn-primary" style="width:100%;justify-content:center">Create admin &amp; install</button>
  </form>
<?php endif; ?>
</div></main>
</body>
</html>
