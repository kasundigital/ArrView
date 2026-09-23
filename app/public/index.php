<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$type = $_GET['type'] ?? null;
$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$instanceId = (int)($_GET['instance'] ?? 0);
$year = (int)($_GET['year'] ?? 0);
$sortBy = strtolower((string)($_GET['sort_by'] ?? 'title'));
$sortDir = strtolower((string)($_GET['sort_dir'] ?? 'asc'));
if (!in_array($sortDir, ['asc','desc'], true)) $sortDir = 'asc';
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = (int)app_setting('library_page_size', '100');
if (!in_array($pageSize, [25,50,100,250,500], true)) $pageSize = 100;
$audioFilter = trim((string)($_GET['audio'] ?? ''));
$preferredLanguages = array_values(array_filter(array_map('trim', explode(',', (string)app_setting('preferred_audio_languages','')))));
$preferredWarnings = app_setting('preferred_language_warning','1') === '1';

$validFilters = $type === 'series'
    ? ['all','airedmissing','missing','complete','future','noaudio','mixed','preferred','unmonitored','monitored']
    : ['all','missing','upcoming','available','unknown','unmonitored','noaudio','preferred','monitored'];
if (!in_array($filter, $validFilters, true)) $filter = 'all';

$movieCount = (int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn();
$seriesCount = (int)$pdo->query('SELECT COUNT(*) FROM series')->fetchColumn();
$instanceCount = (int)$pdo->query('SELECT COUNT(*) FROM instances WHERE enabled=1')->fetchColumn();
$missingMovieCount = (int)$pdo->query("SELECT COUNT(*) FROM movies WHERE has_file=0 AND monitored=1 AND availability_date IS NOT NULL AND datetime(availability_date) <= datetime('now')")->fetchColumn();
$upcomingMovieCount = (int)$pdo->query("SELECT COUNT(*) FROM movies WHERE has_file=0 AND monitored=1 AND availability_date IS NOT NULL AND datetime(availability_date) > datetime('now')")->fetchColumn();
$availableMovieCount = (int)$pdo->query('SELECT COUNT(*) FROM movies WHERE has_file=1')->fetchColumn();
$incompleteSeriesCount = (int)$pdo->query('SELECT COUNT(*) FROM series WHERE episode_file_count < episode_count')->fetchColumn();
$airedMissingSeriesCount = (int)$pdo->query('SELECT COUNT(*) FROM series WHERE aired_missing_count>0')->fetchColumn();

$instances = [];
$years = [];
$audioOptions = [];
if ($type === 'movies' || $type === 'series') {
    $instanceType = $type === 'movies' ? 'radarr' : 'sonarr';
    $stmt = $pdo->prepare('SELECT id,name FROM instances WHERE enabled=1 AND type=? ORDER BY name COLLATE NOCASE');
    $stmt->execute([$instanceType]);
    $instances = $stmt->fetchAll();

    $yearTable = $type === 'movies' ? 'movies' : 'series';
    $yearSql = "SELECT DISTINCT y.year
        FROM {$yearTable} y
        JOIN instances i ON i.id=y.instance_id
        WHERE i.enabled=1 AND i.type=? AND y.year IS NOT NULL AND y.year>0";
    $yearParams = [$instanceType];
    if ($instanceId > 0) {
        $yearSql .= ' AND y.instance_id=?';
        $yearParams[] = $instanceId;
    }
    $yearSql .= ' ORDER BY y.year DESC';
    $yearStmt = $pdo->prepare($yearSql);
    $yearStmt->execute($yearParams);
    $years = array_map('intval', array_column($yearStmt->fetchAll(), 'year'));
    if ($year > 0 && !in_array($year, $years, true)) $year = 0;

    $audioRows = $pdo->query("SELECT DISTINCT audio_languages FROM {$yearTable} WHERE audio_languages IS NOT NULL AND TRIM(audio_languages)<>''")->fetchAll();
    $audioSet = [];
    foreach ($audioRows as $audioRow) {
        foreach (array_map('trim', explode(',', (string)$audioRow['audio_languages'])) as $lang) {
            if ($lang !== '') $audioSet[$lang] = true;
        }
    }
    $audioOptions = array_keys($audioSet);
    natcasesort($audioOptions);
    $audioOptions = array_values($audioOptions);
}

$items = [];
$filterCounts = [];
$totalResults = 0;
$totalPages = 1;

$preferredSql = '';
$preferredParams = [];
if ($preferredLanguages) {
    $parts = [];
    foreach ($preferredLanguages as $language) {
        $parts[] = "LOWER(COALESCE(%s.audio_languages,'')) LIKE ?";
        $preferredParams[] = '%' . strtolower($language) . '%';
    }
    $preferredSql = implode(' OR ', $parts);
}

if ($type === 'movies') {
    $countSql = "SELECT
        COUNT(*) total,
        SUM(CASE WHEN m.has_file=0 AND m.monitored=1 AND m.availability_date IS NOT NULL AND datetime(m.availability_date) <= datetime('now') THEN 1 ELSE 0 END) missing,
        SUM(CASE WHEN m.has_file=0 AND m.monitored=1 AND m.availability_date IS NOT NULL AND datetime(m.availability_date) > datetime('now') THEN 1 ELSE 0 END) upcoming,
        SUM(CASE WHEN m.has_file=1 THEN 1 ELSE 0 END) available,
        SUM(CASE WHEN m.has_file=0 AND m.monitored=1 AND m.availability_date IS NULL THEN 1 ELSE 0 END) unknown,
        SUM(CASE WHEN m.has_file=0 AND m.monitored=0 THEN 1 ELSE 0 END) unmonitored,
        SUM(CASE WHEN m.has_file=1 AND (m.audio_languages IS NULL OR TRIM(m.audio_languages)='') THEN 1 ELSE 0 END) noaudio,
        SUM(CASE WHEN m.monitored=1 THEN 1 ELSE 0 END) monitored
        FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1";
    $countParams = [];
    if ($instanceId > 0) { $countSql .= ' AND m.instance_id=?'; $countParams[] = $instanceId; }
    if ($year > 0) { $countSql .= ' AND m.year=?'; $countParams[] = $year; }
    if ($q !== '') { $countSql .= ' AND m.title LIKE ?'; $countParams[] = '%' . $q . '%'; }
    if ($audioFilter !== '') { $countSql .= " AND LOWER(COALESCE(m.audio_languages,'')) LIKE ?"; $countParams[] = '%' . strtolower($audioFilter) . '%'; }
    $stmt = $pdo->prepare($countSql); $stmt->execute($countParams); $filterCounts = $stmt->fetch() ?: [];

    $sql = 'SELECT m.*, i.name instance_name FROM movies m JOIN instances i ON i.id=m.instance_id WHERE i.enabled=1';
    $params = [];
    if ($instanceId > 0) { $sql .= ' AND m.instance_id=?'; $params[] = $instanceId; }
    if ($year > 0) { $sql .= ' AND m.year=?'; $params[] = $year; }
    if ($q !== '') { $sql .= ' AND m.title LIKE ?'; $params[] = '%' . $q . '%'; }
    if ($audioFilter !== '') { $sql .= " AND LOWER(COALESCE(m.audio_languages,'')) LIKE ?"; $params[] = '%' . strtolower($audioFilter) . '%'; }

    if ($filter === 'missing') $sql .= " AND m.has_file=0 AND m.monitored=1 AND m.availability_date IS NOT NULL AND datetime(m.availability_date) <= datetime('now')";
    elseif ($filter === 'upcoming') $sql .= " AND m.has_file=0 AND m.monitored=1 AND m.availability_date IS NOT NULL AND datetime(m.availability_date) > datetime('now')";
    elseif ($filter === 'available') $sql .= ' AND m.has_file=1';
    elseif ($filter === 'unknown') $sql .= ' AND m.has_file=0 AND m.monitored=1 AND m.availability_date IS NULL';
    elseif ($filter === 'unmonitored') $sql .= ' AND m.has_file=0 AND m.monitored=0';
    elseif ($filter === 'noaudio') $sql .= " AND m.has_file=1 AND (m.audio_languages IS NULL OR TRIM(m.audio_languages)='')";
    elseif ($filter === 'preferred' && $preferredLanguages) {
        $parts=[]; foreach($preferredLanguages as $_){$parts[]="LOWER(COALESCE(m.audio_languages,'')) LIKE ?";}
        $sql .= ' AND m.has_file=1 AND NOT (' . implode(' OR ', $parts) . ')';
        foreach($preferredLanguages as $language)$params[]='%'.strtolower($language).'%';
    }
    elseif ($filter === 'monitored') $sql .= ' AND m.monitored=1';

    $movieSortMap = [
        'title' => 'm.title COLLATE NOCASE',
        'year' => 'm.year',
        'status' => 'm.has_file',
        'audio' => "COALESCE(NULLIF(TRIM(m.audio_languages), ''), 'zzzz') COLLATE NOCASE",
        'quality' => "COALESCE(NULLIF(TRIM(m.quality), ''), 'zzzz') COLLATE NOCASE",
        'instance' => 'i.name COLLATE NOCASE',
    ];
    if (!isset($movieSortMap[$sortBy])) $sortBy = 'title';
    $countResult = $pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') q');
    $countResult->execute($params); $totalResults = (int)$countResult->fetchColumn();
    $totalPages = max(1, (int)ceil($totalResults / $pageSize));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
    $sql .= ' ORDER BY ' . $movieSortMap[$sortBy] . ' ' . strtoupper($sortDir) . ', m.title COLLATE NOCASE ASC LIMIT ' . $pageSize . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
} elseif ($type === 'series') {
    $countSql = "SELECT
        COUNT(*) total,
        SUM(CASE WHEN s.episode_file_count < s.episode_count THEN 1 ELSE 0 END) missing,
        SUM(CASE WHEN s.episode_count > 0 AND s.episode_file_count >= s.episode_count THEN 1 ELSE 0 END) complete,
        SUM(CASE WHEN s.aired_missing_count>0 THEN 1 ELSE 0 END) airedmissing,
        SUM(CASE WHEN s.future_missing_count>0 THEN 1 ELSE 0 END) future,
        SUM(CASE WHEN s.missing_audio_count>0 THEN 1 ELSE 0 END) noaudio,
        SUM(CASE WHEN s.language_inconsistent=1 THEN 1 ELSE 0 END) mixed,
        SUM(CASE WHEN s.monitored=0 THEN 1 ELSE 0 END) unmonitored,
        SUM(CASE WHEN s.monitored=1 THEN 1 ELSE 0 END) monitored
        FROM series s JOIN instances i ON i.id=s.instance_id WHERE i.enabled=1";
    $countParams = [];
    if ($instanceId > 0) { $countSql .= ' AND s.instance_id=?'; $countParams[] = $instanceId; }
    if ($year > 0) { $countSql .= ' AND s.year=?'; $countParams[] = $year; }
    if ($q !== '') { $countSql .= ' AND s.title LIKE ?'; $countParams[] = '%' . $q . '%'; }
    if ($audioFilter !== '') { $countSql .= " AND LOWER(COALESCE(s.audio_languages,'')) LIKE ?"; $countParams[] = '%' . strtolower($audioFilter) . '%'; }
    $stmt = $pdo->prepare($countSql); $stmt->execute($countParams); $filterCounts = $stmt->fetch() ?: [];

    $sql = 'SELECT s.*, i.name instance_name FROM series s JOIN instances i ON i.id=s.instance_id WHERE i.enabled=1';
    $params = [];
    if ($instanceId > 0) { $sql .= ' AND s.instance_id=?'; $params[] = $instanceId; }
    if ($year > 0) { $sql .= ' AND s.year=?'; $params[] = $year; }
    if ($q !== '') { $sql .= ' AND s.title LIKE ?'; $params[] = '%' . $q . '%'; }
    if ($audioFilter !== '') { $sql .= " AND LOWER(COALESCE(s.audio_languages,'')) LIKE ?"; $params[] = '%' . strtolower($audioFilter) . '%'; }

    if ($filter === 'airedmissing') $sql .= ' AND s.aired_missing_count>0';
    elseif ($filter === 'missing') $sql .= ' AND s.episode_file_count < s.episode_count';
    elseif ($filter === 'complete') $sql .= ' AND s.episode_count > 0 AND s.episode_file_count >= s.episode_count';
    elseif ($filter === 'future') $sql .= ' AND s.future_missing_count>0';
    elseif ($filter === 'noaudio') $sql .= ' AND s.missing_audio_count>0';
    elseif ($filter === 'mixed') $sql .= ' AND s.language_inconsistent=1';
    elseif ($filter === 'preferred' && $preferredLanguages) {
        $parts=[]; foreach($preferredLanguages as $_){$parts[]="LOWER(COALESCE(s.audio_languages,'')) LIKE ?";}
        $sql .= ' AND s.episode_file_count>0 AND NOT (' . implode(' OR ', $parts) . ')';
        foreach($preferredLanguages as $language)$params[]='%'.strtolower($language).'%';
    }
    elseif ($filter === 'unmonitored') $sql .= ' AND s.monitored=0';
    elseif ($filter === 'monitored') $sql .= ' AND s.monitored=1';

    $seriesSortMap = [
        'title' => 's.title COLLATE NOCASE',
        'year' => 's.year',
        'status' => '(s.episode_file_count >= s.episode_count)',
        'audio' => "COALESCE(NULLIF(TRIM(s.audio_languages), ''), 'zzzz') COLLATE NOCASE",
        'episodes' => 's.episode_file_count',
        'instance' => 'i.name COLLATE NOCASE',
    ];
    if (!isset($seriesSortMap[$sortBy])) $sortBy = 'title';
    $countResult = $pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') q');
    $countResult->execute($params); $totalResults = (int)$countResult->fetchColumn();
    $totalPages = max(1, (int)ceil($totalResults / $pageSize));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $pageSize;
    $sql .= ' ORDER BY ' . $seriesSortMap[$sortBy] . ' ' . strtoupper($sortDir) . ', s.title COLLATE NOCASE ASC LIMIT ' . $pageSize . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $items = $stmt->fetchAll();
}

