<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$auth->requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required');
}

try {
    $auth->requireCsrf($_POST['csrf_token'] ?? null);
    if (!isset($_FILES['backup_file']) || !is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
        throw new RuntimeException('Choose an ArrView .sqlite backup file.');
    }
    if ((int)($_FILES['backup_file']['size'] ?? 0) > 1024 * 1024 * 1024) {
        throw new RuntimeException('Backup file is too large.');
    }

    $result = $backup->restore($_FILES['backup_file']['tmp_name']);
    $_SESSION['flash_success'] = 'ArrView backup restored successfully. ' . count($result['tables']) . ' data tables recovered.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Restore failed: ' . $e->getMessage();
}
redirect('/system.php');
