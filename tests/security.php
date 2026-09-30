<?php
declare(strict_types=1);

/**
 * Behavioural security and reliability regression tests.
 *
 * Runs from a checkout (`php tests/security.php`) or inside the image
 * (`php /tests/security.php`). Starts the app and the mock Arr server with the
 * PHP built-in server on free local ports.
 */

$appDir = getenv('ARRVIEW_APP_DIR') ?: (is_dir(dirname(__DIR__) . '/app/src') ? dirname(__DIR__) . '/app' : '/app');
$testsDir = __DIR__;

require_once $appDir . '/version.php';
require_once $appDir . '/src/Database.php';
require_once $appDir . '/src/ClientIp.php';
require_once $appDir . '/src/MetadataQuota.php';
require_once $appDir . '/src/SetupToken.php';
require_once $appDir . '/src/SecretService.php';
require_once $appDir . '/src/BackupService.php';
require_once $appDir . '/src/BatchSyncService.php';
require_once $appDir . '/src/Auth.php';
require_once $appDir . '/src/MetadataService.php';

$failures = 0;
function ok(bool $condition, string $message): void {
    global $failures;
    if ($condition) { echo "PASS: {$message}\n"; return; }
    $failures++;
    fwrite(STDERR, "FAIL: {$message}\n");
}

$root = sys_get_temp_dir() . '/arrview-security-' . bin2hex(random_bytes(5));
mkdir($root, 0775, true);
$children = [];
register_shutdown_function(static function () use (&$children, $root): void {
    foreach ($children as $proc) { @proc_terminate($proc); }
    exec('rm -rf ' . escapeshellarg($root));
});

function freePort(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($s, false);
    fclose($s);
    return (int)substr($name, strrpos($name, ':') + 1);
}

function startServer(array $args, array $env): int {
    global $children;
    $port = freePort();
    $cmd = array_merge([PHP_BINARY, '-S', "127.0.0.1:{$port}"], $args);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());
    $children[] = $proc;
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($c) { fclose($c); return $port; }
        usleep(100000);
    }
    throw new RuntimeException('Server did not start: ' . implode(' ', $cmd));
}

function http(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);
    if (isset($opts['cookie'])) { curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['cookie']); curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['cookie']); }
    if (isset($opts['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'location' => $location];
}

function csrf(string $html): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

// ---------------------------------------------------------------- ClientIp
ok(ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], '') === '10.0.0.2',
    'forwarded headers are ignored when no trusted proxy is configured');
ok(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], '10.0.0.0/8') === '203.0.113.9',
    'forwarded headers from an untrusted peer are ignored');
ok(ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 1.2.3.4, 10.0.0.9'], '10.0.0.0/8') === '1.2.3.4',
    'rightmost untrusted X-Forwarded-For hop is used (spoofed leftmost entries ignored)');
ok(ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_CF_CONNECTING_IP' => '198.51.100.4'], '10.0.0.2') === '198.51.100.4',
    'CF-Connecting-IP honoured from a trusted peer');
ok(ClientIp::matches('2001:db8::5', '2001:db8::/32') && !ClientIp::matches('2001:db9::5', '2001:db8::/32'),
    'IPv6 CIDR matching');

// ---------------------------------------------------------------- Login throttling behind a proxy
$authDb = new Database($root . '/auth.sqlite');
$authDb->pdo->prepare('INSERT INTO users(username,password_hash,role) VALUES(?,?,?)')
    ->execute(['admin', password_hash('CorrectPass123!', PASSWORD_DEFAULT), 'admin']);
putenv('ARRVIEW_TRUSTED_PROXIES=127.0.0.1');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.66';
$auth = @new Auth($authDb->pdo);
for ($i = 0; $i < 6; $i++) { $_SESSION = []; $auth->login('admin', 'wrong'); }
ok($auth->loginLockSeconds('admin') > 0, 'attacker behind the proxy gets locked out');
$_SESSION = [];
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
ok($auth->loginLockSeconds('admin') === 0, 'real admin from another client IP is not locked out by the attacker');
ok(@$auth->login('admin', 'CorrectPass123!'), 'real admin can still sign in');
putenv('ARRVIEW_TRUSTED_PROXIES');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);

