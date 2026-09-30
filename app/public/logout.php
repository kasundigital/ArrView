<?php
require_once dirname(__DIR__) . '/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST required');
}
try {
    $auth->requireCsrf($_POST['csrf_token'] ?? null);
} catch (Throwable $e) {
    http_response_code(403);
    exit('Invalid security token.');
}
$auth->logout();
redirect('/login.php');
