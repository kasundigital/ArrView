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
// Prefer the X-ArrView-Token header: query strings end up in proxy/access logs.
// The ?token= form stays supported so existing Radarr/Sonarr webhooks keep working.
$token = (string)($_SERVER['HTTP_X_ARRVIEW_TOKEN'] ?? '');
if ($token === '') $token = (string)($_GET['token'] ?? '');

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

// Timestamp prefix keeps the queue in arrival order for the drain worker.
$jobKey = sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(4));
$payloadFile = $queueDir . '/event-' . $jobKey . '.json';
$tmpFile = $payloadFile . '.tmp';
file_put_contents($tmpFile, json_encode([
    'instance_id'=>$instanceId,
    'event'=>$event,
    'received_at'=>gmdate('c'),
], JSON_UNESCAPED_SLASHES));
rename($tmpFile, $payloadFile); // never let the worker read a half-written file

// Single drain worker: if one is already running this exits immediately.
$cmd = escapeshellarg(PHP_BINARY) . ' ' .
    escapeshellarg(dirname(__DIR__) . '/bin/webhook-job.php') .
    ' >> ' . escapeshellarg('/tmp/arrview-webhook.log') . ' 2>&1 &';
exec($cmd);

http_response_code(202);
echo json_encode([
    'ok'=>true,
    'queued'=>true,
    'event_type'=>$eventType,
    'message'=>'ArrView update queued',
], JSON_UNESCAPED_SLASHES);
