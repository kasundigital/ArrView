<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'POST required']);
    exit;
}

$instanceId = (int)($_GET['instance'] ?? 0);
$token = (string)($_GET['token'] ?? '');

$stmt = $pdo->prepare('SELECT * FROM instances WHERE id=? AND enabled=1 LIMIT 1');
$stmt->execute([$instanceId]);
$instance = $stmt->fetch();

if (!$instance || empty($instance['webhook_token']) || !hash_equals((string)$instance['webhook_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'Invalid webhook credentials']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>'Invalid JSON payload']);
    exit;
}

$eventType = (string)($event['eventType'] ?? $event['event_type'] ?? 'Unknown');
if (strcasecmp($eventType, 'Test') === 0) {
    echo json_encode(['ok'=>true,'message'=>'ArrView webhook connected','instance'=>$instance['name']]);
    exit;
}

$dataDir = getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data';
$queueDir = rtrim($dataDir, '/') . '/webhooks';
if (!is_dir($queueDir) && !mkdir($queueDir, 0775, true) && !is_dir($queueDir)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Could not create webhook queue']);
    exit;
}

$jobKey = bin2hex(random_bytes(8));
$payloadFile = $queueDir . '/event-' . $jobKey . '.json';
file_put_contents($payloadFile, json_encode([
    'instance_id'=>$instanceId,
    'event'=>$event,
    'received_at'=>gmdate('c'),
], JSON_UNESCAPED_SLASHES));

$logFile = '/tmp/arrview-webhook-' . $jobKey . '.log';
$cmd = escapeshellarg(PHP_BINARY) . ' ' .
    escapeshellarg(dirname(__DIR__) . '/bin/webhook-job.php') . ' ' .
    escapeshellarg($payloadFile) . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
exec($cmd);

http_response_code(202);
echo json_encode([
    'ok'=>true,
    'queued'=>true,
    'event_type'=>$eventType,
    'message'=>'ArrView update queued',
], JSON_UNESCAPED_SLASHES);