function libraryUrl(string $type, string $filter, int $instanceId, string $q, int $year, string $sortBy, string $sortDir): string
{
    global $audioFilter;
    $params = [
        'type'=>$type,
        'filter'=>$filter,
        'sort_by'=>$sortBy,
        'sort_dir'=>$sortDir,
    ];
    if ($instanceId > 0) $params['instance'] = $instanceId;
    if ($year > 0) $params['year'] = $year;
    if ($q !== '') $params['q'] = $q;
    if ($audioFilter !== '') $params['audio'] = $audioFilter;
    return '/?' . http_build_query($params);
}

function filterUrl(string $type, string $filter, int $instanceId, string $q, int $year, string $sortBy, string $sortDir): string
{
    return libraryUrl($type, $filter, $instanceId, $q, $year, $sortBy, $sortDir);
}

function columnSortUrl(string $type, string $filter, int $instanceId, string $q, int $year, string $sortBy, string $sortDir, string $column): string
{
    $nextDir = ($sortBy === $column && $sortDir === 'asc') ? 'desc' : 'asc';
    return libraryUrl($type, $filter, $instanceId, $q, $year, $column, $nextDir);
}


function pageUrl(int $page): string
{
    $params = $_GET;
    $params['page'] = max(1, $page);
    return '/?' . http_build_query($params);
}

