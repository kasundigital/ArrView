<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireAdmin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'POST required']);
    exit;
}

try { $auth->requireCsrf($_POST['csrf_token'] ?? null); } catch (Throwable $e) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>$e->getMessage()]); exit; }

$instanceId = (int)($_POST['instance_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM instances WHERE id=?');
$stmt->execute([$instanceId]);
$instance = $stmt->fetch();
if (!$instance) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'message'=>'Instance not found']);
    exit;
}

$active = $pdo->prepare("SELECT id FROM sync_jobs WHERE instance_id=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
$active->execute([$instanceId]);
$existing = $active->fetchColumn();
if ($existing) {
    echo json_encode(['ok'=>true,'job_id'=>(int)$existing,'existing'=>true]);
    exit;
}

$pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message) VALUES(?, 'queued', 'Queued')")->execute([$instanceId]);
$jobId = (int)$pdo->lastInsertId();

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/sync-job.php') . ' ' . $jobId . ' > /tmp/arrview-sync-' . $jobId . '.log 2>&1 &';
exec($cmd);

echo json_encode(['ok'=>true,'job_id'=>$jobId]);
