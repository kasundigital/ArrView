<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/SyncJobService.php';
$currentUser = $auth->requireAdmin();
header('Content-Type: application/json');

SyncJobService::recoverStale($pdo);

$jobId = (int)($_GET['job_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM sync_jobs WHERE id=?');
$stmt->execute([$jobId]);
$job = $stmt->fetch();
if (!$job) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'message'=>'Sync job not found']);
    exit;
}

$dataDir = getenv('ARRVIEW_DATA') ?: (dirname(__DIR__) . '/data');
$progressFile = rtrim($dataDir, '/') . '/progress/sync-' . $jobId . '.json';
$progress = null;
if (is_file($progressFile)) {
    $decoded = json_decode((string)file_get_contents($progressFile), true);
    if (is_array($decoded)) $progress = $decoded;
}

if (!in_array((string)$job['status'], ['queued','running'], true)) {
    $current = (int)$job['current_item'];
    $total = (int)$job['total_items'];
    $progress = [
        'status'=>$job['status'],
        'current'=>$current,
        'total'=>$total,
        'percent'=>$job['status']==='completed' ? 100 : ($total > 0 ? round(($current / $total) * 100, 1) : 0),
        'title'=>$job['current_title'] ?: ucfirst((string)$job['status']),
        'message'=>$job['message'] ?: ucfirst((string)$job['status']),
    ];
} elseif (!$progress) {
    $current = (int)$job['current_item'];
    $total = (int)$job['total_items'];
    $progress = [
        'status'=>$job['status'],
        'current'=>$current,
        'total'=>$total,
        'percent'=>$total > 0 ? round(($current / $total) * 100, 1) : 0,
        'title'=>$job['current_title'] ?: 'Starting...',
        'message'=>$job['message'] ?: 'Working...',
    ];
}

$progress['ok'] = true;
$progress['job_id'] = $jobId;
echo json_encode($progress, JSON_UNESCAPED_SLASHES);