function hasPreferredLanguage(?string $audio, array $preferred): bool
{
    if (!$audio || !$preferred) return true;
    $lower = strtolower($audio);
    foreach ($preferred as $language) if (str_contains($lower, strtolower($language))) return true;
    return false;
}

function sortIndicator(string $sortBy, string $sortDir, string $column): string
{
    if ($sortBy !== $column) return '↕';
    return $sortDir === 'asc' ? '▲' : '▼';
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
        <?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><a href="/system.php">System</a><?php endif;?>
        <span class="user-chip"><?=e($currentUser['username'])?></span>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="wrap compact-library">
<?php if(!$type):?>
    <section class="dashboard-hero">
        <div>
            <p class="eyebrow">ARRVIEW DASHBOARD</p>
            <h1>Media overview</h1>
            <p>Quickly see library health, missing media and connected Radarr/Sonarr services.</p>
        </div>
        <span class="dashboard-instance-chip"><?=$instanceCount?> enabled instance<?=$instanceCount===1?'':'s'?></span>
    </section>

    <section class="dashboard-stats" aria-label="Library summary">
        <a class="dashboard-stat" href="/?type=movies">
            <span class="dashboard-stat-icon">🎬</span>
            <div><small>Movies</small><strong><?=number_format($movieCount)?></strong><span><?=number_format($availableMovieCount)?> available · <?=number_format($upcomingMovieCount)?> upcoming</span></div>
        </a>
        <a class="dashboard-stat alert" href="/?type=movies&filter=missing">
            <span class="dashboard-stat-icon">!</span>
            <div><small>Missing Movies</small><strong><?=number_format($missingMovieCount)?></strong><span>Needs attention</span></div>
        </a>
        <a class="dashboard-stat" href="/?type=series">
            <span class="dashboard-stat-icon">📺</span>
            <div><small>TV Series</small><strong><?=number_format($seriesCount)?></strong><span><?=number_format($incompleteSeriesCount)?> incomplete</span></div>
        </a>
        <a class="dashboard-stat warning" href="/?type=series&filter=airedmissing">
            <span class="dashboard-stat-icon">↳</span>
            <div><small>Aired Missing</small><strong><?=number_format($airedMissingSeriesCount)?></strong><span>Series with aired gaps</span></div>
        </a>
    </section>

    <section class="dashboard-launch">
        <a class="dashboard-launch-card" href="/?type=movies">
            <div class="launch-icon">🎬</div>
            <div><span>Radarr</span><h2>Movies</h2><p>Browse availability, audio language, quality and missing-download diagnostics.</p></div>
            <b>Open →</b>
        </a>
        <a class="dashboard-launch-card" href="/?type=series">
            <div class="launch-icon">📺</div>
            <div><span>Sonarr</span><h2>Series</h2><p>Browse seasons, episodes, aired missing items and audio information.</p></div>
            <b>Open →</b>
        </a>
    </section>
<?php else:?>
    <section class="compact-head">
        <div>
            <p class="eyebrow"><?=$type==='movies'?'RADARR MOVIES':'SONARR SERIES'?></p>
            <h1><?=$type==='movies'?'Movies':'TV Series'?></h1>
        </div>
        <div class="result-count"><?php if($totalResults):?><?=number_format((($page-1)*$pageSize)+1)?>–<?=number_format(min($page*$pageSize,$totalResults))?> of <?=number_format($totalResults)?><?php else:?>0 results<?php endif;?></div>
    </section>

    <form class="library-toolbar" method="get">
        <input type="hidden" name="type" value="<?=e($type)?>">
        <input type="hidden" name="filter" value="<?=e($filter)?>">
        <input type="hidden" name="sort_dir" value="<?=e($sortDir)?>">
        <div class="toolbar-search">
            <input type="search" name="q" value="<?=e($q)?>" placeholder="Search title..." autocomplete="off">
        </div>
        <select name="instance" onchange="this.form.submit()">
            <option value="0">All instances</option>
            <?php foreach($instances as $instance):?>
                <option value="<?=(int)$instance['id']?>" <?=$instanceId===(int)$instance['id']?'selected':''?>><?=e($instance['name'])?></option>
            <?php endforeach;?>
        </select>
        <select name="year" onchange="this.form.submit()" aria-label="Filter by year">
            <option value="0">Filter by year</option>
            <?php foreach($years as $availableYear):?>
                <option value="<?=$availableYear?>" <?=$year===$availableYear?'selected':''?>><?=$availableYear?></option>
            <?php endforeach;?>
        </select>
        <select name="audio" onchange="this.form.submit()" aria-label="Filter by audio language">
            <option value="">All audio languages</option>
            <?php foreach($audioOptions as $lang):?><option value="<?=e($lang)?>" <?=$audioFilter===$lang?'selected':''?>><?=e($lang)?></option><?php endforeach;?>
        </select>
        <select name="sort_by" onchange="this.form.submit()" aria-label="Sort by">
            <option value="title" <?=$sortBy==='title'?'selected':''?>>Sort: Title</option>
            <option value="year" <?=$sortBy==='year'?'selected':''?>>Sort: Year</option>
            <option value="status" <?=$sortBy==='status'?'selected':''?>>Sort: File Status</option>
            <option value="audio" <?=$sortBy==='audio'?'selected':''?>>Sort: Audio Language</option>
            <option value="<?=$type==='movies'?'quality':'episodes'?>" <?=$sortBy===($type==='movies'?'quality':'episodes')?'selected':''?>>Sort: <?=$type==='movies'?'Quality':'Episodes'?></option>
            <option value="instance" <?=$sortBy==='instance'?'selected':''?>>Sort: Instance</option>
        </select>
        <select name="sort_dir" onchange="this.form.submit()" aria-label="Sort direction">
            <option value="asc" <?=$sortDir==='asc'?'selected':''?>>Ascending</option>
            <option value="desc" <?=$sortDir==='desc'?'selected':''?>>Descending</option>
        </select>
        <button type="submit" class="toolbar-search-btn">Search</button>
        <?php if($q!=='' || $instanceId>0 || $year>0 || $audioFilter!=='' || $filter!=='all' || $sortBy!=='title' || $sortDir!=='asc'):?><a class="toolbar-reset" href="/?type=<?=e($type)?>">Reset</a><?php endif;?>
    </form>

    <nav class="quick-filters" aria-label="Library filters">
        <a class="<?=$filter==='all'?'active':''?>" href="<?=e(filterUrl($type,'all',$instanceId,$q,$year,$sortBy,$sortDir))?>">All <span><?=number_format((int)($filterCounts['total']??0))?></span></a>
        <a class="<?=$filter==='missing'?'active danger-filter':''?>" href="<?=e(filterUrl($type,'missing',$instanceId,$q,$year,$sortBy,$sortDir))?>">Missing <span><?=number_format((int)($filterCounts['missing']??0))?></span></a>
        <?php if($type==='movies'):?>
            <a class="<?=$filter==='upcoming'?'active':''?>" href="<?=e(filterUrl($type,'upcoming',$instanceId,$q,$year,$sortBy,$sortDir))?>">Upcoming <span><?=number_format((int)($filterCounts['upcoming']??0))?></span></a>
            <a class="<?=$filter==='available'?'active':''?>" href="<?=e(filterUrl($type,'available',$instanceId,$q,$year,$sortBy,$sortDir))?>">Available <span><?=number_format((int)($filterCounts['available']??0))?></span></a>
            <a class="<?=$filter==='unknown'?'active warning-filter':''?>" href="<?=e(filterUrl($type,'unknown',$instanceId,$q,$year,$sortBy,$sortDir))?>">Unknown Availability <span><?=number_format((int)($filterCounts['unknown']??0))?></span></a>
            <a class="<?=$filter==='unmonitored'?'active':''?>" href="<?=e(filterUrl($type,'unmonitored',$instanceId,$q,$year,$sortBy,$sortDir))?>">Unmonitored <span><?=number_format((int)($filterCounts['unmonitored']??0))?></span></a>
        <?php else:?>
            <a class="<?=$filter==='airedmissing'?'active danger-filter':''?>" href="<?=e(filterUrl($type,'airedmissing',$instanceId,$q,$year,$sortBy,$sortDir))?>">Aired Missing <span><?=number_format((int)($filterCounts['airedmissing']??0))?></span></a>
            <a class="<?=$filter==='complete'?'active':''?>" href="<?=e(filterUrl($type,'complete',$instanceId,$q,$year,$sortBy,$sortDir))?>">Complete <span><?=number_format((int)($filterCounts['complete']??0))?></span></a>
            <a class="<?=$filter==='future'?'active':''?>" href="<?=e(filterUrl($type,'future',$instanceId,$q,$year,$sortBy,$sortDir))?>">Future <span><?=number_format((int)($filterCounts['future']??0))?></span></a>
            <a class="<?=$filter==='unmonitored'?'active':''?>" href="<?=e(filterUrl($type,'unmonitored',$instanceId,$q,$year,$sortBy,$sortDir))?>">Unmonitored <span><?=number_format((int)($filterCounts['unmonitored']??0))?></span></a>
        <?php endif;?>
        <a class="<?=$filter==='noaudio'?'active warning-filter':''?>" href="<?=e(filterUrl($type,'noaudio',$instanceId,$q,$year,$sortBy,$sortDir))?>">Missing Audio Info <span><?=number_format((int)($filterCounts['noaudio']??0))?></span></a>
        <?php if($type==='series'):?><a class="<?=$filter==='mixed'?'active warning-filter':''?>" href="<?=e(filterUrl($type,'mixed',$instanceId,$q,$year,$sortBy,$sortDir))?>">Mixed Languages <span><?=number_format((int)($filterCounts['mixed']??0))?></span></a><?php endif;?>
        <?php if($preferredLanguages):?><a class="<?=$filter==='preferred'?'active warning-filter':''?>" href="<?=e(filterUrl($type,'preferred',$instanceId,$q,$year,$sortBy,$sortDir))?>">Preferred Language Warnings</a><?php endif;?>
        <a class="<?=$filter==='monitored'?'active':''?>" href="<?=e(filterUrl($type,'monitored',$instanceId,$q,$year,$sortBy,$sortDir))?>">Monitored <span><?=number_format((int)($filterCounts['monitored']??0))?></span></a>
    </nav>

    <?php if(!$items):?>
        <div class="empty compact"><h2>No matching items</h2><p>Try another filter or run a fresh sync from Admin.</p></div>
    <?php else:?>
        <div class="media-table-wrap">
            <table class="media-table">
                <thead>
                    <tr>
                        <th><a class="sort-link <?=$sortBy==='title'?'active':''?>" href="<?=e(columnSortUrl($type,$filter,$instanceId,$q,$year,$sortBy,$sortDir,'title'))?>">Title <span><?=sortIndicator($sortBy,$sortDir,'title')?></span></a></th>
                        <th><a class="sort-link <?=$sortBy==='year'?'active':''?>" href="<?=e(columnSortUrl($type,$filter,$instanceId,$q,$year,$sortBy,$sortDir,'year'))?>">Year <span><?=sortIndicator($sortBy,$sortDir,'year')?></span></a></th>
                        <th><a class="sort-link <?=$sortBy==='status'?'active':''?>" href="<?=e(columnSortUrl($type,$filter,$instanceId,$q,$year,$sortBy,$sortDir,'status'))?>">File Status <span><?=sortIndicator($sortBy,$sortDir,'status')?></span></a></th>
                        <th><a class="sort-link <?=$sortBy==='audio'?'active':''?>" href="<?=e(columnSortUrl($type,$filter,$instanceId,$q,$year,$sortBy,$sortDir,'audio'))?>">Audio Language <span><?=sortIndicator($sortBy,$sortDir,'audio')?></span></a></th>
                        <?php $detailSort=$type==='movies'?'quality':'episodes'; ?>
                        <th><a class="sort-link <?=$sortBy===$detailSort?'active':''?>" href="<?=e(columnSortUrl($type,$filter,$instanceId,$q,$year,$sortBy,$sortDir,$detailSort))?>"><?=$type==='movies'?'Quality':'Episodes'?> <span><?=sortIndicator($sortBy,$sortDir,$detailSort)?></span></a></th>
                        <th><a class="sort-link <?=$sortBy==='instance'?'active':''?>" href="<?=e(columnSortUrl($type,$filter,$instanceId,$q,$year,$sortBy,$sortDir,'instance'))?>">Instance <span><?=sortIndicator($sortBy,$sortDir,'instance')?></span></a></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($items as $item):?>
                    <?php
                    if ($type === 'movies') {
                        $movieState = movie_availability_state($item);
                        $isMissing = $movieState['key'] === 'missing';
                        $statusText = $movieState['label'];
                        $statusClass = $movieState['class'];
                        $detail = $item['quality'] ?: '—';
                        $audio = $item['audio_languages'] ?: (!empty($item['has_file']) ? 'Unknown' : 'No file');
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
                            <?php if($preferredWarnings && $preferredLanguages && !empty($item['has_file']) && !hasPreferredLanguage($item['audio_languages']??null,$preferredLanguages)):?><span class="row-note warning-note">Preferred audio missing</span><?php endif;?>
                            <?php if($type==='series' && !empty($item['language_inconsistent'])):?><span class="row-note warning-note">Mixed episode languages</span><?php endif;?>
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
                                <?php if($isMissing):?><a class="table-action important" href="/diagnose.php?id=<?=(int)$item['id']?>">Why missing?</a><?php elseif(($movieState['key']??'')==='upcoming'):?><span class="row-note">Not released yet</span><?php endif;?>
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
        <?php if($totalPages>1):?>
        <nav class="pagination" aria-label="Library pages">
            <a class="<?=$page<=1?'disabled':''?>" href="<?=e(pageUrl(max(1,$page-1)))?>">← Previous</a>
            <span>Page <?=number_format($page)?> of <?=number_format($totalPages)?></span>
            <a class="<?=$page>=$totalPages?'disabled':''?>" href="<?=e(pageUrl(min($totalPages,$page+1)))?>">Next →</a>
        </nav>
        <?php endif;?>
    <?php endif;?>
<?php endif;?>
</main>

<footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Audio & availability focused library monitor</footer>
<script>document.querySelectorAll('tr[data-details-url]').forEach(row=>row.addEventListener('click',e=>{if(e.target.closest('a,button,input,select'))return;location.href=row.dataset.detailsUrl;}));</script>
</body>
</html>
