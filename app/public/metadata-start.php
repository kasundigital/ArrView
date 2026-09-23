<?php
declare(strict_types=1);

ini_set('display_errors','0');
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/src/MetadataJobService.php';

header('Content-Type: application/json; charset=utf-8');

function metadata_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $auth->requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        metadata_json(['ok'=>false,'message'=>'POST required'], 405);
    }

    $auth->requireCsrf($_POST['csrf_token'] ?? null);
    MetadataJobService::recoverStale($pdo);

    $active = $pdo->query("SELECT id FROM metadata_jobs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($active) {
        metadata_json(['ok'=>true,'job_id'=>(int)$active,'existing'=>true]);
    }

    $pdo->exec("INSERT INTO metadata_jobs(status,message,source) VALUES('queued','Metadata enrichment queued','manual')");
    $jobId = (int)$pdo->lastInsertId();

    if (!function_exists('exec')) {
        $pdo->prepare("UPDATE metadata_jobs SET status='failed',message='PHP exec() is unavailable; cannot launch background metadata worker',finished_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$jobId]);
        metadata_json(['ok'=>false,'message'=>'This PHP runtime cannot launch the background metadata worker because exec() is unavailable.'], 500);
    }

    $log = '/tmp/arrview-metadata-' . $jobId . '.log';
    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/metadata-job.php')
        . ' ' . $jobId
        . ' > ' . escapeshellarg($log) . ' 2>&1 &';

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        $pdo->prepare("UPDATE metadata_jobs SET status='failed',message='Could not launch background metadata worker',finished_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$jobId]);
        metadata_json(['ok'=>false,'message'=>'Could not launch background metadata worker. Check container logs.'], 500);
    }

    metadata_json(['ok'=>true,'job_id'=>$jobId]);
} catch (Throwable $e) {
    error_log('ArrView metadata-start: ' . $e->getMessage());
    metadata_json(['ok'=>false,'message'=>$e->getMessage()], 500);
}
