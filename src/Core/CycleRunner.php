<?php

declare(strict_types=1);

namespace SystemMonitoring\Core;

use SystemMonitoring\Network\CheckUpdateClient;
use SystemMonitoring\Network\DatabaseBackupClient;
use SystemMonitoring\Network\PingClient;
use SystemMonitoring\Network\RecoveryClient;
use SystemMonitoring\Network\UpdateApplyClient;
use SystemMonitoring\Network\UpdateDownloadClient;
use SystemMonitoring\Network\VerifyLicenseClient;
use SystemMonitoring\Support\HttpClient;
use SystemMonitoring\Support\BackupSnapshot;
use SystemMonitoring\Support\MonitorLogger;
use SystemMonitoring\Support\UpdateState;

final class CycleRunner
{
    public static function run(array $options = []): int
    {
        $config = system_monitoring_config();
        $logger = new MonitorLogger($config['log_file']);
        $http = new HttpClient();
        $state = UpdateState::load($config['state_file']);

        if (self::licenseIsMissingAndRequired($config)) {
            $logger->error('Bootstrap stopped. License key is required but missing.', [
                'profile' => $config['profile'] ?? 'unknown',
            ]);
            return 6;
        }

        $lockHandle = self::acquireLock($config['state_file'] . '.lock', $logger);
        if ($lockHandle === null) {
            return 0;
        }

        try {
            return self::runInternal($options, $config, $logger, $http, $state);
        } finally {
            UpdateState::save($config['state_file'], $state);
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private static function runInternal(array $options, array $config, MonitorLogger $logger, HttpClient $http, array &$state): int
    {
        $currentVersion = (string) ($state['current_version'] ?? $config['current_version']);

        $logger->info('Bootstrap started.', [
            'software_id' => $config['software_id'],
            'current_version' => $currentVersion,
            'manual' => (bool) ($options['manual'] ?? false),
            'download' => (bool) ($options['download'] ?? false),
        ]);

        $state['softwareid'] = $config['software_id'];
        $state['current_version'] = $currentVersion;
        $state['last_boot_at'] = gmdate('c');

        $pingClient = new PingClient($http, $config);
        $healthChecker = new HealthChecker($pingClient);

        $health = $healthChecker->check();
        $state['last_ping_ok'] = (bool) ($health['ok'] ?? false);
        $state['last_ping_http'] = (int) ($health['status'] ?? 0);
        $state['last_ping_url'] = $config['ping_url'];
        UpdateState::appendHistory($state, $health['ok'] ? 'ping_ok' : 'ping_failed', [
            'message' => $health['ok'] ? 'Ping successful.' : ($health['error'] ?? 'Ping failed.'),
            'retry' => (bool) ($health['retry'] ?? false),
        ]);

        if (! $health['ok']) {
            $logger->error('Ping failed.', [
                'url' => $config['ping_url'],
                'http' => $health['status'] ?? 0,
                'error' => $health['error'] ?? null,
                'retry' => (bool) ($health['retry'] ?? false),
                'body' => $health['body'] ?? null,
            ]);

            if ($config['auto_recovery']) {
                $recoveryManager = new RecoveryManager(
                    new RecoveryClient($http, $config),
                    $logger,
                    $state
                );
                $recoveryManager->recover([
                    'reason' => 'ping_failed',
                    'status' => $health['status'] ?? 0,
                ]);
            }

            return 2;
        }

        $logger->info('Health check passed.');

        if (self::isPingOnly($options)) {
            return 0;
        }

        $licenseClient = new VerifyLicenseClient($http, $config);
        $firstRun = ! (bool) ($state['first_run_completed'] ?? false);
        $manual = (bool) ($options['manual'] ?? false);
        $downloadRequested = (bool) ($options['download'] ?? false);
        $backupRequested = (bool) ($options['backup_now'] ?? false);

        if ($firstRun || $manual) {
            $license = $licenseClient->verify();
            $state['last_license_check_at'] = gmdate('c');
            $state['last_license_check_http'] = (int) ($license['status'] ?? 0);
            $state['license_valid'] = (bool) ($license['ok'] ?? false);
            UpdateState::appendHistory($state, 'license_check', [
                'status' => $license['ok'] ?? false,
                'http' => $license['status'] ?? 0,
            ]);

            if (! ($license['ok'] ?? false)) {
                $logger->error('License verification failed.', [
                    'http' => $license['status'] ?? 0,
                    'message' => $license['message'] ?? null,
                ]);
                return 3;
            }

            $state['first_run_completed'] = true;
            $state['baseline_version'] = $state['baseline_version'] ?? $config['current_version'];
            UpdateState::save($config['state_file'], $state);

            $logger->info('License verification succeeded.', [
                'subscription' => $license['json']['subscription_type'] ?? null,
                'effective_status' => $license['json']['effective_status'] ?? null,
            ]);

            if (self::isLicenseOnly($options)) {
                return 0;
            }
        }

        $databaseBackupClient = new DatabaseBackupClient($http, $config, $logger);
        $backupResult = $databaseBackupClient->backup($state, $backupRequested);
        if (isset($backupResult['message']) && ($backupResult['skipped'] ?? false)) {
            $logger->info('Database backup skipped.', [
                'message' => $backupResult['message'],
            ]);
        } elseif (! ($backupResult['ok'] ?? false)) {
            $logger->warn('Database backup failed or was not completed.', [
                'message' => $backupResult['message'] ?? null,
                'slot' => $backupResult['slot'] ?? null,
            ]);
        }

        if ($firstRun && ! $manual && ! $downloadRequested) {
            $logger->info('First run completed. Update check will start on the next cycle.');
            return 0;
        }

        $updateClient = new CheckUpdateClient($http, $config);
        $updateCheck = $updateClient->check($currentVersion);

        $state['last_update_check_at'] = gmdate('c');
        $state['last_update_http'] = (int) ($updateCheck['status'] ?? 0);
        $state['last_update_response'] = $updateCheck['json'] ?? null;
        UpdateState::appendHistory($state, 'update_check', [
            'status' => $updateCheck['ok'] ?? false,
            'http' => $updateCheck['status'] ?? 0,
        ]);

        if (! ($updateCheck['ok'] ?? false)) {
            $logger->warn('Update check request failed.', [
                'http' => $updateCheck['status'] ?? 0,
                'message' => $updateCheck['message'] ?? null,
            ]);
            return 4;
        }

        if (self::isUpdateCheckOnly($options)) {
            return 0;
        }

        $response = $updateCheck['json'] ?? [];
        $updateAvailable = (bool) ($response['update_available'] ?? false);
        $versionType = strtolower((string) ($response['version_type'] ?? $response['update_type'] ?? $config['update_mode']));
        if (! in_array($versionType, ['full', 'partial'], true)) {
            $versionType = $config['update_mode'];
        }
        $state['last_update_available'] = $updateAvailable;
        $state['last_known_version'] = $response['latest_version'] ?? null;
        $state['last_update_from'] = $currentVersion;
        $state['last_update_to'] = $response['latest_version'] ?? $currentVersion;
        $state['last_update_mode'] = $versionType;

        if (! $updateAvailable) {
            $logger->info('No update available.', [
                'current_version' => $currentVersion,
                'latest_version' => $response['latest_version'] ?? null,
                'version_type' => $versionType,
            ]);
            return 0;
        }

        $logger->warn('Update available.', [
            'from' => $currentVersion,
            'to' => $response['latest_version'] ?? null,
            'version_type' => $response['version_type'] ?? $response['update_type'] ?? $config['update_mode'],
        ]);

        if (! ($config['auto_download_update'] ?? false) && ! $downloadRequested) {
            $logger->info('Auto download is disabled. Update was only checked.');
            return 0;
        }

        $backupResult = BackupSnapshot::create($config, array_merge($response, [
            'version_type' => $response['version_type'] ?? $response['update_type'] ?? $config['update_mode'],
        ]), $state);
        $state['last_backup_at'] = gmdate('c');
        $state['last_backup_directory'] = $backupResult['backup_directory'] ?? null;
        $state['last_backup_json'] = $backupResult['backup_json'] ?? null;
        $state['last_backup_version'] = $backupResult['target_version'] ?? null;
        $state['last_backup_mode'] = $backupResult['version_type'] ?? null;
        UpdateState::appendHistory($state, 'backup_created', [
            'backup_directory' => $backupResult['backup_directory'] ?? null,
            'backup_json' => $backupResult['backup_json'] ?? null,
            'version_type' => $backupResult['version_type'] ?? null,
            'target_version' => $backupResult['target_version'] ?? null,
        ]);

        $logger->info('Pre-update backup created.', [
            'backup_directory' => $backupResult['backup_directory'] ?? null,
            'backup_json' => $backupResult['backup_json'] ?? null,
            'version_type' => $backupResult['version_type'] ?? null,
            'target_version' => $backupResult['target_version'] ?? null,
        ]);

        $downloadClient = new UpdateDownloadClient($http, $config, $logger, $state);
        $downloadResult = $downloadClient->download($response);

        if (! ($downloadResult['ok'] ?? false)) {
            $logger->error('Update download failed.', [
                'message' => $downloadResult['message'] ?? null,
            ]);
            return 5;
        }

        $state['last_download_path'] = $downloadResult['package_path'] ?? null;

        $applyClient = new UpdateApplyClient($config, $logger, $state);
        $applyResult = $applyClient->apply((string) ($downloadResult['package_path'] ?? ''));

        if (! ($applyResult['ok'] ?? false)) {
            $logger->warn('Apply skipped or failed.', [
                'message' => $applyResult['message'] ?? null,
            ]);
            return 0;
        }

        $state['last_applied_package'] = $downloadResult['package_path'] ?? null;
        $state['last_applied_target_root'] = $applyResult['target_root'] ?? null;
        $state['current_version'] = (string) ($downloadResult['version'] ?? $response['latest_version'] ?? $currentVersion);

        $logger->info('Update cycle finished successfully.', [
            'package_path' => $downloadResult['package_path'] ?? null,
            'target_root' => $applyResult['target_root'] ?? null,
            'current_version' => $state['current_version'],
        ]);

        return 0;
    }

    private static function acquireLock(string $lockPath, MonitorLogger $logger)
    {
        $directory = dirname($lockPath);
        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            $logger->warn('Could not open lock file.', ['path' => $lockPath]);
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            $logger->info('Another updater process is already running.');
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private static function licenseIsMissingAndRequired(array $config): bool
    {
        $license = trim((string) ($config['license'] ?? ''));
        $required = (bool) ($config['license_required'] ?? true);
        $allowUnlicensed = (bool) ($config['allow_unlicensed'] ?? false);

        if ($allowUnlicensed) {
            return false;
        }

        return $required && $license === '';
    }

    private static function isPingOnly(array $options): bool
    {
        return (bool) ($options['ping_only'] ?? false);
    }

    private static function isLicenseOnly(array $options): bool
    {
        return (bool) ($options['license_only'] ?? false);
    }

    private static function isUpdateCheckOnly(array $options): bool
    {
        return (bool) ($options['update_check_only'] ?? false);
    }
}
