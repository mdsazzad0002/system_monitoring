<?php

declare(strict_types=1);

if (! function_exists('system_monitoring_project_root')) {
    function system_monitoring_project_root(): string
    {
        return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }
}

if (! function_exists('system_monitoring_runtime_cache_path')) {
    function system_monitoring_runtime_cache_path(): string
    {
        return system_monitoring_project_root() . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'runtime_cache.json';
    }
}

if (! function_exists('system_monitoring_cache_schema_path')) {
    function system_monitoring_cache_schema_path(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'update_cache.schema.json';
    }
}

if (! function_exists('system_monitoring_load_runtime_cache')) {
    function system_monitoring_load_runtime_cache(): array
    {
        $path = system_monitoring_runtime_cache_path();
        if (! is_file($path)) {
            return system_monitoring_runtime_cache_default();
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return system_monitoring_runtime_cache_default();
        }

        return array_replace_recursive(system_monitoring_runtime_cache_default(), $decoded);
    }
}

if (! function_exists('system_monitoring_save_runtime_cache')) {
    function system_monitoring_save_runtime_cache(array $cache): void
    {
        $path = system_monitoring_runtime_cache_path();
        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode(array_replace_recursive(system_monitoring_runtime_cache_default(), $cache), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
    }
}

if (! function_exists('system_monitoring_cache_remember')) {
    function system_monitoring_cache_remember(string $key, int $ttlSeconds, callable $callback)
    {
        static $memoryCache = [];

        $ttlSeconds = max(1, $ttlSeconds);

        if (function_exists('cache')) {
            return cache()->remember($key, $ttlSeconds, $callback);
        }

        $now = time();
        if (isset($memoryCache[$key]) && ($memoryCache[$key]['expires_at'] ?? 0) > $now) {
            return $memoryCache[$key]['value'];
        }

        $value = $callback();
        $memoryCache[$key] = [
            'expires_at' => $now + $ttlSeconds,
            'value' => $value,
        ];

        return $value;
    }
}

if (! function_exists('system_monitoring_runtime_cache_default')) {
    function system_monitoring_runtime_cache_default(): array
    {
        return [
            'schema_version' => 1,
            'daemon' => [
                'last_spawn_at' => null,
                'next_spawn_at' => null,
                'last_spawn_key' => null,
                'last_spawn_ok' => false,
            ],
            'update' => [
                'checked_at' => null,
                'expires_at' => null,
                'software_id' => null,
                'current_version' => null,
                'target_host' => null,
                'status' => null,
                'source' => null,
                'response' => null,
                'error' => null,
            ],
        ];
    }
}

if (! function_exists('system_monitoring_runtime_cache_is_fresh')) {
    function system_monitoring_runtime_cache_is_fresh(array $updateCache): bool
    {
        $expiresAt = (string) ($updateCache['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }

        try {
            $expires = new DateTimeImmutable($expiresAt);
        } catch (Exception $exception) {
            return false;
        }

        return $expires->getTimestamp() > time();
    }
}

if (! function_exists('system_monitoring_runtime_cache_matches')) {
    function system_monitoring_runtime_cache_matches(array $updateCache, array $config, string $currentVersion): bool
    {
        $softwareId = (string) ($updateCache['software_id'] ?? '');
        $targetHost = (string) ($updateCache['target_host'] ?? '');
        $cachedVersion = (string) ($updateCache['current_version'] ?? '');

        return $softwareId !== ''
            && $softwareId === (string) ($config['software_id'] ?? '')
            && $targetHost === (string) ($config['target_host'] ?? '')
            && $cachedVersion === $currentVersion;
    }
}

if (! function_exists('system_monitoring_runtime_cache_should_use_update')) {
    function system_monitoring_runtime_cache_should_use_update(array $cache, array $config, string $currentVersion, array $options = []): bool
    {
        if ((bool) ($options['force_update_check'] ?? false)) {
            return false;
        }

        if ((bool) ($options['manual'] ?? false)) {
            return false;
        }

        $ttlSeconds = max(300, (int) ($config['update_cache_ttl_seconds'] ?? 3600));
        $updateCache = $cache['update'] ?? [];

        if (! is_array($updateCache)) {
            return false;
        }

        if (! system_monitoring_runtime_cache_matches($updateCache, $config, $currentVersion)) {
            return false;
        }

        $checkedAt = (string) ($updateCache['checked_at'] ?? '');
        if ($checkedAt === '') {
            return false;
        }

        try {
            $checked = new DateTimeImmutable($checkedAt);
        } catch (Exception $exception) {
            return false;
        }

        return $checked->getTimestamp() + $ttlSeconds > time() && system_monitoring_runtime_cache_is_fresh($updateCache);
    }
}

if (! function_exists('system_monitoring_build_update_cache_entry')) {
    function system_monitoring_build_update_cache_entry(array $config, string $currentVersion, array $response, int $status): array
    {
        $ttlSeconds = max(300, (int) ($config['update_cache_ttl_seconds'] ?? 3600));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            'checked_at' => $now->format(DateTimeInterface::ATOM),
            'expires_at' => $now->modify('+' . $ttlSeconds . ' seconds')->format(DateTimeInterface::ATOM),
            'software_id' => (string) ($config['software_id'] ?? ''),
            'current_version' => $currentVersion,
            'target_host' => (string) ($config['target_host'] ?? ''),
            'status' => $status,
            'source' => 'remote',
            'response' => $response,
            'error' => null,
        ];
    }
}

if (! function_exists('system_monitoring_update_cache_response')) {
    function system_monitoring_update_cache_response(array $cache): array
    {
        $updateCache = $cache['update'] ?? [];
        if (! is_array($updateCache) || ! isset($updateCache['response']) || ! is_array($updateCache['response'])) {
            return [];
        }

        return $updateCache['response'];
    }
}
