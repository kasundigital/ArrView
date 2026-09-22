<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    exit('Invalid series.');
}

$stmt = $pdo->prepare('SELECT s.*, i.name instance_name, i.url instance_url
    FROM series s JOIN instances i ON i.id=s.instance_id WHERE s.id=? LIMIT 1');
$stmt->execute([$id]);
$series = $stmt->fetch();
if (!$series) {
    http_response_code(404);
    exit('Series not found.');
}

$rawSeries = [];
if (!empty($series['details_json'])) {
    $decodedSeries = json_decode((string)$series['details_json'], true);
    if (is_array($decodedSeries)) $rawSeries = $decodedSeries;
}
$tmdbId = (int)($series['tmdb_id'] ?? $rawSeries['tmdbId'] ?? 0);
$tmdb = $tmdbId > 0 ? $metadata->cached('series', $tmdbId) : null;

$episodeStmt = $pdo->prepare('SELECT * FROM episodes WHERE series_id=? ORDER BY season_number, episode_number');
$episodeStmt->execute([$id]);
$episodes = $episodeStmt->fetchAll();

$seasons = [];
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
foreach ($episodes as $episode) {
    $season = (int)$episode['season_number'];
    $seasons[$season][] = $episode;
}
ksort($seasons);

$settings = [];
foreach ($pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('public_local_root','public_base_url')")->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$publicLocalRoot = rtrim((string)($settings['public_local_root'] ?? ''), "/\\");
$publicBaseUrl = rtrim((string)($settings['public_base_url'] ?? ''), '/');

function seriesBytes(?int $bytes): string {
    if (!$bytes) return '—';
    $units=['B','KB','MB','GB','TB']; $size=(float)$bytes; $i=0;
    while($size>=1024 && $i<count($units)-1){$size/=1024;$i++;}
    return number_format($size,$i>=3?2:1).' '.$units[$i];
}
function seriesDate(?string $date): string {
    if(!$date)return '—';
    try{return (new DateTime($date))->format('Y-m-d H:i');}catch(Throwable){return $date;}
}
function episodeVod(?string $filePath,string $localRoot,string $baseUrl): ?string {
    if(!$filePath || $localRoot==='' || $baseUrl==='')return null;
    $path=str_replace('\\','/',$filePath);
    $root=rtrim(str_replace('\\','/',$localRoot),'/');
    if(!str_starts_with($path,$root))return null;
    $relative=ltrim(substr($path,strlen($root)),'/');
    if($relative==='')return null;
    return $baseUrl.'/'.implode('/',array_map('rawurlencode',explode('/',$relative)));
}
function hasAired(array $episode): bool {
    if(empty($episode['air_date_utc']))return false;
    try{return new DateTimeImmutable($episode['air_date_utc']) <= new DateTimeImmutable('now',new DateTimeZone('UTC'));}catch(Throwable){return false;}
}

$total=count($episodes);
$available=0;$airedMissing=0;$languageIssues=0;$languages=[];
foreach($episodes as $ep){
    if((int)$ep['has_file'])$available++;
    if(!(int)$ep['has_file'] && (int)$ep['monitored'] && hasAired($ep))$airedMissing++;
    if((int)$ep['has_file'] && trim((string)$ep['audio_languages'])==='')$languageIssues++;
    if(!empty($ep['audio_languages'])){
        foreach(array_map('trim',explode(',',$ep['audio_languages'])) as $lang)if($lang!=='')$languages[$lang]=true;
    }
}
$languageNames=array_keys($languages);natcasesort($languageNames);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($series['title'])?> · ArrView</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
<a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a>
<nav><a href="/?type=movies">Movies</a><a class="active" href="/?type=series">Series</a><a href="/support.php">Support</a><?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav>
</header>

<main class="wrap series-detail-wrap">
<div class="detail-back"><a href="/?type=series">← Back to Series</a></div>
<section class="series-hero">
  <div class="movie-detail-poster"><?php if($series['poster_url']):?><img src="<?=e($series['poster_url'])?>" alt="<?=e($series['title'])?>"><?php else:?><div class="poster-fallback">No Poster</div><?php endif;?></div>
  <div class="movie-hero-copy">
    <p class="eyebrow">SONARR SERIES · CACHED VIEW</p>
    <h1><?=e($series['title'])?> <?php if($series['year']):?><span>(<?=e((string)$series['year'])?>)</span><?php endif;?></h1>
    <div class="detail-badges">
      <span class="table-status <?=$airedMissing?'status-missing':'status-ok'?>"><?=$airedMissing?number_format($airedMissing).' aired missing':'No aired missing'?></span>
      <span class="detail-chip"><?=(int)$series['monitored']?'Monitored':'Not monitored'?></span>
      <span class="detail-chip"><?=e($series['instance_name'])?></span>
    </div>
    <?php if(!empty($tmdb['overview'])):?><p class="movie-overview"><?=e($tmdb['overview'])?></p><?php endif;?>
    <p class="meta"><?=number_format($available)?> / <?=number_format($total)?> episodes have files · Audio: <?=e($languageNames?implode(', ',$languageNames):'Unknown')?></p>
    <div class="detail-actions">
      <a class="table-action" href="<?=e(rtrim($series['instance_url'],'/'))?>" target="_blank" rel="noopener noreferrer">Open Sonarr ↗</a>
      <?php if($tmdbId>0):?><a class="table-action tmdb-link" href="https://www.themoviedb.org/tv/<?=$tmdbId?>" target="_blank" rel="noopener noreferrer">TMDB #<?=$tmdbId?> ↗</a><?php endif;?>
      <?php if(!empty($tmdb['imdb_id'])):?><a class="table-action" href="https://www.imdb.com/title/<?=e($tmdb['imdb_id'])?>/" target="_blank" rel="noopener noreferrer">IMDb ↗</a><?php endif;?>
    </div>
  </div>
</section>

<section class="detail-grid">
<div class="detail-card"><span>Seasons</span><strong><?=number_format(count($seasons))?></strong></div>
<div class="detail-card"><span>Episodes</span><strong><?=number_format($total)?></strong></div>
<div class="detail-card"><span>Available</span><strong><?=number_format($available)?></strong></div>
<div class="detail-card"><span>Aired Missing</span><strong><?=number_format($airedMissing)?></strong></div>
<div class="detail-card"><span>Missing Audio Info</span><strong><?=number_format($languageIssues)?></strong></div>
<div class="detail-card"><span>Audio Languages</span><strong><?=e($languageNames?implode(', ',$languageNames):'Unknown')?></strong></div>
</section>

<?php if($tmdbId>0):?>
<section class="panel tmdb-panel">
  <div class="panel-heading-inline"><div><p class="eyebrow">TMDB METADATA</p><h2>Series information</h2></div><span class="type-pill sonarr">TMDB #<?=$tmdbId?></span></div>
  <?php if($tmdb):?>
  <dl class="detail-list metadata-detail-list">
    <div><dt>Original title</dt><dd><?=e($tmdb['original_title'] ?: '—')?></dd></div>
    <div><dt>First air date</dt><dd><?=e($tmdb['release_date'] ?: '—')?></dd></div>
    <div><dt>Status</dt><dd><?=e($tmdb['status'] ?: '—')?></dd></div>
    <div><dt>Genres</dt><dd><?=e(!empty($tmdb['genres']) ? implode(', ', array_values(array_filter(array_map(fn($x)=>is_array($x)?($x['name']??null):null,$tmdb['genres'])))) : '—')?></dd></div>
    <div><dt>Network / Production</dt><dd><?=e(!empty($tmdb['companies']) ? implode(', ', array_values(array_filter(array_map(fn($x)=>is_array($x)?($x['name']??null):null,$tmdb['companies'])))) : '—')?></dd></div>
    <div><dt>Original language</dt><dd><?=e(strtoupper((string)($tmdb['original_language'] ?: '—')))?></dd></div>
    <div><dt>TMDB rating</dt><dd><?=!empty($tmdb['vote_count'])?e(number_format((float)$tmdb['vote_average'],1)).' / 10 · '.number_format((int)$tmdb['vote_count']).' votes':'—'?></dd></div>
    <div><dt>Metadata source</dt><dd><?=e($tmdb['source'] ?? 'TMDB')?> · cached <?=e(seriesDate($tmdb['fetched_at'] ?? null))?></dd></div>
  </dl>
  <?php else:?>
  <div class="metadata-empty"><strong>TMDB ID found, metadata not cached yet.</strong><p>Run <b>Admin → TMDB Metadata → Enrich Metadata</b>. ArrView will save it locally and normal browsing will stay database-only.</p></div>
  <?php endif;?>
</section>
<?php endif;?>

<?php if(!$episodes):?>
<div class="empty"><h2>No cached episodes</h2><p>Run a fresh Sonarr sync from Admin to populate seasons and episodes.</p></div>
<?php else:?>
<section class="season-list">
<?php foreach($seasons as $seasonNumber=>$seasonEpisodes):
    $seasonFiles=0;$seasonAiredMissing=0;
    foreach($seasonEpisodes as $ep){if((int)$ep['has_file'])$seasonFiles++;if(!(int)$ep['has_file']&&(int)$ep['monitored']&&hasAired($ep))$seasonAiredMissing++;}
?>
<details class="season-block" <?=$seasonAiredMissing?'open':''?>>
<summary>
  <div><strong><?=$seasonNumber===0?'Specials':'Season '.$seasonNumber?></strong><span><?=count($seasonEpisodes)?> episodes</span></div>
  <div class="season-summary"><span><?=$seasonFiles?> / <?=count($seasonEpisodes)?> files</span><?php if($seasonAiredMissing):?><b><?=$seasonAiredMissing?> aired missing</b><?php endif;?></div>
</summary>
<div class="episode-table-wrap">
<table class="episode-table">
<thead><tr><th>Episode</th><th>Title</th><th>Air Date</th><th>Status</th><th>Audio</th><th>Quality</th><th>Size</th><th>File Added</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach($seasonEpisodes as $ep):
  $missing=!(int)$ep['has_file'];$aired=hasAired($ep);$vod=episodeVod($ep['file_path'],$publicLocalRoot,$publicBaseUrl);
?>
<tr class="<?=$missing&&$aired?'row-missing':''?>">
<td class="episode-code">S<?=str_pad((string)$ep['season_number'],2,'0',STR_PAD_LEFT)?>E<?=str_pad((string)$ep['episode_number'],2,'0',STR_PAD_LEFT)?></td>
<td><strong><?=e($ep['title'])?></strong><?php if(!(int)$ep['monitored']):?><span class="row-note">Not monitored</span><?php endif;?></td>
<td><?=e(seriesDate($ep['air_date_utc']))?></td>
<td><span class="table-status <?=$missing?'status-missing':'status-ok'?>"><?=$missing?($aired?'Missing':'Unaired / Missing'):'Available'?></span></td>
<td class="audio-cell"><?=e($ep['audio_languages'] ?: ($missing?'No file':'Unknown'))?></td>
<td><?=e($ep['quality'] ?: '—')?></td>
<td><?=e(seriesBytes($ep['file_size']?(int)$ep['file_size']:null))?></td>
<td><?=e(seriesDate($ep['date_added']))?></td>
<td class="action-cell">
<?php if($vod):?><a class="table-action" href="<?=e($vod)?>" target="_blank" rel="noopener noreferrer">▶ VOD</a><button type="button" class="table-action copy-vod-btn" data-url="<?=e($vod)?>">Copy</button><?php endif;?>
<?php if($missing&&$aired):?><a class="table-action important" href="/episode-diagnose.php?id=<?=(int)$ep['id']?>">Why missing?</a><?php endif;?>
<details class="episode-more"><summary>More</summary><div class="episode-more-grid"><span>File path</span><code><?=e($ep['file_path'] ?: '—')?></code><span>Release group</span><b><?=e($ep['release_group'] ?: '—')?></b><span>Video</span><b><?=e(trim(($ep['video_codec']??'').' '.($ep['video_resolution']??'')) ?: '—')?></b><span>Audio codec</span><b><?=e($ep['audio_codec'] ?: '—')?><?php if($ep['audio_channels']):?> · <?=e((string)$ep['audio_channels'])?> ch<?php endif;?></b></div></details>
</td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</details>
<?php endforeach;?>
</section>
<?php endif;?>
</main>
<footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Cached Sonarr season & episode view</footer>
<script>
document.querySelectorAll('.copy-vod-btn').forEach(btn=>btn.addEventListener('click',async()=>{const t=btn.textContent;try{await navigator.clipboard.writeText(btn.dataset.url);btn.textContent='Copied!';}catch(e){btn.textContent='Failed';}setTimeout(()=>btn.textContent=t,1400);}));
</script>
</body></html>
