<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ArrService.php';
require_once __DIR__ . '/src/BatchSyncService.php';
require_once __DIR__ . '/src/Auth.php';

$dataDir = getenv('ARRVIEW_DATA') ?: (__DIR__ . '/data');
$db = new Database(rtrim($dataDir, '/') . '/arrview.sqlite');
$pdo = $db->pdo;
$arr = new ArrService($pdo);
$batchSync = new BatchSyncService($pdo);
$auth = new Auth($pdo);

// Lightweight self-healing reconciliation: normal pages read SQLite only.
// At most once every 12 hours per instance, queue a background full sync
// to catch any changes that may have been missed by webhooks.
if (PHP_SAPI !== 'cli') {
    try {
        $stale = $pdo->query("SELECT id FROM instances
            WHERE enabled=1
              AND (last_full_sync_at IS NULL OR datetime(last_full_sync_at) < datetime('now','-12 hours'))")->fetchAll();

        foreach ($stale as $row) {
            $instanceId = (int)$row['id'];
            $active = $pdo->prepare("SELECT id FROM sync_jobs
                WHERE instance_id=? AND status IN ('queued','running')
                ORDER BY id DESC LIMIT 1");
            $active->execute([$instanceId]);
            if ($active->fetchColumn()) continue;

            $pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message)
                VALUES(?, 'queued', 'Automatic reconciliation queued')")->execute([$instanceId]);
            $jobId = (int)$pdo->lastInsertId();

            $cmd = escapeshellarg(PHP_BINARY) . ' ' .
                escapeshellarg(__DIR__ . '/bin/sync-job.php') . ' ' .
                $jobId . ' > /tmp/arrview-sync-' . $jobId . '.log 2>&1 &';
            exec($cmd);
        }
    } catch (Throwable) {
        // Reconciliation must never block the UI.
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}


function csrf_field(): string
{
    global $auth;
    return '<input type="hidden" name="csrf_token" value="' . e($auth->csrfToken()) . '">';
}
