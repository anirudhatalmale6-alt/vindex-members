<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/content.php';
// The public website's editable text lives one folder up. If the members area
// is installed on its own, the "Website text" page simply does not appear.
define('SITETEXT_LIB', __DIR__ . '/../inc/sitetext.php');
if (is_file(SITETEXT_LIB)) require_once SITETEXT_LIB;
define('HAS_SITETEXT', function_exists('st_save'));
// Contact form settings live with the public site too (inc/contact.php).
define('CONTACT_LIB', __DIR__ . '/../inc/contact.php');
if (is_file(CONTACT_LIB)) require_once CONTACT_LIB;
define('HAS_CONTACT', function_exists('vdx_contact_recipient'));
boot_session();
require_admin();
$me = current_user();

/* ---------------- helpers ---------------- */
function admin_count(): int {
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
}
function apply_move(string $table, string $whereSql, array $params, int $id, string $dir): void {
    $stmt = db()->prepare("SELECT id FROM $table WHERE $whereSql ORDER BY sort_order, id");
    $stmt->execute($params);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    $idx = array_search($id, $ids, true);
    if ($idx === false) return;
    $j = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($j < 0 || $j >= count($ids)) return;
    [$ids[$idx], $ids[$j]] = [$ids[$j], $ids[$idx]];
    $u = db()->prepare("UPDATE $table SET sort_order = ? WHERE id = ?");
    $o = 10;
    foreach ($ids as $sid) { $u->execute([$o, $sid]); $o += 10; }
}
function vis_in(string $k): string { return post($k) === 'leadership' ? 'leadership' : 'all'; }
function type_in(string $k): string { return post($k) === 'foldout' ? 'foldout' : 'text'; }

