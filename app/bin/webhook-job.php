<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/BatchSyncService.php';

$payloadFile = (string)($argv[1] ?? '');
if ($payloadFile === '' || !is_file($payloadFile)) exit(1);

try {
    $payload = json_decode((string)file_get_contents($payloadFile), true, 512, JSON_THROW_ON_ERROR);
    $instanceId = (int)($payload['instance_id'] ?? 0);
    $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
    if ($instanceId < 1 || !$event) exit(2);

    $dataDir = getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data';
    $db = new Database(rtrim($dataDir, '/') . '/arrview.sqlite');
    $pdo = $db->pdo;

    $stmt = $pdo->prepare('SELECT * FROM instances WHERE id=? AND enabled=1 LIMIT 1');
    $stmt->execute([$instanceId]);
    $instance = $stmt->fetch();
    if (!$instance) exit(3);

    $sync = new BatchSyncService($pdo);
    $result = $sync->syncWebhookEvent($instance, $event);
    echo ($result['message'] ?? 'Webhook update completed') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(4);
} finally {
    @unlink($payloadFile);
}
