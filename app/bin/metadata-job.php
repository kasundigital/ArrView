<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/version.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/MetadataService.php';

$jobId=(int)($argv[1]??0);
if($jobId<1) exit(1);
$dataDir=getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data';
$db=new Database(rtrim($dataDir,'/').'/arrview.sqlite');
$pdo=$db->pdo;
$service=new MetadataService($pdo);
$pdo->prepare("UPDATE metadata_jobs SET status='running',started_at=CURRENT_TIMESTAMP,message='Preparing metadata enrichment' WHERE id=?")->execute([$jobId]);

try{
    $result=$service->enrichAll(function(int $current,int $total,string $title) use($pdo,$jobId){
        $pdo->prepare('UPDATE metadata_jobs SET current_item=?,total_items=?,current_title=?,message=? WHERE id=?')
            ->execute([$current,$total,$title,'Enriching TMDB metadata',$jobId]);
    });
    $pdo->prepare("UPDATE metadata_jobs SET status='completed',current_item=total_items,message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$result['message']??'Metadata enrichment completed',$jobId]);
}catch(Throwable $e){
    $pdo->prepare("UPDATE metadata_jobs SET status='failed',message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$e->getMessage(),$jobId]);
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(2);
}
