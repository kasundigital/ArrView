<?php

declare(strict_types=1);

final class Database
{
    public PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Let short-lived background writes finish instead of failing a web request
        // immediately with "database is locked".
        $this->pdo->exec('PRAGMA busy_timeout=10000;');
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA foreign_keys=ON;');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'viewer' CHECK(role IN ('admin','viewer')),
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS instances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    type TEXT NOT NULL CHECK(type IN ('radarr','sonarr')),
    url TEXT NOT NULL,
    api_key TEXT NOT NULL,
    webhook_token TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    last_sync_at TEXT NULL,
    last_full_sync_at TEXT NULL,
    last_status TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS movies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    remote_id INTEGER NOT NULL,
    tmdb_id INTEGER NULL,
    imdb_id TEXT NULL,
    minimum_availability TEXT NULL,
    in_cinemas TEXT NULL,
    digital_release TEXT NULL,
    physical_release TEXT NULL,
    availability_date TEXT NULL,
    title TEXT NOT NULL,
    year INTEGER NULL,
    poster_url TEXT NULL,
    has_file INTEGER NOT NULL DEFAULT 0,
    monitored INTEGER NOT NULL DEFAULT 0,
    quality TEXT NULL,
    audio_languages TEXT NULL,
    aired_missing_count INTEGER NOT NULL DEFAULT 0,
    future_missing_count INTEGER NOT NULL DEFAULT 0,
    missing_audio_count INTEGER NOT NULL DEFAULT 0,
    language_inconsistent INTEGER NOT NULL DEFAULT 0,
    path TEXT NULL,
    file_size INTEGER NULL,
    movie_file_json TEXT NULL,
    details_json TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(instance_id, remote_id),
    FOREIGN KEY(instance_id) REFERENCES instances(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS series (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    remote_id INTEGER NOT NULL,
    tmdb_id INTEGER NULL,
    imdb_id TEXT NULL,
    title TEXT NOT NULL,
    year INTEGER NULL,
    poster_url TEXT NULL,
    monitored INTEGER NOT NULL DEFAULT 0,
    episode_count INTEGER NOT NULL DEFAULT 0,
    episode_file_count INTEGER NOT NULL DEFAULT 0,
    audio_languages TEXT NULL,
    aired_missing_count INTEGER NOT NULL DEFAULT 0,
    future_missing_count INTEGER NOT NULL DEFAULT 0,
    missing_audio_count INTEGER NOT NULL DEFAULT 0,
    path TEXT NULL,
    details_json TEXT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(instance_id, remote_id),
    FOREIGN KEY(instance_id) REFERENCES instances(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_key TEXT PRIMARY KEY,
    failures INTEGER NOT NULL DEFAULT 0,
    first_failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until TEXT NULL
);

CREATE TABLE IF NOT EXISTS vod_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NULL,
    local_root TEXT NOT NULL,
    public_base_url TEXT NOT NULL,
    priority INTEGER NOT NULL DEFAULT 100,
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(instance_id) REFERENCES instances(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS episodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    series_id INTEGER NOT NULL,
    series_remote_id INTEGER NOT NULL,
    remote_id INTEGER NOT NULL,
    season_number INTEGER NOT NULL DEFAULT 0,
    episode_number INTEGER NOT NULL DEFAULT 0,
    absolute_episode_number INTEGER NULL,
    title TEXT NOT NULL,
    air_date_utc TEXT NULL,
    monitored INTEGER NOT NULL DEFAULT 0,
    has_file INTEGER NOT NULL DEFAULT 0,
    episode_file_id INTEGER NULL,
    relative_path TEXT NULL,
    file_path TEXT NULL,
    file_size INTEGER NULL,
    quality TEXT NULL,
    audio_languages TEXT NULL,
    date_added TEXT NULL,
    release_group TEXT NULL,
    scene_name TEXT NULL,
    video_codec TEXT NULL,
    video_resolution TEXT NULL,
    audio_codec TEXT NULL,
    audio_channels REAL NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(instance_id, remote_id),
    FOREIGN KEY(instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY(series_id) REFERENCES series(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS diagnostic_cache (
    cache_key TEXT PRIMARY KEY,
    payload_json TEXT NOT NULL,
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS media_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    media_type TEXT NOT NULL CHECK(media_type IN ('movie','series')),
    tmdb_id INTEGER NOT NULL,
    imdb_id TEXT NULL,
    title TEXT NULL,
    original_title TEXT NULL,
    overview TEXT NULL,
    runtime INTEGER NULL,
    status TEXT NULL,
    release_date TEXT NULL,
    genres_json TEXT NULL,
    companies_json TEXT NULL,
    countries_json TEXT NULL,
    original_language TEXT NULL,
    vote_average REAL NULL,
    vote_count INTEGER NULL,
    poster_path TEXT NULL,
    backdrop_path TEXT NULL,
    homepage TEXT NULL,
    payload_json TEXT NULL,
    source TEXT NOT NULL DEFAULT 'unknown',
    fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(media_type, tmdb_id)
);

CREATE TABLE IF NOT EXISTS metadata_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','completed','failed')),
    current_item INTEGER NOT NULL DEFAULT 0,
    total_items INTEGER NOT NULL DEFAULT 0,
    current_title TEXT NULL,
    message TEXT NULL,
    source TEXT NOT NULL DEFAULT 'manual',
    cancel_requested INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shared_tmdb_cache (
    cache_key TEXT PRIMARY KEY,
    payload_json TEXT NOT NULL,
    fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS shared_tmdb_usage (
    usage_key TEXT NOT NULL,
    usage_month TEXT NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(usage_key, usage_month)
);

CREATE TABLE IF NOT EXISTS sync_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','completed','failed')),
    current_item INTEGER NOT NULL DEFAULT 0,
    total_items INTEGER NOT NULL DEFAULT 0,
    current_title TEXT NULL,
    message TEXT NULL,
    source TEXT NOT NULL DEFAULT 'manual',
    cancel_requested INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NULL,
    heartbeat_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(instance_id) REFERENCES instances(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_movies_title ON movies(title);
CREATE INDEX IF NOT EXISTS idx_series_title ON series(title);
CREATE INDEX IF NOT EXISTS idx_movies_instance ON movies(instance_id);
CREATE INDEX IF NOT EXISTS idx_series_instance ON series(instance_id);
CREATE INDEX IF NOT EXISTS idx_sync_jobs_instance ON sync_jobs(instance_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_episodes_series ON episodes(series_id, season_number, episode_number);
CREATE INDEX IF NOT EXISTS idx_episodes_instance_remote ON episodes(instance_id, remote_id);
CREATE INDEX IF NOT EXISTS idx_episodes_airdate ON episodes(air_date_utc);
CREATE INDEX IF NOT EXISTS idx_episodes_missing ON episodes(has_file, monitored);
CREATE INDEX IF NOT EXISTS idx_media_metadata_tmdb ON media_metadata(media_type, tmdb_id);
CREATE INDEX IF NOT EXISTS idx_metadata_jobs_created ON metadata_jobs(id DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_locked ON login_attempts(locked_until);
CREATE INDEX IF NOT EXISTS idx_vod_mappings_instance ON vod_mappings(instance_id, enabled, priority);
SQL);

        // Lightweight migration for installations created before series audio tracking.
        $seriesColumns = $this->pdo->query('PRAGMA table_info(series)')->fetchAll();
        $hasAudioLanguages = false;
        foreach ($seriesColumns as $column) {
            if (($column['name'] ?? '') === 'audio_languages') {
                $hasAudioLanguages = true;
                break;
            }
        }
        if (!$hasAudioLanguages) {
            $this->pdo->exec('ALTER TABLE series ADD COLUMN audio_languages TEXT NULL');
        }

        $instanceColumnNames = array_column($this->pdo->query('PRAGMA table_info(instances)')->fetchAll(), 'name');
        if (!in_array('webhook_token', $instanceColumnNames, true)) {
            $this->pdo->exec('ALTER TABLE instances ADD COLUMN webhook_token TEXT NULL');
        }
        if (!in_array('last_full_sync_at', $instanceColumnNames, true)) {
            $this->pdo->exec('ALTER TABLE instances ADD COLUMN last_full_sync_at TEXT NULL');
        }
        $tokenRows = $this->pdo->query("SELECT id FROM instances WHERE webhook_token IS NULL OR TRIM(webhook_token)=''")->fetchAll();
        $setToken = $this->pdo->prepare('UPDATE instances SET webhook_token=? WHERE id=?');
        foreach ($tokenRows as $row) {
            $setToken->execute([bin2hex(random_bytes(24)), (int)$row['id']]);
        }

        $installId = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='installation_id' LIMIT 1");
        $installId->execute();
        if (!(string)$installId->fetchColumn()) {
            $this->pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?)')
                ->execute(['installation_id', bin2hex(random_bytes(16))]);
        }

        // TMDB metadata must always have a safe default. Existing installations
        // upgrading from versions before metadata support start in free mode.
        $metadataModeStmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='metadata_mode' LIMIT 1");
        $metadataModeStmt->execute();
        $metadataMode = (string)($metadataModeStmt->fetchColumn() ?: '');
        if (!in_array($metadataMode, ['free','personal'], true)) {
            $this->pdo->prepare(
                'INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value'
            )->execute(['metadata_mode', 'free']);
        }

        $movieColumnNames = array_column($this->pdo->query('PRAGMA table_info(movies)')->fetchAll(), 'name');
        if (!in_array('details_json', $movieColumnNames, true)) {
            $this->pdo->exec('ALTER TABLE movies ADD COLUMN details_json TEXT NULL');
        }
        if (!in_array('movie_file_json', $movieColumnNames, true)) {
            $this->pdo->exec('ALTER TABLE movies ADD COLUMN movie_file_json TEXT NULL');
        }
        if (!in_array('tmdb_id', $movieColumnNames, true)) {
            $this->pdo->exec('ALTER TABLE movies ADD COLUMN tmdb_id INTEGER NULL');
        }
        if (!in_array('imdb_id', $movieColumnNames, true)) {
            $this->pdo->exec('ALTER TABLE movies ADD COLUMN imdb_id TEXT NULL');
        }
        foreach ([
            'minimum_availability' => 'TEXT NULL',
            'in_cinemas' => 'TEXT NULL',
            'digital_release' => 'TEXT NULL',
            'physical_release' => 'TEXT NULL',
            'availability_date' => 'TEXT NULL',
        ] as $name => $definition) {
            if (!in_array($name, $movieColumnNames, true)) {
                $this->pdo->exec("ALTER TABLE movies ADD COLUMN {$name} {$definition}");
            }
        }

        $seriesColumnNames = array_column($this->pdo->query('PRAGMA table_info(series)')->fetchAll(), 'name');
        foreach ([
            'aired_missing_count' => 'INTEGER NOT NULL DEFAULT 0',
            'future_missing_count' => 'INTEGER NOT NULL DEFAULT 0',
            'missing_audio_count' => 'INTEGER NOT NULL DEFAULT 0',
            'language_inconsistent' => 'INTEGER NOT NULL DEFAULT 0',
            'details_json' => 'TEXT NULL',
            'tmdb_id' => 'INTEGER NULL',
            'imdb_id' => 'TEXT NULL',
        ] as $name => $definition) {
            if (!in_array($name, $seriesColumnNames, true)) {
                $this->pdo->exec("ALTER TABLE series ADD COLUMN {$name} {$definition}");
            }
        }

        // Backfill availability from the Radarr JSON already cached by older ArrView versions.
        // This makes upgrades immediately availability-aware without waiting for the next full sync.
        try {
            $this->pdo->exec(<<<'SQL'
UPDATE movies
SET
    minimum_availability = COALESCE(minimum_availability, json_extract(details_json, '$.minimumAvailability')),
    in_cinemas = COALESCE(in_cinemas, json_extract(details_json, '$.inCinemas')),
    digital_release = COALESCE(digital_release, json_extract(details_json, '$.digitalRelease')),
    physical_release = COALESCE(physical_release, json_extract(details_json, '$.physicalRelease')),
    availability_date = COALESCE(
        availability_date,
        CASE lower(COALESCE(json_extract(details_json, '$.minimumAvailability'), ''))
            WHEN 'incinemas' THEN json_extract(details_json, '$.inCinemas')
            WHEN 'released' THEN
                CASE
                    WHEN json_extract(details_json, '$.digitalRelease') IS NOT NULL
                         AND json_extract(details_json, '$.physicalRelease') IS NOT NULL
                    THEN min(json_extract(details_json, '$.digitalRelease'), json_extract(details_json, '$.physicalRelease'))
                    ELSE COALESCE(
                        json_extract(details_json, '$.digitalRelease'),
                        json_extract(details_json, '$.physicalRelease'),
                        json_extract(details_json, '$.inCinemas')
                    )
                END
            ELSE NULL
        END
    )
WHERE details_json IS NOT NULL;
SQL);
        } catch (Throwable) {
            // Older SQLite builds without JSON functions can wait for the next Radarr sync.
        }


        $syncColumnNames = array_column($this->pdo->query('PRAGMA table_info(sync_jobs)')->fetchAll(), 'name');
        foreach ([
            'source' => "TEXT NOT NULL DEFAULT 'manual'",
            'cancel_requested' => 'INTEGER NOT NULL DEFAULT 0',
            'heartbeat_at' => 'TEXT NULL',
        ] as $name => $definition) {
            if (!in_array($name, $syncColumnNames, true)) {
                $this->pdo->exec("ALTER TABLE sync_jobs ADD COLUMN {$name} {$definition}");
            }
        }

        $defaults = [
            'sync_interval_hours' => '12',
            'viewer_vod_enabled' => '0',
            'preferred_audio_languages' => '',
            'preferred_language_warning' => '1',
            'diagnostic_cache_ttl_minutes' => '15',
            'encrypt_api_keys' => '0',
            'library_page_size' => '100',
        ];
        $settingInsert = $this->pdo->prepare('INSERT OR IGNORE INTO app_settings(setting_key,setting_value) VALUES(?,?)');
        foreach ($defaults as $key => $value) {
            $settingInsert->execute([$key, $value]);
        }

        // Keep the server-side rate-limit table compact.
        $this->pdo->exec("DELETE FROM login_attempts WHERE datetime(last_failed_at) < datetime('now','-30 days')");

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_movies_tmdb ON movies(tmdb_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_movies_availability ON movies(has_file, availability_date, monitored)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_series_tmdb ON series(tmdb_id)');
    }
}