/* ---------------- POST actions (PRG) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $back   = post('redirect') ?: 'admin.php';
    $code   = 'saved';

    try {
        switch ($action) {
            case 'user_add':
                $email = strtolower(post('email'));
                if (post('name') === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($_POST['password'] ?? '') < 8) {
                    $code = 'err_user';
                    break;
                }
                $role = in_array(post('role'), ['admin', 'leadership', 'employee'], true) ? post('role') : 'employee';
                $st = db()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
                $st->execute([post('name'), $email, pw_hash($_POST['password']), $role]);
                $code = 'user_added';
                break;

            case 'user_role':
                $id = (int)post('id');
                $role = in_array(post('role'), ['admin', 'leadership', 'employee'], true) ? post('role') : 'employee';
                if ($role !== 'admin' && admin_count() <= 1) {
                    $cur = db()->prepare("SELECT role FROM users WHERE id=?"); $cur->execute([$id]);
                    if ($cur->fetchColumn() === 'admin') { $code = 'err_last_admin'; break; }
                }
                db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $id]);
                break;

            case 'user_password':
                $id = (int)post('id');
                if (strlen($_POST['password'] ?? '') < 8) { $code = 'err_pw'; break; }
                db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
                    ->execute([pw_hash($_POST['password']), $id]);
                break;

            case 'user_delete':
                $id = (int)post('id');
                if ($id === (int)$me['id']) { $code = 'err_self'; break; }
                $cur = db()->prepare("SELECT role FROM users WHERE id=?"); $cur->execute([$id]);
                if ($cur->fetchColumn() === 'admin' && admin_count() <= 1) { $code = 'err_last_admin'; break; }
                db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
                break;

            case 'user_access':
                $uid = (int)post('id');
                $secIds = $_POST['sections'] ?? [];
                if (!is_array($secIds)) $secIds = [];
                db()->prepare('DELETE FROM user_section_access WHERE user_id=?')->execute([$uid]);
                if ($secIds) {
                    $ins = db()->prepare('INSERT IGNORE INTO user_section_access (user_id, section_id) VALUES (?,?)');
                    foreach ($secIds as $sidv) {
                        $sidv = (int)$sidv;
                        if ($sidv > 0) $ins->execute([$uid, $sidv]);
                    }
                }
                $code = 'access_saved';
                $back = 'admin.php?view=users';
                break;

            case 'section_add':
                if (post('title_en') === '') { $code = 'err_title'; break; }
                $ord = ((int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM sections')->fetchColumn()) + 10;
                db()->prepare('INSERT INTO sections (title_en,title_da,sort_order,visibility) VALUES (?,?,?,?)')
                    ->execute([post('title_en'), post('title_da'), $ord, vis_in('visibility')]);
                break;

            case 'section_update':
                if (post('title_en') === '') { $code = 'err_title'; break; }
                db()->prepare('UPDATE sections SET title_en=?, title_da=?, visibility=? WHERE id=?')
                    ->execute([post('title_en'), post('title_da'), vis_in('visibility'), (int)post('id')]);
                break;

            case 'section_move':
                apply_move('sections', '1=1', [], (int)post('id'), post('dir') === 'up' ? 'up' : 'down');
                break;

            case 'section_delete':
                db()->prepare('DELETE FROM sections WHERE id=?')->execute([(int)post('id')]);
                $back = 'admin.php?view=sections';
                break;

            case 'block_add':
                $sid = (int)post('section');
                $parent = post('parent') !== '' ? (int)post('parent') : null;
                if (post('title_en') === '' && post('body_en') === '') { $code = 'err_block'; break; }
                $w = 'section_id=? AND ' . ($parent === null ? 'parent_id IS NULL' : 'parent_id=?');
                $p = $parent === null ? [$sid] : [$sid, $parent];
                $st = db()->prepare("SELECT COALESCE(MAX(sort_order),0) FROM blocks WHERE $w");
                $st->execute($p);
                $ord = ((int)$st->fetchColumn()) + 10;
                db()->prepare('INSERT INTO blocks (section_id,parent_id,type,title_en,title_da,body_en,body_da,visibility,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$sid, $parent, type_in('type'), post('title_en'), post('title_da'),
                        post('body_en'), post('body_da'), vis_in('visibility'), $ord]);
                break;

            case 'block_update':
                db()->prepare('UPDATE blocks SET type=?, title_en=?, title_da=?, body_en=?, body_da=?, visibility=? WHERE id=?')
                    ->execute([type_in('type'), post('title_en'), post('title_da'),
                        post('body_en'), post('body_da'), vis_in('visibility'), (int)post('id')]);
                break;

            case 'block_move':
                $id = (int)post('id');
                $b = db()->prepare('SELECT section_id, parent_id FROM blocks WHERE id=?');
                $b->execute([$id]);
                if ($row = $b->fetch()) {
                    $w = 'section_id=? AND ' . ($row['parent_id'] === null ? 'parent_id IS NULL' : 'parent_id=?');
                    $p = $row['parent_id'] === null ? [$row['section_id']] : [$row['section_id'], $row['parent_id']];
                    apply_move('blocks', $w, $p, $id, post('dir') === 'up' ? 'up' : 'down');
                }
                break;

            case 'block_delete':
                db()->prepare('DELETE FROM blocks WHERE id=?')->execute([(int)post('id')]);
                break;

            case 'contact_save':
                if (!HAS_CONTACT) break;
                $back = 'admin.php?view=sitetext';
                $em = strtolower(trim(post('contact_email')));
                if (!vdx_contact_save(db(), $em)) { $code = 'contact_bad'; break; }
                // Sending mailbox: a new password is saved encrypted; an empty
                // password box keeps the saved one; the tick-box removes it.
                $pw = isset($_POST['smtp_forget']) ? null : (string)($_POST['smtp_pass'] ?? '');
                $why = vdx_mailbox_save(db(), post('smtp_user'), $pw);
                if ($why === 'bad_user') { $code = 'smtp_bad_user'; break; }
                if ($why === 'no_key')   { $code = 'smtp_no_key'; break; }
                $code = $em === '' ? 'contact_cleared' : 'contact_saved';
                break;

            case 'contact_test':
                if (!HAS_CONTACT) break;
                $to = vdx_contact_recipient(db());
                if ($to === '') { $code = 'contact_none'; }
                else {
                    $r = vdx_send_enquiry($to, [
                        'name' => 'Vindex admin test', 'company' => '', 'email' => '',
                        'topic' => 'Test', 'message' => 'Sent with the "Send test email" button in the admin.',
                    ], true, db());
                    $code = $r['ok'] ? 'contact_test_ok' : 'contact_test_fail';
                    $_SESSION['contact_test_detail'] = $r['ok'] ? '' : $r['error'];
                }
                $back = 'admin.php?view=sitetext';
                break;

            case 'sitetext_save':
                if (!HAS_SITETEXT) break;
                st_ensure_table(db());
                $page   = array_key_exists(post('page'), st_pages()) ? post('page') : 'index';
                $fields = st_admin_fields(db(), $page);
                $in     = $_POST['st'] ?? [];
                // Save everything typed on the page first, even when the click
                // was a Reset — otherwise resetting one field would throw away
                // unsaved edits to all the others.
                $n = 0;
                foreach ($fields as $key => $f) {
                    if (!isset($in[$key]) || !is_array($in[$key])) continue;
                    $r = st_save(db(), $key, (string)($in[$key]['en'] ?? ''), (string)($in[$key]['da'] ?? ''), (int)$me['id']);
                    if ($r === 'saved') $n++;
                }
                $reset = post('reset_key');
                if ($reset !== '' && isset($fields[$reset])) { st_reset(db(), $reset); $code = 'st_reset'; }
                else $code = 'st_saved';
                $back = 'admin.php?view=sitetext&page=' . $page;
                break;
        }
    } catch (PDOException $ex) {
        $code = (strpos($ex->getMessage(), '1062') !== false) ? 'err_dupe' : 'err_db';
    }
    redirect($back . (strpos($back, '?') !== false ? '&' : '?') . 'ok=' . $code);
}

/* ---------------- flash text ---------------- */
$flashes = [
    'saved' => ['ok', 'Saved.'],
    'user_added' => ['ok', 'User added.'],
    'access_saved' => ['ok', 'Section access updated.'],
    'err_user' => ['err', 'Please enter a name, valid email, and password of 8+ characters.'],
    'err_dupe' => ['err', 'That email is already in use.'],
    'err_pw' => ['err', 'Password must be at least 8 characters.'],
    'err_self' => ['err', "You can't delete your own account while logged in."],
    'err_last_admin' => ['err', 'There must always be at least one admin.'],
    'err_title' => ['err', 'A section needs an English title.'],
    'err_block' => ['err', 'A block needs a title or some text.'],
    'err_db' => ['err', 'Something went wrong saving that.'],
    'st_saved' => ['ok', 'Website text saved — it is live on the site now.'],
    'st_reset' => ['ok', 'That text is back to its original wording.'],
    'contact_saved' => ['ok', 'Saved. Contact-form messages now go to that address — press "Send test email" to check it arrives.'],
    'contact_cleared' => ['ok', 'Cleared. The form now uses the address given at install, if there was one.'],
    'contact_bad' => ['err', 'That does not look like an email address. Nothing was changed.'],
    'contact_none' => ['err', 'There is no address to send to yet — enter one and press Save first.'],
    'contact_test_ok' => ['ok', 'Test email handed to the mail server. Check that inbox now — and the spam folder the first time. If nothing arrives within a few minutes, contact your web developer.'],
    'contact_test_fail' => ['err', 'The test email was NOT sent, so contact-form messages would not arrive either.'],
    'smtp_bad_user' => ['err', 'The sending mailbox does not look like an email address. Nothing was changed.'],
    'smtp_no_key' => ['err', 'The password could not be stored safely because this site has no encryption key. Nothing was saved — contact your web developer.'],
];
$flash = $flashes[get('ok')] ?? null;
// A failed test email says WHY (wrong password, server unreachable, ...).
if (get('ok') === 'contact_test_fail' && !empty($_SESSION['contact_test_detail'])) {
    $flash[1] .= ' Reason: ' . $_SESSION['contact_test_detail'] . '.';
    unset($_SESSION['contact_test_detail']);
}
$view  = get('view', 'dashboard');

