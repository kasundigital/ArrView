<?php
declare(strict_types=1);

// Prints the one-time setup token to the container logs while no admin exists.
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/SetupToken.php';

$dataDir = rtrim(getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data', '/');
$db = new Database($dataDir . '/arrview.sqlite');
$setup = new SetupToken($dataDir);

if ((int)$db->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    $setup->clear();
    exit(0);
}

$token = $setup->ensure();
echo PHP_EOL
    . '==================================================' . PHP_EOL
    . ' ArrView first-run setup token: ' . $token . PHP_EOL
    . ' Enter it on the setup page to create the admin.' . PHP_EOL
    . '==================================================' . PHP_EOL . PHP_EOL;
