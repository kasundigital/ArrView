<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser=$auth->requireLogin();

$id=(int)($_GET['id']??0);
$deep=($_GET['deep']??'')==='1';

$stmt=$pdo->prepare('SELECT e.*,s.title series_title,s.id series_local_id,i.id instance_id,i.name instance_name,i.type,i.url,i.api_key
 FROM episodes e JOIN series s ON s.id=e.series_id JOIN instances i ON i.id=e.instance_id WHERE e.id=? LIMIT 1');
$stmt->execute([$id]);
$episode=$stmt->fetch();
if(!$episode){http_response_code(404);exit('Episode not found.');}

$result=null;$error=null;
try{
    $result=$arr->diagnoseMissingEpisode([
        'id'=>(int)$episode['instance_id'],'name'=>$episode['instance_name'],'type'=>$episode['type'],
        'url'=>$episode['url'],'api_key'=>$episode['api_key']
    ],(int)$episode['remote_id'],$deep);
}catch(Throwable $e){$error=$e->getMessage();}

function epDate(?string $date):string{if(!$date)return '—';try{return(new DateTime($date))->format('Y-m-d H:i:s');}catch(Throwable){return$date;}}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Episode Diagnostics · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a><nav><a href="/?type=movies">Movies</a><a class="active" href="/?type=series">Series</a><a href="/support.php">Support</a><?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap admin-wrap">
<section class="catalog-head"><div><p class="eyebrow">SONARR EPISODE DIAGNOSTICS</p><h1><?=e($episode['series_title'])?></h1><p class="meta">S<?=str_pad((string)$episode['season_number'],2,'0',STR_PAD_LEFT)?>E<?=str_pad((string)$episode['episode_number'],2,'0',STR_PAD_LEFT)?> · <?=e($episode['title'])?> · <?=e($episode['instance_name'])?></p></div><a class="button-link" href="/series.php?id=<?=(int)$episode['series_local_id']?>">← Back to Series</a></section>

<div class="notice info"><?=$deep?'Deep Search is querying enabled Sonarr indexers.':'Light check reads episode state, queue and recent history only. It does not search indexers.'?></div>
<?php if($error):?><div class="notice error"><strong>Diagnostic failed:</strong> <?=e($error)?></div>
<?php elseif($result):
$diag=$result['diagnosis']??['label'=>'Unknown','severity'=>'warning','detail'=>''];
?>
<section class="diagnosis-hero diag-<?=e($diag['severity']??'warning')?>"><div><p class="eyebrow">PRIMARY DIAGNOSIS</p><h2><?=e($diag['label'])?></h2><p><?=e($diag['detail'])?></p></div><span class="diagnosis-state"><?=e(strtoupper($diag['severity']??'warning'))?></span></section>

<?php if(empty($result['deep_performed']) && empty($episode['has_file'])):?><section class="panel deep-search-cta"><h2>Need indexer-level reasons?</h2><p class="muted">Run Deep Search only when needed. It checks live Sonarr releases and rejection reasons without loading the entire response into memory.</p><a class="support-primary" href="/episode-diagnose.php?id=<?=$id?>&deep=1">Run Deep Search</a></section><?php endif;?>

<section class="panel"><h2>Episode state</h2><div class="diag-facts"><div><span>Monitored</span><strong><?=!empty($result['episode']['monitored'])?'Yes':'No'?></strong></div><div><span>Air Date</span><strong><?=e(epDate($result['episode']['airDateUtc']??$episode['air_date_utc']))?></strong></div><div><span>File</span><strong><?=!empty($result['episode']['hasFile'])?'Available':'Missing'?></strong></div><div><span>Cached Audio</span><strong><?=e($episode['audio_languages']?:'—')?></strong></div></div></section>

<?php if(!empty($result['queue'])):?><section class="panel"><h2>Download / import queue</h2><div class="release-list"><?php foreach($result['queue'] as $q):?><article class="release-row"><div><strong><?=e((string)$q['title'])?></strong><p class="meta"><?=e((string)$q['state'])?><?php if(!empty($q['download_client'])):?> · <?=e($q['download_client'])?><?php endif;?></p></div><div class="release-status"><?=e((string)$q['state'])?></div><?php if(!empty($q['messages'])):?><div class="release-reasons"><?php foreach($q['messages'] as $m):?><span><?=e($m)?></span><?php endforeach;?></div><?php endif;?></article><?php endforeach;?></div></section><?php endif;?>

<?php if(!empty($result['categories'])):?><section class="panel"><h2>Detected reasons</h2><div class="reason-list"><?php foreach($result['categories'] as $name=>$messages):?><div class="reason-card"><h3><?=e($name)?></h3><?php foreach($messages as $m):?><p><?=e($m)?></p><?php endforeach;?></div><?php endforeach;?></div></section><?php endif;?>

<?php if(!empty($result['history'])):?><section class="panel"><h2>Recent Sonarr history</h2><div class="history-list"><?php foreach(array_slice($result['history'],0,20) as $event):?><article class="history-row"><div class="history-type"><?=e($event['event_type']?:'event')?></div><div><strong><?=e($event['source_title']?:ucfirst((string)$event['event_type']))?></strong><p class="meta"><?=e(epDate($event['date']??null))?><?php if($event['quality']):?> · <?=e($event['quality'])?><?php endif;?><?php if($event['languages']):?> · <?=e($event['languages'])?><?php endif;?></p><?php if($event['message']):?><p><?=e((string)$event['message'])?></p><?php endif;?></div></article><?php endforeach;?></div></section><?php endif;?>

<?php if(!empty($result['deep_performed'])):?><section class="panel"><div class="panel-heading-inline"><h2>Release search results</h2><span class="muted">Showing up to <?=count($result['releases']??[])?> releases</span></div><?php if(empty($result['releases'])):?><div class="empty compact"><p>No releases returned.</p></div><?php else:?><div class="release-list"><?php foreach($result['releases'] as $release):?><article class="release-row"><div><strong><?=e($release['title'])?></strong><p class="meta"><?=e($release['indexer'])?><?php if($release['quality']):?> · <?=e($release['quality'])?><?php endif;?><?php if($release['languages']):?> · <?=e($release['languages'])?><?php endif;?></p></div><div class="release-status <?=$release['rejected']?'bad':'good'?>"><?=$release['rejected']?'Rejected':'Accepted'?></div><?php if($release['rejections']):?><div class="release-reasons"><?php foreach($release['rejections'] as $reason):?><span><?=e($reason)?></span><?php endforeach;?></div><?php endif;?></article><?php endforeach;?></div><?php endif;?></section><?php endif;?>
<?php endif;?>
</main><footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Sonarr episode diagnostics</footer></body></html>