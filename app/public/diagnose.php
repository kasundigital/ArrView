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
    $result = [
        'status'=>'available',
        'summary'=>'This movie is already available.',
        'diagnosis'=>['label'=>'Available','severity'=>'good','detail'=>'A movie file is already present.'],
        'categories'=>[],
        'releases'=>[],
        'blocklist'=>[],
        'queue'=>[],
        'history'=>[],
    ];
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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Why Missing? · ArrView</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/">ArrView</a>
  <nav>
    <a href="/?type=movies">Movies</a>
    <a href="/?type=series">Series</a>
    <?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?>
    <span class="user-chip"><?=e($currentUser['username'])?></span>
    <a href="/logout.php">Logout</a>
  </nav>
</header>

<main class="wrap admin-wrap">
<section class="catalog-head">
  <div>
    <p class="eyebrow">ARRVIEW DIAGNOSTICS</p>
    <h1><?=e($movie['title'])?></h1>
    <p class="meta"><?=e((string)$movie['year'])?> · <?=e($movie['instance_name'])?></p>
  </div>
  <a class="button-link" href="/?type=movies">← Back to Movies</a>
</section>

<div class="notice info">Live check: ArrView reads Radarr movie state, queue, recent history, blocklist, and performs an indexer release search. It does not grab or modify releases.</div>

<?php if($error):?>
  <div class="notice error"><strong>Diagnostic failed:</strong> <?=e($error)?></div>
<?php elseif($result):?>

<?php $diag=$result['diagnosis'] ?? ['label'=>'Unknown','severity'=>'warning','detail'=>$result['summary'] ?? '']; ?>
<section class="diagnosis-hero diag-<?=e($diag['severity'] ?? 'warning')?>">
  <div>
    <p class="eyebrow">PRIMARY DIAGNOSIS</p>
    <h2><?=e($diag['label'] ?? 'Unknown')?></h2>
    <p><?=e($diag['detail'] ?? '')?></p>
  </div>
  <span class="diagnosis-state"><?=e(strtoupper($diag['severity'] ?? 'warning'))?></span>
</section>

<?php if(!empty($result['movie'])):?>
<section class="panel">
  <h2>Radarr movie state</h2>
  <div class="diag-facts">
    <div><span>Monitored</span><strong><?=$result['movie']['monitored']?'Yes':'No'?></strong></div>
    <div><span>Minimum availability</span><strong><?=e((string)($result['movie']['minimumAvailability'] ?? 'Unknown'))?></strong></div>
    <div><span>Quality profile ID</span><strong><?=e((string)($result['movie']['qualityProfileId'] ?? 'Unknown'))?></strong></div>
    <div><span>Acceptable releases now</span><strong><?=(int)($result['accepted_count'] ?? 0)?></strong></div>
  </div>
</section>
<?php endif;?>

<?php if(!empty($result['queue'])):?>
<section class="panel">
  <h2>Download / import queue</h2>
  <div class="release-list">
    <?php foreach($result['queue'] as $queue):?>
      <article class="release-row queue-row">
        <div>
          <strong><?=e($queue['title'])?></strong>
          <p class="meta">
            State: <?=e($queue['state'])?>
            <?php if(!empty($queue['download_client'])):?> · <?=e($queue['download_client'])?><?php endif;?>
            <?php if(!empty($queue['time_left'])):?> · <?=e((string)$queue['time_left'])?> left<?php endif;?>
          </p>
          <?php if(!empty($queue['output_path'])):?><p class="path-line"><?=e($queue['output_path'])?></p><?php endif;?>
        </div>
        <div class="release-status <?=in_array($queue['state'],['failed','failedPending','importBlocked'],true)?'bad':'good'?>"><?=e($queue['state'])?></div>
        <?php if(!empty($queue['messages'])):?><div class="release-reasons"><?php foreach($queue['messages'] as $message):?><span><?=e($message)?></span><?php endforeach;?></div><?php endif;?>
      </article>
    <?php endforeach;?>
  </div>
</section>
<?php endif;?>

<?php if(!empty($result['categories'])):?>
<section class="panel">
  <h2>Detected reasons</h2>
  <div class="reason-list">
    <?php foreach($result['categories'] as $category=>$messages):?>
      <div class="reason-card">
        <h3><?=e($category)?></h3>
        <?php foreach($messages as $message):?><p><?=e($message)?></p><?php endforeach;?>
      </div>
    <?php endforeach;?>
  </div>
</section>
<?php endif;?>

<?php if(!empty($result['history'])):?>
<section class="panel">
  <h2>Recent Radarr history</h2>
  <div class="history-list">
    <?php foreach(array_slice($result['history'],0,15) as $event):?>
      <article class="history-row">
        <div class="history-type"><?=e($event['event_type'] ?: 'event')?></div>
        <div>
          <strong><?=e($event['source_title'] ?: ucfirst($event['event_type'] ?: 'History event'))?></strong>
          <p class="meta">
            <?=e((string)($event['date'] ?? ''))?>
            <?php if(!empty($event['quality'])):?> · <?=e($event['quality'])?><?php endif;?>
            <?php if(!empty($event['languages'])):?> · <?=e($event['languages'])?><?php endif;?>
          </p>
          <?php if(!empty($event['message'])):?><p><?=e($event['message'])?></p><?php endif;?>
        </div>
      </article>
    <?php endforeach;?>
  </div>
</section>
<?php endif;?>

<section class="panel">
  <div class="panel-heading-inline">
    <h2>Release search results</h2>
    <span class="muted"><?=count($result['releases'] ?? [])?> found</span>
  </div>
  <?php if(empty($result['releases'])):?>
    <div class="empty compact"><p>No releases were returned by enabled Radarr indexers.</p></div>
  <?php else:?>
    <div class="release-list">
      <?php foreach(array_slice($result['releases'],0,50) as $release):?>
        <article class="release-row">
          <div>
            <strong><?=e($release['title'])?></strong>
            <p class="meta">
              <?=e($release['indexer'])?>
              <?php if($release['quality']):?> · <?=e($release['quality'])?><?php endif;?>
              <?php if($release['languages']):?> · <?=e($release['languages'])?><?php endif;?>
              <?php if($release['size']):?> · <?=number_format($release['size']/1073741824,2)?> GB<?php endif;?>
            </p>
          </div>
          <div class="release-status <?=$release['rejected']?'bad':'good'?>"><?=$release['rejected']?'Rejected':'Accepted'?></div>
          <?php if(!empty($release['rejections'])):?><div class="release-reasons"><?php foreach($release['rejections'] as $reason):?><span><?=e($reason)?></span><?php endforeach;?></div><?php endif;?>
        </article>
      <?php endforeach;?>
    </div>
  <?php endif;?>
</section>

<?php endif;?>
</main>
<footer>ArrView · Radarr missing movie diagnostics</footer>
</body>
</html>
