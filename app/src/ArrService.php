<?php

declare(strict_types=1);

final class ArrService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function test(array $instance): array
    {
        try {
            $endpoint = $instance['type'] === 'radarr' ? '/api/v3/system/status' : '/api/v3/system/status';
            $data = $this->request($instance, $endpoint);
            return [
                'ok' => true,
                'message' => ($data['appName'] ?? ucfirst($instance['type'])) . ' ' . ($data['version'] ?? '') . ' connected',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncInstance(array $instance): array
    {
        return $instance['type'] === 'radarr'
            ? $this->syncRadarr($instance)
            : $this->syncSonarr($instance);
    }

    private function syncRadarr(array $instance): array
    {
        $movies = $this->request($instance, '/api/v3/movie');
        $seen = [];
        $count = 0;

        $sql = <<<'SQL'
INSERT INTO movies (instance_id, remote_id, title, year, poster_url, has_file, monitored, quality, audio_languages, path, file_size, updated_at)
VALUES (:instance_id, :remote_id, :title, :year, :poster_url, :has_file, :monitored, :quality, :audio_languages, :path, :file_size, CURRENT_TIMESTAMP)
ON CONFLICT(instance_id, remote_id) DO UPDATE SET
 title=excluded.title, year=excluded.year, poster_url=excluded.poster_url,
 has_file=excluded.has_file, monitored=excluded.monitored, quality=excluded.quality,
 audio_languages=excluded.audio_languages, path=excluded.path, file_size=excluded.file_size,
 updated_at=CURRENT_TIMESTAMP
SQL;
        $stmt = $this->pdo->prepare($sql);

        foreach ($movies as $movie) {
            $remoteId = (int)($movie['id'] ?? 0);
            if (!$remoteId) {
                continue;
            }
            $seen[] = $remoteId;

            $movieFile = $movie['movieFile'] ?? null;
            if (($movie['hasFile'] ?? false) && !$movieFile) {
                try {
                    $files = $this->request($instance, '/api/v3/moviefile?movieId=' . $remoteId);
                    $movieFile = $files[0] ?? null;
                } catch (Throwable) {
                    $movieFile = null;
                }
            }

            $stmt->execute([
                ':instance_id' => $instance['id'],
                ':remote_id' => $remoteId,
                ':title' => (string)($movie['title'] ?? 'Unknown'),
                ':year' => $movie['year'] ?? null,
                ':poster_url' => $this->poster($movie['images'] ?? []),
                ':has_file' => !empty($movie['hasFile']) ? 1 : 0,
                ':monitored' => !empty($movie['monitored']) ? 1 : 0,
                ':quality' => $this->quality($movieFile),
                ':audio_languages' => $this->audioLanguages($movieFile),
                ':path' => $movie['path'] ?? null,
                ':file_size' => $movieFile['size'] ?? null,
            ]);
            $count++;
        }

        $this->removeStale('movies', (int)$instance['id'], $seen);
        $this->markSync($instance['id'], "OK - {$count} movies");
        return ['ok' => true, 'count' => $count, 'message' => "Synced {$count} movies"];
    }

    private function syncSonarr(array $instance): array
    {
        $items = $this->request($instance, '/api/v3/series');
        $seen = [];
        $count = 0;

        $sql = <<<'SQL'
INSERT INTO series (instance_id, remote_id, title, year, poster_url, monitored, episode_count, episode_file_count, path, updated_at)
VALUES (:instance_id, :remote_id, :title, :year, :poster_url, :monitored, :episode_count, :episode_file_count, :path, CURRENT_TIMESTAMP)
ON CONFLICT(instance_id, remote_id) DO UPDATE SET
 title=excluded.title, year=excluded.year, poster_url=excluded.poster_url,
 monitored=excluded.monitored, episode_count=excluded.episode_count,
 episode_file_count=excluded.episode_file_count, path=excluded.path, updated_at=CURRENT_TIMESTAMP
SQL;
        $stmt = $this->pdo->prepare($sql);

        foreach ($items as $series) {
            $remoteId = (int)($series['id'] ?? 0);
            if (!$remoteId) {
                continue;
            }
            $seen[] = $remoteId;
            $stats = $series['statistics'] ?? [];

            $stmt->execute([
                ':instance_id' => $instance['id'],
                ':remote_id' => $remoteId,
                ':title' => (string)($series['title'] ?? 'Unknown'),
                ':year' => $series['year'] ?? null,
                ':poster_url' => $this->poster($series['images'] ?? []),
                ':monitored' => !empty($series['monitored']) ? 1 : 0,
                ':episode_count' => (int)($stats['episodeCount'] ?? 0),
                ':episode_file_count' => (int)($stats['episodeFileCount'] ?? 0),
                ':path' => $series['path'] ?? null,
            ]);
            $count++;
        }

        $this->removeStale('series', (int)$instance['id'], $seen);
        $this->markSync($instance['id'], "OK - {$count} series");
        return ['ok' => true, 'count' => $count, 'message' => "Synced {$count} series"];
    }

    private function request(array $instance, string $path): array
    {
        $url = rtrim((string)$instance['url'], '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['X-Api-Key: ' . $instance['api_key'], 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error) {
            throw new RuntimeException($error ?: 'Connection failed');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("HTTP {$status} from {$instance['type']}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response');
        }
        return $decoded;
    }

    private function poster(array $images): ?string
    {
        foreach ($images as $image) {
            if (($image['coverType'] ?? '') === 'poster') {
                return $image['remoteUrl'] ?? $image['url'] ?? null;
            }
        }
        return null;
    }

    private function quality(?array $file): ?string
    {
        return $file['quality']['quality']['name'] ?? $file['quality']['quality']['resolution'] ?? null;
    }

    private function audioLanguages(?array $file): ?string
    {
        if (!$file) {
            return null;
        }
        $mediaInfo = $file['mediaInfo'] ?? [];
        $value = $mediaInfo['audioLanguages'] ?? $mediaInfo['audioLanguage'] ?? null;
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $language) {
                $parts[] = is_array($language)
                    ? ($language['name'] ?? $language['englishName'] ?? $language['iso6391'] ?? '')
                    : (string)$language;
            }
            return implode(', ', array_filter($parts));
        }
        return $value ? (string)$value : null;
    }

    private function removeStale(string $table, int $instanceId, array $seen): void
    {
        if (!$seen) {
            $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE instance_id = ?");
            $stmt->execute([$instanceId]);
            return;
        }
        $marks = implode(',', array_fill(0, count($seen), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE instance_id = ? AND remote_id NOT IN ({$marks})");
        $stmt->execute(array_merge([$instanceId], $seen));
    }

    private function markSync(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE instances SET last_sync_at=CURRENT_TIMESTAMP, last_status=? WHERE id=?');
        $stmt->execute([$status, $id]);
    }
}
