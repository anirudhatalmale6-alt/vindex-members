<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/analytics.php';
boot_session();

if (is_logged_in()) redirect('index.php');

$error = '';
$lockMins = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = post('email');
    $pass  = $_POST['password'] ?? '';

    $wait = seconds_until_unblocked($email);
    if ($wait > 0) {
        // Too many recent failures — refuse without even checking the password.
        $error = 'locked';
        $lockMins = (int)ceil($wait / 60);
    } elseif (attempt_login($email, $pass)) {
        record_login_attempt($email, true);
        redirect('index.php');
    } else {
        record_login_attempt($email, false);
        $error = 'invalid';
        // Did that failure just trip the lockout?
        $wait = seconds_until_unblocked($email);
        if ($wait > 0) { $error = 'locked'; $lockMins = (int)ceil($wait / 60); }
    }
}
$lockEn = 'Too many attempts. Please wait about ' . $lockMins . ' minute' . ($lockMins === 1 ? '' : 's') . ' and try again.';
$lockDa = 'For mange forsøg. Vent venligst ca. ' . $lockMins . ' minut' . ($lockMins === 1 ? '' : 'ter') . ', og prøv igen.';
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vindex — Member Login</title>
<link rel="stylesheet" href="../assets/fonts/fonts.css"><!-- self-hosted: nothing is sent to Google -->
<link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
<script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('vindex-theme')||'dark');}catch(e){}</script>
</head>
<body class="auth-body">
<div class="auth-topbar">
  <a class="brand" href="index.php"><span class="dot"></span>VINDEX</a>
  <div class="auth-toggles">
    <div class="toggle" id="lang"><button data-lang="en" class="on">EN</button><button data-lang="da">DA</button></div>
    <button class="icon-btn" id="theme" aria-label="Toggle theme"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="theme-ic"></svg></button>
  </div>
</div>

<main class="auth-wrap">
  <div class="auth-card">
    <span class="eyebrow" data-en="Members area" data-da="Medlemsområde">Members area</span>
    <div class="accent-rule"></div>
    <h1 data-en="Sign in to Vindex" data-da="Log ind på Vindex">Sign in to Vindex</h1>
    <p class="auth-sub" data-en="Enter the details your administrator gave you." data-da="Indtast de oplysninger, din administrator har givet dig.">Enter the details your administrator gave you.</p>

    <?php if ($error === 'locked'): ?>
      <div class="auth-error" data-en="<?= e($lockEn) ?>" data-da="<?= e($lockDa) ?>"><?= e($lockEn) ?></div>
    <?php elseif ($error): ?>
      <div class="auth-error" data-en="That email and password didn't match. Please try again." data-da="E-mail og adgangskode passede ikke. Prøv igen.">That email and password didn't match. Please try again.</div>
    <?php endif; ?>

    <form method="post" class="auth-form" autocomplete="on">
      <?= csrf_field() ?>
      <div class="field">
        <label data-en="Email" data-da="E-mail">Email</label>
        <input type="email" name="email" required autofocus value="<?= e(post('email')) ?>" placeholder="you@company.com">
      </div>
      <div class="field">
        <label data-en="Password" data-da="Adgangskode">Password</label>
        <input type="password" name="password" required placeholder="••••••••">
      </div>
      <button type="submit" class="btn btn-primary" style="justify-content:center;width:100%" data-en="Sign in" data-da="Log ind">Sign in</button>
    </form>

    <p class="auth-note" data-en="Protected by HTTPS, hashed passwords and secure sessions." data-da="Beskyttet af HTTPS, hashede adgangskoder og sikre sessioner.">Protected by HTTPS, hashed passwords and secure sessions.</p>
  </div>
</main>
<?= render_consent_and_analytics() ?>
<script src="<?= e(asset_url('assets/app.js')) ?>"></script>
</body>
</html>
