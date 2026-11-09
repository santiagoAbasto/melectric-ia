<?php

declare(strict_types=1);

namespace App\Sources;

use RuntimeException;

/**
 * Protección SSRF para todo lo que descarga el sistema (spec §125): sólo http/https, sólo hosts de la lista blanca,
 * y nunca IPs de loopback, redes privadas, link-local ni metadatos de nube, aunque el DNS las devuelva.
 */
final class UrlGuard
{
    /** @param list<string> $allowedHosts */
    public static function assertSafe(string $url, array $allowedHosts): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Esquema no permitido: {$scheme}");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URL con credenciales embebidas');
        }
        if (! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException("Host fuera de la lista blanca: {$host}");
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new RuntimeException("No se pudo resolver {$host}");
        }
        foreach ($ips as $ip) {
            if (! self::publicIp($ip)) {
                throw new RuntimeException("{$host} resuelve a una IP no pública ({$ip})");
            }
        }
    }

    public static function publicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! str_starts_with($ip, '169.254.') && ! str_starts_with($ip, '100.100.') && $ip !== '0.0.0.0';
    }
}
