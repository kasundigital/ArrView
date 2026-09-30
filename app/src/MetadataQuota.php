<?php

declare(strict_types=1);

/**
 * Rate limits for the shared (public) metadata endpoint.
 *
 * Limits are enforced per client IP. The X-ArrView-Install header is chosen by the
 * caller, so it can never be used as the key for a limit: rotating it would give
 * unlimited quota.
 */
final class MetadataQuota
{
    public function __construct(
        private PDO $pdo,
        private int $monthlyLimit,
        private int $hourlyLimit,
    ) {
    }

    public static function fromEnv(PDO $pdo): self
    {
        $monthly = max(50, (int)(getenv('ARRVIEW_FREE_METADATA_MONTHLY_LIMIT') ?: 1000));
        $hourly = max(10, (int)(getenv('ARRVIEW_FREE_METADATA_HOURLY_LIMIT') ?: 120));
        return new self($pdo, $monthly, $hourly);
    }

    /**
     * Counts the request against the client's limits.
     *
     * @return array{allowed:bool,used:int,limit:int,remaining:int,window:string}
     */
    public function consume(string $clientIp): array
    {
        $key = hash('sha256', 'ip:' . $clientIp);
        $month = gmdate('Y-m');
        $hour = 'h:' . gmdate('Y-m-d\TH');

        $monthUsed = $this->count($key, $month);
        if ($monthUsed >= $this->monthlyLimit) {
            return ['allowed'=>false,'used'=>$monthUsed,'limit'=>$this->monthlyLimit,'remaining'=>0,'window'=>'month'];
        }
        $hourUsed = $this->count($key, $hour);
        if ($hourUsed >= $this->hourlyLimit) {
            return ['allowed'=>false,'used'=>$hourUsed,'limit'=>$this->hourlyLimit,'remaining'=>0,'window'=>'hour'];
        }

        // Count the attempt before calling TMDB: failed upstream calls still cost quota.
        $this->increment($key, $month);
        $this->increment($key, $hour);
        $this->pruneOldHourlyRows();

        $monthUsed++;
        return [
            'allowed'=>true,
            'used'=>$monthUsed,
            'limit'=>$this->monthlyLimit,
            'remaining'=>max(0, $this->monthlyLimit - $monthUsed),
            'window'=>'month',
        ];
    }

    private function count(string $key, string $window): int
    {
        $stmt = $this->pdo->prepare('SELECT request_count FROM shared_tmdb_usage WHERE usage_key=? AND usage_month=? LIMIT 1');
        $stmt->execute([$key, $window]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function increment(string $key, string $window): void
    {
        $this->pdo->prepare(
            'INSERT INTO shared_tmdb_usage(usage_key,usage_month,request_count,updated_at) VALUES(?,?,1,CURRENT_TIMESTAMP)
             ON CONFLICT(usage_key,usage_month) DO UPDATE SET request_count=request_count+1,updated_at=CURRENT_TIMESTAMP'
        )->execute([$key, $window]);
    }

    private function pruneOldHourlyRows(): void
    {
        if (random_int(1, 50) !== 1) return;
        $this->pdo->exec("DELETE FROM shared_tmdb_usage WHERE usage_month LIKE 'h:%' AND datetime(updated_at) < datetime('now','-2 hours')");
    }
}