// ---------------------------------------------------------------- Metadata quota (unit)
$quotaDb = new Database($root . '/quota.sqlite');
$quota = new MetadataQuota($quotaDb->pdo, 50, 3);
$results = [];
for ($i = 0; $i < 4; $i++) $results[] = $quota->consume('203.0.113.7')['allowed'];
ok($results === [true, true, true, false], 'hourly burst limit blocks the 4th request from one IP');
ok($quota->consume('198.51.100.8')['allowed'], 'a different client IP has its own quota');

// ---------------------------------------------------------------- Setup token (unit)
$setup = new SetupToken($root . '/setup-unit');
$token = $setup->ensure();
ok(strlen($token) >= 20 && $setup->ensure() === $token, 'setup token is generated once and stable');
ok(!$setup->verify('') && !$setup->verify('wrong') && $setup->verify($token), 'setup token verification');
ok((fileperms($setup->path()) & 0077) === 0, 'setup token file is not readable by other users');
$setup->clear();
ok(!is_file($setup->path()), 'setup token file is removed after setup');

// ---------------------------------------------------------------- Backup safety copies
$backupDir = $root . '/backup';
mkdir($backupDir);
$backupDb = new Database($backupDir . '/arrview.sqlite');
for ($i = 1; $i <= 8; $i++) touch($backupDir . sprintf('/pre-restore-20260101-0000%02d.sqlite', $i));
$removed = (new BackupService($backupDb->pdo, $backupDir . '/arrview.sqlite'))->pruneSafetyBackups(5);
$left = glob($backupDir . '/pre-restore-*.sqlite');
ok($removed === 3 && count($left) === 5 && in_array($backupDir . '/pre-restore-20260101-000008.sqlite', $left, true),
    'only the newest 5 pre-restore copies are kept');

// ---------------------------------------------------------------- No built-in metadata service
putenv('ARRVIEW_METADATA_API_URL');
putenv('ARRVIEW_SHARED_TMDB_BEARER_TOKEN');
$metaDb = new Database($root . '/meta.sqlite');
$metaService = new MetadataService($metaDb->pdo);
ok($metaService->settings()['free_endpoint'] === '', 'there is no built-in shared metadata service URL');
try {
    $metaService->enrich('movie', 603, true);
    ok(false, 'free mode without a configured service explains how to fix it');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'No shared metadata service is configured'), 'free mode without a configured service explains how to fix it');
}

