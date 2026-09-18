<?php

declare(strict_types=1);

final class BatchSyncService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function syncInstance(array $instance, ?callable $progress = null): array
    {
        return ($instance['type'] ?? '') === 'radarr'
            ? $this->syncRadarr($instance, $progress)
            : $this->syncSonarr($instance, $progress);
    }

    private function syncRadarr(array $instance, ?callable $progress): array
    {
        $tmp = $this->downloadToTemp($instance, '/api/v3/movie');
        try {
            $total = $this->countObjects($tmp);
            $progress?->__invoke(0, $total, 'Preparing movie sync');
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
            $this->pdo->beginTransaction();
            try {
                foreach ($this->streamArrayFile($tmp) as $movie) {
                    $remoteId = (int)($movie['id'] ?? 0);
                    if (!$remoteId) continue;
                    $seen[] = $remoteId;
                    $movieFile = $movie['movieFile'] ?? null;
                    $stmt->execute([
                        ':instance_id' => (int)$instance['id'], ':remote_id' => $remoteId,
                        ':title' => (string)($movie['title'] ?? 'Unknown'), ':year' => $movie['year'] ?? null,
                        ':poster_url' => $this->poster($movie['images'] ?? []), ':has_file' => !empty($movie['hasFile']) ? 1 : 0,
                        ':monitored' => !empty($movie['monitored']) ? 1 : 0, ':quality' => $this->quality($movieFile),
                        ':audio_languages' => $this->audioLanguages($movieFile), ':path' => $movie['path'] ?? null,
                        ':file_size' => $movieFile['size'] ?? null,
                    ]);
                    $count++;
                    $progress?->__invoke($count, $total, (string)($movie['title'] ?? 'Movie'));
                    if (($count % 250) === 0) {
                        $this->pdo->commit();
                        $this->pdo->beginTransaction();
                    }
                }
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
            $this->removeStale('movies', (int)$instance['id'], $seen);
            $this->markSync((int)$instance['id'], "OK - {$count} movies (streamed)");
            $progress?->__invoke($count, max($total, $count), 'Completed');
            return ['ok'=>true,'count'=>$count,'message'=>"Synced {$count} movies in streaming batches"];
        } finally { @unlink($tmp); }
    }

    private function syncSonarr(array $instance, ?callable $progress): array
    {
        $tmp = $this->downloadToTemp($instance, '/api/v3/series');
        try {
            $total = $this->countObjects($tmp);
            $progress?->__invoke(0, $total, 'Preparing series + episode sync');
            $seenSeries = [];
            $seriesCount = 0;
            $episodeCount = 0;

            $seriesSql = <<<'SQL'
INSERT INTO series (instance_id, remote_id, title, year, poster_url, monitored, episode_count, episode_file_count, audio_languages, path, updated_at)
VALUES (:instance_id, :remote_id, :title, :year, :poster_url, :monitored, :episode_count, :episode_file_count, :audio_languages, :path, CURRENT_TIMESTAMP)
ON CONFLICT(instance_id, remote_id) DO UPDATE SET
 title=excluded.title, year=excluded.year, poster_url=excluded.poster_url,
 monitored=excluded.monitored, episode_count=excluded.episode_count,
 episode_file_count=excluded.episode_file_count, audio_languages=excluded.audio_languages,
 path=excluded.path, updated_at=CURRENT_TIMESTAMP
SQL;
            $seriesStmt = $this->pdo->prepare($seriesSql);

            $episodeSql = <<<'SQL'
INSERT INTO episodes (
 instance_id, series_id, series_remote_id, remote_id, season_number, episode_number,
 absolute_episode_number, title, air_date_utc, monitored, has_file, episode_file_id,
 relative_path, file_path, file_size, quality, audio_languages, date_added,
 release_group, scene_name, video_codec, video_resolution, audio_codec, audio_channels,
 updated_at
)
VALUES (
 :instance_id, :series_id, :series_remote_id, :remote_id, :season_number, :episode_number,
 :absolute_episode_number, :title, :air_date_utc, :monitored, :has_file, :episode_file_id,
 :relative_path, :file_path, :file_size, :quality, :audio_languages, :date_added,
 :release_group, :scene_name, :video_codec, :video_resolution, :audio_codec, :audio_channels,
 CURRENT_TIMESTAMP
)
ON CONFLICT(instance_id, remote_id) DO UPDATE SET
 series_id=excluded.series_id, series_remote_id=excluded.series_remote_id,
 season_number=excluded.season_number, episode_number=excluded.episode_number,
 absolute_episode_number=excluded.absolute_episode_number, title=excluded.title,
 air_date_utc=excluded.air_date_utc, monitored=excluded.monitored, has_file=excluded.has_file,
 episode_file_id=excluded.episode_file_id, relative_path=excluded.relative_path,
 file_path=excluded.file_path, file_size=excluded.file_size, quality=excluded.quality,
 audio_languages=excluded.audio_languages, date_added=excluded.date_added,
 release_group=excluded.release_group, scene_name=excluded.scene_name,
 video_codec=excluded.video_codec, video_resolution=excluded.video_resolution,
 audio_codec=excluded.audio_codec, audio_channels=excluded.audio_channels,
 updated_at=CURRENT_TIMESTAMP
SQL;
            $episodeStmt = $this->pdo->prepare($episodeSql);

            foreach ($this->streamArrayFile($tmp) as $series) {
                $remoteId = (int)($series['id'] ?? 0);
                if (!$remoteId) continue;
                $seenSeries[] = $remoteId;

                $episodes = [];
                try {
                    $episodes = $this->requestJson(
                        $instance,
                        '/api/v3/episode?seriesId=' . $remoteId . '&includeEpisodeFile=true'
                    );
                } catch (Throwable) {
                    $episodes = [];
                }

                $languages = [];
                $seenEpisodes = [];
                $actualFileCount = 0;

                $this->pdo->beginTransaction();
                try {
                    // Ensure the series row exists first so episodes can reference its local id.
                    $stats = $series['statistics'] ?? [];
                    $seriesStmt->execute([
                        ':instance_id'=>(int)$instance['id'],
                        ':remote_id'=>$remoteId,
                        ':title'=>(string)($series['title'] ?? 'Unknown'),
                        ':year'=>$series['year'] ?? null,
                        ':poster_url'=>$this->poster($series['images'] ?? []),
                        ':monitored'=>!empty($series['monitored']) ? 1 : 0,
                        ':episode_count'=>(int)($stats['episodeCount'] ?? count($episodes)),
                        ':episode_file_count'=>(int)($stats['episodeFileCount'] ?? 0),
                        ':audio_languages'=>null,
                        ':path'=>$series['path'] ?? null,
                    ]);

                    $seriesLocalStmt = $this->pdo->prepare('SELECT id FROM series WHERE instance_id=? AND remote_id=? LIMIT 1');
                    $seriesLocalStmt->execute([(int)$instance['id'], $remoteId]);
                    $seriesLocalId = (int)$seriesLocalStmt->fetchColumn();
                    if ($seriesLocalId < 1) throw new RuntimeException('Could not resolve cached series row.');

                    foreach ($episodes as $episode) {
                        if (!is_array($episode)) continue;
                        $episodeId = (int)($episode['id'] ?? 0);
                        if ($episodeId < 1) continue;

                        $seenEpisodes[] = $episodeId;
                        $file = is_array($episode['episodeFile'] ?? null) ? $episode['episodeFile'] : null;
                        $hasFile = !empty($episode['hasFile']) || $file !== null;
                        if ($hasFile) $actualFileCount++;

                        $languageText = $this->audioLanguages($file);
                        if ($languageText) {
                            foreach (array_map('trim', explode(',', $languageText)) as $language) {
                                if ($language !== '') $languages[$language] = true;
                            }
                        }

                        $mediaInfo = is_array($file['mediaInfo'] ?? null) ? $file['mediaInfo'] : [];
                        $relativePath = $file['relativePath'] ?? null;
                        $seriesPath = (string)($series['path'] ?? '');
                        $filePath = $file['path'] ?? (
                            $seriesPath !== '' && $relativePath
                                ? rtrim($seriesPath, '/\\') . '/' . ltrim((string)$relativePath, '/\\')
                                : null
                        );

                        $episodeStmt->execute([
                            ':instance_id'=>(int)$instance['id'],
                            ':series_id'=>$seriesLocalId,
                            ':series_remote_id'=>$remoteId,
                            ':remote_id'=>$episodeId,
                            ':season_number'=>(int)($episode['seasonNumber'] ?? 0),
                            ':episode_number'=>(int)($episode['episodeNumber'] ?? 0),
                            ':absolute_episode_number'=>$episode['absoluteEpisodeNumber'] ?? null,
                            ':title'=>(string)($episode['title'] ?? 'Episode'),
                            ':air_date_utc'=>$episode['airDateUtc'] ?? null,
                            ':monitored'=>!empty($episode['monitored']) ? 1 : 0,
                            ':has_file'=>$hasFile ? 1 : 0,
                            ':episode_file_id'=>$file['id'] ?? null,
                            ':relative_path'=>$relativePath,
                            ':file_path'=>$filePath,
                            ':file_size'=>$file['size'] ?? null,
                            ':quality'=>$this->quality($file),
                            ':audio_languages'=>$languageText,
                            ':date_added'=>$file['dateAdded'] ?? null,
                            ':release_group'=>$file['releaseGroup'] ?? null,
                            ':scene_name'=>$file['sceneName'] ?? null,
                            ':video_codec'=>$mediaInfo['videoCodec'] ?? null,
                            ':video_resolution'=>$mediaInfo['resolution'] ?? $mediaInfo['videoResolution'] ?? null,
                            ':audio_codec'=>$mediaInfo['audioCodec'] ?? null,
                            ':audio_channels'=>$mediaInfo['audioChannels'] ?? null,
                        ]);
                        $episodeCount++;
                    }

                    $this->removeStaleEpisodes((int)$instance['id'], $seriesLocalId, $seenEpisodes);

                    $names = array_keys($languages);
                    natcasesort($names);
                    $audioLanguages = $names ? implode(', ', $names) : null;

                    $updateSeries = $this->pdo->prepare(
                        'UPDATE series SET audio_languages=?, episode_file_count=? WHERE id=?'
                    );
                    $updateSeries->execute([
                        $audioLanguages,
                        $episodes ? $actualFileCount : (int)($stats['episodeFileCount'] ?? 0),
                        $seriesLocalId,
                    ]);

                    $this->pdo->commit();
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $e;
                }

                $seriesCount++;
                $progress?->__invoke(
                    $seriesCount,
                    $total,
                    (string)($series['title'] ?? 'Series') . ' · ' . count($episodes) . ' episodes'
                );
            }

            $this->removeStale('series', (int)$instance['id'], $seenSeries);
            $this->markSync(
                (int)$instance['id'],
                "OK - {$seriesCount} series / {$episodeCount} episodes cached"
            );
            $progress?->__invoke($seriesCount, max($total, $seriesCount), 'Completed');
            return [
                'ok'=>true,
                'count'=>$seriesCount,
                'message'=>"Synced {$seriesCount} series and {$episodeCount} episodes to local cache"
            ];
        } finally {
            @unlink($tmp);
        }
    }

    private function requestJson(array $instance, string $path): array
    {
        $ch = curl_init(rtrim((string)$instance['url'], '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['X-Api-Key: '.$instance['api_key'], 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error) throw new RuntimeException($error ?: 'Connection failed');
        if ($status < 200 || $status >= 300) throw new RuntimeException("HTTP {$status} from {$instance['type']}");
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException('Invalid JSON response');
        return $decoded;
    }

    private function downloadToTemp(array $instance, string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'arrview_');
        if ($tmp === false) throw new RuntimeException('Could not create temporary sync file.');
        $fp = fopen($tmp, 'w+b');
        if ($fp === false) { @unlink($tmp); throw new RuntimeException('Could not open temporary sync file.'); }
        try {
            $ch = curl_init(rtrim((string)$instance['url'], '/') . $path);
            curl_setopt_array($ch, [CURLOPT_FILE=>$fp,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>300,CURLOPT_HTTPHEADER=>['X-Api-Key: '.$instance['api_key'],'Accept: application/json']]);
            $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
            if ($ok === false || $error) throw new RuntimeException($error ?: 'Connection failed');
            if ($status < 200 || $status >= 300) throw new RuntimeException("HTTP {$status} from {$instance['type']}");
        } catch (Throwable $e) { fclose($fp); @unlink($tmp); throw $e; }
        fclose($fp);
        return $tmp;
    }

    private function countObjects(string $file): int { $count=0; foreach($this->streamArrayFile($file,false) as $_)$count++; return $count; }

    private function streamArrayFile(string $file, bool $decode = true): Generator
    {
        $fp=fopen($file,'rb'); if($fp===false) throw new RuntimeException('Could not read temporary sync file.');
        try {
            $buffer='';$depth=0;$inString=false;$escape=false;$capturing=false;
            while(!feof($fp)){
                $chunk=fread($fp,65536); if($chunk===false) throw new RuntimeException('Failed reading streamed Arr response.');
                $len=strlen($chunk);
                for($i=0;$i<$len;$i++){
                    $c=$chunk[$i];
                    if(!$capturing){ if($c==='{'){ $capturing=true;$depth=1;$buffer='{';$inString=false;$escape=false; } continue; }
                    $buffer.=$c;
                    if($inString){ if($escape)$escape=false; elseif($c==='\\')$escape=true; elseif($c==='"')$inString=false; continue; }
                    if($c==='"')$inString=true; elseif($c==='{')$depth++; elseif($c==='}'){
                        $depth--; if($depth===0){ if($decode){$item=json_decode($buffer,true,512,JSON_THROW_ON_ERROR);if(is_array($item))yield $item;}else yield true; $buffer='';$capturing=false; }
                    }
                }
            }
        } finally { fclose($fp); }
    }

    private function poster(array $images): ?string { foreach($images as $image) if(($image['coverType']??'')==='poster') return $image['remoteUrl']??$image['url']??null; return null; }
    private function quality(?array $file): ?string { return $file['quality']['quality']['name'] ?? $file['quality']['quality']['resolution'] ?? null; }
    private function audioLanguages(?array $file): ?string
    {
        if(!$file)return null;

        $value = $file['languages']
            ?? (($file['mediaInfo']??[])['audioLanguages'] ?? (($file['mediaInfo']??[])['audioLanguage'] ?? null));

        if(is_array($value)){
            $parts=[];
            foreach($value as $language){
                $parts[]=is_array($language)
                    ? ($language['name']??$language['englishName']??$language['iso6391']??'')
                    : (string)$language;
            }
            $parts=array_values(array_filter(array_unique(array_map('trim',$parts))));
            return $parts?implode(', ',$parts):null;
        }

        return $value ? trim((string)$value) : null;
    }

    private function removeStale(string $table,int $instanceId,array $seen):void
    {
        if(!$seen)return;
        $this->pdo->exec('CREATE TEMP TABLE IF NOT EXISTS arrview_seen_ids (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('DELETE FROM arrview_seen_ids');
        $insert=$this->pdo->prepare('INSERT OR IGNORE INTO arrview_seen_ids(id) VALUES(?)');
        foreach($seen as $id)$insert->execute([(int)$id]);
        $stmt=$this->pdo->prepare("DELETE FROM {$table} WHERE instance_id=? AND remote_id NOT IN (SELECT id FROM arrview_seen_ids)");
        $stmt->execute([$instanceId]);
    }

    private function removeStaleEpisodes(int $instanceId, int $seriesLocalId, array $seen): void
    {
        if (!$seen) {
            $stmt = $this->pdo->prepare('DELETE FROM episodes WHERE instance_id=? AND series_id=?');
            $stmt->execute([$instanceId, $seriesLocalId]);
            return;
        }

        $marks = implode(',', array_fill(0, count($seen), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM episodes WHERE instance_id=? AND series_id=? AND remote_id NOT IN ({$marks})"
        );
        $stmt->execute(array_merge([$instanceId, $seriesLocalId], array_map('intval', $seen)));
    }

    private function markSync(int $id,string $status):void
    {
        $stmt=$this->pdo->prepare('UPDATE instances SET last_sync_at=CURRENT_TIMESTAMP,last_status=? WHERE id=?');
        $stmt->execute([$status,$id]);
    }
}
