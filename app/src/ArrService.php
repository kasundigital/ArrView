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

    public function movieDetails(array $instance, int $movieId): array
    {
        if (($instance['type'] ?? '') !== 'radarr') {
            throw new RuntimeException('Movie details are available for Radarr only.');
        }

        $movie = $this->request($instance, '/api/v3/movie/' . $movieId);
        $movieFile = is_array($movie['movieFile'] ?? null) ? $movie['movieFile'] : null;

        if (!empty($movie['hasFile']) && !$movieFile) {
            try {
                $files = $this->request($instance, '/api/v3/moviefile?movieId=' . $movieId);
                $movieFile = $files[0] ?? null;
            } catch (Throwable) {
                $movieFile = null;
            }
        }

        $historyRows = [];
        $grabbedAt = null;
        $importedAt = null;

        try {
            $history = $this->request($instance, '/api/v3/history/movie?movieId=' . $movieId . '&includeMovie=false');
            foreach (array_slice($history, 0, 50) as $event) {
                $eventType = (string)($event['eventType'] ?? '');
                $date = $event['date'] ?? null;
                $data = is_array($event['data'] ?? null) ? $event['data'] : [];

                if ($grabbedAt === null && $eventType === 'grabbed' && $date) {
                    $grabbedAt = $date;
                }
                if ($importedAt === null && $eventType === 'downloadFolderImported' && $date) {
                    $importedAt = $date;
                }

                $historyRows[] = [
                    'event_type' => $eventType,
                    'date' => $date,
                    'source_title' => (string)($event['sourceTitle'] ?? ''),
                    'quality' => $event['quality']['quality']['name'] ?? null,
                    'languages' => $this->languageNames($event['languages'] ?? []),
                    'download_id' => $data['downloadId'] ?? null,
                    'download_client' => $data['downloadClient'] ?? null,
                    'message' => $data['message'] ?? null,
                ];
            }
        } catch (Throwable) {
            $historyRows = [];
        }

        $mediaInfo = is_array($movieFile['mediaInfo'] ?? null) ? $movieFile['mediaInfo'] : [];
        $audioLanguages = $this->languageNames($movieFile['languages'] ?? [])
            ?? $this->audioLanguages($movieFile);

        return [
            'movie' => [
                'id' => $movie['id'] ?? $movieId,
                'title_slug' => $movie['titleSlug'] ?? null,
                'title' => $movie['title'] ?? 'Unknown',
                'original_title' => $movie['originalTitle'] ?? null,
                'year' => $movie['year'] ?? null,
                'overview' => $movie['overview'] ?? null,
                'runtime' => $movie['runtime'] ?? null,
                'studio' => $movie['studio'] ?? null,
                'status' => $movie['status'] ?? null,
                'monitored' => !empty($movie['monitored']),
                'has_file' => !empty($movie['hasFile']),
                'path' => $movie['path'] ?? null,
                'added' => $movie['added'] ?? null,
                'minimum_availability' => $movie['minimumAvailability'] ?? null,
                'tmdb_id' => $movie['tmdbId'] ?? null,
                'imdb_id' => $movie['imdbId'] ?? null,
                'poster_url' => $this->poster($movie['images'] ?? []),
            ],
            'file' => $movieFile ? [
                'id' => $movieFile['id'] ?? null,
                'relative_path' => $movieFile['relativePath'] ?? null,
                'path' => $movieFile['path'] ?? (($movie['path'] ?? '') && ($movieFile['relativePath'] ?? '') ? rtrim((string)$movie['path'], '/') . '/' . ltrim((string)$movieFile['relativePath'], '/') : null),
                'size' => isset($movieFile['size']) ? (int)$movieFile['size'] : null,
                'date_added' => $movieFile['dateAdded'] ?? null,
                'quality' => $this->quality($movieFile),
                'languages' => $audioLanguages,
                'release_group' => $movieFile['releaseGroup'] ?? null,
                'scene_name' => $movieFile['sceneName'] ?? null,
                'video_codec' => $mediaInfo['videoCodec'] ?? null,
                'video_resolution' => $mediaInfo['resolution'] ?? $mediaInfo['videoResolution'] ?? null,
                'audio_codec' => $mediaInfo['audioCodec'] ?? null,
                'audio_channels' => $mediaInfo['audioChannels'] ?? null,
                'audio_stream_count' => $mediaInfo['audioStreamCount'] ?? null,
                'subtitles' => $mediaInfo['subtitles'] ?? null,
            ] : null,
            'timeline' => [
                'added_to_radarr' => $movie['added'] ?? null,
                'grabbed_at' => $grabbedAt,
                'imported_at' => $importedAt,
                'file_added_at' => $movieFile['dateAdded'] ?? null,
            ],
            'history' => $historyRows,
        ];
    }

    public function diagnoseMissingMovie(array $instance, int $movieId, bool $deep = false): array
    {
        if (($instance['type'] ?? '') !== 'radarr') {
            throw new RuntimeException('Missing movie diagnostics are available for Radarr only.');
        }

        $movie = $this->request($instance, '/api/v3/movie/' . $movieId);
        if (!empty($movie['hasFile'])) {
            return [
                'status' => 'available',
                'summary' => 'Radarr reports this movie as available.',
                'diagnosis' => [
                    'label' => 'Available',
                    'severity' => 'good',
                    'detail' => 'Radarr reports a movie file for this title.',
                ],
                'releases' => [],
                'categories' => [],
                'blocklist' => [],
                'queue' => [],
                'history' => [],
            ];
        }

        $categories = [];
        if (empty($movie['monitored'])) {
            $categories['Not monitored'][] = 'Movie is not monitored, so Radarr will not automatically search or grab it.';
        }

        $blocklist = [];
        try {
            $blocklist = $this->request($instance, '/api/v3/blocklist/movie?movieId=' . $movieId);
            if ($blocklist) {
                $categories['Blocklisted releases'][] = count($blocklist) . ' previous release(s) are blocklisted for this movie.';
            }
        } catch (Throwable) {
            $blocklist = [];
        }

        $queueRows = [];
        try {
            $queue = $this->request($instance, '/api/v3/queue/details?movieId=' . $movieId . '&includeMovie=false');
            foreach ($queue as $item) {
                $messages = [];
                foreach (($item['statusMessages'] ?? []) as $statusMessage) {
                    if (!empty($statusMessage['title'])) {
                        $messages[] = (string)$statusMessage['title'];
                    }
                    foreach (($statusMessage['messages'] ?? []) as $message) {
                        $messages[] = (string)$message;
                    }
                }
                if (!empty($item['errorMessage'])) {
                    $messages[] = (string)$item['errorMessage'];
                }
                $messages = array_values(array_unique(array_filter(array_map('trim', $messages))));

                $state = (string)($item['trackedDownloadState'] ?? '');
                $trackedStatus = (string)($item['trackedDownloadStatus'] ?? '');

                if ($state === 'importBlocked') {
                    $categories['Import blocked'][] = $messages[0] ?? 'Radarr reports that import is blocked.';
                } elseif (in_array($state, ['failed', 'failedPending'], true) || $trackedStatus === 'error') {
                    $categories['Download failed'][] = $messages[0] ?? 'Radarr reports a failed download.';
                } elseif ($state === 'importPending' || $state === 'importing') {
                    $categories['Import pending'][] = $messages[0] ?? 'Download completed and Radarr is waiting to import it.';
                } elseif ($state === 'downloading') {
                    $categories['Currently downloading'][] = 'A release is currently in the download queue.';
                }

                $queueRows[] = [
                    'title' => (string)($item['title'] ?? 'Queued release'),
                    'state' => $state ?: (string)($item['status'] ?? 'unknown'),
                    'tracked_status' => $trackedStatus,
                    'status' => (string)($item['status'] ?? ''),
                    'size' => isset($item['size']) ? (int)$item['size'] : null,
                    'size_left' => isset($item['sizeleft']) ? (int)$item['sizeleft'] : null,
                    'time_left' => $item['timeleft'] ?? null,
                    'download_client' => $item['downloadClient'] ?? null,
                    'output_path' => $item['outputPath'] ?? null,
                    'messages' => $messages,
                ];
            }
        } catch (Throwable) {
            $queueRows = [];
        }

        $historyRows = [];
        try {
            $history = $this->request($instance, '/api/v3/history/movie?movieId=' . $movieId . '&includeMovie=false');
            foreach (array_slice($history, 0, 50) as $event) {
                $eventType = (string)($event['eventType'] ?? '');
                $data = is_array($event['data'] ?? null) ? $event['data'] : [];
                $message = null;

                if ($eventType === 'downloadFailed') {
                    $message = (string)($data['message'] ?? 'A previous download failed.');
                    $categories['Download failed'][] = $message;
                } elseif ($eventType === 'downloadIgnored') {
                    $message = (string)($data['message'] ?? 'A previous download was ignored.');
                    $categories['Download ignored'][] = $message;
                } elseif ($eventType === 'movieFileDeleted') {
                    $reason = (string)($data['reason'] ?? 'Unknown');
                    $message = 'A movie file was deleted. Reason: ' . $reason . '.';
                    $categories['File deleted'][] = $message;
                } elseif ($eventType === 'downloadFolderImported') {
                    $message = 'Radarr previously imported a completed download.';
                } elseif ($eventType === 'grabbed') {
                    $message = 'Radarr previously grabbed this release.';
                }

                $historyRows[] = [
                    'event_type' => $eventType,
                    'date' => $event['date'] ?? null,
                    'source_title' => (string)($event['sourceTitle'] ?? ''),
                    'quality' => $event['quality']['quality']['name'] ?? null,
                    'languages' => $this->languageNames($event['languages'] ?? []),
                    'message' => $message,
                    'data' => $data,
                ];
            }
        } catch (Throwable) {
            $historyRows = [];
        }

        if (!$deep) {
            $diagnosis = $this->buildMissingDiagnosis(
                $movie,
                $queueRows,
                $historyRows,
                [],
                0,
                $categories,
                false
            );

            return [
                'status' => 'missing',
                'summary' => $diagnosis['detail'],
                'diagnosis' => $diagnosis,
                'movie' => [
                    'title' => $movie['title'] ?? 'Unknown',
                    'year' => $movie['year'] ?? null,
                    'monitored' => !empty($movie['monitored']),
                    'minimumAvailability' => $movie['minimumAvailability'] ?? null,
                    'inCinemas' => $movie['inCinemas'] ?? null,
                    'digitalRelease' => $movie['digitalRelease'] ?? null,
                    'physicalRelease' => $movie['physicalRelease'] ?? null,
                    'qualityProfileId' => $movie['qualityProfileId'] ?? null,
                ],
                'categories' => $categories,
                'releases' => [],
                'accepted_count' => 0,
                'blocklist' => $blocklist,
                'queue' => $queueRows,
                'history' => $historyRows,
                'deep_performed' => false,
            ];
        }

        // Deep search is explicit and streamed to keep peak PHP memory bounded.
        $releaseRows = [];
        $accepted = 0;
        $releaseFound = false;

        foreach ($this->streamRequestArray($instance, '/api/v3/release?movieId=' . $movieId) as $release) {
            $releaseFound = true;
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

            if (count($releaseRows) < 100) {
                $releaseRows[] = [
                    'title' => (string)($release['title'] ?? 'Unknown release'),
                    'indexer' => (string)($release['indexer'] ?? $release['indexerName'] ?? 'Unknown indexer'),
                    'size' => isset($release['size']) ? (int)$release['size'] : null,
                    'quality' => $release['quality']['quality']['name'] ?? null,
                    'languages' => $this->languageNames($release['languages'] ?? []),
                    'rejected' => $isRejected,
                    'rejections' => array_slice($rejections, 0, 10),
                ];
            }
        }

        foreach ($categories as $name => $messages) {
            $categories[$name] = array_values(array_unique($messages));
        }

        if (!$releaseFound) {
            $categories['No releases found'][] = 'Radarr did not receive any matching releases from the enabled indexers.';
        } elseif ($accepted > 0) {
            $categories['Acceptable releases found'][] = $accepted . ' release(s) passed Radarr rejection checks.';
        }

        $diagnosis = $this->buildMissingDiagnosis(
            $movie,
            $queueRows,
            $historyRows,
            $releaseRows,
            $accepted,
            $categories,
            true
        );

        return [
            'status' => 'missing',
            'summary' => $diagnosis['detail'],
            'diagnosis' => $diagnosis,
            'movie' => [
                'title' => $movie['title'] ?? 'Unknown',
                'year' => $movie['year'] ?? null,
                'monitored' => !empty($movie['monitored']),
                'minimumAvailability' => $movie['minimumAvailability'] ?? null,
                'inCinemas' => $movie['inCinemas'] ?? null,
                'digitalRelease' => $movie['digitalRelease'] ?? null,
                'physicalRelease' => $movie['physicalRelease'] ?? null,
                'qualityProfileId' => $movie['qualityProfileId'] ?? null,
            ],
            'categories' => $categories,
            'releases' => $releaseRows,
            'accepted_count' => $accepted,
            'blocklist' => $blocklist,
            'queue' => $queueRows,
            'history' => $historyRows,
            'deep_performed' => true,
        ];
    }

    public function diagnoseMissingEpisode(array $instance, int $episodeId, bool $deep = false): array
    {
        if (($instance['type'] ?? '') !== 'sonarr') {
            throw new RuntimeException('Missing episode diagnostics are available for Sonarr only.');
        }

        $episode = $this->request($instance, '/api/v3/episode/' . $episodeId);
        if (!empty($episode['hasFile'])) {
            return [
                'diagnosis'=>['label'=>'Available','severity'=>'good','detail'=>'Sonarr reports an episode file for this episode.'],
                'episode'=>$episode,'queue'=>[],'history'=>[],'categories'=>[],'releases'=>[],'deep_performed'=>false
            ];
        }

        $categories = [];
        if (empty($episode['monitored'])) {
            $categories['Not monitored'][] = 'This episode is not monitored.';
        }

        $airDate = $episode['airDateUtc'] ?? null;
        if ($airDate) {
            try {
                if (new DateTimeImmutable($airDate) > new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                    $categories['Not aired yet'][] = 'The episode air date is still in the future.';
                }
            } catch (Throwable) {
            }
        }

        $queueRows = [];
        try {
            $queue = $this->request($instance, '/api/v3/queue/details?episodeIds=' . $episodeId . '&includeEpisode=false');
            foreach ($queue as $item) {
                $messages=[];
                foreach (($item['statusMessages'] ?? []) as $statusMessage) {
                    foreach (($statusMessage['messages'] ?? []) as $message) $messages[]=(string)$message;
                    if (!empty($statusMessage['title'])) $messages[]=(string)$statusMessage['title'];
                }
                if (!empty($item['errorMessage'])) $messages[]=(string)$item['errorMessage'];
                foreach ($messages as $pathMessage) {
                    $lowerMessage = strtolower($pathMessage);
                    if (str_contains($lowerMessage, 'remote path mapping')) {
                        $categories['Remote path mapping'][] = $pathMessage;
                    } elseif (
                        (str_contains($lowerMessage, 'path') || str_contains($lowerMessage, 'directory'))
                        && (str_contains($lowerMessage, 'does not exist') || str_contains($lowerMessage, 'not found') || str_contains($lowerMessage, 'missing'))
                    ) {
                        $categories['Download path missing'][] = $pathMessage;
                    } elseif (str_contains($lowerMessage, 'permission denied') || str_contains($lowerMessage, 'access denied') || str_contains($lowerMessage, 'not accessible')) {
                        $categories['Path permissions'][] = $pathMessage;
                    } elseif (str_contains($lowerMessage, 'download client') && (str_contains($lowerMessage, 'unavailable') || str_contains($lowerMessage, 'cannot') || str_contains($lowerMessage, 'failed'))) {
                        $categories['Download client'][] = $pathMessage;
                    }
                }

                $state=(string)($item['trackedDownloadState'] ?? $item['status'] ?? '');
                $tracked=(string)($item['trackedDownloadStatus'] ?? '');
                if ($state==='importBlocked') $categories['Import blocked'][]=$messages[0] ?? 'Sonarr reports import is blocked.';
                elseif (in_array($state,['failed','failedPending'],true) || $tracked==='error') $categories['Download failed'][]=$messages[0] ?? 'The download failed.';
                elseif (in_array($state,['importPending','importing'],true)) $categories['Import pending'][]=$messages[0] ?? 'The episode is waiting for import.';
                elseif ($state==='downloading') $categories['Currently downloading'][]='The episode is currently downloading.';
                $queueRows[]=[
                    'title'=>$item['title']??'Queued release',
                    'state'=>$state,
                    'messages'=>array_values(array_unique($messages)),
                    'download_client'=>$item['downloadClient']??null,
                    'output_path'=>$item['outputPath']??$item['downloadPath']??null,
                    'status'=>$item['status']??null,
                    'tracked_status'=>$tracked,
                ];
            }
        } catch (Throwable) {
        }

        $historyRows = [];
        try {
            $historyResponse = $this->request($instance, '/api/v3/history?episodeId=' . $episodeId . '&page=1&pageSize=50&sortKey=date&sortDirection=descending&includeSeries=false&includeEpisode=false');
            $history = $historyResponse['records'] ?? $historyResponse;
            foreach (array_slice(is_array($history)?$history:[],0,50) as $event) {
                $eventType=(string)($event['eventType'] ?? '');
                $data=is_array($event['data']??null)?$event['data']:[];
                if ($eventType==='downloadFailed') $categories['Download failed'][]=(string)($data['message']??'A previous download failed.');
                $historyMessage=(string)($data['message']??'');
                $historyLower=strtolower($historyMessage);
                if ($historyMessage!=='') {
                    if (str_contains($historyLower,'remote path mapping')) $categories['Remote path mapping'][]=$historyMessage;
                    elseif ((str_contains($historyLower,'path')||str_contains($historyLower,'directory')) && (str_contains($historyLower,'does not exist')||str_contains($historyLower,'not found'))) $categories['Download path missing'][]=$historyMessage;
                    elseif (str_contains($historyLower,'permission denied')||str_contains($historyLower,'access denied')) $categories['Path permissions'][]=$historyMessage;
                }
                $historyRows[]=[
                    'event_type'=>$eventType,'date'=>$event['date']??null,'source_title'=>$event['sourceTitle']??'',
                    'quality'=>$event['quality']['quality']['name']??null,'languages'=>$this->languageNames($event['languages']??[]),
                    'message'=>$data['message']??null,
                    'download_client'=>$data['downloadClient']??null,
                    'dropped_path'=>$data['droppedPath']??$data['downloadPath']??null,
                    'imported_path'=>$data['importedPath']??null,
                ];
            }
        } catch (Throwable) {
        }

        $diagnosis = $this->buildEpisodeDiagnosis($episode,$queueRows,$historyRows,$categories,false,0,[]);
        if (!$deep) {
            return ['diagnosis'=>$diagnosis,'episode'=>$episode,'queue'=>$queueRows,'history'=>$historyRows,'categories'=>$categories,'releases'=>[],'deep_performed'=>false];
        }

        $releaseRows=[];$accepted=0;$found=false;
        foreach ($this->streamRequestArray($instance, '/api/v3/release?episodeId=' . $episodeId) as $release) {
            $found=true;
            $rejections=$release['rejections']??$release['rejectionReasons']??[];
            if(!is_array($rejections))$rejections=[$rejections];
            $rejections=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),$rejections)));
            $isRejected=!empty($release['rejected'])||!empty($rejections);
            if(!$isRejected)$accepted++;
            foreach($rejections as $reason)$categories[$this->classifyRejection($reason)][]=$reason;
            if(count($releaseRows)<100)$releaseRows[]=[
                'title'=>$release['title']??'Unknown release','indexer'=>$release['indexer']??$release['indexerName']??'Unknown indexer',
                'quality'=>$release['quality']['quality']['name']??null,'languages'=>$this->languageNames($release['languages']??[]),
                'size'=>isset($release['size'])?(int)$release['size']:null,'rejected'=>$isRejected,'rejections'=>array_slice($rejections,0,10)
            ];
        }
        if(!$found)$categories['No releases found'][]='Enabled Sonarr indexers returned no matching releases.';
        elseif($accepted>0)$categories['Acceptable releases found'][]=$accepted.' release(s) passed Sonarr checks.';
        foreach($categories as $name=>$messages)$categories[$name]=array_values(array_unique(array_slice($messages,0,20)));

        $diagnosis=$this->buildEpisodeDiagnosis($episode,$queueRows,$historyRows,$categories,true,$accepted,$releaseRows);
        return ['diagnosis'=>$diagnosis,'episode'=>$episode,'queue'=>$queueRows,'history'=>$historyRows,'categories'=>$categories,'releases'=>$releaseRows,'accepted_count'=>$accepted,'deep_performed'=>true];
    }

    private function buildEpisodeDiagnosis(array $episode,array $queue,array $history,array $categories,bool $deep,int $accepted,array $releases): array
    {
        if(empty($episode['monitored'])) return ['label'=>'Episode is not monitored','severity'=>'warning','detail'=>'Sonarr will not automatically grab this episode until monitoring is enabled.'];
        if(!empty($categories['Not aired yet'])) return ['label'=>'Not aired yet','severity'=>'info','detail'=>'This episode has not aired yet.'];
        foreach(['Remote path mapping','Download path missing','Path permissions','Download client'] as $pathCategory){
            if(!empty($categories[$pathCategory])) return [
                'label'=>$pathCategory,
                'severity'=>'bad',
                'detail'=>(string)$categories[$pathCategory][0]
            ];
        }
        foreach($queue as $item){
            if(($item['state']??'')==='importBlocked') return ['label'=>'Import blocked','severity'=>'bad','detail'=>$item['messages'][0]??'Sonarr cannot import the completed download.'];
            if(in_array(($item['state']??''),['failed','failedPending'],true)) return ['label'=>'Download failed','severity'=>'bad','detail'=>$item['messages'][0]??'The episode download failed.'];
            if(in_array(($item['state']??''),['importPending','importing'],true)) return ['label'=>'Waiting for import','severity'=>'warning','detail'=>$item['messages'][0]??'The download completed and is waiting for import.'];
            if(($item['state']??'')==='downloading') return ['label'=>'Currently downloading','severity'=>'info','detail'=>'A release is currently downloading.'];
        }
        foreach($history as $event) if(($event['event_type']??'')==='downloadFailed') return ['label'=>'Previous download failed','severity'=>'bad','detail'=>$event['message']??'A previous download failed.'];
        if(!$deep) return ['label'=>'Deep search available','severity'=>'info','detail'=>'No queue/history problem was found. Run Deep Search to inspect indexer releases and rejection reasons.'];
        if($accepted>0) return ['label'=>'Acceptable release available','severity'=>'info','detail'=>$accepted.' release(s) currently pass Sonarr checks.'];
        if(!$releases) return ['label'=>'No releases found','severity'=>'warning','detail'=>'Enabled indexers returned no matching releases.'];
        foreach(['Language','Size limit','Quality / profile','Custom format score','Peers / seeders','Blocklisted','Age / retention','Indexer'] as $category){
            if(!empty($categories[$category])) return ['label'=>$category,'severity'=>'bad','detail'=>'Sonarr rejection rules in this category are blocking available releases.'];
        }
        return ['label'=>'All releases rejected','severity'=>'bad','detail'=>'Sonarr found releases, but none currently pass all acceptance rules.'];
    }

    private function buildMissingDiagnosis(
        array $movie,
        array $queue,
        array $history,
        array $releases,
        int $accepted,
        array $categories,
        bool $searchPerformed = true
    ): array {
        if (empty($movie['monitored'])) {
            return [
                'label' => 'Movie is not monitored',
                'severity' => 'warning',
                'detail' => 'Radarr will not automatically search or grab this movie until monitoring is enabled.',
            ];
        }

        foreach ($queue as $item) {
            if (($item['state'] ?? '') === 'importBlocked') {
                return [
                    'label' => 'Import blocked',
                    'severity' => 'bad',
                    'detail' => $item['messages'][0] ?? 'The download exists, but Radarr cannot import it. Check permissions, paths, remote path mappings, free space, and file accessibility.',
                ];
            }
        }

        foreach ($queue as $item) {
            if (in_array(($item['state'] ?? ''), ['failed', 'failedPending'], true) || ($item['tracked_status'] ?? '') === 'error') {
                return [
                    'label' => 'Download failed',
                    'severity' => 'bad',
                    'detail' => $item['messages'][0] ?? 'The selected release failed in the download client.',
                ];
            }
        }

        foreach ($queue as $item) {
            if (in_array(($item['state'] ?? ''), ['importPending', 'importing'], true)) {
                return [
                    'label' => 'Waiting for import',
                    'severity' => 'warning',
                    'detail' => $item['messages'][0] ?? 'The download has completed and Radarr is waiting to import it.',
                ];
            }
        }

        foreach ($queue as $item) {
            if (($item['state'] ?? '') === 'downloading') {
                return [
                    'label' => 'Currently downloading',
                    'severity' => 'info',
                    'detail' => 'A release is currently downloading. The movie will remain missing until download and import finish.',
                ];
            }
        }

        foreach ($history as $event) {
            if (($event['event_type'] ?? '') === 'downloadFailed') {
                return [
                    'label' => 'Previous download failed',
                    'severity' => 'bad',
                    'detail' => $event['message'] ?: 'The most relevant recent history contains a failed download.',
                ];
            }
            if (($event['event_type'] ?? '') === 'downloadIgnored') {
                return [
                    'label' => 'Download was ignored',
                    'severity' => 'warning',
                    'detail' => $event['message'] ?: 'Radarr ignored a previous download for this movie.',
                ];
            }
        }

        $seenGrab = false;
        foreach ($history as $event) {
            if (($event['event_type'] ?? '') === 'downloadFolderImported') {
                break;
            }
            if (($event['event_type'] ?? '') === 'grabbed') {
                $seenGrab = true;
                break;
            }
        }
        if ($seenGrab && !$queue) {
            return [
                'label' => 'Grabbed but not imported',
                'severity' => 'warning',
                'detail' => 'Radarr previously grabbed a release, but there is no current queue item or later import event. Check the download client, completed-download handling, paths, and import history.',
            ];
        }

        if ($accepted > 0) {
            return [
                'label' => 'Acceptable release available',
                'severity' => 'info',
                'detail' => $accepted . ' release(s) currently pass Radarr checks. The movie may be waiting on delay-profile rules or has not been grabbed yet.',
            ];
        }

        if (!$searchPerformed) {
            return [
                'label' => 'Deep search available',
                'severity' => 'info',
                'detail' => 'No queue/history problem was found. Run Deep Search to inspect live indexer releases and rejection reasons.',
            ];
        }

        if (!$releases) {
            return [
                'label' => 'No releases found',
                'severity' => 'warning',
                'detail' => 'Enabled indexers returned no matching releases for this movie.',
            ];
        }

        $priority = [
            'Language' => 'Language requirements are blocking available releases.',
            'Size limit' => 'Available releases are outside the configured size limits.',
            'Quality / profile' => 'Available releases do not satisfy the selected quality profile.',
            'Custom format score' => 'Available releases do not meet the required custom-format score.',
            'Peers / seeders' => 'Torrent releases do not meet peer or seeder requirements.',
            'Blocklisted' => 'Matching releases are blocklisted.',
            'Age / retention' => 'Release age or retention rules are blocking available releases.',
            'Release type / edition' => 'Release type or edition requirements are blocking available releases.',
            'Upgrade rules' => 'Upgrade rules reject the available releases.',
            'Movie matching' => 'Radarr is rejecting releases because movie matching is uncertain.',
            'Indexer' => 'Indexer-specific rejection rules are blocking releases.',
        ];

        foreach ($priority as $category => $detail) {
            if (!empty($categories[$category])) {
                return [
                    'label' => $category,
                    'severity' => 'bad',
                    'detail' => $detail,
                ];
            }
        }

        return [
            'label' => 'All releases rejected',
            'severity' => 'bad',
            'detail' => 'Radarr found releases, but none currently pass all acceptance rules. Review the rejection list below.',
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

    private function streamRequestArray(array $instance, string $path): Generator
    {
        $tmp=tempnam(sys_get_temp_dir(),'arrdiag_');
        if($tmp===false) throw new RuntimeException('Could not create diagnostic temp file.');
        $fp=fopen($tmp,'w+b');
        if($fp===false){@unlink($tmp);throw new RuntimeException('Could not open diagnostic temp file.');}

        try {
            $ch=curl_init(rtrim((string)$instance['url'],'/').$path);
            curl_setopt_array($ch,[
                CURLOPT_FILE=>$fp,
                CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_TIMEOUT=>120,
                CURLOPT_HTTPHEADER=>['X-Api-Key: '.$instance['api_key'],'Accept: application/json'],
            ]);
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
            fclose($fp);
            if($ok===false||$error) throw new RuntimeException($error?:'Connection failed');
            if($status<200||$status>=300) throw new RuntimeException("HTTP {$status} from {$instance['type']}");

            $fp=fopen($tmp,'rb');
            if($fp===false) throw new RuntimeException('Could not read diagnostic response.');
            try {
                $buffer='';$depth=0;$inString=false;$escape=false;$capturing=false;
                while(!feof($fp)){
                    $chunk=fread($fp,65536);
                    if($chunk===false) throw new RuntimeException('Failed reading diagnostic response.');
                    $len=strlen($chunk);
                    for($i=0;$i<$len;$i++){
                        $chv=$chunk[$i];
                        if(!$capturing){if($chv==='{'){$capturing=true;$depth=1;$buffer='{';$inString=false;$escape=false;}continue;}
                        $buffer.=$chv;
                        if($inString){if($escape)$escape=false;elseif($chv==='\\')$escape=true;elseif($chv==='"')$inString=false;continue;}
                        if($chv==='"')$inString=true;elseif($chv==='{')$depth++;elseif($chv==='}'){
                            $depth--;
                            if($depth===0){
                                $item=json_decode($buffer,true,512,JSON_THROW_ON_ERROR);
                                if(is_array($item))yield $item;
                                $buffer='';$capturing=false;
                            }
                        }
                    }
                }
            } finally { fclose($fp); }
        } finally {
            if(is_resource($fp)) @fclose($fp);
            @unlink($tmp);
        }
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
