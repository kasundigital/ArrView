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
            $progress?->__invoke(0, $total, 'Preparing series sync');
            $seen = [];
            $count = 0;
            $sql = <<<'SQL'
INSERT INTO series (instance_id, remote_id, title, year, poster_url, monitored, episode_count, episode_file_count, audio_languages, path, updated_at)
VALUES (:instance_id, :remote_id, :title, :year, :poster_url, :monitored, :episode_count, :episode_file_count, :audio_languages, :path, CURRENT_TIMESTAMP)
ON CONFLICT(instance_id, remote_id) DO UPDATE SET
 title=excluded.title, year=excluded.year, poster_url=excluded.poster_url,
 monitored=excluded.monitored, episode_count=excluded.episode_count,
 episode_file_count=excluded.episode_file_count, audio_languages=excluded.audio_languages,
 path=excluded.path, updated_at=CURRENT_TIMESTAMP
SQL;
            $stmt = $this->pdo->prepare($sql);
            $this->pdo->beginTransaction();
            try {
                foreach ($this->streamArrayFile($tmp) as $series) {
                    $remoteId = (int)($series['id'] ?? 0);
                    if (!$remoteId) continue;
                    $seen[] = $remoteId;
                    $stats = $series['statistics'] ?? [];
                    $fileCount = (int)($stats['episodeFileCount'] ?? 0);
                    $audioLanguages = null;

                    if ($fileCount > 0) {
                        try {
                            $audioLanguages = $this->seriesAudioLanguages($instance, $remoteId);
                        } catch (Throwable) {
                            $audioLanguages = null;
                        }
                    }

                    $stmt->execute([
                        ':instance_id'=>(int)$instance['id'], ':remote_id'=>$remoteId,
                        ':title'=>(string)($series['title'] ?? 'Unknown'), ':year'=>$series['year'] ?? null,
                        ':poster_url'=>$this->poster($series['images'] ?? []), ':monitored'=>!empty($series['monitored']) ? 1 : 0,
                        ':episode_count'=>(int)($stats['episodeCount'] ?? 0), ':episode_file_count'=>$fileCount,
                        ':audio_languages'=>$audioLanguages, ':path'=>$series['path'] ?? null,
                    ]);
                    $count++;
                    $progress?->__invoke($count, $total, (string)($series['title'] ?? 'Series'));
                    if (($count % 100) === 0) {
                        $this->pdo->commit();
                        $this->pdo->beginTransaction();
                    }
                }
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
            $this->removeStale('series', (int)$instance['id'], $seen);
            $this->markSync((int)$instance['id'], "OK - {$count} series (audio scanned)");
            $progress?->__invoke($count, max($total, $count), 'Completed');
            return ['ok'=>true,'count'=>$count,'message'=>"Synced {$count} series with audio-language scan"];
        } finally { @unlink($tmp); }
    }

    private function seriesAudioLanguages(array $instance, int $seriesId): ?string
    {
        $files = $this->requestJson($instance, '/api/v3/episodefile?seriesId=' . $seriesId);
        $languages = [];

        foreach ($files as $file) {
            $value = $this->audioLanguages(is_array($file) ? $file : null);
            if (!$value) continue;
            foreach (array_map('trim', explode(',', $value)) as $language) {
                if ($language !== '') $languages[$language] = true;
            }
        }

        $names = array_keys($languages);
        natcasesort($names);
        return $names ? implode(', ', $names) : null;
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

    private function markSync(int $id,string $status):void
    {
        $stmt=$this->pdo->prepare('UPDATE instances SET last_sync_at=CURRENT_TIMESTAMP,last_status=? WHERE id=?');
        $stmt->execute([$status,$id]);
    }
}
