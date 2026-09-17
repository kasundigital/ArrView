<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ArrService.php';
require_once __DIR__ . '/src/BatchSyncService.php';
require_once __DIR__ . '/src/Auth.php';

$dataDir = getenv('ARRVIEW_DATA') ?: (__DIR__ . '/data');
$db = new Database(rtrim($dataDir, '/') . '/arrview.sqlite');
$pdo = $db->pdo;
$arr = new ArrService($pdo);
$batchSync = new BatchSyncService($pdo);
$auth = new Auth($pdo);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
