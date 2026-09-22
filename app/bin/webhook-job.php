<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/BatchSyncService.php';
require_once dirname(__DIR__) . '/src/MetadataService.php';

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

    // Refresh TMDB metadata only for the affected movie/series. Failures here must
    // never cause the Arr webhook sync itself to fail.
    try {
        $metadata = new MetadataService($pdo);
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
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(4);
} finally {
    @unlink($payloadFile);
}
