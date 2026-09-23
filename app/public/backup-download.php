<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$auth->requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required');
}
$auth->requireCsrf($_POST['csrf_token'] ?? null);

$tmpDir = rtrim($dataDir, '/') . '/backups';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0775, true);
$file = $tmpDir . '/arrview-backup-' . gmdate('Ymd-His') . '.sqlite';
$backup->create($file);

header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store');
readfile($file);
@unlink($file);
