<?php
require_once dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $pdo->query('SELECT 1')->fetchColumn();
    // Anonymous callers (uptime monitors, Docker health checks) only need up/down.
    // Version and library size are shown to signed-in admins only.
    $payload=['ok'=>true,'database'=>'ok','time'=>gmdate('c')];
    $viewer=$auth->user();
    if($viewer && ($viewer['role'] ?? '')==='admin'){
        $payload+=[
            'version'=>ARRVIEW_VERSION,
            'movies'=>(int)$pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn(),
            'series'=>(int)$pdo->query('SELECT COUNT(*) FROM series')->fetchColumn(),
            'episodes'=>(int)$pdo->query('SELECT COUNT(*) FROM episodes')->fetchColumn(),
        ];
    }
    echo json_encode($payload,JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'database'=>'error','time'=>gmdate('c')]);
}
