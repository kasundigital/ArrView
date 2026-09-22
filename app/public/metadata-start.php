<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$auth->requireAdmin();
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
try{$auth->requireCsrf($_POST['csrf_token']??null);}catch(Throwable $e){http_response_code(403);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);exit;}
$active=$pdo->query("SELECT id FROM metadata_jobs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")->fetchColumn();
if($active){echo json_encode(['ok'=>true,'job_id'=>(int)$active,'existing'=>true]);exit;}
$pdo->exec("INSERT INTO metadata_jobs(status,message) VALUES('queued','Metadata enrichment queued')");
$jobId=(int)$pdo->lastInsertId();
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/metadata-job.php').' '.$jobId.' > '.escapeshellarg('/tmp/arrview-metadata-'.$jobId.'.log').' 2>&1 &';
exec($cmd);
echo json_encode(['ok'=>true,'job_id'=>$jobId]);
