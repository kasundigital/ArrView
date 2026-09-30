<?php

declare(strict_types=1);

/**
 * One-time token required to create the first administrator.
 *
 * Without it, whoever reaches a fresh install first becomes its admin. The token is
 * printed to the container logs on boot and stored in <data>/setup-token.txt; set
 * ARRVIEW_SETUP_TOKEN to choose it yourself. It is deleted once setup completes.
 */
final class SetupToken
{
    public function __construct(private string $dataDir)
    {
    }

    public function path(): string
    {
        return rtrim($this->dataDir, '/') . '/setup-token.txt';
    }

    public function ensure(): string
    {
        $fromEnv = trim((string)getenv('ARRVIEW_SETUP_TOKEN'));
        if ($fromEnv !== '') return $fromEnv;

        $path = $this->path();
        if (is_file($path)) {
            $existing = trim((string)@file_get_contents($path));
            if ($existing !== '') return $existing;
        }

        $token = implode('-', str_split(bin2hex(random_bytes(12)), 6));
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0770, true);
        $old = umask(0077);
        $written = @file_put_contents($path, $token . PHP_EOL, LOCK_EX);
        umask($old);
        if ($written === false) throw new RuntimeException('Could not write the setup token file.');
        return $token;
    }

    public function verify(?string $candidate): bool
    {
        $candidate = trim((string)$candidate);
        return $candidate !== '' && hash_equals($this->ensure(), $candidate);
    }

    public function clear(): void
    {
        @unlink($this->path());
    }
}
