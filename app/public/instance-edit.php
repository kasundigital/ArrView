<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser=$auth->requireAdmin();
$id=(int)($_GET['id']??$_POST['id']??0);
$stmt=$pdo->prepare('SELECT * FROM instances WHERE id=? LIMIT 1');$stmt->execute([$id]);$instance=$stmt->fetch();
if(!$instance){http_response_code(404);exit('Instance not found.');}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $auth->requireCsrf($_POST['csrf_token']??null);
    $name=trim($_POST['name']??'');$type=$_POST['type']??'';$url=rtrim(trim($_POST['url']??''),'/');$apiKey=trim($_POST['api_key']??'');
    if($name===''||!in_array($type,['radarr','sonarr'],true)||$url==='')throw new RuntimeException('Name, type and URL are required.');
    if(!preg_match('#^https?://#i',$url))throw new RuntimeException('URL must start with http:// or https://');
    if($apiKey!==''){$q=$pdo->prepare('UPDATE instances SET name=?,type=?,url=?,api_key=? WHERE id=?');$q->execute([$name,$type,$url,$secret->protect($apiKey),$id]);}
    else{$q=$pdo->prepare('UPDATE instances SET name=?,type=?,url=? WHERE id=?');$q->execute([$name,$type,$url,$id]);}
    $message='Instance updated.';
    $stmt->execute([$id]);$instance=$stmt->fetch();
  }catch(Throwable $e){$error=$e->getMessage();}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Edit Instance · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a><nav><a href="/">Library</a><a class="active" href="/admin.php">Instances</a><a href="/users.php">Users</a><a href="/system.php">System</a><a href="/support.php">Support</a><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap admin-wrap"><section class="catalog-head"><div><p class="eyebrow">ADMIN</p><h1>Edit instance</h1><p class="meta"><?=e($instance['name'])?></p></div><a class="button-link" href="/admin.php">← Back</a></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<section class="panel"><form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>">
<label>Name<input name="name" value="<?=e($instance['name'])?>" required></label>
<label>Type<select name="type"><option value="radarr" <?=$instance['type']==='radarr'?'selected':''?>>Radarr</option><option value="sonarr" <?=$instance['type']==='sonarr'?'selected':''?>>Sonarr</option></select></label>
<label>URL<input name="url" value="<?=e($instance['url'])?>" required></label>
<label>API Key<input name="api_key" type="password" placeholder="Leave blank to keep current key"><small class="muted">The existing API key is never displayed.</small></label>
<button class="primary" type="submit">Save Changes</button>
</form></section></main><footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Instance settings</footer></body></html>