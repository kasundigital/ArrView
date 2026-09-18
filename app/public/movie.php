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

$error = null;
$details = null;
try {
    $details = $arr->movieDetails($instance, (int)$cached['remote_id']);
} catch (Throwable $e) {
    $error = $e->getMessage();
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

$movie = $details['movie'] ?? [
    'title'=>$cached['title'], 'year'=>$cached['year'], 'poster_url'=>$cached['poster_url'],
    'monitored'=>(bool)$cached['monitored'], 'has_file'=>(bool)$cached['has_file'], 'path'=>$cached['path']
];
$file = $details['file'] ?? null;
$timeline = $details['timeline'] ?? [];
$history = $details['history'] ?? [];

$settings = [];
foreach ($pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('public_local_root','public_base_url')")->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$publicLocalRoot = rtrim((string)($settings['public_local_root'] ?? ''), "/\\");
$publicBaseUrl = rtrim((string)($settings['public_base_url'] ?? ''), '/');

function buildPublicMediaUrl(?string $filePath, string $localRoot, string $baseUrl): ?string {
    if (!$filePath || $localRoot === '' || $baseUrl === '') return null;

    $normalizedPath = str_replace('\\', '/', $filePath);
    $normalizedRoot = rtrim(str_replace('\\', '/', $localRoot), '/');

    if ($normalizedRoot !== '' && str_starts_with($normalizedPath, $normalizedRoot)) {
        $relative = ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
    } else {
        return null;
    }

    if ($relative === '') return null;
    $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));
    return $baseUrl . '/' . $encoded;
}

$publicMediaUrl = buildPublicMediaUrl($file['path'] ?? null, $publicLocalRoot, $publicBaseUrl);
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
        <?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?>
        <span class="user-chip"><?=e($currentUser['username'])?></span>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="wrap movie-detail-wrap">
    <div class="detail-back"><a href="/?type=movies">← Back to Movies</a></div>

    <?php if($error):?><div class="notice error">Live Radarr details could not be loaded: <?=e($error)?>. Showing cached ArrView data where available.</div><?php endif;?>

    <section class="movie-hero">
        <div class="movie-detail-poster">
            <?php if(!empty($movie['poster_url'])):?><img src="<?=e($movie['poster_url'])?>" alt="<?=e($movie['title'])?>"><?php else:?><div class="poster-fallback">No Poster</div><?php endif;?>
        </div>
        <div class="movie-hero-copy">
            <p class="eyebrow">RADARR MOVIE</p>
            <h1><?=e($movie['title'])?><?php if(!empty($movie['year'])):?> <span>(<?=e((string)$movie['year'])?>)</span><?php endif;?></h1>
            <?php if(!empty($movie['original_title']) && $movie['original_title'] !== $movie['title']):?><p class="meta"><?=e($movie['original_title'])?></p><?php endif;?>
            <div class="detail-badges">
                <span class="table-status <?=!empty($movie['has_file'])?'status-ok':'status-missing'?>"><?=!empty($movie['has_file'])?'Available':'Missing'?></span>
                <span class="detail-chip"><?=!empty($movie['monitored'])?'Monitored':'Not monitored'?></span>
                <?php if(!empty($movie['status'])):?><span class="detail-chip"><?=e(ucfirst((string)$movie['status']))?></span><?php endif;?>
            </div>
            <?php if(!empty($movie['overview'])):?><p class="movie-overview"><?=e($movie['overview'])?></p><?php endif;?>
            <div class="detail-actions">
                <?php if(empty($movie['has_file'])):?><a class="table-action important" href="/diagnose.php?id=<?=$id?>">Why missing?</a><?php endif;?>
                <?php if(!empty($movie['title_slug'])):?><a class="table-action" href="<?=e(rtrim($instance['url'],'/').'/movie/'.$movie['title_slug'])?>" target="_blank" rel="noopener noreferrer">Open in Radarr ↗</a><?php else:?><a class="table-action" href="<?=e(rtrim($instance['url'],'/'))?>" target="_blank" rel="noopener noreferrer">Open Radarr ↗</a><?php endif;?>
            </div>
        </div>
    </section>

    <section class="detail-grid">
        <div class="detail-card"><span>Audio Language</span><strong><?=e($file['languages'] ?? $cached['audio_languages'] ?? 'Unknown')?></strong></div>
        <div class="detail-card"><span>Quality</span><strong><?=e($file['quality'] ?? $cached['quality'] ?? '—')?></strong></div>
        <div class="detail-card"><span>File Size</span><strong><?=e(movieBytes(isset($file['size'])?(int)$file['size']:(isset($cached['file_size'])?(int)$cached['file_size']:null)))?></strong></div>
        <div class="detail-card"><span>Runtime</span><strong><?=!empty($movie['runtime'])?e((string)$movie['runtime']).' min':'—'?></strong></div>
        <div class="detail-card"><span>Studio</span><strong><?=e($movie['studio'] ?? '—')?></strong></div>
        <div class="detail-card"><span>Instance</span><strong><?=e($instance['name'])?></strong></div>
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
    <?php elseif($publicBaseUrl && $publicLocalRoot && !empty($file['path'])):?>
    <div class="notice info">Public/VOD link is configured, but this file path does not start with the configured local media root.</div>
    <?php endif;?>

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
