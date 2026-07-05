<?php

declare(strict_types=1);

if (! function_exists('system_monitoring_config')) {
    function system_monitoring_config(): array
    {
        static $config = null;

        if (is_array($config)) {
            return $config;
        }

        $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $env = system_monitoring_load_env($projectRoot . DIRECTORY_SEPARATOR . '.env');

        $softwareId = system_monitoring_env_value($env, ['softwareid', 'software_id', 'software']) ?? 'default';
        $license = system_monitoring_env_value($env, ['license', 'license_key']) ?? '';
        $targetHost = rtrim(system_monitoring_env_value($env, ['targethost', 'target_host']) ?? '', '/');
        $currentVersion = system_monitoring_env_value($env, ['currentversion', 'current_version']) ?? '0.0.0';

        $downloadChunkSize = (int) (system_monitoring_env_value($env, ['download_chunk_size']) ?? 1048576);
        if ($downloadChunkSize < 65536) {
            $downloadChunkSize = 65536;
        }

        $downloadTimeout = (int) (system_monitoring_env_value($env, ['download_timeout']) ?? 30);
        if ($downloadTimeout < 5) {
            $downloadTimeout = 5;
        }

        $targetRoot = system_monitoring_env_value($env, ['update_target_root', 'target_root']);
        $targetRoot = $targetRoot !== null && $targetRoot !== ''
            ? system_monitoring_resolve_path($targetRoot, $projectRoot)
            : $projectRoot;

        $updateMode = strtolower(system_monitoring_env_value($env, ['update_mode', 'version_type']) ?? 'partial');
        if (! in_array($updateMode, ['full', 'partial'], true)) {
            $updateMode = 'partial';
        }

        $recoveryUrl = system_monitoring_env_value($env, ['recovery_url']);

        $config = [
            'project_root' => $projectRoot,
            'env_path' => $projectRoot . DIRECTORY_SEPARATOR . '.env',
            'log_file' => $projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring' . DIRECTORY_SEPARATOR . 'system_monitoring.log',
            'state_file' => $projectRoot . DIRECTORY_SEPARATOR . 'update_data' . DIRECTORY_SEPARATOR . 'updater.json',
            'download_root' => $projectRoot . DIRECTORY_SEPARATOR . 'update_data' . DIRECTORY_SEPARATOR . 'downloads',
            'backup_root' => $projectRoot . DIRECTORY_SEPARATOR . 'update_data' . DIRECTORY_SEPARATOR . 'backups',
            'software_id' => $softwareId,
            'license' => $license,
            'target_host' => $targetHost,
            'current_version' => $currentVersion,
            'update_mode' => $updateMode,
            'ping_url' => $targetHost !== '' ? $targetHost . '/api/system_monitoring/ping' : '',
            'verify_license_url' => $targetHost !== '' ? $targetHost . '/api/system_monitoring/verify-license' : '',
            'check_update_url' => $targetHost !== '' ? $targetHost . '/api/system_monitoring/update/check' : '',
            'recovery_url' => $targetHost !== '' && $recoveryUrl ? rtrim($recoveryUrl, '/') : '',
            'update_target_root' => $targetRoot,
            'auto_recovery' => system_monitoring_env_bool($env, ['auto_recovery'], true),
            'auto_download_update' => system_monitoring_env_bool($env, ['auto_download_update'], true),
            'allow_self_update' => system_monitoring_env_bool($env, ['allow_self_update'], false),
            'download_chunk_size' => $downloadChunkSize,
            'download_timeout' => $downloadTimeout,
            'request_timeout' => $downloadTimeout,
        ];

        $config['ping_url'] = $config['ping_url'] !== ''
            ? $config['ping_url'] . '?software=' . rawurlencode($config['software_id']) . '&license=' . rawurlencode($config['license'])
            : '';

        return $config;
    }

    function system_monitoring_load_env(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $result = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);

            if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }

    function system_monitoring_env_value(array $env, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $env)) {
                $value = trim((string) $env[$key]);
                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    function system_monitoring_env_bool(array $env, array $keys, bool $default = false): bool
    {
        $value = system_monitoring_env_value($env, $keys);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    function system_monitoring_resolve_path(string $path, string $basePath): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if ($path === '') {
            return '';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($normalized, DIRECTORY_SEPARATOR)) {
            return rtrim($normalized, "\\/");
        }

        return rtrim($basePath, "\\/") . DIRECTORY_SEPARATOR . ltrim($normalized, "\\/");
    }
}
