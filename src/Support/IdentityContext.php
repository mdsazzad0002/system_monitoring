<?php

declare(strict_types=1);

namespace SystemMonitoring\Support;

final class IdentityContext
{
    public static function resolveTargetHost(array $env, string $projectRoot): string
    {
        $candidate = system_monitoring_env_value($env, ['targethost', 'target_host']);
        if ($candidate === null || strtolower(trim($candidate)) === 'auto') {
            $candidate = system_monitoring_env_value($env, ['APP_URL', 'app_url']);
        }

        if ($candidate === null || trim($candidate) === '') {
            return '';
        }

        $candidate = trim($candidate);
        $normalized = str_contains($candidate, '://') ? $candidate : 'https://' . $candidate;
        $host = parse_url($normalized, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return rtrim($candidate, '/');
        }

        $scheme = parse_url($normalized, PHP_URL_SCHEME) ?: 'https';

        return $scheme . '://' . $host . (parse_url($normalized, PHP_URL_PORT) ? ':' . parse_url($normalized, PHP_URL_PORT) : '');
    }

    public static function normalizeDomain(?string $value): ?string
    {
        $normalized = self::normalizeNullable($value);
        if ($normalized === null) {
            return null;
        }

        $candidate = str_contains($normalized, '://') ? $normalized : 'https://' . $normalized;
        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        if (self::isLocalHost($host)) {
            return null;
        }

        $domain = trim(strtolower($host), '.');

        return $domain !== '' ? $domain : null;
    }

    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '.'));

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.localhost')
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private static function normalizeNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
