<?php
declare(strict_types=1);

require_once '/app/src/Database.php';
require_once '/app/src/SecretService.php';
require_once '/app/src/BackupService.php';
require_once '/app/src/Auth.php';
require_once '/app/src/SyncJobService.php';

function ok(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}
");
        exit(1);
    }
    echo "PASS: {$message}
";
}

function columns(PDO $pdo, string $table): array {
    return array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
}

$root=sys_get_temp_dir().'/arrview-v1-tests-'.bin2hex(random_bytes(5));
mkdir($root,0775,true);

// Fresh install migration.
$freshPath=$root.'/fresh.sqlite';
$fresh=new Database($freshPath);
$pdo=$fresh->pdo;

$_SERVER['REMOTE_ADDR']='127.0.0.1';
$pdo->prepare("INSERT INTO users(username,password_hash,role) VALUES(?,?,?)")
    ->execute(['rate-test',password_hash('CorrectPass123!',PASSWORD_DEFAULT),'admin']);
$auth=new Auth($pdo);
for($i=0;$i<5;$i++)$auth->login('rate-test','wrong-password');
ok($auth->loginLockSeconds('rate-test')>0,'server-side login rate limit locks repeated failures');

ok(is_file($freshPath),'fresh SQLite database created');
foreach(['users','instances','movies','series','episodes','app_settings','vod_mappings','login_attempts','sync_jobs','media_metadata'] as $table){
    $count=(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table))->fetchColumn();
    ok($count===1,"fresh schema contains {$table}");
}
ok((string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='sync_interval_hours'")->fetchColumn()==='12','default scheduled sync interval is 12 hours');
ok((string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='viewer_vod_enabled'")->fetchColumn()==='0','viewer VOD is disabled by default');

// Upgrade test from a pre-v1 style database.
$legacyPath=$root.'/legacy.sqlite';
$legacy=new PDO('sqlite:'.$legacyPath);
$legacy->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$legacy->exec("
CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT UNIQUE,password_hash TEXT,role TEXT DEFAULT 'viewer',enabled INTEGER DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE instances(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,type TEXT,url TEXT,api_key TEXT,enabled INTEGER DEFAULT 1,last_sync_at TEXT,last_status TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE movies(id INTEGER PRIMARY KEY AUTOINCREMENT,instance_id INTEGER,remote_id INTEGER,title TEXT,year INTEGER,poster_url TEXT,has_file INTEGER DEFAULT 0,monitored INTEGER DEFAULT 0,quality TEXT,audio_languages TEXT,path TEXT,file_size INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(instance_id,remote_id));
CREATE TABLE series(id INTEGER PRIMARY KEY AUTOINCREMENT,instance_id INTEGER,remote_id INTEGER,title TEXT,year INTEGER,poster_url TEXT,monitored INTEGER DEFAULT 0,episode_count INTEGER DEFAULT 0,episode_file_count INTEGER DEFAULT 0,audio_languages TEXT,path TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(instance_id,remote_id));
CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
CREATE TABLE episodes(
 id INTEGER PRIMARY KEY AUTOINCREMENT,instance_id INTEGER,series_id INTEGER,series_remote_id INTEGER,remote_id INTEGER,
 season_number INTEGER DEFAULT 0,episode_number INTEGER DEFAULT 0,absolute_episode_number INTEGER,title TEXT,
 air_date_utc TEXT,monitored INTEGER DEFAULT 0,has_file INTEGER DEFAULT 0,episode_file_id INTEGER,
 relative_path TEXT,file_path TEXT,file_size INTEGER,quality TEXT,audio_languages TEXT,date_added TEXT,
 release_group TEXT,scene_name TEXT,video_codec TEXT,video_resolution TEXT,audio_codec TEXT,audio_channels REAL,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(instance_id,remote_id)
);
CREATE TABLE diagnostic_cache(cache_key TEXT PRIMARY KEY,payload_json TEXT NOT NULL,checked_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE sync_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,instance_id INTEGER,status TEXT DEFAULT 'queued',current_item INTEGER DEFAULT 0,total_items INTEGER DEFAULT 0,current_title TEXT,message TEXT,started_at TEXT,finished_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
INSERT INTO instances(name,type,url,api_key) VALUES('Legacy Radarr','radarr','http://radarr:7878','legacy-key');
");
unset($legacy);
$upgraded=new Database($legacyPath);
$up=$upgraded->pdo;
foreach(['tmdb_id','minimum_availability','availability_date','details_json'] as $col) ok(in_array($col,columns($up,'movies'),true),"upgrade adds movies.{$col}");
foreach(['tmdb_id','details_json','language_inconsistent'] as $col) ok(in_array($col,columns($up,'series'),true),"upgrade adds series.{$col}");
foreach(['source','cancel_requested','heartbeat_at'] as $col) ok(in_array($col,columns($up,'sync_jobs'),true),"upgrade adds sync_jobs.{$col}");
ok((int)$up->query("SELECT COUNT(*) FROM vod_mappings")->fetchColumn()===0,'upgrade creates VOD mappings table');
ok((string)$up->query("SELECT setting_value FROM app_settings WHERE setting_key='metadata_mode'")->fetchColumn()==='free','upgrade defaults TMDB metadata to free');

// Stale sync recovery: dead jobs must not remain queued/running for hours.
$pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source,created_at) VALUES(1,'queued','old queued','scheduled',datetime('now','-10 minutes'))")->execute();
$staleQueuedId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source,started_at,heartbeat_at,created_at) VALUES(1,'running','old running','scheduled',datetime('now','-10 minutes'),datetime('now','-10 minutes'),datetime('now','-10 minutes'))")->execute();
$staleRunningId=(int)$pdo->lastInsertId();
$recovered=SyncJobService::recoverStale($pdo);
ok($recovered>=2,'stale queued and running sync jobs are recovered');
foreach([$staleQueuedId,$staleRunningId] as $staleId){
    $state=(string)$pdo->query("SELECT status FROM sync_jobs WHERE id=".$staleId)->fetchColumn();
    ok($state==='failed',"stale sync job {$staleId} becomes failed");
}

// Large-library stress: 50k movies, indexed sort/pagination.
$pdo->exec("INSERT INTO instances(name,type,url,api_key) VALUES('Stress Radarr','radarr','http://radarr:7878','stress-key')");
$instanceId=(int)$pdo->lastInsertId();
$pdo->beginTransaction();
$insert=$pdo->prepare("INSERT INTO movies(instance_id,remote_id,title,year,has_file,monitored,audio_languages,availability_date) VALUES(?,?,?,?,?,?,?,?)");
for($i=1;$i<=50000;$i++){
    $insert->execute([$instanceId,$i,sprintf('Movie %05d',$i),2000+($i%27),$i%3===0?1:0,1,$i%2?'English':'English, Japanese','2020-01-01 00:00:00']);
}
$pdo->commit();
$start=microtime(true);
$stmt=$pdo->prepare("SELECT id,title FROM movies WHERE instance_id=? ORDER BY title COLLATE NOCASE DESC LIMIT 100 OFFSET 25000");
$stmt->execute([$instanceId]);
$rows=$stmt->fetchAll();
$elapsed=microtime(true)-$start;
ok(count($rows)===100,'50k movie pagination returns requested page');
ok($elapsed<5.0,'50k movie page query completes under 5 seconds');

// VOD mapping persistence.
$pdo->prepare("INSERT INTO vod_mappings(instance_id,local_root,public_base_url,priority) VALUES(?,?,?,?)")
    ->execute([$instanceId,'/mnt/Movies','https://media.example/Movies',10]);
ok((int)$pdo->query('SELECT COUNT(*) FROM vod_mappings')->fetchColumn()===1,'per-instance VOD mapping persists');

// Optional encryption-at-rest.
if(function_exists('sodium_crypto_secretbox')){
    putenv('ARRVIEW_ENCRYPTION_KEY=v1-test-secret-that-is-only-used-in-ci');
    $secret=new SecretService($pdo);
    ok($secret->configured(),'encryption key is accepted');
    $secret->setEnabled(true);
    $stored=(string)$pdo->query("SELECT api_key FROM instances WHERE id={$instanceId}")->fetchColumn();
    ok(str_starts_with($stored,'enc:v1:'),'instance API key encrypted at rest');
    ok($secret->reveal($stored)==='stress-key','encrypted instance key decrypts correctly');
    $secret->setEnabled(false);
    $plain=(string)$pdo->query("SELECT api_key FROM instances WHERE id={$instanceId}")->fetchColumn();
    ok($plain==='stress-key','encryption can be safely disabled');
}else{
    echo "SKIP: libsodium unavailable; encryption remains optional
";
}

// Backup / restore recovery.
$backupFile=$root.'/recovery.sqlite';
$backup=new BackupService($pdo,$freshPath);
$before=(int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn();
$backup->create($backupFile);
$pdo->exec('DELETE FROM movies');
ok((int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn()===0,'test mutation removed movies');
$result=$backup->restore($backupFile);
$after=(int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn();
ok($after===$before,'backup restore recovers complete movie dataset');
ok(in_array('movies',$result['tables'],true),'restore reports movies table recovered');

// Database survives a new connection (restart/persistence analogue).
unset($backup,$pdo,$fresh);
$reopened=new Database($freshPath);
ok((int)$reopened->pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn()===$before,'data persists after database reopen');

// Diagnostic cache TTL table round-trip.
$reopened->pdo->prepare("INSERT INTO diagnostic_cache(cache_key,payload_json,checked_at) VALUES(?,?,CURRENT_TIMESTAMP)
    ON CONFLICT(cache_key) DO UPDATE SET payload_json=excluded.payload_json,checked_at=CURRENT_TIMESTAMP")
    ->execute(['test', json_encode(['ok'=>true])]);
ok((string)$reopened->pdo->query("SELECT json_extract(payload_json,'$.ok') FROM diagnostic_cache WHERE cache_key='test'")->fetchColumn()==='1','diagnostic cache persists valid JSON');

echo "
ArrView v1 regression suite completed successfully.
";
