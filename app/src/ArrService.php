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
            $data = $this->request($instance, '/api/v3/system/status');
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

    public function diagnoseMissingMovie(array $instance, int $movieId): array
    {
        if (($instance['type'] ?? '') !== 'radarr') {
            throw new RuntimeException('Missing movie diagnostics are available for Radarr only.');
        }

        $movie = $this->request($instance, '/api/v3/movie/' . $movieId);
        if (!empty($movie['hasFile'])) {
            return [
                'status' => 'available',
                'summary' => 'Radarr reports this movie as available.',
                'releases' => [],
                'categories' => [],
                'blocklist' => [],
            ];
        }

        $categories = [];
        if (empty($movie['monitored'])) {
            $categories['Not monitored'][] = 'Movie is not monitored, so Radarr will not automatically search/grab it.';
        }

        $minimumAvailability = $movie['minimumAvailability'] ?? null;
        $digitalRelease = $movie['digitalRelease'] ?? null;
        $physicalRelease = $movie['physicalRelease'] ?? null;
        $inCinemas = $movie['inCinemas'] ?? null;

        $blocklist = [];
        try {
            $blocklist = $this->request($instance, '/api/v3/blocklist/movie?movieId=' . $movieId);
            if ($blocklist) {
                $categories['Blocklisted releases'][] = count($blocklist) . ' previous release(s) are blocklisted for this movie.';
            }
        } catch (Throwable) {
            $blocklist = [];
        }

        // This endpoint performs a live indexer search. Keep it on-demand only.
        $releases = $this->request($instance, '/api/v3/release?movieId=' . $movieId);
        $releaseRows = [];
        $accepted = 0;

        foreach ($releases as $release) {
            $rejections = $release['rejections'] ?? $release['rejectionReasons'] ?? [];
            if (!is_array($rejections)) {
                $rejections = [$rejections];
            }
            $rejections = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $rejections)));
            $isRejected = !empty($release['rejected']) || !empty($rejections);
            if (!$isRejected) {
                $accepted++;
            }

            foreach ($rejections as $reason) {
                $category = $this->classifyRejection($reason);
                $categories[$category][] = $reason;
            }

            $releaseRows[] = [
                'title' => (string)($release['title'] ?? 'Unknown release'),
                'indexer' => (string)($release['indexer'] ?? $release['indexerName'] ?? 'Unknown indexer'),
                'size' => isset($release['size']) ? (int)$release['size'] : null,
                'quality' => $release['quality']['quality']['name'] ?? null,
                'languages' => $this->languageNames($release['languages'] ?? []),
                'rejected' => $isRejected,
                'rejections' => $rejections,
            ];
        }

        foreach ($categories as $name => $messages) {
            $categories[$name] = array_values(array_unique($messages));
        }

        if (!$releases) {
            $categories['No releases found'][] = 'Radarr did not receive any matching releases from the enabled indexers.';
        } elseif ($accepted > 0) {
            $categories['Acceptable releases found'][] = $accepted . ' release(s) passed Radarr rejection checks but have not been grabbed yet.';
        }

        $summary = match (true) {
            !$releases => 'No releases were returned by Radarr indexers.',
            $accepted > 0 => $accepted . ' acceptable release(s) found. Check delay, queue, download client, or grab history if the movie remains missing.',
            !empty($categories) => 'Releases were found, but Radarr rejected them. See the reasons below.',
            default => 'Movie is missing and no specific rejection reason was returned.',
        };

        return [
            'status' => 'missing',
            'summary' => $summary,
            'movie' => [
                'title' => $movie['title'] ?? 'Unknown',
                'year' => $movie['year'] ?? null,
                'monitored' => !empty($movie['monitored']),
                'minimumAvailability' => $minimumAvailability,
                'inCinemas' => $inCinemas,
                'digitalRelease' => $digitalRelease,
                'physicalRelease' => $physicalRelease,
                'qualityProfileId' => $movie['qualityProfileId'] ?? null,
            ],
            'categories' => $categories,
            'releases' => $releaseRows,
            'accepted_count' => $accepted,
            'blocklist' => $blocklist,
        ];
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
            CURLOPT_TIMEOUT => 60,
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
            return $this->languageNames($value);
        }
        return $value ? (string)$value : null;
    }

    private function languageNames(array $languages): ?string
    {
        $parts = [];
        foreach ($languages as $language) {
            $parts[] = is_array($language)
                ? ($language['name'] ?? $language['englishName'] ?? $language['iso6391'] ?? '')
                : (string)$language;
        }
        $parts = array_values(array_filter(array_unique($parts)));
        return $parts ? implode(', ', $parts) : null;
    }

    private function classifyRejection(string $reason): string
    {
        $r = strtolower($reason);
        return match (true) {
            str_contains($r, 'size') || str_contains($r, 'larger than') || str_contains($r, 'smaller than') => 'Size limit',
            str_contains($r, 'language') => 'Language',
            str_contains($r, 'quality') || str_contains($r, 'profile') => 'Quality / profile',
            str_contains($r, 'custom format') || str_contains($r, 'score') => 'Custom format score',
            str_contains($r, 'blocklist') || str_contains($r, 'blacklist') => 'Blocklisted',
            str_contains($r, 'seed') || str_contains($r, 'peer') => 'Peers / seeders',
            str_contains($r, 'age') || str_contains($r, 'retention') => 'Age / retention',
            str_contains($r, 'indexer') => 'Indexer',
            str_contains($r, 'release type') || str_contains($r, 'edition') => 'Release type / edition',
            str_contains($r, 'upgrade') => 'Upgrade rules',
            str_contains($r, 'monitored') => 'Not monitored',
            str_contains($r, 'year') || str_contains($r, 'movie') => 'Movie matching',
            default => 'Other Radarr rejection',
        };
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