// ---------------------------------------------------------------- Encryption at rest by default
putenv('ARRVIEW_ENCRYPTION_KEY');
$encData = $root . '/enc';
$encDb = new Database($encData . '/arrview.sqlite');
$encDb->pdo->prepare("INSERT INTO instances(name,type,url,api_key) VALUES('Enc','radarr','http://x','plain-secret-key-123')")->execute();
$runInit = static function (string $dir) use ($appDir): string {
    exec('ARRVIEW_ENCRYPTION_KEY= ARRVIEW_DATA=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($appDir . '/bin/encryption-init.php') . ' 2>&1', $out);
    return implode("\n", $out);
};
$runInit($encData);
$stored = (string)$encDb->pdo->query("SELECT api_key FROM instances WHERE name='Enc'")->fetchColumn();
$encSecret = new SecretService($encDb->pdo, $encData . '/encryption.key');
ok(is_file($encData . '/encryption.key') && (fileperms($encData . '/encryption.key') & 0077) === 0,
    'first boot generates a private encryption key file');
ok(str_starts_with($stored, 'enc:v1:') && $encSecret->reveal($stored) === 'plain-secret-key-123',
    'existing API keys are encrypted by default and still decrypt');
$encBackup = $root . '/enc-backup.sqlite';
(new BackupService($encDb->pdo, $encData . '/arrview.sqlite'))->create($encBackup);
ok(!str_contains((string)file_get_contents($encBackup), 'plain-secret-key-123'), 'downloaded backups do not contain plaintext API keys');
$otherKey = $root . '/other.key';
SecretService::ensureKeyFile($otherKey);
ok((new SecretService($encDb->pdo, $otherKey))->undecryptableCredentials() === ['Enc'],
    'credentials encrypted with a different key are detected (restore warning)');

$optOutData = $root . '/enc-optout';
$optOutDb = new Database($optOutData . '/arrview.sqlite');
$optOutDb->pdo->prepare("INSERT INTO instances(name,type,url,api_key) VALUES('Plain','radarr','http://x','kept-plain')")->execute();
(new SecretService($optOutDb->pdo, $root . '/unused.key'))->recordAdminChoice(); // admin clicked "Disable Encryption"
$runInit($optOutData);
ok($optOutDb->pdo->query("SELECT api_key FROM instances")->fetchColumn() === 'kept-plain',
    'an explicit admin choice to disable encryption is respected');

// ---------------------------------------------------------------- Sync worker guards and heartbeat
$mockPort = startServer([$testsDir . '/mock-arr.php'], []);
$syncData = $root . '/sync';
$syncDb = new Database($syncData . '/arrview.sqlite');
$syncDb->pdo->prepare("INSERT INTO instances(name,type,url,api_key,webhook_token,enabled) VALUES('Mock','radarr',?, 'k','hook-token',1)")
    ->execute(["http://127.0.0.1:{$mockPort}"]);
$instanceId = (int)$syncDb->pdo->lastInsertId();

$sync = new BatchSyncService($syncDb->pdo);
$sync->setHeartbeat(static fn(): bool => false);
$instance = $syncDb->pdo->query("SELECT * FROM instances WHERE id={$instanceId}")->fetch();
try {
    $sync->syncInstance($instance);
    ok(false, 'heartbeat returning false stops the sync');
} catch (RuntimeException $e) {
    ok($e->getMessage() === BatchSyncService::CANCELLED, 'heartbeat returning false stops the sync');
}

$runWorker = static function (int $jobId) use ($appDir, $syncData): int {
    exec('ARRVIEW_DATA=' . escapeshellarg($syncData) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($appDir . '/bin/sync-job.php') . ' ' . $jobId . ' 2>&1', $out, $code);
    return $code;
};
$syncDb->pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source) VALUES(?,'failed','Recovered as stale','manual')")->execute([$instanceId]);
$recoveredJob = (int)$syncDb->pdo->lastInsertId();
$runWorker($recoveredJob);
$state = $syncDb->pdo->query("SELECT status FROM sync_jobs WHERE id={$recoveredJob}")->fetchColumn();
ok($state === 'failed', 'a job already recovered as failed is never resurrected or marked completed');

$syncDb->pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source) VALUES(?,'running','Other worker','manual')")->execute([$instanceId]);
$otherRunning = (int)$syncDb->pdo->lastInsertId();
$syncDb->pdo->prepare("UPDATE sync_jobs SET heartbeat_at=CURRENT_TIMESTAMP,started_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$otherRunning]);
$syncDb->pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source) VALUES(?,'queued','Second','manual')")->execute([$instanceId]);
$second = (int)$syncDb->pdo->lastInsertId();
$runWorker($second);
ok($syncDb->pdo->query("SELECT status FROM sync_jobs WHERE id={$second}")->fetchColumn() === 'failed',
    'a second worker for the same instance refuses to run');
$syncDb->pdo->prepare("UPDATE sync_jobs SET status='completed' WHERE id=?")->execute([$otherRunning]);

$syncDb->pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source) VALUES(?,'queued','Normal','manual')")->execute([$instanceId]);
$normal = (int)$syncDb->pdo->lastInsertId();
$code = $runWorker($normal);
ok($code === 0 && $syncDb->pdo->query("SELECT status FROM sync_jobs WHERE id={$normal}")->fetchColumn() === 'completed'
    && (int)$syncDb->pdo->query('SELECT COUNT(*) FROM movies')->fetchColumn() === 2,
    'a normal queued job still syncs and completes');

