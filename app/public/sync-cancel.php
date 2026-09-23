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
    $state=$pdo->prepare('SELECT status FROM sync_jobs WHERE id=?');
    $state->execute([$jobId]);
    $status=(string)($state->fetchColumn() ?: '');
    if($status==='queued'){
        $stmt=$pdo->prepare("UPDATE sync_jobs SET cancel_requested=1,status='failed',message='Cancelled by administrator before start',finished_at=CURRENT_TIMESTAMP WHERE id=? AND status='queued'");
        $stmt->execute([$jobId]);
        echo json_encode(['ok'=>true,'job_id'=>$jobId,'message'=>'Queued sync cancelled.']);
    } elseif($status==='running'){
        $stmt=$pdo->prepare("UPDATE sync_jobs SET cancel_requested=1,message='Cancellation requested' WHERE id=? AND status='running'");
        $stmt->execute([$jobId]);
        echo json_encode(['ok'=>true,'job_id'=>$jobId,'message'=>'Cancellation requested.']);
    } else {
        throw new RuntimeException('Sync job is not active.');
    }
} catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
