<?php
declare(strict_types=1);

/**
 * Drains the webhook event queue (data/webhooks/event-*.json) one event at a time.
 *
 * webhook.php queues every event and spawns this worker. Only one drain worker can
 * hold the lock; extra spawns exit immediately, so a burst of Sonarr events (a season
 * pack import) no longer creates dozens of concurrent SQLite writers.
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/BatchSyncService.php';
require_once dirname(__DIR__) . '/src/MetadataService.php';
require_once dirname(__DIR__) . '/src/SecretService.php';
require_once dirname(__DIR__) . '/version.php';

$dataDir = rtrim(getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data', '/');
$queueDir = $dataDir . '/webhooks';
if (!is_dir($queueDir) && !@mkdir($queueDir, 0775, true) && !is_dir($queueDir)) exit(1);

$lockHandle = fopen($queueDir . '/.drain.lock', 'c');
if ($lockHandle === false) exit(1);

$pending = static fn(): array => glob($queueDir . '/event-*.json') ?: [];

$pdo = null;
$secret = null;
$sync = null;

while (true) {
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) exit(0); // another drain worker owns the queue

    while ($files = $pending()) {
        sort($files, SORT_STRING); // names start with a timestamp, so this is arrival order
        foreach ($files as $payloadFile) {
            if ($pdo === null) {
                $db = new Database($dataDir . '/arrview.sqlite');
                $pdo = $db->pdo;
                $secret = new SecretService($pdo);
                $sync = new BatchSyncService($pdo);
            }
            processWebhookEvent($payloadFile, $pdo, $secret, $sync);
        }
    }

    flock($lockHandle, LOCK_UN);
    // An event may have been queued after the last scan while we still held the lock;
    // its own worker exited because the lock was taken, so check once more.
    if (!$pending()) break;
}

function processWebhookEvent(string $payloadFile, PDO $pdo, SecretService $secret, BatchSyncService $sync): void
{
    try {
        $raw = @file_get_contents($payloadFile);
        if ($raw === false) return;
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $instanceId = (int)($payload['instance_id'] ?? 0);
        $event = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        if ($instanceId < 1 || !$event) return;

        $stmt = $pdo->prepare('SELECT * FROM instances WHERE id=? AND enabled=1 LIMIT 1');
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch();
        if (!$instance) return;

        $instance['api_key'] = $secret->reveal((string)$instance['api_key']);
        $result = $sync->syncWebhookEvent($instance, $event);

        // Refresh TMDB metadata only for the affected movie/series. Failures here must
        // never cause the Arr webhook sync itself to fail.
        try {
            $metadata = new MetadataService($pdo, $secret);
            if (($instance['type'] ?? '') === 'radarr') {
                $remoteId = (int)($event['movie']['id'] ?? $event['movieId'] ?? 0);
                if ($remoteId > 0) {
                    $q = $pdo->prepare('SELECT tmdb_id FROM movies WHERE instance_id=? AND remote_id=? LIMIT 1');
                    $q->execute([(int)$instance['id'], $remoteId]);
                    $tmdbId = (int)($q->fetchColumn() ?: 0);
                    if ($tmdbId > 0) $metadata->enrich('movie', $tmdbId, false);
                }
            } else {
                $remoteId = (int)($event['series']['id'] ?? $event['seriesId'] ?? 0);
                if ($remoteId > 0) {
                    $q = $pdo->prepare('SELECT tmdb_id FROM series WHERE instance_id=? AND remote_id=? LIMIT 1');
                    $q->execute([(int)$instance['id'], $remoteId]);
                    $tmdbId = (int)($q->fetchColumn() ?: 0);
                    if ($tmdbId > 0) $metadata->enrich('series', $tmdbId, false);
                }
            }
        } catch (Throwable $metadataError) {
            fwrite(STDERR, 'Metadata refresh skipped: ' . $metadataError->getMessage() . PHP_EOL);
        }

        echo ($result['message'] ?? 'Webhook update completed') . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, basename($payloadFile) . ': ' . $e->getMessage() . PHP_EOL);
    } finally {
        @unlink($payloadFile);
    }
}
