<?php
require_once dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $pdo->query('SELECT 1')->fetchColumn();
    $payload=[
        'ok'=>true,
        'version'=>ARRVIEW_VERSION,
        'database'=>'ok',
        'movies'=>(int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn(),
        'series'=>(int)$pdo->query('SELECT COUNT(*) FROM series')->fetchColumn(),
        'episodes'=>(int)$pdo->query('SELECT COUNT(*) FROM episodes')->fetchColumn(),
        'time'=>gmdate('c'),
    ];
    echo json_encode($payload,JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'version'=>ARRVIEW_VERSION,'database'=>'error','time'=>gmdate('c')]);
}
