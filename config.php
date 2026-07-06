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
        $fallbackEnv = system_monitoring_load_env($projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . '.env');
        $jsonConfig = system_monitoring_load_json_config($projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'system_monitoring.json');

        if ($fallbackEnv !== []) {
            $env = array_merge($fallbackEnv, $env);
        }

        if ($jsonConfig !== []) {
            $env = array_merge($env, $jsonConfig);
        }

        $softwareId = system_monitoring_env_value($env, ['softwareid', 'software_id', 'software']) ?? 'default';
        $license = system_monitoring_env_value($env, ['license', 'license_key']) ?? '';
        $licenseRequired = system_monitoring_env_bool($env, ['license_required'], true);
        $allowUnlicensed = system_monitoring_env_bool($env, ['allow_unlicensed', 'developer_mode'], false);
        $targetHost = rtrim(\SystemMonitoring\Support\IdentityContext::resolveTargetHost($env, $projectRoot), '/');
        $currentVersion = system_monitoring_env_value($env, ['currentversion', 'current_version']) ?? '0.0.0';
        $deviceId = system_monitoring_detect_device_id($env);
        $requestDomain = \SystemMonitoring\Support\IdentityContext::normalizeDomain($targetHost);

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
        $databaseBackupTimes = system_monitoring_env_value($env, ['database_backup_times']) ?? '12:00,22:00';
        $databaseBackupRetryMinutes = (int) (system_monitoring_env_value($env, ['database_backup_retry_minutes']) ?? 30);
        if ($databaseBackupRetryMinutes < 1) {
            $databaseBackupRetryMinutes = 1;
        }

        $databaseBackupStaleRetryMinutes = (int) (system_monitoring_env_value($env, ['database_backup_stale_retry_minutes']) ?? 10);
        if ($databaseBackupStaleRetryMinutes < 1) {
            $databaseBackupStaleRetryMinutes = 1;
        }

        $databaseBackupMinGapHours = (int) (system_monitoring_env_value($env, ['database_backup_min_gap_hours']) ?? 6);
        if ($databaseBackupMinGapHours < 1) {
            $databaseBackupMinGapHours = 1;
        }

        $databaseBackupChunkSize = (int) (system_monitoring_env_value($env, ['database_backup_chunk_size']) ?? 2097152);
        if ($databaseBackupChunkSize < 65536) {
            $databaseBackupChunkSize = 65536;
        }

        $databaseBackupTimeout = (int) (system_monitoring_env_value($env, ['database_backup_timeout']) ?? 60);
        if ($databaseBackupTimeout < 5) {
            $databaseBackupTimeout = 5;
        }

        $updateCacheTtl = (int) (system_monitoring_env_value($env, ['update_cache_ttl_seconds', 'update_check_cache_ttl_seconds', 'update_check_ttl_seconds']) ?? 3600);
        if ($updateCacheTtl < 300) {
            $updateCacheTtl = 300;
        }

        $databaseBackupRoot = system_monitoring_env_value($env, ['database_backup_root', 'backup_temp_root']);
        $databaseBackupRoot = $databaseBackupRoot !== null && $databaseBackupRoot !== ''
            ? system_monitoring_resolve_path($databaseBackupRoot, $projectRoot)
            : $projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'database_backups';

        $config = [
            'project_root' => $projectRoot,
            'env_path' => $projectRoot . DIRECTORY_SEPARATOR . '.env',
            'log_file' => $projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'system_monitoring.log',
            'state_file' => $projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'updater.json',
            'download_root' => $projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'downloads',
            'backup_root' => $projectRoot . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'backups',
            'software_id' => $softwareId,
            'license' => $license,
            'license_required' => $licenseRequired,
            'allow_unlicensed' => $allowUnlicensed,
            'device_id' => $deviceId,
            'target_host' => $targetHost,
            'request_domain' => $requestDomain,
            'current_version' => $currentVersion,
            'update_mode' => $updateMode,
            'ping_url' => $targetHost !== '' ? $targetHost . '/api/system_monitoring/ping' : '',
            'verify_license_url' => $targetHost !== '' ? $targetHost . '/api/verify-license' : '',
            'check_update_url' => $targetHost !== '' ? $targetHost . '/api/system_monitoring/update/check' : '',
            'backup_api_base_url' => $targetHost !== '' ? $targetHost . '/api' : '',
            'backup_ping_url' => $targetHost !== '' ? $targetHost . '/api/ping' : '',
            'backup_initialize_url' => $targetHost !== '' ? $targetHost . '/api/backup/initialize' : '',
            'backup_chunk_url' => $targetHost !== '' ? $targetHost . '/api/backup/chunk' : '',
            'backup_complete_url' => $targetHost !== '' ? $targetHost . '/api/backup/complete' : '',
            'recovery_url' => $targetHost !== '' && $recoveryUrl ? rtrim($recoveryUrl, '/') : '',
            'update_target_root' => $targetRoot,
            'auto_recovery' => system_monitoring_env_bool($env, ['auto_recovery'], true),
            'auto_download_update' => system_monitoring_env_bool($env, ['auto_download_update'], true),
            'auto_database_backup' => system_monitoring_env_bool($env, ['auto_database_backup', 'auto_backup'], true),
            'database_backup_times' => $databaseBackupTimes,
            'database_backup_retry_minutes' => $databaseBackupRetryMinutes,
            'database_backup_stale_retry_minutes' => $databaseBackupStaleRetryMinutes,
            'database_backup_min_gap_hours' => $databaseBackupMinGapHours,
            'database_backup_chunk_size' => $databaseBackupChunkSize,
            'database_backup_timeout' => $databaseBackupTimeout,
            'database_backup_root' => $databaseBackupRoot,
            'database_backup_keep_local' => system_monitoring_env_bool($env, ['database_backup_keep_local'], false),
            'allow_self_update' => system_monitoring_env_bool($env, ['allow_self_update'], false),
            'update_cache_ttl_seconds' => $updateCacheTtl,
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

    function system_monitoring_load_json_config(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (is_bool($value)) {
                $result[$key] = $value ? 'true' : 'false';
                continue;
            }

            if (is_int($value) || is_float($value) || is_string($value) || $value === null) {
                $result[$key] = $value;
                continue;
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

    function system_monitoring_detect_device_id(array $env): string
    {
        $override = system_monitoring_env_value($env, ['device_id', 'device_fingerprint']);
        if ($override !== null) {
            return strtoupper(trim($override));
        }

        $parts = [];

        $hostname = gethostname();
        if (is_string($hostname) && $hostname !== '') {
            $parts[] = $hostname;
        }

        $computerName = getenv('COMPUTERNAME');
        if (is_string($computerName) && $computerName !== '') {
            $parts[] = $computerName;
        }

        $parts[] = php_uname('n');
        $parts[] = php_uname('s') . '|' . php_uname('r') . '|' . php_uname('m');

        $macAddresses = system_monitoring_collect_mac_addresses();
        foreach ($macAddresses as $macAddress) {
            $parts[] = $macAddress;
        }

        $source = implode('|', array_values(array_filter($parts, fn ($value) => is_string($value) && trim($value) !== '')));
        if ($source === '') {
            $source = (string) microtime(true);
        }

        return 'DEV-' . strtoupper(substr(hash('sha256', $source), 0, 24));
    }

    /**
     * @return array<int, string>
     */
    function system_monitoring_collect_mac_addresses(): array
    {
        $addresses = [];

        $commands = ['getmac /fo csv /nh'];

        if (system_monitoring_has_command('wmic')) {
            $commands[] = 'wmic nic where NetEnabled=true get MACAddress /value';
        }

        foreach ($commands as $command) {
            $output = @shell_exec($command);
            if (! is_string($output) || trim($output) === '') {
                continue;
            }

            if (preg_match_all('/([0-9A-F]{2}(?:[-:][0-9A-F]{2}){5})/i', $output, $matches) === 1) {
                foreach ($matches[1] as $match) {
                    $normalized = strtoupper(str_replace('-', ':', trim($match)));
                    $addresses[$normalized] = $normalized;
                }
            }
        }

        return array_values($addresses);
    }

    function system_monitoring_has_command(string $command): bool
    {
        $output = @shell_exec('where ' . $command . ' 2>NUL');

        return is_string($output) && trim($output) !== '';
    }
}
