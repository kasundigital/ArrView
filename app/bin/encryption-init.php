<?php
declare(strict_types=1);

/**
 * Runs on container start. Makes API-key encryption at rest the default:
 * - without ARRVIEW_ENCRYPTION_KEY, a random key is generated in <data>/encryption.key
 * - installs using that generated key are encrypted unless an admin toggled
 *   encryption in System (an explicit "Disable Encryption" is respected)
 */
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/SecretService.php';

if (!function_exists('sodium_crypto_secretbox')) {
    fwrite(STDERR, "ArrView: libsodium unavailable; API keys stay in plaintext.\n");
    exit(0);
}

$dataDir = rtrim(getenv('ARRVIEW_DATA') ?: dirname(__DIR__) . '/data', '/');
$db = new Database($dataDir . '/arrview.sqlite');
$keyFile = $dataDir . '/encryption.key';

if (trim((string)getenv('ARRVIEW_ENCRYPTION_KEY')) === '' && SecretService::ensureKeyFile($keyFile)) {
    echo "ArrView: generated API-key encryption key at {$keyFile}. Keep it with your data volume.\n";
}

$secret = new SecretService($db->pdo, $keyFile);
if (!$secret->configured()) {
    fwrite(STDERR, "ArrView: encryption key unavailable; API keys stay in plaintext.\n");
    exit(0);
}

$bad = $secret->undecryptableCredentials();
if ($bad) {
    fwrite(STDERR, 'ArrView: these credentials cannot be decrypted with the current key and must be re-entered: ' . implode(', ', $bad) . "\n");
    exit(0);
}

// Only auto-enable for installs using the generated key file. Admins who set their own
// ARRVIEW_ENCRYPTION_KEY, or toggled encryption in System, keep manual control.
if (!$secret->enabled() && $secret->keySource() === 'file' && !$secret->preferenceRecorded()) {
    $secret->setEnabled(true);
    echo "ArrView: API-key encryption at rest enabled.\n";
}
