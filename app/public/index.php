<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$type = $_GET['type'] ?? null;
$q = trim($_GET['q'] ?? '');

$movieCount = (int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn();
$seriesCount = (int)$pdo->query('SELECT COUNT(*) FROM series')->fetchColumn();
$instanceCount = (int)$pdo->query('SELECT COUNT(*) FROM instances WHERE enabled=1')->fetchColumn();

$items = [];
if ($type === 'movies') {
    $sql = 'SELECT m.*, i.name instance_name FROM movies m JOIN instances i ON i.id=m.instance_id WHERE 1=1';
    $params = [];
    if ($q !== '') { $sql .= ' AND m.title LIKE ?'; $params[] = '%' . $q . '%'; }
    $sql .= ' ORDER BY m.title COLLATE NOCASE LIMIT 500';
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
} elseif ($type === 'series') {
    $sql = 'SELECT s.*, i.name instance_name FROM series s JOIN instances i ON i.id=s.instance_id WHERE 1=1';
    $params = [];
    if ($q !== '') { $sql .= ' AND s.title LIKE ?'; $params[] = '%' . $q . '%'; }
    $sql .= ' ORDER BY s.title COLLATE NOCASE LIMIT 500';
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView</a><nav><a href="/?type=movies">Movies</a><a href="/?type=series">Series</a><?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap">
<?php if(!$type):?><section class="hero"><p class="eyebrow">RADARR + SONARR LIBRARY VIEW</p><h1>Your media. One clean view.</h1><p>Browse availability, quality, posters and language information across all configured instances.</p></section><section class="chooser"><a class="choice" href="/?type=movies"><div class="choice-icon">🎬</div><div><span>Radarr</span><h2>Movies</h2><p><?=$movieCount?> indexed movies</p></div></a><a class="choice" href="/?type=series"><div class="choice-icon">📺</div><div><span>Sonarr</span><h2>TV Series</h2><p><?=$seriesCount?> indexed series</p></div></a></section><p class="muted center"><?=$instanceCount?> enabled instance<?=$instanceCount===1?'':'s'?></p>
<?php else:?><section class="catalog-head"><div><p class="eyebrow"><?=$type==='movies'?'RADARR':'SONARR'?></p><h1><?=$type==='movies'?'Movies':'TV Series'?></h1></div><form class="search" method="get"><input type="hidden" name="type" value="<?=e($type)?>"><input id="searchInput" type="search" name="q" value="<?=e($q)?>" placeholder="Search <?=$type==='movies'?'movies':'series'?>..." autocomplete="off"></form></section>
<?php if(!$items):?><div class="empty"><h2>No media found</h2><p><?php if($currentUser['role']==='admin'):?>Add an instance in Admin and run a sync.<?php else:?>No indexed media is available yet.<?php endif;?></p></div><?php else:?><section class="grid" id="mediaGrid"><?php foreach($items as $item):?><article class="card" data-title="<?=e(strtolower($item['title']))?>"><div class="poster"><?php if(!empty($item['poster_url'])):?><img src="<?=e($item['poster_url'])?>" alt="<?=e($item['title'])?>" loading="lazy"><?php else:?><div class="poster-fallback">No Poster</div><?php endif;?><?php if($type==='movies'):?><span class="badge <?=$item['has_file']?'ok':'missing'?>"><?=$item['has_file']?'Available':'Missing'?></span><?php endif;?></div><div class="card-body"><h3><?=e($item['title'])?></h3><p class="meta"><?=e((string)$item['year'])?> · <?=e($item['instance_name'])?></p><?php if($type==='movies'):?><div class="chips"><?php if($item['quality']):?><span><?=e($item['quality'])?></span><?php endif;?><?php if($item['audio_languages']):?><span>🔊 <?=e($item['audio_languages'])?></span><?php endif;?></div><?php else:?><p class="meta"><?=(int)$item['episode_file_count']?> / <?=(int)$item['episode_count']?> episodes available</p><?php endif;?></div></article><?php endforeach;?></section><?php endif;?><?php endif;?></main><footer>ArrView · Open source self-hosted media catalog</footer>
<script>const input=document.getElementById('searchInput');if(input){input.addEventListener('input',()=>{const q=input.value.toLowerCase();document.querySelectorAll('.card').forEach(c=>c.style.display=c.dataset.title.includes(q)?'':'none')})}</script></body></html>
