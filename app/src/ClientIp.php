<?php

declare(strict_types=1);

/**
 * Resolves the real client IP address.
 *
 * Forwarding headers are client-controlled and are only honoured when the direct
 * peer (REMOTE_ADDR) is listed in ARRVIEW_TRUSTED_PROXIES (comma-separated IPs or
 * CIDR ranges, e.g. "172.16.0.0/12,10.0.0.5"). Without that setting ArrView uses
 * REMOTE_ADDR as-is, which is safe but means every request behind a reverse proxy
 * shares the proxy's address.
 */
final class ClientIp
{
    public static function resolve(?array $server = null, ?string $trustedSetting = null): string
    {
        $server ??= $_SERVER;
        $remote = trim((string)($server['REMOTE_ADDR'] ?? ''));
        if (!self::valid($remote)) return 'unknown';

        $trusted = self::parseList($trustedSetting ?? (string)getenv('ARRVIEW_TRUSTED_PROXIES'));
        if (!$trusted || !self::inList($remote, $trusted)) return $remote;

        $cf = trim((string)($server['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (self::valid($cf)) return $cf;

        $forwarded = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            // Walk right-to-left: the rightmost entries were appended by our own proxies.
            $hops = array_reverse(array_map('trim', explode(',', $forwarded)));
            foreach ($hops as $hop) {
                if (!self::valid($hop)) break;
                if (!self::inList($hop, $trusted)) return $hop;
            }
        }

        $real = trim((string)($server['HTTP_X_REAL_IP'] ?? ''));
        if (self::valid($real)) return $real;

        return $remote;
    }

    /** @return list<string> */
    public static function parseList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
    }

    public static function inList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if (self::matches($ip, $entry)) return true;
        }
        return false;
    }

    public static function matches(string $ip, string $cidr): bool
    {
        $bits = null;
        if (str_contains($cidr, '/')) {
            [$cidr, $bitsRaw] = explode('/', $cidr, 2);
            if (!ctype_digit($bitsRaw)) return false;
            $bits = (int)$bitsRaw;
        }
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($cidr);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;

        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null ? $maxBits : min($bits, $maxBits);
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $netBin, $bytes) !== 0) return false;
        $remainder = $bits % 8;
        if ($remainder === 0) return true;
        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    private static function valid(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
