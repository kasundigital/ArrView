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
if (!is_dir($tmpDir)) mkdir($tmpDir, 0770, true);

// Backups contain API keys and password hashes. Remove leftovers from earlier
// interrupted downloads, and make sure this one is deleted even if the client
// disconnects mid-transfer.
foreach (glob($tmpDir . '/arrview-backup-*.sqlite') ?: [] as $stale) {
    if (filemtime($stale) < time() - 600) @unlink($stale);
}
ignore_user_abort(true);

$file = $tmpDir . '/arrview-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
try {
    $backup->create($file);
    @chmod($file, 0600);

    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="arrview-backup-' . gmdate('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) ob_end_clean();
    readfile($file);
} finally {
    @unlink($file);
}
