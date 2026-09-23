<?php
declare(strict_types=1);

final class SyncJobService
{
    public const QUEUED_STALE_MINUTES = 3;
    public const RUNNING_STALE_MINUTES = 5;

    public static function recoverStale(PDO $pdo): int
    {
        $count = 0;

        $queued = $pdo->prepare(
            "UPDATE sync_jobs
             SET status='failed',
                 message='Sync worker did not start; stale queued job recovered',
                 finished_at=CURRENT_TIMESTAMP
             WHERE status='queued'
               AND datetime(created_at) < datetime('now', ?)"
        );
        $queued->execute(['-' . self::QUEUED_STALE_MINUTES . ' minutes']);
        $count += $queued->rowCount();

        $running = $pdo->prepare(
            "UPDATE sync_jobs
             SET status='failed',
                 message=CASE
                    WHEN cancel_requested=1 THEN 'Cancelled by administrator'
                    ELSE 'Sync worker stopped responding; stale running job recovered'
                 END,
                 finished_at=CURRENT_TIMESTAMP
             WHERE status='running'
               AND datetime(COALESCE(heartbeat_at,started_at,created_at)) < datetime('now', ?)"
        );
        $running->execute(['-' . self::RUNNING_STALE_MINUTES . ' minutes']);
        $count += $running->rowCount();

        return $count;
    }

    public static function isActive(array $job): bool
    {
        return in_array((string)($job['status'] ?? ''), ['queued','running'], true);
    }
}
