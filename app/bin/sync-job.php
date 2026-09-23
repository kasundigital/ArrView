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

$pdo->prepare("UPDATE sync_jobs SET status='running',started_at=CURRENT_TIMESTAMP,message='Starting sync' WHERE id=?")->execute([$jobId]);
$writeProgress(['status'=>'running','current'=>0,'total'=>0,'percent'=>0,'title'=>'Starting sync','message'=>'Connecting to Arr instance...']);

try {
    $result = $batchSync->syncInstance($instance, function(int $current, int $total, string $title) use ($writeProgress, $jobId, $pdo): void {
        $cancel = $pdo->prepare('SELECT cancel_requested FROM sync_jobs WHERE id=?');
        $cancel->execute([$jobId]);
        if ((int)$cancel->fetchColumn() === 1) {
            throw new RuntimeException('__ARRVIEW_SYNC_CANCELLED__');
        }

        $percent = $total > 0 ? min(100, round(($current / $total) * 100, 1)) : 0;
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
    $pdo->prepare("UPDATE sync_jobs SET status='completed',current_item=?,total_items=?,current_title='Completed',message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$count,$count,(string)($result['message'] ?? 'Sync completed'),$jobId]);
    $writeProgress(['status'=>'completed','current'=>$count,'total'=>$count,'percent'=>100,'title'=>'Completed','message'=>(string)($result['message'] ?? 'Sync completed')]);
} catch (Throwable $e) {
    if ($e->getMessage() === '__ARRVIEW_SYNC_CANCELLED__') {
        $pdo->prepare("UPDATE sync_jobs SET status='failed',message='Cancelled by administrator',finished_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$jobId]);
        $writeProgress(['status'=>'cancelled','current'=>0,'total'=>0,'percent'=>0,'title'=>'Sync cancelled','message'=>'Cancelled by administrator']);
        exit(0);
    }

    $pdo->prepare("UPDATE sync_jobs SET status='failed',message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$e->getMessage(),$jobId]);
    $writeProgress(['status'=>'failed','current'=>0,'total'=>0,'percent'=>0,'title'=>'Sync failed','message'=>$e->getMessage()]);
    exit(4);
}
