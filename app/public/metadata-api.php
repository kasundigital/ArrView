<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/ClientIp.php';
require_once dirname(__DIR__) . '/src/MetadataQuota.php';

header('Content-Type: application/json');

$type = strtolower((string)($_GET['type'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
if (!in_array($type, ['movie','series'], true) || $id < 1) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>'Valid type and TMDB id are required.']);
    exit;
}

$token = trim((string)getenv('ARRVIEW_SHARED_TMDB_BEARER_TOKEN'));
if ($token === '') {
    http_response_code(503);
    echo json_encode(['ok'=>false,'message'=>'ArrView Free Metadata service is not enabled on this server.']);
    exit;
}

$dataDir = getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data';
$db = new Database(rtrim($dataDir, '/') . '/arrview.sqlite');
$pdo = $db->pdo;

$key = $type . ':' . $id;
$cache = $pdo->prepare("SELECT payload_json FROM shared_tmdb_cache WHERE cache_key=? AND datetime(fetched_at) >= datetime('now','-30 days') LIMIT 1");
$cache->execute([$key]);
$cached = $cache->fetchColumn();
if ($cached) {
    echo json_encode(['ok'=>true,'data'=>json_decode((string)$cached,true),'cached'=>true,'quota'=>null], JSON_UNESCAPED_SLASHES);
    exit;
}

$quota = MetadataQuota::fromEnv($pdo)->consume(ClientIp::resolve());
if (!$quota['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . ($quota['window'] === 'hour' ? '3600' : '86400'));
    echo json_encode([
        'ok'=>false,
        'message'=>$quota['window'] === 'hour'
            ? 'ArrView Free Metadata hourly limit reached. Please retry later.'
            : 'ArrView Free Metadata monthly quota reached. Add your own TMDB API key in ArrView Admin for unlimited personal usage.',
        'quota'=>['used'=>$quota['used'],'limit'=>$quota['limit'],'remaining'=>0,'window'=>$quota['window']],
    ]);
    exit;
}

$path = $type === 'movie' ? 'movie' : 'tv';
$url = "https://api.themoviedb.org/3/{$path}/{$id}?append_to_response=external_ids";
$ch = curl_init($url);
curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>8,
    CURLOPT_TIMEOUT=>20,
    CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token],
    CURLOPT_USERAGENT=>'ArrView-Free-Metadata/'.ARRVIEW_VERSION,
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code($status === 429 ? 429 : 502);
    echo json_encode(['ok'=>false,'message'=>$status===429?'TMDB rate limit reached. Please retry shortly.':'TMDB request failed.']);
    if ($error) error_log('ArrView metadata-api upstream error: ' . $error);
    exit;
}

$json = json_decode((string)$body,true);
if (!is_array($json)) {
    http_response_code(502);
    echo json_encode(['ok'=>false,'message'=>'TMDB returned invalid JSON.']);
    exit;
}

$pdo->prepare('INSERT INTO shared_tmdb_cache(cache_key,payload_json,fetched_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(cache_key) DO UPDATE SET payload_json=excluded.payload_json,fetched_at=CURRENT_TIMESTAMP')
    ->execute([$key,json_encode($json,JSON_UNESCAPED_SLASHES)]);

echo json_encode(['ok'=>true,'data'=>$json,'cached'=>false,'quota'=>['used'=>$quota['used'],'limit'=>$quota['limit'],'remaining'=>$quota['remaining']]], JSON_UNESCAPED_SLASHES);