// ---------------------------------------------------------------- Webhook drain worker
$queueDir = $syncData . '/webhooks';
@mkdir($queueDir, 0775, true);
for ($i = 0; $i < 3; $i++) {
    file_put_contents($queueDir . sprintf('/event-%.6f-%d.json', microtime(true), $i), json_encode(['instance_id' => 99999, 'event' => ['eventType' => 'Download']]));
}
$lock = fopen($queueDir . '/.drain.lock', 'c');
flock($lock, LOCK_EX);
$drainCmd = 'ARRVIEW_DATA=' . escapeshellarg($syncData) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($appDir . '/bin/webhook-job.php') . ' 2>&1';
$start = microtime(true);
exec($drainCmd, $o, $c);
ok($c === 0 && count(glob($queueDir . '/event-*.json')) === 3 && microtime(true) - $start < 5,
    'a second webhook worker exits immediately while another holds the queue');
flock($lock, LOCK_UN);
fclose($lock);
exec($drainCmd, $o, $c);
ok(count(glob($queueDir . '/event-*.json')) === 0, 'the drain worker processes and removes all queued events');

// ---------------------------------------------------------------- HTTP: the app itself
$appData = $root . '/app-data';
mkdir($appData);
$appPort = startServer(['-t', $appDir . '/public'], [
    'ARRVIEW_DATA' => $appData,
    'ARRVIEW_SHARED_TMDB_BEARER_TOKEN' => 'test-token-not-real',
    'ARRVIEW_FREE_METADATA_HOURLY_LIMIT' => '10',
]);
$base = "http://127.0.0.1:{$appPort}";

$health = json_decode(http('GET', "{$base}/health.php")['body'], true);
ok(($health['ok'] ?? false) === true && !isset($health['version']) && !isset($health['movies']),
    'anonymous health check reports status only (no version or library size)');

// First-run setup requires the token.
exec('ARRVIEW_DATA=' . escapeshellarg($appData) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($appDir . '/bin/setup-token.php'), $tokenOut);
$setupToken = trim((string)file_get_contents($appData . '/setup-token.txt'));
ok(str_contains(implode("\n", $tokenOut), $setupToken), 'setup token is printed to the container log on boot');

$jar = $root . '/admin.cookies';
$page = http('GET', "{$base}/setup.php", ['cookie' => $jar]);
$form = ['csrf_token' => csrf($page['body']), 'username' => 'owner', 'password' => 'OwnerPass123!', 'confirm_password' => 'OwnerPass123!'];
$noToken = http('POST', "{$base}/setup.php", ['cookie' => $jar, 'form' => $form + ['setup_token' => 'guess']]);
$appDb = new Database($appData . '/arrview.sqlite');
ok($noToken['status'] === 200 && (int)$appDb->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0,
    'setup without the correct token creates no admin');
$withToken = http('POST', "{$base}/setup.php", ['cookie' => $jar, 'form' => $form + ['setup_token' => $setupToken]]);
ok($withToken['status'] === 302 && (int)$appDb->pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() === 1
    && !is_file($appData . '/setup-token.txt'),
    'setup with the token creates the admin and deletes the token');

// Metadata quota cannot be bypassed by rotating X-ArrView-Install.
$hourKey = hash('sha256', 'ip:127.0.0.1');
$appDb->pdo->prepare('INSERT INTO shared_tmdb_usage(usage_key,usage_month,request_count) VALUES(?,?,10)')
    ->execute([$hourKey, 'h:' . gmdate('Y-m-d\TH')]);
$bypass = http('GET', "{$base}/metadata-api.php?type=movie&id=550", ['headers' => ['X-ArrView-Install: rotated-' . bin2hex(random_bytes(4))]]);
ok($bypass['status'] === 429, 'rotating X-ArrView-Install does not bypass the metadata quota');
$appDb->pdo->prepare("INSERT INTO shared_tmdb_cache(cache_key,payload_json) VALUES('movie:603','{\"id\":603}')")->execute();
$cached = http('GET', "{$base}/metadata-api.php?type=movie&id=603");
ok($cached['status'] === 200 && str_contains($cached['body'], '"cached":true'), 'cached metadata is still served when the quota is exhausted');

// Webhook token via header, query kept for compatibility.
$appDb->pdo->prepare("INSERT INTO instances(name,type,url,api_key,webhook_token,enabled) VALUES('Hook','radarr','http://127.0.0.1:1','k','hdr-token',1)")->execute();
$hookId = (int)$appDb->pdo->lastInsertId();
$testEvent = json_encode(['eventType' => 'Test']);
ok(str_contains(http('POST', "{$base}/webhook.php?instance={$hookId}", ['headers' => ['X-ArrView-Token: hdr-token', 'Content-Type: application/json'], 'body' => $testEvent])['body'], 'webhook connected'),
    'webhook accepts the token in the X-ArrView-Token header');
