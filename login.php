<?php
require_once __DIR__ . '/lib/auth.php';
boot_session();

if (is_logged_in()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = post('email');
    $pass  = $_POST['password'] ?? '';
    if (attempt_login($email, $pass)) {
        redirect('index.php');
    }
    $error = 'invalid';
}
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vindex — Member Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
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

    <?php if ($error): ?>
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
<script src="assets/app.js"></script>
</body>
</html>
