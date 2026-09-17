<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT m.*, i.name instance_name, i.type instance_type, i.url, i.api_key, i.enabled FROM movies m JOIN instances i ON i.id=m.instance_id WHERE m.id=?');
$stmt->execute([$id]);
$movie = $stmt->fetch();
if (!$movie) {
    http_response_code(404);
    exit('Movie not found');
}

$result = null;
$error = null;
if (!empty($movie['has_file'])) {
    $result = ['status'=>'available','summary'=>'This movie is already available.','categories'=>[],'releases'=>[],'blocklist'=>[]];
} else {
    try {
        $result = $arr->diagnoseMissingMovie([
            'id'=>$movie['instance_id'],
            'name'=>$movie['instance_name'],
            'type'=>$movie['instance_type'],
            'url'=>$movie['url'],
            'api_key'=>$movie['api_key'],
            'enabled'=>$movie['enabled'],
        ], (int)$movie['remote_id']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Why Missing? · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView</a><nav><a href="/?type=movies">Movies</a><a href="/?type=series">Series</a><?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap admin-wrap">
<section class="catalog-head"><div><p class="eyebrow">RADARR DIAGNOSTIC</p><h1><?=e($movie['title'])?></h1><p class="meta"><?=e((string)$movie['year'])?> · <?=e($movie['instance_name'])?></p></div><a class="button-link" href="/?type=movies">← Back to Movies</a></section>
<div class="notice info">This check performs a live Radarr indexer search. Results reflect what Radarr reports right now.</div>
<?php if($error):?><div class="notice error"><strong>Diagnostic failed:</strong> <?=e($error)?></div><?php elseif($result):?>
<section class="panel"><h2>Status</h2><p><?=e($result['summary'] ?? '')?></p></section>
<?php if(!empty($result['movie'])):?><section class="panel"><h2>Radarr movie state</h2><div class="diag-facts"><div><span>Monitored</span><strong><?=$result['movie']['monitored']?'Yes':'No'?></strong></div><div><span>Minimum availability</span><strong><?=e((string)($result['movie']['minimumAvailability'] ?? 'Unknown'))?></strong></div><div><span>Quality profile ID</span><strong><?=e((string)($result['movie']['qualityProfileId'] ?? 'Unknown'))?></strong></div></div></section><?php endif;?>
<?php if(!empty($result['categories'])):?><section class="panel"><h2>Probable reasons</h2><div class="reason-list"><?php foreach($result['categories'] as $category=>$messages):?><div class="reason-card"><h3><?=e($category)?></h3><?php foreach($messages as $message):?><p><?=e($message)?></p><?php endforeach;?></div><?php endforeach;?></div></section><?php endif;?>
<?php if(!empty($result['releases'])):?><section class="panel"><h2>Release search results</h2><div class="release-list"><?php foreach(array_slice($result['releases'],0,50) as $release):?><article class="release-row"><div><strong><?=e($release['title'])?></strong><p class="meta"><?=e($release['indexer'])?><?php if($release['quality']):?> · <?=e($release['quality'])?><?php endif;?><?php if($release['languages']):?> · <?=e($release['languages'])?><?php endif;?><?php if($release['size']):?> · <?=number_format($release['size']/1073741824,2)?> GB<?php endif;?></p></div><div class="release-status <?=$release['rejected']?'bad':'good'?>"><?=$release['rejected']?'Rejected':'Accepted'?></div><?php if(!empty($release['rejections'])):?><div class="release-reasons"><?php foreach($release['rejections'] as $reason):?><span><?=e($reason)?></span><?php endforeach;?></div><?php endif;?></article><?php endforeach;?></div></section><?php endif;?>
<?php endif;?>
</main><footer>ArrView · Radarr missing movie diagnostics</footer></body></html>
