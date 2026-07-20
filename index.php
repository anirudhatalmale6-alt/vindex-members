<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/content.php';
boot_session();
require_login();

$me = current_user();

// ---- AJAX: return one section's content (server-side access enforced) ----
if (get('section') !== '') {
    $sid = (int)get('section');
    $stmt = db()->prepare('SELECT * FROM sections WHERE id = ? AND (' . visibility_sql() . ') LIMIT 1');
    $stmt->execute([$sid]);
    $sec = $stmt->fetch();
    if (!$sec) json_out(['ok' => false, 'error' => 'not_found'], 404);
    $map  = fetch_block_map($sid);
    $body = render_blocks($map, null);
    json_out([
        'ok'       => true,
        'title_en' => $sec['title_en'],
        'title_da' => $sec['title_da'] !== '' ? $sec['title_da'] : $sec['title_en'],
        'html'     => $body,
    ]);
}

$sections = fetch_sections();
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vindex — Knowledge Base</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('vindex-theme')||'dark');}catch(e){}</script>
</head>
<body class="app-body">

<header class="app-header">
  <div class="app-header-l">
    <button class="icon-btn side-toggle" id="side-toggle" aria-label="Menu"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
    <a class="brand" href="index.php"><span class="dot"></span>VINDEX</a>
  </div>
  <div class="app-header-r">
    <div class="toggle" id="lang"><button data-lang="en" class="on">EN</button><button data-lang="da">DA</button></div>
    <button class="icon-btn" id="theme" aria-label="Toggle theme"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="theme-ic"></svg></button>
    <?php if (is_admin()): ?>
      <a class="btn btn-ghost" href="admin.php" data-en="Admin" data-da="Admin">Admin</a>
    <?php endif; ?>
    <div class="user-chip">
      <span class="user-name"><?= e($me['name']) ?></span>
      <span class="role-badge role-<?= e($me['role']) ?>"><?= e(ucfirst($me['role'])) ?></span>
    </div>
    <a class="btn btn-ghost" href="logout.php" data-en="Log out" data-da="Log ud">Log out</a>
  </div>
</header>

<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-label" data-en="Knowledge base" data-da="Vidensbase">Knowledge base</div>
    <nav class="side-nav" id="side-nav">
      <?php if (!$sections): ?>
        <p class="side-empty" data-en="No sections yet." data-da="Ingen sektioner endnu.">No sections yet.</p>
      <?php endif; ?>
      <?php foreach ($sections as $s): ?>
        <a class="side-item" href="#s<?= (int)$s['id'] ?>" data-id="<?= (int)$s['id'] ?>">
          <span data-en="<?= e($s['title_en']) ?>" data-da="<?= e($s['title_da'] !== '' ? $s['title_da'] : $s['title_en']) ?>"><?= e($s['title_en']) ?></span>
          <?php if ($s['visibility'] === 'leadership'): ?><span class="badge lead" data-en="Leadership" data-da="Ledelse">Leadership</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <main class="content" id="content">
    <div class="content-inner" id="content-inner">
      <div class="welcome" id="welcome">
        <span class="eyebrow" data-en="Welcome back" data-da="Velkommen tilbage"><?= e($me['name']) ?></span>
        <div class="accent-rule"></div>
        <h1 data-en="Your knowledge base" data-da="Jeres vidensbase">Your knowledge base</h1>
        <p data-en="Pick a section from the menu on the left. Sections with an arrow expand to reveal more — click a heading to open it." data-da="Vælg en sektion i menuen til venstre. Sektioner med en pil folder ud og viser mere — klik på en overskrift for at åbne den.">Pick a section from the menu on the left. Sections with an arrow expand to reveal more — click a heading to open it.</p>
      </div>
    </div>
    <div class="content-loading" id="loading" hidden><span class="spinner"></span></div>
  </main>
</div>

<script src="assets/app.js"></script>
</body>
</html>
