<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$type = $_GET['type'] ?? null;
$q = trim($_GET['q'] ?? '');
$view = $_GET['view'] ?? 'list';
if (!in_array($view, ['list','summary'], true)) $view = 'list';

$movieCount = (int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn();
$seriesCount = (int)$pdo->query('SELECT COUNT(*) FROM series')->fetchColumn();
$instanceCount = (int)$pdo->query('SELECT COUNT(*) FROM instances WHERE enabled=1')->fetchColumn();

$items = [];
$summary = [];
$qualityBreakdown = [];
$languageBreakdown = [];

if ($type === 'movies') {
    if ($view === 'summary') {
        $summary = $pdo->query("SELECT COUNT(*) total, SUM(CASE WHEN m.has_file=1 THEN 1 ELSE 0 END) available, SUM(CASE WHEN m.has_file=0 THEN 1 ELSE 0 END) missing, SUM(CASE WHEN m.monitored=1 THEN 1 ELSE 0 END) monitored, COALESCE(SUM(m.file_size),0) total_size FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1")->fetch() ?: [];
        $qualityBreakdown = $pdo->query("SELECT COALESCE(NULLIF(TRIM(m.quality),''),'Unknown') label, COUNT(*) count FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1 GROUP BY label ORDER BY count DESC, label COLLATE NOCASE LIMIT 10")->fetchAll();
        $languageBreakdown = $pdo->query("SELECT COALESCE(NULLIF(TRIM(m.audio_languages),''),'Unknown') label, COUNT(*) count FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1 GROUP BY label ORDER BY count DESC, label COLLATE NOCASE LIMIT 10")->fetchAll();
    } else {
        $sql = 'SELECT m.*, i.name instance_name FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1';
        $params = [];
        if ($q !== '') { $sql .= ' AND m.title LIKE ?'; $params[] = '%' . $q . '%'; }
        $sql .= ' ORDER BY m.title COLLATE NOCASE LIMIT 500';
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
    }
} elseif ($type === 'series') {
    if ($view === 'summary') {
        $summary = $pdo->query("SELECT COUNT(*) total, SUM(CASE WHEN s.monitored=1 THEN 1 ELSE 0 END) monitored, COALESCE(SUM(s.episode_count),0) episodes, COALESCE(SUM(s.episode_file_count),0) files FROM series s JOIN instances i ON i.id=s.instance_id WHERE i.enabled=1")->fetch() ?: [];
    } else {
        $sql = 'SELECT s.*, i.name instance_name FROM series s JOIN instances i ON i.id=s.instance_id WHERE i.enabled=1';
        $params = [];
        if ($q !== '') { $sql .= ' AND s.title LIKE ?'; $params[] = '%' . $q . '%'; }
        $sql .= ' ORDER BY s.title COLLATE NOCASE LIMIT 500';
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
    }
}

function bytesLabel(int|float $bytes): string {
    if ($bytes <= 0) return '0 GB';
    $units = ['B','KB','MB','GB','TB','PB'];
    $i = 0; $value = (float)$bytes;
    while ($value >= 1024 && $i < count($units)-1) { $value /= 1024; $i++; }
    return number_format($value, $i >= 3 ? 2 : 1) . ' ' . $units[$i];
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ArrView <?=e(ARRVIEW_VERSION)?></title><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/summary.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a><nav><a href="/?type=movies">Movies</a><a href="/?type=series">Series</a><?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap">
<?php if(!$type):?>
<section class="hero"><p class="eyebrow">RADARR + SONARR LIBRARY VIEW</p><h1>Your media. One clean view.</h1><p>Browse availability, quality, posters and language information across all configured instances.</p></section>
<section class="chooser"><a class="choice" href="/?type=movies"><div class="choice-icon">🎬</div><div><span>Radarr</span><h2>Movies</h2><p><?=$movieCount?> indexed movies</p></div></a><a class="choice" href="/?type=series"><div class="choice-icon">📺</div><div><span>Sonarr</span><h2>TV Series</h2><p><?=$seriesCount?> indexed series</p></div></a></section><p class="muted center"><?=$instanceCount?> enabled instance<?=$instanceCount===1?'':'s'?></p>
<?php else:?>
<section class="catalog-head">
  <div><p class="eyebrow"><?=$type==='movies'?'RADARR':'SONARR'?></p><h1><?=$type==='movies'?'Movies':'TV Series'?></h1></div>
  <?php if($view==='list'):?><form class="search" method="get"><input type="hidden" name="type" value="<?=e($type)?>"><input type="hidden" name="view" value="list"><input id="searchInput" type="search" name="q" value="<?=e($q)?>" placeholder="Search <?=$type==='movies'?'movies':'series'?>..." autocomplete="off"></form><?php endif;?>
</section>
<div class="view-switch" role="navigation" aria-label="Library view"><a class="<?=$view==='list'?'active':''?>" href="/?type=<?=e($type)?>&view=list">☷ List View</a><a class="<?=$view==='summary'?'active':''?>" href="/?type=<?=e($type)?>&view=summary">▦ Summary</a></div>

<?php if($view==='summary'):?>
  <?php if($type==='movies'):?>
    <?php $total=(int)($summary['total']??0); $available=(int)($summary['available']??0); $missing=(int)($summary['missing']??0); $monitored=(int)($summary['monitored']??0); $pct=$total?round(($available/$total)*100,1):0; ?>
    <section class="summary-grid">
      <div class="summary-card"><span>Total Movies</span><strong><?=number_format($total)?></strong></div>
      <div class="summary-card"><span>Available</span><strong><?=number_format($available)?></strong><small><?=$pct?>%</small></div>
      <div class="summary-card"><span>Missing</span><strong><?=number_format($missing)?></strong><small><?=$total?round(($missing/$total)*100,1):0?>%</small></div>
      <div class="summary-card"><span>Monitored</span><strong><?=number_format($monitored)?></strong></div>
      <div class="summary-card"><span>Library Size</span><strong><?=e(bytesLabel((int)($summary['total_size']??0)))?></strong></div>
    </section>
    <section class="panel summary-panel"><h2>Availability</h2><div class="summary-progress"><div style="width:<?=$pct?>%"></div></div><p class="meta"><?=number_format($available)?> of <?=number_format($total)?> movies currently have files.</p></section>
    <section class="summary-columns">
      <div class="panel"><h2>Top Qualities</h2><?php if(!$qualityBreakdown):?><p class="muted">No quality data yet.</p><?php else:?><div class="stat-list"><?php foreach($qualityBreakdown as $row):?><div><span><?=e($row['label'])?></span><strong><?=number_format((int)$row['count'])?></strong></div><?php endforeach;?></div><?php endif;?></div>
      <div class="panel"><h2>Audio Languages</h2><?php if(!$languageBreakdown):?><p class="muted">No language data yet.</p><?php else:?><div class="stat-list"><?php foreach($languageBreakdown as $row):?><div><span><?=e($row['label'])?></span><strong><?=number_format((int)$row['count'])?></strong></div><?php endforeach;?></div><?php endif;?></div>
    </section>
  <?php else:?>
    <?php $total=(int)($summary['total']??0); $episodes=(int)($summary['episodes']??0); $files=(int)($summary['files']??0); $monitored=(int)($summary['monitored']??0); $pct=$episodes?round(($files/$episodes)*100,1):0; ?>
    <section class="summary-grid">
      <div class="summary-card"><span>Total Series</span><strong><?=number_format($total)?></strong></div>
      <div class="summary-card"><span>Monitored</span><strong><?=number_format($monitored)?></strong></div>
      <div class="summary-card"><span>Total Episodes</span><strong><?=number_format($episodes)?></strong></div>
      <div class="summary-card"><span>Available Episodes</span><strong><?=number_format($files)?></strong><small><?=$pct?>%</small></div>
      <div class="summary-card"><span>Missing Episodes</span><strong><?=number_format(max(0,$episodes-$files))?></strong></div>
    </section>
    <section class="panel summary-panel"><h2>Episode Availability</h2><div class="summary-progress"><div style="width:<?=$pct?>%"></div></div><p class="meta"><?=number_format($files)?> of <?=number_format($episodes)?> episodes currently have files.</p></section>
  <?php endif;?>
<?php else:?>
  <?php if(!$items):?><div class="empty"><h2>No media found</h2><p><?php if($currentUser['role']==='admin'):?>Add an instance in Admin and run a sync.<?php else:?>No indexed media is available yet.<?php endif;?></p></div><?php else:?><section class="grid" id="mediaGrid"><?php foreach($items as $item):?><article class="card" data-title="<?=e(strtolower($item['title']))?>"><div class="poster"><?php if(!empty($item['poster_url'])):?><img src="<?=e($item['poster_url'])?>" alt="<?=e($item['title'])?>" loading="lazy"><?php else:?><div class="poster-fallback">No Poster</div><?php endif;?><?php if($type==='movies'):?><span class="badge <?=$item['has_file']?'ok':'missing'?>"><?=$item['has_file']?'Available':'Missing'?></span><?php endif;?></div><div class="card-body"><h3><?=e($item['title'])?></h3><p class="meta"><?=e((string)$item['year'])?> · <?=e($item['instance_name'])?></p><?php if($type==='movies'):?><div class="chips"><?php if($item['quality']):?><span><?=e($item['quality'])?></span><?php endif;?><?php if($item['audio_languages']):?><span>🔊 <?=e($item['audio_languages'])?></span><?php endif;?></div><?php if(!$item['has_file']):?><a class="why-link" href="/diagnose.php?id=<?=(int)$item['id']?>">Why missing?</a><?php endif;?><?php else:?><p class="meta"><?=(int)$item['episode_file_count']?> / <?=(int)$item['episode_count']?> episodes available</p><?php endif;?></div></article><?php endforeach;?></section><?php endif;?>
<?php endif;?>
<?php endif;?></main>
<footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Open source self-hosted media catalog</footer>
<script>const input=document.getElementById('searchInput');if(input){input.addEventListener('input',()=>{const q=input.value.toLowerCase();document.querySelectorAll('.card').forEach(c=>c.style.display=c.dataset.title.includes(q)?'':'none')})}</script></body></html>
