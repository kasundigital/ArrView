<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$type = $_GET['type'] ?? null;
$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$instanceId = (int)($_GET['instance'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$sort = strtolower((string)($_GET['sort'] ?? 'asc'));
if (!in_array($sort, ['asc','desc'], true)) $sort = 'asc';

$validFilters = $type === 'series'
    ? ['all','airedmissing','missing','complete','future','noaudio','unmonitored','monitored']
    : ['all','missing','available','noaudio','monitored'];
if (!in_array($filter, $validFilters, true)) $filter = 'all';

$movieCount = (int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn();
$seriesCount = (int)$pdo->query('SELECT COUNT(*) FROM series')->fetchColumn();
$instanceCount = (int)$pdo->query('SELECT COUNT(*) FROM instances WHERE enabled=1')->fetchColumn();

$instances = [];
$years = [];
if ($type === 'movies' || $type === 'series') {
    $instanceType = $type === 'movies' ? 'radarr' : 'sonarr';
    $stmt = $pdo->prepare('SELECT id,name FROM instances WHERE enabled=1 AND type=? ORDER BY name COLLATE NOCASE');
    $stmt->execute([$instanceType]);
    $instances = $stmt->fetchAll();

    $yearTable = $type === 'movies' ? 'movies' : 'series';
    $yearStmt = $pdo->prepare("SELECT DISTINCT y.year
        FROM {$yearTable} y
        JOIN instances i ON i.id=y.instance_id
        WHERE i.enabled=1 AND i.type=? AND y.year IS NOT NULL AND y.year>0
        ORDER BY y.year DESC");
    $yearStmt->execute([$instanceType]);
    $years = array_map('intval', array_column($yearStmt->fetchAll(), 'year'));
}

$items = [];
$filterCounts = [];

if ($type === 'movies') {
    $countSql = "SELECT
        COUNT(*) total,
        SUM(CASE WHEN m.has_file=0 THEN 1 ELSE 0 END) missing,
        SUM(CASE WHEN m.has_file=1 THEN 1 ELSE 0 END) available,
        SUM(CASE WHEN m.has_file=1 AND (m.audio_languages IS NULL OR TRIM(m.audio_languages)='') THEN 1 ELSE 0 END) noaudio,
        SUM(CASE WHEN m.monitored=1 THEN 1 ELSE 0 END) monitored
        FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1";
    $countParams = [];
    if ($instanceId > 0) { $countSql .= ' AND m.instance_id=?'; $countParams[] = $instanceId; }
    $stmt = $pdo->prepare($countSql); $stmt->execute($countParams); $filterCounts = $stmt->fetch() ?: [];

    $sql = 'SELECT m.*, i.name instance_name FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1';
    $params = [];
    if ($instanceId > 0) { $sql .= ' AND m.instance_id=?'; $params[] = $instanceId; }
    if ($year > 0) { $sql .= ' AND m.year=?'; $params[] = $year; }
    if ($q !== '') { $sql .= ' AND m.title LIKE ?'; $params[] = '%' . $q . '%'; }

    if ($filter === 'missing') $sql .= ' AND m.has_file=0';
    elseif ($filter === 'available') $sql .= ' AND m.has_file=1';
    elseif ($filter === 'noaudio') $sql .= " AND m.has_file=1 AND (m.audio_languages IS NULL OR TRIM(m.audio_languages)='')";
    elseif ($filter === 'monitored') $sql .= ' AND m.monitored=1';

    $sql .= ' ORDER BY m.title COLLATE NOCASE ' . strtoupper($sort) . ' LIMIT 1000';
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
} elseif ($type === 'series') {
    $countSql = "SELECT
        COUNT(*) total,
        SUM(CASE WHEN s.episode_file_count < s.episode_count THEN 1 ELSE 0 END) missing,
        SUM(CASE WHEN s.episode_count > 0 AND s.episode_file_count >= s.episode_count THEN 1 ELSE 0 END) complete,
        SUM(CASE WHEN s.aired_missing_count>0 THEN 1 ELSE 0 END) airedmissing,
        SUM(CASE WHEN s.future_missing_count>0 THEN 1 ELSE 0 END) future,
        SUM(CASE WHEN s.missing_audio_count>0 THEN 1 ELSE 0 END) noaudio,
        SUM(CASE WHEN s.monitored=0 THEN 1 ELSE 0 END) unmonitored,
        SUM(CASE WHEN s.monitored=1 THEN 1 ELSE 0 END) monitored
        FROM series s JOIN instances i ON i.id=s.instance_id WHERE i.enabled=1";
    $countParams = [];
    if ($instanceId > 0) { $countSql .= ' AND s.instance_id=?'; $countParams[] = $instanceId; }
    $stmt = $pdo->prepare($countSql); $stmt->execute($countParams); $filterCounts = $stmt->fetch() ?: [];

    $sql = 'SELECT s.*, i.name instance_name FROM series s JOIN instances i ON i.id=s.instance_id WHERE i.enabled=1';
    $params = [];
    if ($instanceId > 0) { $sql .= ' AND s.instance_id=?'; $params[] = $instanceId; }
    if ($year > 0) { $sql .= ' AND s.year=?'; $params[] = $year; }
    if ($q !== '') { $sql .= ' AND s.title LIKE ?'; $params[] = '%' . $q . '%'; }

    if ($filter === 'airedmissing') $sql .= ' AND s.aired_missing_count>0';
    elseif ($filter === 'missing') $sql .= ' AND s.episode_file_count < s.episode_count';
    elseif ($filter === 'complete') $sql .= ' AND s.episode_count > 0 AND s.episode_file_count >= s.episode_count';
    elseif ($filter === 'future') $sql .= ' AND s.future_missing_count>0';
    elseif ($filter === 'noaudio') $sql .= ' AND s.missing_audio_count>0';
    elseif ($filter === 'unmonitored') $sql .= ' AND s.monitored=0';
    elseif ($filter === 'monitored') $sql .= ' AND s.monitored=1';

    $sql .= ' ORDER BY s.title COLLATE NOCASE ' . strtoupper($sort) . ' LIMIT 1000';
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
}

function filterUrl(string $type, string $filter, int $instanceId, string $q = '', int $year = 0, string $sort = 'asc'): string
{
    $params = ['type'=>$type, 'filter'=>$filter, 'sort'=>$sort];
    if ($instanceId > 0) $params['instance'] = $instanceId;
    if ($year > 0) $params['year'] = $year;
    if ($q !== '') $params['q'] = $q;
    return '/?' . http_build_query($params);
}

function sortUrl(string $type, string $filter, int $instanceId, string $q, int $year, string $sort): string
{
    return filterUrl($type, $filter, $instanceId, $q, $year, $sort);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ArrView <?=e(ARRVIEW_VERSION)?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a>
    <nav>
        <a href="/?type=movies" class="<?=$type==='movies'?'active':''?>">Movies</a>
        <a href="/?type=series" class="<?=$type==='series'?'active':''?>">Series</a>
        <a href="/support.php">Support</a>
        <?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?>
        <span class="user-chip"><?=e($currentUser['username'])?></span>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="wrap compact-library">
<?php if(!$type):?>
    <section class="hero">
        <p class="eyebrow">RADARR + SONARR LIBRARY VIEW</p>
        <h1>Find what is missing.</h1>
        <p>Compact library monitoring focused on file availability, audio language and missing-download diagnostics.</p>
    </section>
    <section class="chooser">
        <a class="choice" href="/?type=movies"><div class="choice-icon">🎬</div><div><span>Radarr</span><h2>Movies</h2><p><?=$movieCount?> indexed movies</p></div></a>
        <a class="choice" href="/?type=series"><div class="choice-icon">📺</div><div><span>Sonarr</span><h2>TV Series</h2><p><?=$seriesCount?> indexed series</p></div></a>
    </section>
    <p class="muted center"><?=$instanceCount?> enabled instance<?=$instanceCount===1?'':'s'?></p>
<?php else:?>
    <section class="compact-head">
        <div>
            <p class="eyebrow"><?=$type==='movies'?'RADARR MOVIES':'SONARR SERIES'?></p>
            <h1><?=$type==='movies'?'Movies':'TV Series'?></h1>
        </div>
        <div class="result-count"><?=number_format(count($items))?> shown</div>
    </section>

    <form class="library-toolbar" method="get">
        <input type="hidden" name="type" value="<?=e($type)?>">
        <input type="hidden" name="filter" value="<?=e($filter)?>">
        <input type="hidden" name="sort" value="<?=e($sort)?>">
        <div class="toolbar-search">
            <input type="search" name="q" value="<?=e($q)?>" placeholder="Search title..." autocomplete="off">
        </div>
        <select name="instance" onchange="this.form.submit()">
            <option value="0">All instances</option>
            <?php foreach($instances as $instance):?>
                <option value="<?=(int)$instance['id']?>" <?=$instanceId===(int)$instance['id']?'selected':''?>><?=e($instance['name'])?></option>
            <?php endforeach;?>
        </select>
        <select name="year" onchange="this.form.submit()">
            <option value="0">All years</option>
            <?php foreach($years as $availableYear):?>
                <option value="<?=$availableYear?>" <?=$year===$availableYear?'selected':''?>><?=$availableYear?></option>
            <?php endforeach;?>
        </select>
        <button type="submit">Search</button>
        <?php if($q!=='' || $instanceId>0 || $year>0):?><a class="toolbar-reset" href="/?type=<?=e($type)?>&filter=<?=e($filter)?>&sort=<?=e($sort)?>">Reset</a><?php endif;?>
    </form>

    <nav class="quick-filters" aria-label="Library filters">
        <a class="<?=$filter==='all'?'active':''?>" href="<?=e(filterUrl($type,'all',$instanceId,$q,$year,$sort))?>">All <span><?=number_format((int)($filterCounts['total']??0))?></span></a>
        <a class="<?=$filter==='missing'?'active danger-filter':''?>" href="<?=e(filterUrl($type,'missing',$instanceId,$q,$year,$sort))?>">Missing <span><?=number_format((int)($filterCounts['missing']??0))?></span></a>
        <?php if($type==='movies'):?>
            <a class="<?=$filter==='available'?'active':''?>" href="<?=e(filterUrl($type,'available',$instanceId,$q,$year,$sort))?>">Available <span><?=number_format((int)($filterCounts['available']??0))?></span></a>
        <?php else:?>
            <a class="<?=$filter==='airedmissing'?'active danger-filter':''?>" href="<?=e(filterUrl($type,'airedmissing',$instanceId,$q,$year,$sort))?>">Aired Missing <span><?=number_format((int)($filterCounts['airedmissing']??0))?></span></a>
            <a class="<?=$filter==='complete'?'active':''?>" href="<?=e(filterUrl($type,'complete',$instanceId,$q,$year,$sort))?>">Complete <span><?=number_format((int)($filterCounts['complete']??0))?></span></a>
            <a class="<?=$filter==='future'?'active':''?>" href="<?=e(filterUrl($type,'future',$instanceId,$q,$year,$sort))?>">Future <span><?=number_format((int)($filterCounts['future']??0))?></span></a>
            <a class="<?=$filter==='unmonitored'?'active':''?>" href="<?=e(filterUrl($type,'unmonitored',$instanceId,$q,$year,$sort))?>">Unmonitored <span><?=number_format((int)($filterCounts['unmonitored']??0))?></span></a>
        <?php endif;?>
        <a class="<?=$filter==='noaudio'?'active warning-filter':''?>" href="<?=e(filterUrl($type,'noaudio',$instanceId,$q,$year,$sort))?>">Missing Audio Info <span><?=number_format((int)($filterCounts['noaudio']??0))?></span></a>
        <a class="<?=$filter==='monitored'?'active':''?>" href="<?=e(filterUrl($type,'monitored',$instanceId,$q,$year,$sort))?>">Monitored <span><?=number_format((int)($filterCounts['monitored']??0))?></span></a>
    </nav>

    <?php if(!$items):?>
        <div class="empty compact"><h2>No matching items</h2><p>Try another filter or run a fresh sync from Admin.</p></div>
    <?php else:?>
        <div class="media-table-wrap">
            <table class="media-table">
                <thead>
                    <tr>
                        <th><a class="sort-link" href="<?=e(sortUrl($type,$filter,$instanceId,$q,$year,$sort==='asc'?'desc':'asc'))?>">Title <?=$sort==='asc'?'▲':'▼'?></a></th>
                        <th>Year</th>
                        <th>File Status</th>
                        <th>Audio Language</th>
                        <th><?=$type==='movies'?'Quality':'Episodes'?></th>
                        <th>Instance</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($items as $item):?>
                    <?php
                    if ($type === 'movies') {
                        $isMissing = !(int)$item['has_file'];
                        $statusText = $isMissing ? 'Missing' : 'Available';
                        $statusClass = $isMissing ? 'status-missing' : 'status-ok';
                        $detail = $item['quality'] ?: '—';
                        $audio = $item['audio_languages'] ?: ($isMissing ? 'No file' : 'Unknown');
                    } else {
                        $episodes = (int)$item['episode_count'];
                        $files = (int)$item['episode_file_count'];
                        $isMissing = $files < $episodes;
                        $statusText = $isMissing ? 'Missing episodes' : 'Complete';
                        $statusClass = $isMissing ? 'status-missing' : 'status-ok';
                        $detail = number_format($files) . ' / ' . number_format($episodes);
                        $audio = $item['audio_languages'] ?: ($files > 0 ? 'Unknown' : 'No files');
                    }
                    ?>
                    <tr class="<?=$isMissing?'row-missing':''?> <?=($type==='movies'||$type==='series')?'clickable-row':''?>" <?=$type==='movies'?'data-details-url="/movie.php?id='.(int)$item['id'].'"':($type==='series'?'data-details-url="/series.php?id='.(int)$item['id'].'"':'')?>>
                        <td class="title-cell">
                            <?php if($type==='movies'):?><a class="movie-title-link" href="/movie.php?id=<?=(int)$item['id']?>"><?=e($item['title'])?></a><?php else:?><a class="movie-title-link" href="/series.php?id=<?=(int)$item['id']?>"><?=e($item['title'])?></a><?php endif;?>
                            <?php if(!(int)$item['monitored']):?><span class="row-note">Not monitored</span><?php endif;?>
                        </td>
                        <td><?=e((string)($item['year'] ?: '—'))?></td>
                        <td><span class="table-status <?=$statusClass?>"><?=e($statusText)?></span></td>
                        <td class="audio-cell">
                            <span class="<?=($audio==='Unknown' || $audio==='No file' || $audio==='No files')?'audio-unknown':'audio-known'?>">🔊 <?=e($audio)?></span>
                        </td>
                        <td><?=e($detail)?></td>
                        <td class="instance-cell"><?=e($item['instance_name'])?></td>
                        <td class="action-cell">
                            <?php if($type==='movies'):?>
                                <a class="table-action" href="/movie.php?id=<?=(int)$item['id']?>">Details</a>
                                <?php if($isMissing):?><a class="table-action important" href="/diagnose.php?id=<?=(int)$item['id']?>">Why missing?</a><?php endif;?>
                            <?php elseif($type==='series'):?>
                                <a class="table-action" href="/series.php?id=<?=(int)$item['id']?>">Seasons & Episodes</a>
                                <?php if($isMissing):?><span class="muted"> · Missing <?=$episodes-$files?> ep.</span><?php endif;?>
                            <?php else:?>
                                <span class="muted">—</span>
                            <?php endif;?>
                        </td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <?php if(count($items)>=1000):?><p class="muted table-limit-note">Showing first 1,000 results. Use search or filters to narrow the list.</p><?php endif;?>
    <?php endif;?>
<?php endif;?>
</main>

<footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Audio & availability focused library monitor</footer>
<script>document.querySelectorAll('tr[data-details-url]').forEach(row=>row.addEventListener('click',e=>{if(e.target.closest('a,button,input,select'))return;location.href=row.dataset.detailsUrl;}));</script>
</body>
</html>
