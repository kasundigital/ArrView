<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireAdmin();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? '';
            $url = trim($_POST['url'] ?? '');
            $apiKey = trim($_POST['api_key'] ?? '');
            if ($name === '' || !in_array($type, ['radarr','sonarr'], true) || $url === '' || $apiKey === '') throw new RuntimeException('All fields are required.');
            $stmt = $pdo->prepare('INSERT INTO instances(name,type,url,api_key) VALUES(?,?,?,?)');
            $stmt->execute([$name,$type,rtrim($url,'/'),$apiKey]);
            $message = 'Instance added.';
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM instances WHERE id=?'); $stmt->execute([(int)($_POST['id'] ?? 0)]); $message='Instance deleted.';
        } elseif ($action === 'toggle') {
            $stmt = $pdo->prepare('UPDATE instances SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id=?'); $stmt->execute([(int)($_POST['id'] ?? 0)]); $message='Instance status updated.';
        } elseif (in_array($action, ['test','sync'], true)) {
            $stmt = $pdo->prepare('SELECT * FROM instances WHERE id=?'); $stmt->execute([(int)($_POST['id'] ?? 0)]); $instance=$stmt->fetch();
            if (!$instance) throw new RuntimeException('Instance not found.');
            $result = $action === 'test' ? $arr->test($instance) : $arr->syncInstance($instance);
            if (!$result['ok']) throw new RuntimeException($result['message']);
            $message = $result['message'];
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$instances = $pdo->query('SELECT * FROM instances ORDER BY type,name')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView</a><nav><a href="/">Library</a><a class="active" href="/admin.php">Instances</a><a href="/users.php">Users</a><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap admin-wrap"><section class="catalog-head"><div><p class="eyebrow">SETTINGS</p><h1>Instances</h1></div></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<section class="panel"><h2>Add Radarr / Sonarr</h2><form method="post" class="instance-form"><input type="hidden" name="action" value="add"><label>Name<input name="name" placeholder="Radarr Main" required></label><label>Type<select name="type"><option value="radarr">Radarr</option><option value="sonarr">Sonarr</option></select></label><label>URL<input name="url" placeholder="http://192.168.1.10:7878" required></label><label>API Key<input name="api_key" type="password" placeholder="API key" required></label><button class="primary" type="submit">Add Instance</button></form></section>
<section class="panel"><h2>Configured Instances</h2><?php if(!$instances):?><div class="empty compact"><p>No instances configured yet.</p></div><?php else:?><div class="instance-list"><?php foreach($instances as $instance):?><article class="instance-row"><div><div class="instance-title"><span class="type-pill <?=e($instance['type'])?>"><?=e(ucfirst($instance['type']))?></span><strong><?=e($instance['name'])?></strong></div><p><?=e($instance['url'])?></p><small>Last sync: <?=e($instance['last_sync_at'] ?: 'Never')?><?=$instance['last_status']?' · '.e($instance['last_status']):''?></small></div><div class="actions"><form method="post"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=(int)$instance['id']?>"><button>Test</button></form><form method="post"><input type="hidden" name="action" value="sync"><input type="hidden" name="id" value="<?=(int)$instance['id']?>"><button class="primary">Sync Now</button></form><form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$instance['id']?>"><button><?=$instance['enabled']?'Disable':'Enable'?></button></form><form method="post" onsubmit="return confirm('Delete this instance and its cached media?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$instance['id']?>"><button class="danger">Delete</button></form></div></article><?php endforeach;?></div><?php endif;?></section></main><footer>ArrView Admin</footer></body></html>
