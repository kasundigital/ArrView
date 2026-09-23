<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$auth->requireAdmin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'POST required']);
    exit;
}

try {
    $auth->requireCsrf($_POST['csrf_token'] ?? null);
    $jobId=(int)($_POST['job_id']??0);
    if($jobId<1) throw new RuntimeException('Invalid sync job.');
    $stmt=$pdo->prepare("UPDATE sync_jobs SET cancel_requested=1,message='Cancellation requested' WHERE id=? AND status IN ('queued','running')");
    $stmt->execute([$jobId]);
    if($stmt->rowCount()<1) throw new RuntimeException('Sync job is not running.');
    echo json_encode(['ok'=>true,'job_id'=>$jobId,'message'=>'Cancellation requested.']);
} catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