/* ---------------- admin block-tree renderer ---------------- */
function vis_select(string $name, string $current): string {
    $o = function ($v, $label) use ($current) {
        return '<option value="' . $v . '"' . ($current === $v ? ' selected' : '') . '>' . $label . '</option>';
    };
    return '<select name="' . $name . '">' . $o('all', 'Everyone (Employees + Leadership)') . $o('leadership', 'Leadership only') . '</select>';
}
function type_select(string $current): string {
    $o = function ($v, $label) use ($current) {
        return '<option value="' . $v . '"' . ($current === $v ? ' selected' : '') . '>' . $label . '</option>';
    };
    return '<select name="type">' . $o('text', 'Text block') . $o('foldout', 'Fold-out (expandable)') . '</select>';
}
function render_admin_blocks(array $map, ?int $parent, int $sectionId, string $back): string {
    $key = $parent === null ? 0 : $parent;
    if (empty($map[$key])) return '';
    $html = '<div class="tree">';
    foreach ($map[$key] as $b) {
        $id = (int)$b['id'];
        $isFold = $b['type'] === 'foldout';
        $label = $b['title_en'] !== '' ? $b['title_en'] : (mb_strimwidth(strip_tags((string)$b['body_en']), 0, 60, '…') ?: '(text)');
        $lead = $b['visibility'] === 'leadership' ? '<span class="badge lead">Leadership</span>' : '';
        $csrf = csrf_field();
        $html .= '<div class="tnode">';
        $html .= '<div class="trow">'
            . '<span class="ttype">' . ($isFold ? '▸ fold-out' : 'text') . '</span>'
            . '<span class="tlabel">' . e($label) . '</span>' . $lead
            . '<span class="tactions">'
            . '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="block_move"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="dir" value="up"><input type="hidden" name="redirect" value="' . e($back) . '"><button class="btn btn-sm btn-ghost" title="Move up">↑</button></form>'
            . '<form method="post" class="inline">' . $csrf . '<input type="hidden" name="action" value="block_move"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="dir" value="down"><input type="hidden" name="redirect" value="' . e($back) . '"><button class="btn btn-sm btn-ghost" title="Move down">↓</button></form>'
            . '<button class="btn btn-sm btn-ghost" type="button" onclick="tog(\'edit' . $id . '\')">Edit</button>'
            . '<button class="btn btn-sm btn-ghost" type="button" onclick="tog(\'add' . $id . '\')">+ Sub</button>'
            . '<form method="post" class="inline" onsubmit="return confirm(\'Delete this block and everything inside it?\')">' . $csrf . '<input type="hidden" name="action" value="block_delete"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="redirect" value="' . e($back) . '"><button class="btn btn-sm btn-danger">Delete</button></form>'
            . '</span></div>';

        // edit form
        $html .= '<div class="editbox" id="edit' . $id . '" hidden><form method="post">' . $csrf
            . '<input type="hidden" name="action" value="block_update"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="redirect" value="' . e($back) . '">'
            . '<div class="grid2"><div class="field"><label>Type</label>' . type_select($b['type']) . '</div>'
            . '<div class="field"><label>Visible to</label>' . vis_select('visibility', $b['visibility']) . '</div></div>'
            . '<div class="grid2"><div class="field"><label>Heading (English)</label><input name="title_en" value="' . e($b['title_en']) . '"></div>'
            . '<div class="field"><label>Heading (Danish)</label><input name="title_da" value="' . e($b['title_da']) . '"></div></div>'
            . '<div class="grid2"><div class="field"><label>Text (English)</label><textarea name="body_en">' . e($b['body_en']) . '</textarea></div>'
            . '<div class="field"><label>Text (Danish)</label><textarea name="body_da">' . e($b['body_da']) . '</textarea></div></div>'
            . '<button class="btn btn-primary btn-sm">Save changes</button></form></div>';

        // add-child form
        $html .= '<div class="editbox" id="add' . $id . '" hidden><form method="post">' . $csrf
            . '<input type="hidden" name="action" value="block_add"><input type="hidden" name="section" value="' . $sectionId . '"><input type="hidden" name="parent" value="' . $id . '"><input type="hidden" name="redirect" value="' . e($back) . '">'
            . '<p class="hint">Add a block inside &ldquo;' . e($label) . '&rdquo;</p>'
            . '<div class="grid2"><div class="field"><label>Type</label>' . type_select('text') . '</div>'
            . '<div class="field"><label>Visible to</label>' . vis_select('visibility', 'all') . '</div></div>'
            . '<div class="grid2"><div class="field"><label>Heading (English)</label><input name="title_en"></div>'
            . '<div class="field"><label>Heading (Danish)</label><input name="title_da"></div></div>'
            . '<div class="grid2"><div class="field"><label>Text (English)</label><textarea name="body_en"></textarea></div>'
            . '<div class="field"><label>Text (Danish)</label><textarea name="body_da"></textarea></div></div>'
            . '<button class="btn btn-primary btn-sm">Add block</button></form></div>';

        $html .= render_admin_blocks($map, $id, $sectionId, $back);
        $html .= '</div>'; // tnode
    }
    $html .= '</div>';
    return $html;
}
?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vindex — Admin</title>
<link rel="stylesheet" href="../assets/fonts/fonts.css"><!-- self-hosted: nothing is sent to Google -->
<link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/admin.css')) ?>">
<script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('vindex-theme')||'dark');}catch(e){}</script>
</head>
<body class="app-body">
<header class="app-header">
  <div class="app-header-l"><a class="brand" href="index.php"><span class="dot"></span>VINDEX</a><span class="badge">Admin</span></div>
  <div class="app-header-r">
    <button class="icon-btn" id="theme" aria-label="Toggle theme"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="theme-ic"></svg></button>
    <a class="btn btn-ghost" href="index.php">View knowledge base</a>
    <a class="btn btn-ghost" href="logout.php">Log out</a>
  </div>
