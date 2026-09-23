<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';

$dataDir=getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data';
$databasePath=rtrim($dataDir,'/').'/arrview.sqlite';
$once=in_array('--once',$argv,true);

function schedulerRun(PDO $pdo): void {
    $stmt=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='sync_interval_hours' LIMIT 1");
    $stmt->execute();
    $hours=max(0,min(168,(int)($stmt->fetchColumn() ?: 12)));
    if($hours===0)return;

    // Recover stale jobs so one crashed worker never blocks scheduled sync forever.
    $pdo->exec("UPDATE sync_jobs SET status='failed',message='Stale scheduled sync recovered',finished_at=CURRENT_TIMESTAMP
        WHERE status IN ('queued','running')
          AND datetime(COALESCE(started_at,created_at)) < datetime('now','-6 hours')");

    $query=$pdo->query("SELECT id FROM instances
        WHERE enabled=1
          AND (last_full_sync_at IS NULL OR datetime(last_full_sync_at) < datetime('now','-".$hours." hours'))");
    foreach($query->fetchAll() as $row){
        $instanceId=(int)$row['id'];
        $active=$pdo->prepare("SELECT id FROM sync_jobs WHERE instance_id=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
        $active->execute([$instanceId]);
        if($active->fetchColumn())continue;

        $pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source,cancel_requested)
            VALUES(?,'queued','Scheduled sync queued','scheduled',0)")->execute([$instanceId]);
        $jobId=(int)$pdo->lastInsertId();
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/sync-job.php').' '.$jobId.
            ' > '.escapeshellarg('/tmp/arrview-sync-'.$jobId.'.log').' 2>&1 &';
        exec($cmd);
    }
}

do{
    try{
        $db=new Database($databasePath);
        schedulerRun($db->pdo);
    }catch(Throwable $e){
        @file_put_contents(rtrim($dataDir,'/').'/scheduler-error.log',gmdate('c').' '.$e->getMessage().PHP_EOL,FILE_APPEND);
    }
    if($once)break;
    sleep(60);
}while(true);