ok(str_contains(http('POST', "{$base}/webhook.php?instance={$hookId}&token=hdr-token", ['headers' => ['Content-Type: application/json'], 'body' => $testEvent])['body'], 'webhook connected'),
    'webhook still accepts the legacy ?token= query parameter');
ok(http('POST', "{$base}/webhook.php?instance={$hookId}", ['headers' => ['X-ArrView-Token: wrong'], 'body' => $testEvent])['status'] === 403,
    'webhook rejects a wrong header token');

// Instance URL change requires re-entering the key.
$edit = http('GET', "{$base}/instance-edit.php?id={$hookId}", ['cookie' => $jar]);
$moved = http('POST', "{$base}/instance-edit.php?id={$hookId}", ['cookie' => $jar, 'form' => [
    'csrf_token' => csrf($edit['body']), 'id' => $hookId, 'name' => 'Hook', 'type' => 'radarr', 'url' => 'https://evil.example', 'api_key' => '',
]]);
ok(str_contains($moved['body'], 'Re-enter the API key') && $appDb->pdo->query("SELECT url FROM instances WHERE id={$hookId}")->fetchColumn() === 'http://127.0.0.1:1',
    'changing the instance URL without re-entering the API key is refused');

// Viewers: no Deep Search, no internal Arr URLs.
$appDb->pdo->prepare('INSERT INTO users(username,password_hash,role) VALUES(?,?,?)')->execute(['viewer', password_hash('ViewerPass123!', PASSWORD_DEFAULT), 'viewer']);
$appDb->pdo->prepare("INSERT INTO movies(instance_id,remote_id,tmdb_id,title,year,has_file,monitored,availability_date) VALUES(?,1,603,'Missing Movie',2001,0,1,'2001-01-01T00:00:00Z')")->execute([$hookId]);
$movieId = (int)$appDb->pdo->lastInsertId();
// Cached light result, so the page renders its full diagnosis (the fixture Arr host is unreachable).
$appDb->pdo->prepare('INSERT INTO diagnostic_cache(cache_key,payload_json,checked_at) VALUES(?,?,CURRENT_TIMESTAMP)')->execute([
    "movie:{$hookId}:1:light",
    json_encode(['status' => 'missing', 'summary' => 'Missing', 'diagnosis' => ['label' => 'Not grabbed', 'severity' => 'warning', 'detail' => 'x'],
        'categories' => [], 'releases' => [], 'blocklist' => [], 'queue' => [], 'history' => [], 'deep_performed' => false]),
]);
$vjar = $root . '/viewer.cookies';
$login = http('GET', "{$base}/login.php", ['cookie' => $vjar]);
http('POST', "{$base}/login.php", ['cookie' => $vjar, 'form' => ['csrf_token' => csrf($login['body']), 'username' => 'viewer', 'password' => 'ViewerPass123!']]);
$diag = http('GET', "{$base}/diagnose.php?id={$movieId}&deep=1&refresh=1", ['cookie' => $vjar]);
ok($diag['status'] === 200 && !str_contains($diag['body'], 'Deep Search is querying') && !str_contains($diag['body'], 'Run Deep Search'),
    'viewers cannot run or see Deep Search');
$moviePage = http('GET', "{$base}/movie.php?id={$movieId}", ['cookie' => $vjar]);
ok($moviePage['status'] === 200 && !str_contains($moviePage['body'], 'http://127.0.0.1:1'), 'viewers do not see internal Radarr URLs');
ok(str_contains(http('GET', "{$base}/movie.php?id={$movieId}", ['cookie' => $jar])['body'], 'http://127.0.0.1:1'),
    'control: admins still see the Radarr link');
ok(str_contains(http('GET', "{$base}/diagnose.php?id={$movieId}", ['cookie' => $jar])['body'], 'Run Deep Search'),
    'control: admins still get Deep Search');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} security test(s) failed.\n");
    exit(1);
}
echo "\nAll security regression tests passed.\n";
