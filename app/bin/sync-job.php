<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/BatchSyncService.php';
require_once dirname(__DIR__) . '/src/SecretService.php';

$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) exit(1);

$dataDir = getenv('ARRVIEW_DATA') ?: (dirname(__DIR__) . '/data');
$db = new Database(rtrim($dataDir, '/') . '/arrview.sqlite');
$pdo = $db->pdo;
$secret = new SecretService($pdo);
$batchSync = new BatchSyncService($pdo);

$jobStmt = $pdo->prepare('SELECT * FROM sync_jobs WHERE id=?');
$jobStmt->execute([$jobId]);
$job = $jobStmt->fetch();
if (!$job) exit(2);
if ((int)($job['cancel_requested'] ?? 0) === 1 || !in_array((string)$job['status'], ['queued','running'], true)) {
    exit(0);
}

// Never run two workers against the same instance.
$other = $pdo->prepare("SELECT id FROM sync_jobs WHERE instance_id=? AND id<>? AND status='running' LIMIT 1");
$other->execute([(int)$job['instance_id'], $jobId]);
if ($other->fetchColumn()) {
    $pdo->prepare("UPDATE sync_jobs SET status='failed',message='Another sync is already running for this instance',finished_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('queued','running')")
        ->execute([$jobId]);
    exit(0);
}

$instanceStmt = $pdo->prepare('SELECT * FROM instances WHERE id=?');
$instanceStmt->execute([(int)$job['instance_id']]);
$instance = $instanceStmt->fetch();
if (!$instance) exit(3);
$instance['api_key'] = $secret->reveal((string)$instance['api_key']);

$progressDir = rtrim($dataDir, '/') . '/progress';
if (!is_dir($progressDir)) mkdir($progressDir, 0775, true);
$progressFile = $progressDir . '/sync-' . $jobId . '.json';

$writeProgress = static function(array $payload) use ($progressFile): void {
    $tmp = $progressFile . '.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES));
    @rename($tmp, $progressFile);
};

$start = $pdo->prepare("UPDATE sync_jobs
    SET status='running',
        started_at=COALESCE(started_at,CURRENT_TIMESTAMP),
        heartbeat_at=CURRENT_TIMESTAMP,
        message='Starting sync'
    WHERE id=? AND status IN ('queued','running') AND cancel_requested=0");
$start->execute([$jobId]);
if ($start->rowCount() === 0) exit(0); // cancelled or recovered before it started

// Keeps heartbeat_at fresh during long transfers. Returns false once the job was
// cancelled or recovered as stale elsewhere, which stops this worker.
$batchSync->setHeartbeat(static function () use ($pdo, $jobId): bool {
    $beat = $pdo->prepare("UPDATE sync_jobs SET heartbeat_at=CURRENT_TIMESTAMP WHERE id=? AND status='running' AND cancel_requested=0");
    $beat->execute([$jobId]);
    return $beat->rowCount() > 0;
});
$writeProgress(['status'=>'running','current'=>0,'total'=>0,'percent'=>0,'title'=>'Starting sync','message'=>'Connecting to Arr instance...']);

try {
    $result = $batchSync->syncInstance($instance, function(int $current, int $total, string $title) use ($writeProgress, $jobId, $pdo): void {
        $state = $pdo->prepare('SELECT cancel_requested,status FROM sync_jobs WHERE id=?');
        $state->execute([$jobId]);
        $row = $state->fetch();
        if (!$row || (int)$row['cancel_requested'] === 1 || (string)$row['status'] !== 'running') {
            throw new RuntimeException(BatchSyncService::CANCELLED);
        }

        $percent = $total > 0 ? min(100, round(($current / $total) * 100, 1)) : 0;
        $pdo->prepare("UPDATE sync_jobs SET current_item=?,total_items=?,current_title=?,message=?,heartbeat_at=CURRENT_TIMESTAMP WHERE id=? AND status='running'")
            ->execute([$current,$total,$title,$total > 0 ? "{$current} / {$total}" : "{$current} items",$jobId]);
        $writeProgress([
            'status'=>'running',
            'current'=>$current,
            'total'=>$total,
            'percent'=>$percent,
            'title'=>$title,
            'message'=>$total > 0 ? "{$current} / {$total}" : "{$current} items",
        ]);
    });

    $count = (int)($result['count'] ?? 0);
    // Guarded: a job already recovered as stale or cancelled must not flip to completed.
    $pdo->prepare("UPDATE sync_jobs SET status='completed',current_item=?,total_items=?,current_title='Completed',message=?,heartbeat_at=CURRENT_TIMESTAMP,finished_at=CURRENT_TIMESTAMP WHERE id=? AND status='running'")
        ->execute([$count,$count,(string)($result['message'] ?? 'Sync completed'),$jobId]);
    $writeProgress(['status'=>'completed','current'=>$count,'total'=>$count,'percent'=>100,'title'=>'Completed','message'=>(string)($result['message'] ?? 'Sync completed')]);
} catch (Throwable $e) {
    if ($e->getMessage() === BatchSyncService::CANCELLED) {
        $pdo->prepare("UPDATE sync_jobs SET status='failed',message='Cancelled by administrator',heartbeat_at=CURRENT_TIMESTAMP,finished_at=CURRENT_TIMESTAMP WHERE id=? AND status='running'")->execute([$jobId]);
        $writeProgress(['status'=>'cancelled','current'=>0,'total'=>0,'percent'=>0,'title'=>'Sync cancelled','message'=>'Cancelled by administrator']);
        exit(0);
    }

    $pdo->prepare("UPDATE sync_jobs SET status='failed',message=?,heartbeat_at=CURRENT_TIMESTAMP,finished_at=CURRENT_TIMESTAMP WHERE id=? AND status='running'")->execute([$e->getMessage(),$jobId]);
    $writeProgress(['status'=>'failed','current'=>0,'total'=>0,'percent'=>0,'title'=>'Sync failed','message'=>$e->getMessage()]);
    exit(4);
}
