<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    exit('Invalid movie.');
}

$stmt = $pdo->prepare('SELECT m.*, i.id instance_db_id, i.name instance_name, i.type instance_type, i.url instance_url, i.api_key
    FROM movies m JOIN instances i ON i.id=m.instance_id WHERE m.id=? LIMIT 1');
$stmt->execute([$id]);
$cached = $stmt->fetch();

if (!$cached) {
    http_response_code(404);
    exit('Movie not found.');
}

$instance = [
    'id' => (int)$cached['instance_db_id'],
    'name' => $cached['instance_name'],
    'type' => $cached['instance_type'],
    'url' => $cached['instance_url'],
    'api_key' => $cached['api_key'],
];

$rawMovie = [];
if (!empty($cached['details_json'])) {
    $decoded = json_decode((string)$cached['details_json'], true);
    if (is_array($decoded)) $rawMovie = $decoded;
}

function movieBytes(?int $bytes): string {
    if (!$bytes) return '—';
    $units = ['B','KB','MB','GB','TB'];
    $size = (float)$bytes; $i = 0;
    while ($size >= 1024 && $i < count($units)-1) { $size /= 1024; $i++; }
    return number_format($size, $i >= 3 ? 2 : 1) . ' ' . $units[$i];
}

function dateLabel(?string $date): string {
    if (!$date) return '—';
    try {
        return (new DateTime($date))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $date;
    }
}

$tmdbId = (int)($cached['tmdb_id'] ?? $rawMovie['tmdbId'] ?? 0);
$tmdb = $tmdbId > 0 ? $metadata->cached('movie', $tmdbId) : null;
$rawFile = is_array($rawMovie['movieFile'] ?? null) ? $rawMovie['movieFile'] : null;
$mediaInfo = is_array($rawFile['mediaInfo'] ?? null) ? $rawFile['mediaInfo'] : [];
$movie = [
    'title'=>$rawMovie['title'] ?? $cached['title'],
    'original_title'=>$rawMovie['originalTitle'] ?? null,
    'title_slug'=>$rawMovie['titleSlug'] ?? null,
    'year'=>$rawMovie['year'] ?? $cached['year'],
    'poster_url'=>$cached['poster_url'],
    'monitored'=>(bool)($rawMovie['monitored'] ?? $cached['monitored']),
    'has_file'=>(bool)($rawMovie['hasFile'] ?? $cached['has_file']),
    'path'=>$rawMovie['path'] ?? $cached['path'],
    'status'=>$rawMovie['status'] ?? null,
    'overview'=>$rawMovie['overview'] ?? ($tmdb['overview'] ?? null),
    'runtime'=>$rawMovie['runtime'] ?? ($tmdb['runtime'] ?? null),
    'studio'=>$rawMovie['studio'] ?? (!empty($tmdb['companies']) ? implode(', ', array_values(array_filter(array_map(fn($x)=>is_array($x)?($x['name']??null):null, $tmdb['companies'])))) : null),
];
$file = $rawFile ? [
    'path'=>$rawFile['path'] ?? (($movie['path'] ?? '') && !empty($rawFile['relativePath']) ? rtrim((string)$movie['path'],'/\\') . '/' . ltrim((string)$rawFile['relativePath'],'/\\') : null),
    'relative_path'=>$rawFile['relativePath'] ?? null,
    'size'=>$rawFile['size'] ?? $cached['file_size'],
    'quality'=>$rawFile['quality']['quality']['name'] ?? $cached['quality'],
    'languages'=>$cached['audio_languages'],
    'date_added'=>$rawFile['dateAdded'] ?? null,
    'release_group'=>$rawFile['releaseGroup'] ?? null,
    'scene_name'=>$rawFile['sceneName'] ?? null,
    'video_codec'=>$mediaInfo['videoCodec'] ?? null,
    'video_resolution'=>$mediaInfo['resolution'] ?? $mediaInfo['videoResolution'] ?? null,
    'audio_codec'=>$mediaInfo['audioCodec'] ?? null,
    'audio_channels'=>$mediaInfo['audioChannels'] ?? null,
] : null;
$timeline = [
    'added_to_radarr'=>$rawMovie['added'] ?? null,
    'grabbed_at'=>null,
    'imported_at'=>null,
    'file_added_at'=>$rawFile['dateAdded'] ?? null,
];
$history = [];

$publicMediaUrl = viewer_can_see_vod($currentUser)
    ? vod_url_for((int)$instance['id'], $file['path'] ?? null)
    : null;
$movieState = movie_availability_state($cached);
$availabilityLabel = !empty($cached['availability_date']) ? dateLabel((string)$cached['availability_date']) : '—';
$preferredLanguages = array_values(array_filter(array_map('trim', explode(',', (string)app_setting('preferred_audio_languages','')))));
$preferredWarning = app_setting('preferred_language_warning','1') === '1'
    && $preferredLanguages
    && !empty($movie['has_file'])
    && !audio_has_preferred_language($file['languages'] ?? $cached['audio_languages'] ?? null, $preferredLanguages);
$folderYearMismatch = false;
if (!empty($movie['path']) && !empty($movie['year']) && preg_match('/\((\d{4})\)\s*$/', (string)$movie['path'], $folderYearMatch)) {
    $folderYearMismatch = (int)$folderYearMatch[1] !== (int)$movie['year'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($movie['title'] ?? 'Movie')?> · ArrView</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a>
    <nav>
        <a class="active" href="/?type=movies">Movies</a>
        <a href="/?type=series">Series</a>
        <a href="/support.php">Support</a>
        <?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><a href="/system.php">System</a><?php endif;?>
        <span class="user-chip"><?=e($currentUser['username'])?></span>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="wrap movie-detail-wrap">
    <div class="detail-back"><a href="/?type=movies">← Back to Movies</a></div>

    <div class="notice info">Showing locally cached ArrView data. Radarr is contacted only during sync, webhook updates, or diagnostics.</div>

    <section class="movie-hero">
        <div class="movie-detail-poster">
            <?php if(!empty($movie['poster_url'])):?><img src="<?=e($movie['poster_url'])?>" alt="<?=e($movie['title'])?>"><?php else:?><div class="poster-fallback">No Poster</div><?php endif;?>
        </div>
        <div class="movie-hero-copy">
            <p class="eyebrow">RADARR MOVIE</p>
            <h1><?=e($movie['title'])?><?php if(!empty($movie['year'])):?> <span>(<?=e((string)$movie['year'])?>)</span><?php endif;?></h1>
            <?php if(!empty($movie['original_title']) && $movie['original_title'] !== $movie['title']):?><p class="meta"><?=e($movie['original_title'])?></p><?php endif;?>
            <div class="detail-badges">
                <span class="table-status <?=e($movieState['class'])?>"><?=e($movieState['label'])?></span>
                <span class="detail-chip"><?=!empty($movie['monitored'])?'Monitored':'Not monitored'?></span>
                <?php if(!empty($movie['status'])):?><span class="detail-chip"><?=e(ucfirst((string)$movie['status']))?></span><?php endif;?>
            </div>
            <?php if(!empty($movie['overview'])):?><p class="movie-overview"><?=e($movie['overview'])?></p><?php endif;?>
            <div class="detail-actions">
                <?php if(!empty($movieState['diagnosable'])):?><a class="table-action important" href="/diagnose.php?id=<?=$id?>">Why missing?</a><?php endif;?>
                <?php if(!empty($movie['title_slug'])):?><a class="table-action" href="<?=e(rtrim($instance['url'],'/').'/movie/'.$movie['title_slug'])?>" target="_blank" rel="noopener noreferrer">Open in Radarr ↗</a><?php else:?><a class="table-action" href="<?=e(rtrim($instance['url'],'/'))?>" target="_blank" rel="noopener noreferrer">Open Radarr ↗</a><?php endif;?>
                <?php if($tmdbId>0):?><a class="table-action tmdb-link" href="https://www.themoviedb.org/movie/<?=$tmdbId?>" target="_blank" rel="noopener noreferrer">TMDB #<?=$tmdbId?> ↗</a><?php endif;?>
                <?php if(!empty($tmdb['imdb_id'])):?><a class="table-action" href="https://www.imdb.com/title/<?=e($tmdb['imdb_id'])?>/" target="_blank" rel="noopener noreferrer">IMDb ↗</a><?php endif;?>
            </div>
        </div>
    </section>

    <?php if($preferredWarning):?>
    <div class="notice warning availability-notice"><strong>Preferred audio language not found.</strong> Preferred: <?=e(implode(', ', $preferredLanguages))?> · Cached audio: <?=e((string)($file['languages'] ?? $cached['audio_languages'] ?? 'Unknown'))?></div>
    <?php endif;?>
    <?php if(($movieState['key'] ?? '')==='upcoming'):?>
    <div class="notice info availability-notice"><strong>Upcoming — not released yet.</strong> Radarr is monitoring this title for its future availability. It is not counted as a missing movie.</div>
    <?php elseif(($movieState['key'] ?? '')==='unknown'):?>
    <div class="notice info availability-notice"><strong>Availability unknown.</strong> Radarr does not currently provide a usable availability date for this movie, so ArrView does not count it as missing.</div>
    <?php elseif(($movieState['key'] ?? '')==='unmonitored'):?>
    <div class="notice info availability-notice"><strong>Not monitored.</strong> ArrView does not count this movie as an active missing item.</div>
    <?php endif;?>

    <section class="detail-grid">
        <div class="detail-card"><span>Audio Language</span><strong><?=e($file['languages'] ?? $cached['audio_languages'] ?? 'Unknown')?></strong></div>
        <div class="detail-card"><span>Quality</span><strong><?=e($file['quality'] ?? $cached['quality'] ?? '—')?></strong></div>
        <div class="detail-card"><span>File Size</span><strong><?=e(movieBytes(isset($file['size'])?(int)$file['size']:(isset($cached['file_size'])?(int)$cached['file_size']:null)))?></strong></div>
        <div class="detail-card"><span>Runtime</span><strong><?=!empty($movie['runtime'])?e((string)$movie['runtime']).' min':'—'?></strong></div>
        <div class="detail-card"><span>Studio</span><strong><?=e($movie['studio'] ?? '—')?></strong></div>
        <div class="detail-card"><span>Instance</span><strong><?=e($instance['name'])?></strong></div>
        <div class="detail-card"><span>Minimum Availability</span><strong><?=e($cached['minimum_availability'] ?: 'Unknown')?></strong></div>
        <div class="detail-card"><span>Expected Availability</span><strong><?=e($availabilityLabel)?></strong></div>
    </section>

    <?php if($publicMediaUrl):?>
    <section class="panel public-media-panel">
        <div class="panel-heading-inline"><h2>Public / VOD Link</h2><span class="type-pill radarr">STREAM</span></div>
        <div class="public-link-box">
            <a class="public-link-url" href="<?=e($publicMediaUrl)?>" target="_blank" rel="noopener noreferrer"><?=e($publicMediaUrl)?></a>
            <div class="detail-actions">
                <a class="support-primary vod-open" href="<?=e($publicMediaUrl)?>" target="_blank" rel="noopener noreferrer">▶ Open VOD</a>
                <button type="button" class="table-action copy-vod-btn" data-url="<?=e($publicMediaUrl)?>">Copy Link</button>
            </div>
        </div>
    </section>
    <?php elseif(!empty($file['path']) && $currentUser['role']==='admin'):?>
    <div class="notice info">No VOD mapping matched this file path. Add or adjust mappings in <a href="/system.php">System → VOD Path Mappings</a>.</div>
    <?php endif;?>

    <?php if($tmdbId>0):?>
    <section class="panel tmdb-panel">
        <div class="panel-heading-inline"><div><p class="eyebrow">TMDB METADATA</p><h2>Movie information</h2></div><span class="type-pill radarr">TMDB #<?=$tmdbId?></span></div>
        <?php if($tmdb):?>
        <dl class="detail-list metadata-detail-list">
            <div><dt>Original title</dt><dd><?=e($tmdb['original_title'] ?: '—')?></dd></div>
            <div><dt>Release date</dt><dd><?=e($tmdb['release_date'] ?: '—')?></dd></div>
            <div><dt>Genres</dt><dd><?=e(!empty($tmdb['genres']) ? implode(', ', array_values(array_filter(array_map(fn($x)=>is_array($x)?($x['name']??null):null,$tmdb['genres'])))) : '—')?></dd></div>
            <div><dt>Production</dt><dd><?=e(!empty($tmdb['companies']) ? implode(', ', array_values(array_filter(array_map(fn($x)=>is_array($x)?($x['name']??null):null,$tmdb['companies'])))) : '—')?></dd></div>
            <div><dt>Original language</dt><dd><?=e(strtoupper((string)($tmdb['original_language'] ?: '—')))?></dd></div>
            <div><dt>TMDB rating</dt><dd><?=!empty($tmdb['vote_count'])?e(number_format((float)$tmdb['vote_average'],1)).' / 10 · '.number_format((int)$tmdb['vote_count']).' votes':'—'?></dd></div>
            <div><dt>Metadata source</dt><dd><?=e($tmdb['source'] ?? 'TMDB')?> · cached <?=e(dateLabel($tmdb['fetched_at'] ?? null))?></dd></div>
        </dl>
        <?php else:?>
        <div class="metadata-empty"><strong>TMDB ID found, metadata not cached yet.</strong><p>Run <b>Admin → TMDB Metadata → Enrich Metadata</b>. ArrView will save it locally and future page views will use SQLite only.</p></div>
        <?php endif;?>
    </section>
    <?php endif;?>

    <section class="panel availability-panel">
        <div class="panel-heading-inline"><h2>Release availability</h2><span class="table-status <?=e($movieState['class'])?>"><?=e($movieState['label'])?></span></div>
        <dl class="detail-list">
            <div><dt>Minimum availability</dt><dd><?=e($cached['minimum_availability'] ?: 'Unknown')?></dd></div>
            <div><dt>In cinemas</dt><dd><?=e(dateLabel($cached['in_cinemas'] ?? null))?></dd></div>
            <div><dt>Digital release</dt><dd><?=e(dateLabel($cached['digital_release'] ?? null))?></dd></div>
            <div><dt>Physical release</dt><dd><?=e(dateLabel($cached['physical_release'] ?? null))?></dd></div>
            <div><dt>ArrView availability date</dt><dd><?=e($availabilityLabel)?></dd></div>
        </dl>
        <?php if($folderYearMismatch):?><div class="notice info compact-notice">The movie folder year differs from Radarr's current movie year. This is metadata information only and does not make the title missing.</div><?php endif;?>
    </section>

    <section class="panel">
        <h2>File information</h2>
        <dl class="detail-list">
            <div><dt>Movie folder</dt><dd class="path-line"><?=e($movie['path'] ?? $cached['path'] ?? '—')?></dd></div>
            <div><dt>File location</dt><dd class="path-line"><?=e($file['path'] ?? '—')?></dd></div>
            <div><dt>Relative path</dt><dd class="path-line"><?=e($file['relative_path'] ?? '—')?></dd></div>
            <div><dt>Video</dt><dd><?=e(trim(($file['video_codec'] ?? '') . ' ' . ($file['video_resolution'] ?? '')) ?: '—')?></dd></div>
            <div><dt>Audio codec</dt><dd><?=e($file['audio_codec'] ?? '—')?><?php if(!empty($file['audio_channels'])):?> · <?=e((string)$file['audio_channels'])?> channels<?php endif;?></dd></div>
            <div><dt>Release group</dt><dd><?=e($file['release_group'] ?? '—')?></dd></div>
            <div><dt>Scene name</dt><dd><?=e($file['scene_name'] ?? '—')?></dd></div>
        </dl>
    </section>

    <section class="panel">
        <h2>Timeline</h2>
        <div class="timeline-grid">
            <div><span>Added to Radarr</span><strong><?=e(dateLabel($timeline['added_to_radarr'] ?? null))?></strong></div>
            <div><span>Release grabbed</span><strong><?=e(dateLabel($timeline['grabbed_at'] ?? null))?></strong></div>
            <div><span>Imported</span><strong><?=e(dateLabel($timeline['imported_at'] ?? null))?></strong></div>
            <div><span>Movie file added</span><strong><?=e(dateLabel($timeline['file_added_at'] ?? null))?></strong></div>
        </div>
    </section>

    <?php if($history):?>
    <section class="panel">
        <div class="panel-heading-inline"><h2>Recent Radarr history</h2><span class="muted"><?=count($history)?> events</span></div>
        <div class="history-list">
            <?php foreach(array_slice($history,0,20) as $event):?>
            <article class="history-row">
                <div class="history-type"><?=e($event['event_type'] ?: 'event')?></div>
                <div>
                    <strong><?=e($event['source_title'] ?: ucfirst((string)$event['event_type']))?></strong>
                    <p class="meta"><?=e(dateLabel($event['date'] ?? null))?><?php if(!empty($event['quality'])):?> · <?=e($event['quality'])?><?php endif;?><?php if(!empty($event['languages'])):?> · <?=e($event['languages'])?><?php endif;?></p>
                    <?php if(!empty($event['download_client'])):?><p class="meta">Download client: <?=e($event['download_client'])?></p><?php endif;?>
                    <?php if(!empty($event['message'])):?><p><?=e((string)$event['message'])?></p><?php endif;?>
                </div>
            </article>
            <?php endforeach;?>
        </div>
    </section>
    <?php endif;?>
</main>

<footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Movie details</footer>
<script>
document.querySelectorAll('.copy-vod-btn').forEach(btn=>btn.addEventListener('click',async()=>{
  const original=btn.textContent;
  try{await navigator.clipboard.writeText(btn.dataset.url);btn.textContent='Copied!';}
  catch(e){btn.textContent='Copy failed';}
  setTimeout(()=>btn.textContent=original,1600);
}));
</script>
</body>
</html>
