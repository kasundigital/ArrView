<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$auth->requireAdmin();
header('Content-Type: application/json');
$id=(int)($_GET['job_id']??0);
$stmt=$pdo->prepare('SELECT * FROM metadata_jobs WHERE id=? LIMIT 1');$stmt->execute([$id]);$job=$stmt->fetch();
if(!$job){http_response_code(404);echo json_encode(['ok'=>false,'message'=>'Job not found']);exit;}
$total=(int)$job['total_items'];$current=(int)$job['current_item'];
echo json_encode(['ok'=>true,'status'=>$job['status'],'current'=>$current,'total'=>$total,'percent'=>$total>0?round(($current/$total)*100,1):0,'title'=>$job['current_title'],'message'=>$job['message']]);
