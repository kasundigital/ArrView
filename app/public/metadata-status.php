<?php
declare(strict_types=1);

ini_set('display_errors','0');
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/MetadataJobService.php';

header('Content-Type: application/json; charset=utf-8');

function metadata_status_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $auth->requireAdmin();
    MetadataJobService::recoverStale($pdo);

    $id = (int)($_GET['job_id'] ?? 0);
    if ($id < 1) metadata_status_json(['ok'=>false,'message'=>'Invalid metadata job id'], 400);

    $stmt = $pdo->prepare('SELECT * FROM metadata_jobs WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $job = $stmt->fetch();

    if (!$job) metadata_status_json(['ok'=>false,'message'=>'Job not found'], 404);

    $total = (int)$job['total_items'];
    $current = (int)$job['current_item'];
    metadata_status_json([
        'ok'=>true,
        'status'=>$job['status'],
        'current'=>$current,
        'total'=>$total,
        'percent'=>$total > 0 ? round(($current / $total) * 100, 1) : 0,
        'title'=>$job['current_title'],
        'message'=>$job['message'],
        'heartbeat_at'=>$job['heartbeat_at'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('ArrView metadata-status: ' . $e->getMessage());
    metadata_status_json(['ok'=>false,'message'=>$e->getMessage()], 500);
}
