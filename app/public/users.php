<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireAdmin();
$message=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    try {
        if ($action==='add') {
            $password=$_POST['password']??'';
            if ($password!==($_POST['confirm_password']??'')) throw new RuntimeException('Passwords do not match.');
            $auth->createUser($_POST['username']??'', $password, $_POST['role']??'viewer');
            $message='User created.';
        } elseif ($action==='toggle') {
            $id=(int)($_POST['id']??0);
            if ($id===(int)$currentUser['id']) throw new RuntimeException('You cannot disable your own account.');
            $stmt=$pdo->prepare('UPDATE users SET enabled=CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id=?');
            $stmt->execute([$id]);
            $message='User status updated.';
        } elseif ($action==='role') {
            $id=(int)($_POST['id']??0); $role=$_POST['role']??'';
            if (!in_array($role,['admin','viewer'],true)) throw new RuntimeException('Invalid role.');
            if ($id===(int)$currentUser['id'] && $role!=='admin') throw new RuntimeException('You cannot remove your own admin role.');
            $stmt=$pdo->prepare('UPDATE users SET role=? WHERE id=?'); $stmt->execute([$role,$id]);
            $message='Role updated.';
        } elseif ($action==='password') {
            $id=(int)($_POST['id']??0); $password=$_POST['password']??'';
            if (strlen($password)<8) throw new RuntimeException('Password must be at least 8 characters.');
            $stmt=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?'); $stmt->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
            $message='Password reset.';
        } elseif ($action==='delete') {
            $id=(int)($_POST['id']??0);
            if ($id===(int)$currentUser['id']) throw new RuntimeException('You cannot delete your own account.');
            $stmt=$pdo->prepare('DELETE FROM users WHERE id=?'); $stmt->execute([$id]);
            $message='User deleted.';
        }
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
$users=$pdo->query('SELECT id,username,role,enabled,created_at FROM users ORDER BY username COLLATE NOCASE')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Users · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a><nav><a href="/">Library</a><a href="/admin.php">Instances</a><a class="active" href="/users.php">Users</a><a href="/support.php">Support</a><a href="/logout.php">Logout</a></nav></header>
<main class="wrap admin-wrap"><section class="catalog-head"><div><p class="eyebrow">ADMIN</p><h1>Users</h1></div></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<section class="panel"><h2>Add User</h2><form method="post" class="instance-form"><input type="hidden" name="action" value="add"><label>Username<input name="username" minlength="3" required></label><label>Role<select name="role"><option value="viewer">Viewer</option><option value="admin">Admin</option></select></label><label>Password<input name="password" type="password" minlength="8" required></label><label>Confirm<input name="confirm_password" type="password" minlength="8" required></label><button class="primary">Create User</button></form></section>
<section class="panel"><h2>Accounts</h2><div class="instance-list">
<?php foreach($users as $u):?><article class="instance-row"><div><div class="instance-title"><span class="type-pill <?=e($u['role'])?>"><?=e(ucfirst($u['role']))?></span><strong><?=e($u['username'])?></strong><?php if(!(int)$u['enabled']):?><span class="badge-inline">Disabled</span><?php endif;?></div><small>Created <?=e($u['created_at'])?></small></div><div class="actions">
<form method="post"><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?=$u['id']?>"><select name="role" onchange="this.form.submit()"><option value="viewer" <?=$u['role']==='viewer'?'selected':''?>>Viewer</option><option value="admin" <?=$u['role']==='admin'?'selected':''?>>Admin</option></select></form>
<form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$u['id']?>"><button><?=$u['enabled']?'Disable':'Enable'?></button></form>
<form method="post" class="inline-reset"><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?=$u['id']?>"><input name="password" type="password" minlength="8" placeholder="New password" required><button>Reset</button></form>
<form method="post" onsubmit="return confirm('Delete this user?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="danger">Delete</button></form>
</div></article><?php endforeach;?>
</div></section></main><footer>ArrView User Management</footer></body></html>
