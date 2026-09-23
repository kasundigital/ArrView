<?php

declare(strict_types=1);

final class SecretService
{
    private const PREFIX = 'enc:v1:';
    private ?string $key = null;

    public function __construct(private PDO $pdo)
    {
        $raw = trim((string)getenv('ARRVIEW_ENCRYPTION_KEY'));
        if ($raw !== '' && function_exists('sodium_crypto_secretbox')) {
            if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
                $key = hex2bin($raw);
            } else {
                $decoded = base64_decode($raw, true);
                $key = is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
                    ? $decoded
                    : sodium_crypto_generichash($raw, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            }
            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                $this->key = $key;
            }
        }
    }

    public function supported(): bool
    {
        return function_exists('sodium_crypto_secretbox');
    }

    public function configured(): bool
    {
        return $this->key !== null;
    }

    public function enabled(): bool
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='encrypt_api_keys' LIMIT 1");
        $stmt->execute();
        return (string)$stmt->fetchColumn() === '1';
    }

    public function reveal(?string $value): string
    {
        $value = (string)$value;
        if (!str_starts_with($value, self::PREFIX)) return $value;
        if (!$this->configured()) {
            throw new RuntimeException('Encrypted API credentials exist, but ARRVIEW_ENCRYPTION_KEY is not configured.');
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted credential is invalid.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if (!is_string($plain)) throw new RuntimeException('Could not decrypt API credential.');
        return $plain;
    }

    public function protect(?string $value): string
    {
        $value = (string)$value;
        if ($value === '' || str_starts_with($value, self::PREFIX)) return $value;
        if (!$this->enabled()) return $value;
        if (!$this->configured()) {
            throw new RuntimeException('Enable encryption only after ARRVIEW_ENCRYPTION_KEY is configured.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($value, $nonce, $this->key);
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function setEnabled(bool $enabled): void
    {
        if ($enabled && !$this->configured()) {
            throw new RuntimeException('ARRVIEW_ENCRYPTION_KEY must be configured before enabling encryption at rest.');
        }

        $this->pdo->beginTransaction();
        try {
            $instances = $this->pdo->query('SELECT id,api_key FROM instances')->fetchAll();
            $updateInstance = $this->pdo->prepare('UPDATE instances SET api_key=? WHERE id=?');
            foreach ($instances as $row) {
                $current = (string)$row['api_key'];
                $new = $enabled
                    ? $this->encryptValue($this->reveal($current))
                    : $this->reveal($current);
                $updateInstance->execute([$new, (int)$row['id']]);
            }

            $stmt = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='tmdb_api_key' LIMIT 1");
            $stmt->execute();
            $tmdb = $stmt->fetchColumn();
            if ($tmdb !== false && (string)$tmdb !== '') {
                $current = (string)$tmdb;
                $new = $enabled
                    ? $this->encryptValue($this->reveal($current))
                    : $this->reveal($current);
                $this->pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='tmdb_api_key'")
                    ->execute([$new]);
            }

            $this->pdo->prepare(
                "INSERT INTO app_settings(setting_key,setting_value) VALUES('encrypt_api_keys',?)
                 ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value"
            )->execute([$enabled ? '1' : '0']);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function encryptValue(string $value): string
    {
        if ($value === '') return '';
        if (!$this->configured()) throw new RuntimeException('Encryption key is unavailable.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->key));
    }
}
