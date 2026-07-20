<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/content.php';
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
                $st->execute([post('name'), $email, password_hash($_POST['password'], PASSWORD_DEFAULT), $role]);
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
                    ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
                break;

            case 'user_delete':
                $id = (int)post('id');
                if ($id === (int)$me['id']) { $code = 'err_self'; break; }
                $cur = db()->prepare("SELECT role FROM users WHERE id=?"); $cur->execute([$id]);
                if ($cur->fetchColumn() === 'admin' && admin_count() <= 1) { $code = 'err_last_admin'; break; }
                db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
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
    'err_user' => ['err', 'Please enter a name, valid email, and password of 8+ characters.'],
    'err_dupe' => ['err', 'That email is already in use.'],
    'err_pw' => ['err', 'Password must be at least 8 characters.'],
    'err_self' => ['err', "You can't delete your own account while logged in."],
    'err_last_admin' => ['err', 'There must always be at least one admin.'],
    'err_title' => ['err', 'A section needs an English title.'],
    'err_block' => ['err', 'A block needs a title or some text.'],
    'err_db' => ['err', 'Something went wrong saving that.'],
];
$flash = $flashes[get('ok')] ?? null;
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
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/admin.css">
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
        $users = db()->query('SELECT id,name,email,role,last_login FROM users ORDER BY role, name')->fetchAll(); ?>
      <h1>Users</h1>
      <p class="lead-p">Add people and set each one as Leadership (sees everything) or Employee (sees only content marked for everyone). Admins can manage users and content.</p>

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
              <?php if ($u['id'] != $me['id']): ?>
              <form method="post" class="inline" onsubmit="return confirm('Delete this user?')"><?= csrf_field() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=users"><button class="btn btn-sm btn-danger">Delete</button></form>
              <?php endif; ?>
              <div class="editbox" id="pw<?= $u['id'] ?>" hidden style="margin-top:8px">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="user_password"><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="redirect" value="admin.php?view=users">
                  <input name="password" placeholder="New password" minlength="8" required style="max-width:180px"><button class="btn btn-sm btn-primary">Set</button>
                </form>
              </div>
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
