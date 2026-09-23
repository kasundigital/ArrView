<?php

declare(strict_types=1);

final class BackupService
{
    public function __construct(private PDO $pdo, private string $databasePath)
    {
    }

    public function create(string $destination): void
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        if (is_file($destination)) @unlink($destination);

        try {
            $quoted = $this->pdo->quote($destination);
            $this->pdo->exec('VACUUM INTO ' . $quoted);
        } catch (Throwable) {
            $this->pdo->exec('PRAGMA wal_checkpoint(FULL)');
            if (!copy($this->databasePath, $destination)) {
                throw new RuntimeException('Could not create ArrView backup.');
            }
        }

        $this->validate($destination);
    }

    public function validate(string $path): void
    {
        if (!is_file($path) || filesize($path) < 100) {
            throw new RuntimeException('Backup file is missing or invalid.');
        }
        $check = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $integrity = (string)$check->query('PRAGMA integrity_check')->fetchColumn();
        if (strtolower($integrity) !== 'ok') {
            throw new RuntimeException('Backup integrity check failed: ' . $integrity);
        }
        $tables = array_column($check->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
        foreach (['users','instances','movies','series','episodes','app_settings'] as $required) {
            if (!in_array($required, $tables, true)) {
                throw new RuntimeException('Backup is not a valid ArrView database. Missing table: ' . $required);
            }
        }
    }

    public function restore(string $path): array
    {
        $this->validate($path);

        $safety = dirname($this->databasePath) . '/pre-restore-' . gmdate('Ymd-His') . '.sqlite';
        $this->create($safety);

        $attach = $this->pdo->quote($path);
        $this->pdo->exec('PRAGMA foreign_keys=OFF');
        $this->pdo->exec('ATTACH DATABASE ' . $attach . ' AS restoredb');

        $tables = [
            'episodes','movies','series','media_metadata','diagnostic_cache',
            'metadata_jobs','shared_tmdb_cache','shared_tmdb_usage','sync_jobs',
            'vod_mappings','login_attempts','instances','users','app_settings'
        ];
        $restored = [];

        try {
            $this->pdo->beginTransaction();
            foreach ($tables as $table) {
                if (!$this->tableExists('restoredb', $table) || !$this->tableExists('main', $table)) continue;

                $mainCols = $this->columns('main', $table);
                $backupCols = $this->columns('restoredb', $table);
                $cols = array_values(array_intersect($mainCols, $backupCols));
                if (!$cols) continue;

                $quotedCols = implode(',', array_map(fn($c) => '"' . str_replace('"','""',$c) . '"', $cols));
                $this->pdo->exec('DELETE FROM main."' . str_replace('"','""',$table) . '"');
                $this->pdo->exec(
                    'INSERT INTO main."' . str_replace('"','""',$table) . '" (' . $quotedCols . ') ' .
                    'SELECT ' . $quotedCols . ' FROM restoredb."' . str_replace('"','""',$table) . '"'
                );
                $restored[] = $table;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            try { $this->pdo->exec('DETACH DATABASE restoredb'); } catch (Throwable) {}
            $this->pdo->exec('PRAGMA foreign_keys=ON');
        }

        return ['tables'=>$restored,'safety_backup'=>$safety];
    }

    private function tableExists(string $db, string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$db}.sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function columns(string $db, string $table): array
    {
        $rows = $this->pdo->query('PRAGMA ' . $db . '.table_info("' . str_replace('"','""',$table) . '")')->fetchAll();
        return array_values(array_filter(array_map(fn($row) => $row['name'] ?? null, $rows)));
    }
}
