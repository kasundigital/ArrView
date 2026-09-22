<?php

declare(strict_types=1);

final class MetadataService
{
    private const DEFAULT_FREE_ENDPOINT = 'https://arrview.dashboards420.com/metadata-api.php';
    private const CACHE_TTL_DAYS = 30;

    public function __construct(private PDO $pdo)
    {
    }

    public function settings(): array
    {
        $rows = $this->pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('metadata_mode','tmdb_api_key','installation_id')")->fetchAll();
        $settings = [];
        foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];

        return [
            'mode' => in_array(($settings['metadata_mode'] ?? 'free'), ['free','personal'], true) ? $settings['metadata_mode'] : 'free',
            'tmdb_api_key' => (string)($settings['tmdb_api_key'] ?? ''),
            'installation_id' => (string)($settings['installation_id'] ?? ''),
            'free_endpoint' => rtrim((string)(getenv('ARRVIEW_METADATA_API_URL') ?: self::DEFAULT_FREE_ENDPOINT), '?&'),
        ];
    }

    public function saveSettings(string $mode, ?string $apiKey = null): void
    {
        if (!in_array($mode, ['free','personal'], true)) {
            throw new InvalidArgumentException('Invalid metadata mode.');
        }
        $stmt = $this->pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
        $stmt->execute(['metadata_mode', $mode]);
        if ($apiKey !== null && trim($apiKey) !== '') {
            $stmt->execute(['tmdb_api_key', trim($apiKey)]);
        }
    }

    public function removePersonalKey(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM app_settings WHERE setting_key='tmdb_api_key'");
        $stmt->execute();
        $this->saveSettings('free');
    }

    public function stats(): array
    {
        return [
            'movies_cached' => (int)$this->pdo->query("SELECT COUNT(*) FROM media_metadata WHERE media_type='movie'")->fetchColumn(),
            'series_cached' => (int)$this->pdo->query("SELECT COUNT(*) FROM media_metadata WHERE media_type='series'")->fetchColumn(),
            'pending_movies' => (int)$this->pdo->query("SELECT COUNT(*) FROM movies m WHERE m.tmdb_id IS NOT NULL AND m.tmdb_id>0 AND NOT EXISTS (SELECT 1 FROM media_metadata mm WHERE mm.media_type='movie' AND mm.tmdb_id=m.tmdb_id)")->fetchColumn(),
            'pending_series' => (int)$this->pdo->query("SELECT COUNT(*) FROM series s WHERE s.tmdb_id IS NOT NULL AND s.tmdb_id>0 AND NOT EXISTS (SELECT 1 FROM media_metadata mm WHERE mm.media_type='series' AND mm.tmdb_id=s.tmdb_id)")->fetchColumn(),
        ];
    }

    public function extractTmdbId(?string $detailsJson): int
    {
        if (!$detailsJson) return 0;
        $decoded = json_decode($detailsJson, true);
        if (!is_array($decoded)) return 0;
        return (int)($decoded['tmdbId'] ?? 0);
    }

    public function cached(string $mediaType, int $tmdbId): ?array
    {
        if ($tmdbId < 1 || !in_array($mediaType, ['movie','series'], true)) return null;
        $stmt = $this->pdo->prepare('SELECT * FROM media_metadata WHERE media_type=? AND tmdb_id=? LIMIT 1');
        $stmt->execute([$mediaType, $tmdbId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        foreach (['genres_json'=>'genres','companies_json'=>'companies','countries_json'=>'countries'] as $jsonField=>$target) {
            $row[$target] = json_decode((string)($row[$jsonField] ?? '[]'), true) ?: [];
        }
        return $row;
    }

    public function enrich(string $mediaType, int $tmdbId, bool $force = false): array
    {
        if (!in_array($mediaType, ['movie','series'], true) || $tmdbId < 1) {
            throw new InvalidArgumentException('Invalid TMDB media target.');
        }

        if (!$force) {
            $stmt = $this->pdo->prepare("SELECT * FROM media_metadata WHERE media_type=? AND tmdb_id=? AND datetime(fetched_at) >= datetime('now', ?)");
            $stmt->execute([$mediaType, $tmdbId, '-' . self::CACHE_TTL_DAYS . ' days']);
            if ($stmt->fetch()) return ['ok'=>true,'cached'=>true,'source'=>'local'];
        }

        $settings = $this->settings();
        if ($settings['mode'] === 'personal' && $settings['tmdb_api_key'] !== '') {
            $payload = $this->fetchDirect($mediaType, $tmdbId, $settings['tmdb_api_key']);
            $source = 'personal_tmdb';
            $quota = null;
        } else {
            $response = $this->fetchFreeService($mediaType, $tmdbId, $settings);
            $payload = $response['data'];
            $source = 'arrview_free';
            $quota = $response['quota'] ?? null;
        }

        $this->store($mediaType, $tmdbId, $payload, $source);
        return ['ok'=>true,'cached'=>false,'source'=>$source,'quota'=>$quota];
    }

    public function testConnection(): array
    {
        $row = $this->pdo->query("SELECT tmdb_id,title FROM movies WHERE tmdb_id IS NOT NULL AND tmdb_id>0 LIMIT 1")->fetch();
        $type = 'movie';
        if (!$row) {
            $row = $this->pdo->query("SELECT tmdb_id,title FROM series WHERE tmdb_id IS NOT NULL AND tmdb_id>0 LIMIT 1")->fetch();
            $type = 'series';
        }
        if (!$row) throw new RuntimeException('No cached Radarr/Sonarr item with a TMDB ID is available yet.');
        $tmdbId = (int)$row['tmdb_id'];
        $result = $this->enrich($type, $tmdbId, true);
        return ['ok'=>true,'message'=>'TMDB metadata connected using ' . ($result['source'] ?? 'configured source') . '.'];
    }

    public function enrichAll(?callable $progress = null): array
    {
        $targets = [];
        foreach (['movie'=>'movies','series'=>'series'] as $type=>$table) {
            $rows = $this->pdo->query("SELECT title,tmdb_id FROM {$table} WHERE tmdb_id IS NOT NULL AND tmdb_id>0")->fetchAll();
            foreach ($rows as $row) {
                $tmdbId = (int)$row['tmdb_id'];
                if ($tmdbId > 0) $targets[] = [$type,$tmdbId,(string)$row['title']];
            }
        }

        $total = count($targets);
        $done = 0;
        $failed = 0;
        foreach ($targets as [$type,$tmdbId,$title]) {
            $progress?->__invoke($done, $total, $title);
            try {
                $this->enrich($type, $tmdbId, false);
            } catch (Throwable $e) {
                $failed++;
                if (str_contains(strtolower($e->getMessage()), 'quota')) {
                    throw $e;
                }
            }
            $done++;
            if (($done % 20) === 0) usleep(150000);
        }
        $progress?->__invoke($done, $total, 'Completed');
        return ['ok'=>true,'count'=>$done,'failed'=>$failed,'message'=>"Metadata enrichment completed: {$done} processed, {$failed} failed."];
    }

    private function fetchDirect(string $mediaType, int $tmdbId, string $credential): array
    {
        $path = $mediaType === 'movie' ? 'movie' : 'tv';
        $url = "https://api.themoviedb.org/3/{$path}/{$tmdbId}?append_to_response=external_ids";

        $headers = ['Accept: application/json'];
        if (str_starts_with($credential, 'eyJ') || strlen($credential) > 50) {
            $headers[] = 'Authorization: Bearer ' . $credential;
        } else {
            $url .= '&api_key=' . rawurlencode($credential);
        }

        return $this->httpJson($url, $headers);
    }

    private function fetchFreeService(string $mediaType, int $tmdbId, array $settings): array
    {
        $url = $settings['free_endpoint'] . '?type=' . rawurlencode($mediaType) . '&id=' . $tmdbId;
        $headers = [
            'Accept: application/json',
            'X-ArrView-Install: ' . $settings['installation_id'],
            'X-ArrView-Version: ' . ARRVIEW_VERSION,
        ];
        $payload = $this->httpJson($url, $headers);
        if (empty($payload['ok']) || !is_array($payload['data'] ?? null)) {
            throw new RuntimeException((string)($payload['message'] ?? 'ArrView Free Metadata service returned an invalid response.'));
        }
        return $payload;
    }

    private function httpJson(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'ArrView/' . ARRVIEW_VERSION,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Metadata request failed: ' . $error);
        $json = json_decode((string)$body, true);
        if ($status === 429) throw new RuntimeException((string)($json['message'] ?? 'Metadata quota or rate limit reached.'));
        if ($status < 200 || $status >= 300) throw new RuntimeException((string)($json['message'] ?? "Metadata service HTTP {$status}."));
        if (!is_array($json)) throw new RuntimeException('Metadata service returned invalid JSON.');
        return $json;
    }

    private function store(string $mediaType, int $tmdbId, array $payload, string $source): void
    {
        $isMovie = $mediaType === 'movie';
        $runtime = $isMovie
            ? ($payload['runtime'] ?? null)
            : (($payload['episode_run_time'][0] ?? null) ?: null);
        $releaseDate = $isMovie ? ($payload['release_date'] ?? null) : ($payload['first_air_date'] ?? null);
        $companies = $isMovie ? ($payload['production_companies'] ?? []) : ($payload['networks'] ?? $payload['production_companies'] ?? []);
        $countries = $payload['production_countries'] ?? $payload['origin_country'] ?? [];
        $external = is_array($payload['external_ids'] ?? null) ? $payload['external_ids'] : [];
        $imdbId = $payload['imdb_id'] ?? $external['imdb_id'] ?? null;

        $stmt = $this->pdo->prepare(<<<SQL
INSERT INTO media_metadata (
 media_type,tmdb_id,imdb_id,title,original_title,overview,runtime,status,release_date,
 genres_json,companies_json,countries_json,original_language,vote_average,vote_count,
 poster_path,backdrop_path,homepage,payload_json,source,fetched_at,updated_at
) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
ON CONFLICT(media_type,tmdb_id) DO UPDATE SET
 imdb_id=excluded.imdb_id,title=excluded.title,original_title=excluded.original_title,
 overview=excluded.overview,runtime=excluded.runtime,status=excluded.status,release_date=excluded.release_date,
 genres_json=excluded.genres_json,companies_json=excluded.companies_json,countries_json=excluded.countries_json,
 original_language=excluded.original_language,vote_average=excluded.vote_average,vote_count=excluded.vote_count,
 poster_path=excluded.poster_path,backdrop_path=excluded.backdrop_path,homepage=excluded.homepage,
 payload_json=excluded.payload_json,source=excluded.source,fetched_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP
SQL);
        $stmt->execute([
            $mediaType,$tmdbId,$imdbId,
            $payload['title'] ?? $payload['name'] ?? null,
            $payload['original_title'] ?? $payload['original_name'] ?? null,
            $payload['overview'] ?? null,$runtime,$payload['status'] ?? null,$releaseDate,
            json_encode($payload['genres'] ?? [], JSON_UNESCAPED_SLASHES),
            json_encode($companies, JSON_UNESCAPED_SLASHES),
            json_encode($countries, JSON_UNESCAPED_SLASHES),
            $payload['original_language'] ?? null,
            $payload['vote_average'] ?? null,$payload['vote_count'] ?? null,
            $payload['poster_path'] ?? null,$payload['backdrop_path'] ?? null,
            $payload['homepage'] ?? null,json_encode($payload, JSON_UNESCAPED_SLASHES),$source,
        ]);
    }
}