</header>

<div class="app-shell">
  <aside class="sidebar">
    <div class="sidebar-label">Admin</div>
    <nav class="side-nav">
      <a class="side-item <?= $view === 'dashboard' ? 'active' : '' ?>" href="admin.php">Dashboard</a>
      <a class="side-item <?= $view === 'users' ? 'active' : '' ?>" href="admin.php?view=users">Users</a>
      <a class="side-item <?= in_array($view, ['sections', 'content']) ? 'active' : '' ?>" href="admin.php?view=sections">Sections &amp; content</a>
      <?php if (HAS_SITETEXT): ?><a class="side-item <?= $view === 'sitetext' ? 'active' : '' ?>" href="admin.php?view=sitetext">Website text</a><?php endif; ?>
    </nav>
  </aside>

  <main class="content"><div class="content-inner admin-inner">
    <?php if ($flash): ?><div class="flash <?= $flash[0] ?>"><?= e($flash[1]) ?></div><?php endif; ?>

    <?php if ($view === 'dashboard'):
        $uc = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $sc = (int)db()->query('SELECT COUNT(*) FROM sections')->fetchColumn();
        $bc = (int)db()->query('SELECT COUNT(*) FROM blocks')->fetchColumn(); ?>
      <h1>Admin dashboard</h1>
      <p class="lead-p">Welcome, <?= e($me['name']) ?>. Manage your members and the knowledge base here.</p>
      <div class="stat-cards">
        <a class="stat-card" href="admin.php?view=users"><div class="n"><?= $uc ?></div><div class="l">Users</div></a>
        <a class="stat-card" href="admin.php?view=sections"><div class="n"><?= $sc ?></div><div class="l">Sections</div></a>
        <a class="stat-card" href="admin.php?view=sections"><div class="n"><?= $bc ?></div><div class="l">Content blocks</div></a>
      </div>

    <?php elseif ($view === 'users'):
        $users = db()->query('SELECT id,name,email,role,last_login FROM users ORDER BY role, name')->fetchAll();
        // Leadership-only sections are the ones a per-person grant is meaningful for.
        $leadSecs = db()->query("SELECT id,title_en FROM sections WHERE visibility='leadership' ORDER BY sort_order,id")->fetchAll();
        $grants = [];
        foreach (db()->query('SELECT user_id, section_id FROM user_section_access')->fetchAll() as $g) {
            $grants[(int)$g['user_id']][(int)$g['section_id']] = true;
        } ?>
      <h1>Users</h1>
      <p class="lead-p">Add people and set each one as Leadership (sees everything) or Employee (sees only content marked for everyone). Admins can manage users and content. For an individual Employee you can also grant access to specific Leadership sections with <b>Custom access</b>.</p>

      <details class="addpanel"><summary class="btn btn-primary">+ Add user</summary>
        <form method="post" class="card-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="user_add"><input type="hidden" name="redirect" value="admin.php?view=users">
          <div class="grid2"><div class="field"><label>Name</label><input name="name" required></div>
            <div class="field"><label>Email</label><input type="email" name="email" required></div></div>
          <div class="grid2"><div class="field"><label>Temporary password (8+ chars)</label><input name="password" required minlength="8"></div>
            <div class="field"><label>Role</label><select name="role"><option value="employee">Employee</option><option value="leadership">Leadership</option><option value="admin">Admin</option></select></div></div>
          <button class="btn btn-primary btn-sm">Create user</button>
        </form>
      </details>

      <table class="tbl">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last login</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['name']) ?><?= $u['id'] == $me['id'] ? ' <span class="badge">you</span>' : '' ?></td>
            <td class="muted"><?= e($u['email']) ?></td>
            <td>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="user_role"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=users">
                <select name="role" onchange="this.form.submit()">
                  <?php foreach (['employee' => 'Employee', 'leadership' => 'Leadership', 'admin' => 'Admin'] as $rv => $rl): ?>
                    <option value="<?= $rv ?>" <?= $u['role'] === $rv ? 'selected' : '' ?>><?= $rl ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="muted"><?= $u['last_login'] ? e(substr($u['last_login'], 0, 16)) : '—' ?></td>
            <td class="row-actions">
              <button class="btn btn-sm btn-ghost" type="button" onclick="tog('pw<?= $u['id'] ?>')">Password</button>
              <?php if ($u['role'] === 'employee' && $leadSecs): ?>
              <button class="btn btn-sm btn-ghost" type="button" onclick="tog('acc<?= $u['id'] ?>')">Custom access</button>
              <?php endif; ?>
              <?php if ($u['id'] != $me['id']): ?>
              <form method="post" class="inline" onsubmit="return confirm('Delete this user?')"><?= csrf_field() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=users"><button class="btn btn-sm btn-danger">Delete</button></form>
              <?php endif; ?>
              <div class="editbox" id="pw<?= $u['id'] ?>" hidden style="margin-top:8px">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="user_password"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=users">
                  <input name="password" placeholder="New password" minlength="8" required style="max-width:180px"><button class="btn btn-sm btn-primary">Set</button>
                </form>
              </div>
              <?php if ($u['role'] === 'employee' && $leadSecs): ?>
              <div class="editbox" id="acc<?= $u['id'] ?>" hidden style="margin-top:8px">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_access"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=users">
                  <p class="hint">Give <?= e($u['name']) ?> access to these Leadership sections:</p>
                  <div class="acc-list">
                  <?php foreach ($leadSecs as $ls): ?>
                    <label class="acc-item"><input type="checkbox" name="sections[]" value="<?= (int)$ls['id'] ?>" <?= isset($grants[(int)$u['id']][(int)$ls['id']]) ? 'checked' : '' ?>> <?= e($ls['title_en']) ?></label>
                  <?php endforeach; ?>
                  </div>
                  <button class="btn btn-sm btn-primary">Save access</button>
                </form>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

    <?php elseif ($view === 'sections'):
        $sections = db()->query('SELECT * FROM sections ORDER BY sort_order, id')->fetchAll(); ?>
      <h1>Sections &amp; content</h1>
      <p class="lead-p">These are the items in the left-hand menu. Reorder them, rename them in English and Danish, choose who can see each, and open one to edit its content.</p>

      <details class="addpanel"><summary class="btn btn-primary">+ Add section</summary>
        <form method="post" class="card-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="section_add"><input type="hidden" name="redirect" value="admin.php?view=sections">
          <div class="grid2"><div class="field"><label>Title (English)</label><input name="title_en" required></div>
            <div class="field"><label>Title (Danish)</label><input name="title_da"></div></div>
          <div class="field"><label>Visible to</label><?= vis_select('visibility', 'all') ?></div>
          <button class="btn btn-primary btn-sm">Add section</button>
        </form>
      </details>

      <div class="seclist">
        <?php foreach ($sections as $s): ?>
          <div class="secrow">
            <div class="secmain">
              <span class="secname"><?= e($s['title_en']) ?></span>
              <span class="secda"><?= e($s['title_da']) ?></span>
              <?php if ($s['visibility'] === 'leadership'): ?><span class="badge lead">Leadership</span><?php endif; ?>
            </div>
            <div class="secacts">
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="section_move"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="dir" value="up"><input type="hidden" name="redirect" value="admin.php?view=sections"><button class="btn btn-sm btn-ghost" title="Move up">↑</button></form>
              <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="section_move"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="dir" value="down"><input type="hidden" name="redirect" value="admin.php?view=sections"><button class="btn btn-sm btn-ghost" title="Move down">↓</button></form>
              <a class="btn btn-sm btn-primary" href="admin.php?view=content&section=<?= $s['id'] ?>">Edit content</a>
              <button class="btn btn-sm btn-ghost" type="button" onclick="tog('sec<?= $s['id'] ?>')">Rename</button>
              <form method="post" class="inline" onsubmit="return confirm('Delete this section and all its content?')"><?= csrf_field() ?><input type="hidden" name="action" value="section_delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=sections"><button class="btn btn-sm btn-danger">Delete</button></form>
            </div>
            <div class="editbox" id="sec<?= $s['id'] ?>" hidden>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="section_update"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=sections">
                <div class="grid2"><div class="field"><label>Title (English)</label><input name="title_en" value="<?= e($s['title_en']) ?>" required></div>
                  <div class="field"><label>Title (Danish)</label><input name="title_da" value="<?= e($s['title_da']) ?>"></div></div>
                <div class="field"><label>Visible to</label><?= vis_select('visibility', $s['visibility']) ?></div>
                <button class="btn btn-primary btn-sm">Save</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php elseif ($view === 'content'):
        $sid = (int)get('section');
        $sec = db()->prepare('SELECT * FROM sections WHERE id=?'); $sec->execute([$sid]); $sec = $sec->fetch();
        if (!$sec): ?>
          <h1>Section not found</h1><a class="btn btn-ghost" href="admin.php?view=sections">Back</a>
        <?php else:
          $back = 'admin.php?view=content&section=' . $sid;
          $map = fetch_block_map($sid, true); ?>
          <div class="crumbs"><a href="admin.php?view=sections">Sections</a> / <?= e($sec['title_en']) ?></div>
          <h1>Edit: <?= e($sec['title_en']) ?></h1>
          <p class="lead-p">Build this section from blocks. A <b>text block</b> shows text directly; a <b>fold-out</b> is a heading with an arrow that expands. Add sub-blocks inside a fold-out to nest as deep as you like. Mark any block Leadership-only to hide it from Employees.</p>

          <details class="addpanel" open><summary class="btn btn-primary">+ Add a top-level block</summary>
            <form method="post" class="card-form">
              <?= csrf_field() ?><input type="hidden" name="action" value="block_add"><input type="hidden" name="section" value="<?= $sid ?>"><input type="hidden" name="parent" value=""><input type="hidden" name="redirect" value="<?= e($back) ?>">
              <div class="grid2"><div class="field"><label>Type</label><?= type_select('text') ?></div>
                <div class="field"><label>Visible to</label><?= vis_select('visibility', 'all') ?></div></div>
              <div class="grid2"><div class="field"><label>Heading (English)</label><input name="title_en"></div>
                <div class="field"><label>Heading (Danish)</label><input name="title_da"></div></div>
              <div class="grid2"><div class="field"><label>Text (English)</label><textarea name="body_en"></textarea></div>
                <div class="field"><label>Text (Danish)</label><textarea name="body_da"></textarea></div></div>
              <button class="btn btn-primary btn-sm">Add block</button>
            </form>
          </details>

          <?php $tree = render_admin_blocks($map, null, $sid, $back);
          echo $tree !== '' ? $tree : '<p class="muted" style="margin-top:20px">No blocks yet — add your first one above.</p>'; ?>
        <?php endif; ?>

    <?php elseif ($view === 'sitetext' && HAS_SITETEXT):
        $pages  = st_pages();
        $page   = array_key_exists(get('page'), $pages) ? get('page') : 'index';
        $fields = st_admin_fields(db(), $page);
        $edited = count(array_filter($fields, fn($f) => $f['saved'] !== null));
        $groups = [];
        foreach ($fields as $k => $f) $groups[$f['group']][$k] = $f;
        // The page title and Google description go LAST. They were first, and
        // they are the one thing whose change you cannot see on the page —
        // the client edited the title, looked at the page, and reasonably
        // concluded that saving did nothing.
        $seoGroup = 'Search engines & browser tab';
        if (isset($groups[$seoGroup])) { $g = $groups[$seoGroup]; unset($groups[$seoGroup]); $groups[$seoGroup] = $g; }
        $viewUrl = '../' . ($page === 'shared' ? 'index' : $page) . '.php'; ?>
      <h1>Website text</h1>
      <p class="lead-p">Change any heading or paragraph on the public website, in English and Danish. Press <b>Save</b> and it is live straight away. Every box shows its original wording until you change it, and <b>Reset</b> puts a box back to the original at any time — so experiment freely.</p>

      <?php if (HAS_CONTACT):
          $ctNow = vdx_contact_recipient(db()); $ctSet = vdx_contact_setting(db()); $box = vdx_mailbox(db());
          $ready = $ctNow !== '' && $box['user'] !== '' && $box['has_pass']; ?>
      <div class="st-contact <?= $ctNow === '' ? 'off' : (!$ready ? 'warn' : '') ?>">
        <div class="st-gtitle" style="border:0;margin:0 0 6px">Contact form</div>
        <?php if ($ctNow === ''): ?>
          <p class="st-warn">The contact form is <b>switched off</b> — there is no address for enquiries to go to, so visitors see "the contact form isn't switched on yet". Fill in the boxes below and press Save.</p>
        <?php elseif (!$ready): ?>
          <p class="st-warn">Messages go to <b><?= e($ctNow) ?></b>, but no sending mailbox is set up yet. one.com only lets a website send email through a <b>real mailbox on your own domain</b>, so add one below — otherwise messages will not be sent.</p>
        <?php else: ?>
          <p class="muted" style="margin:0 0 10px">Messages go to <b><?= e($ctNow) ?></b>, sent through your mailbox <b><?= e($box['user']) ?></b>. ✓</p>
        <?php endif; ?>
        <form method="post" class="st-cgrid" autocomplete="off">
          <?= csrf_field() ?><input type="hidden" name="action" value="contact_save">
          <label>Send enquiries to
            <input type="email" name="contact_email" value="<?= e($ctSet !== '' ? $ctSet : $ctNow) ?>" placeholder="you@yourdomain.dk"></label>
          <label>Send them using this mailbox <span class="muted">(a real mailbox on your domain)</span>
            <input type="email" name="smtp_user" value="<?= e($box['user']) ?>" placeholder="info@yourdomain.dk"></label>
          <label>That mailbox's password
            <input type="password" name="smtp_pass" value="" autocomplete="new-password" placeholder="<?= $box['has_pass'] ? '•••••••• saved — leave empty to keep it' : 'the password you use to log in to that mailbox' ?>"></label>
          <?php if ($box['has_pass']): ?><label class="st-check"><input type="checkbox" name="smtp_forget" value="1"> Remove the saved password</label><?php endif; ?>
          <div><button class="btn btn-primary btn-sm">Save</button>
            <span class="muted" style="font-size:12.5px;margin-left:8px">The password is stored encrypted and is never shown again.</span></div>
        </form>
        <?php if ($ctNow !== ''): ?>
        <form method="post" style="margin-top:10px">
          <?= csrf_field() ?><input type="hidden" name="action" value="contact_test">
          <button class="btn btn-ghost btn-sm">Send test email</button>
          <span class="muted" style="font-size:13px;margin-left:6px">sends a short test message to <?= e($ctNow) ?></span>
        </form>
        <?php endif; ?>
        <?php $sends = vdx_recent_sends(db()); if ($sends): ?>
        <details class="st-log" open><summary>Recent sends — the last <?= count($sends) ?> attempts, newest first</summary>
          <table><tr><th>When (server time)</th><th>From</th><th>Result</th><th>How</th></tr>
          <?php foreach ($sends as $l): ?>
            <tr><td><?= e($l['at']) ?></td><td><?= $l['source'] === 'test' ? 'Test button' : 'Website form' ?></td>
              <td class="<?= $l['ok'] ? 'ok' : 'bad' ?>"><?= $l['ok'] ? 'Accepted by the mail server' : 'NOT sent — ' . e($l['error']) ?></td>
              <td><?= $l['via'] === 'smtp' ? 'your mailbox' : 'basic PHP mail' ?></td></tr>
          <?php endforeach; ?></table>
          <p class="muted" style="font-size:12.5px;margin:6px 0 0">Only the outcome is recorded, never the message itself. "Accepted" means the mail server took it; if it then doesn't reach the inbox, check the spam folder.</p>
        </details>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="st-tabs">
        <?php foreach ($pages as $pk => $pl): ?>
          <a class="st-tab <?= $pk === $page ? 'on' : '' ?>" href="admin.php?view=sitetext&page=<?= e($pk) ?>"><?= e($pl) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="st-help">
        <b>Formatting:</b> <code>**bold**</code> · <code>==cyan highlight==</code> (used in the big headline) · <code>[link text](https://…)</code> · a new line in the box becomes a new line on the page.
        <?php if ($page === 'shared'): ?><br>These appear on <b>every</b> page — the fonts, the top menu and the footer. Changing one changes it everywhere. Pick a font and press Save, then refresh the website to see it.<?php endif; ?>
      </div>

      <form method="post" class="st-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="sitetext_save"><input type="hidden" name="page" value="<?= e($page) ?>">
        <div class="st-bar">
          <span class="muted"><?= count($fields) ?> pieces of text<?= $edited ? ' · <b>' . $edited . ' changed from the original</b>' : ' · all original' ?></span>
          <span><a class="btn btn-ghost btn-sm" href="<?= e($viewUrl) ?>" target="_blank" rel="noopener">Open this page ↗</a>
          <button class="btn btn-primary btn-sm">Save</button></span>
        </div>

        <?php foreach ($groups as $gname => $gfields): ?>
          <div class="st-group">
            <div class="st-gtitle"><?= e($gname) ?></div>
            <?php if ($gname === $seoGroup): ?><p class="st-note">These two are <b>not shown on the page itself</b>. The title appears on the browser tab and as the blue headline in Google results; the description appears under it in Google. Changes here won't be visible when you look at the page.</p><?php endif; ?>
            <?php foreach ($gfields as $key => $f):
                $cur  = $f['saved'] ?? ['en' => $f['en'], 'da' => $f['da']];
                $rows = fn($t) => max(1, min(10, (int)ceil(mb_strlen($t) / 70) + substr_count($t, "\n")));
                $isSeo = in_array($f['kind'], ['seo', 'setting', 'font'], true); ?>
              <div class="st-field <?= $f['saved'] ? 'changed' : '' ?>" id="f-<?= e($key) ?>">
                <div class="st-flabel"><?= e($f['label']) ?>
                  <?php if ($f['saved']): ?><span class="st-badge">changed</span>
                    <button class="btn btn-ghost btn-xs" name="reset_key" value="<?= e($key) ?>" title="Original: <?= e($f['en']) ?>">Reset to original</button>
                  <?php endif; ?></div>
                <div class="<?= $isSeo ? '' : 'grid2' ?>">
                  <?php if ($f['kind'] === 'font'): $fc = st_font_choices(); ?>
                  <div class="field st-font"><label>Font</label>
                    <select name="st[<?= e($key) ?>][en]" onchange="var o=this.options[this.selectedIndex];this.parentNode.querySelector('.st-fprev').style.fontFamily=o.getAttribute('data-family')">
                      <?php foreach ($fc as $slug => $font): ?>
                        <option value="<?= e($slug) ?>" data-family="'<?= e($font['family']) ?>',sans-serif" style="font-family:'<?= e($font['family']) ?>',sans-serif"<?= $cur['en'] === $slug ? ' selected' : '' ?>><?= e($font['family']) ?><?= $slug === $f['en'] ? ' (original)' : '' ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="st-fprev" style="font-family:'<?= e(($fc[$cur['en']] ?? $fc[$f['en']])['family']) ?>',sans-serif">VINDEX — Fremtidssikrede resultater · Æ Ø Å · 2026</div></div>
                  <?php elseif ($f['kind'] === 'setting'): ?>
                  <div class="field"><label>Number</label>
                    <input type="number" name="st[<?= e($key) ?>][en]" value="<?= e($cur['en']) ?>" min="0" max="<?= (int)($f['max'] ?? 100) ?>" step="1" style="max-width:120px"></div>
                  <?php else: ?>
                  <div class="field"><label><?= $isSeo ? 'Text' : 'English' ?></label>
                    <textarea name="st[<?= e($key) ?>][en]" rows="<?= $rows($cur['en']) ?>" maxlength="4000"><?= e($cur['en']) ?></textarea></div>
                  <?php endif; ?>
                  <?php if (!$isSeo): ?>
                  <div class="field"><label>Dansk</label>
                    <textarea name="st[<?= e($key) ?>][da]" rows="<?= $rows($cur['da']) ?>" maxlength="4000"><?= e($cur['da']) ?></textarea></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <div class="st-bar st-bar-bottom"><span class="muted">Leave a box empty to go back to its original wording.</span>
          <button class="btn btn-primary">Save</button></div>
      </form>
    <?php endif; ?>
  </div></main>
</div>

<script>
function tog(id){var el=document.getElementById(id);if(el)el.hidden=!el.hidden;}
(function(){var r=document.documentElement,ic=document.getElementById('theme-ic');
 var M='<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',S='<circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.4 1.4M17.6 17.6L19 19M19 5l-1.4 1.4M6.4 17.6L5 19"/>';
 function paint(){if(ic)ic.innerHTML=r.getAttribute('data-theme')==='light'?S:M;}
 var b=document.getElementById('theme');if(b)b.onclick=function(){var t=r.getAttribute('data-theme')==='light'?'dark':'light';r.setAttribute('data-theme',t);try{localStorage.setItem('vindex-theme',t);}catch(e){}paint();};paint();})();
</script>
</body>
</html>
