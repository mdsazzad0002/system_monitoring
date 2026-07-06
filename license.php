<?php

declare(strict_types=1);

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/config.php';

use SystemMonitoring\Support\UpdateState;

if (! function_exists('system_monitoring_license_status')) {
    function system_monitoring_license_status(): array
    {
        $config = system_monitoring_config();
        $state = system_monitoring_load_cached_update_state((string) ($config['state_file'] ?? ''));
        $stateSignature = system_monitoring_file_signature((string) ($config['state_file'] ?? ''));
        $configSignature = system_monitoring_file_signature((string) ($config['env_path'] ?? ''))
            . '|'
            . system_monitoring_file_signature(system_monitoring_project_root() . DIRECTORY_SEPARATOR . 'system_monitoring_update_data' . DIRECTORY_SEPARATOR . 'system_monitoring.json');
        $cacheKey = 'system_monitoring.license_status.' . sha1(json_encode([
            'config' => $configSignature,
            'state' => $stateSignature,
            'license' => $config['license'] ?? '',
            'required' => (bool) ($config['license_required'] ?? true),
            'allow_unlicensed' => (bool) ($config['allow_unlicensed'] ?? false),
        ]));

        return system_monitoring_cache_remember($cacheKey, 60, static function () use ($config, $state): array {
            $license = trim((string) ($config['license'] ?? ''));
            $allowUnlicensed = (bool) ($config['allow_unlicensed'] ?? false);
            $required = (bool) ($config['license_required'] ?? true);

            if ($allowUnlicensed || ! $required) {
                return [
                    'required' => false,
                    'redirect' => false,
                    'reason' => 'unlicensed_mode',
                    'license' => $license,
                    'state' => $state,
                    'config' => $config,
                ];
            }

            if ($license === '') {
                return [
                    'required' => true,
                    'redirect' => true,
                    'reason' => 'missing_license',
                    'license' => $license,
                    'state' => $state,
                    'config' => $config,
                ];
            }

            $effectiveStatus = strtolower(trim((string) ($state['last_license_effective_status'] ?? $state['license_effective_status'] ?? '')));
            $subscription = strtolower(trim((string) ($state['last_license_subscription_type'] ?? $state['license_subscription_type'] ?? '')));
            $expiresAt = trim((string) ($state['last_license_expires_at'] ?? $state['license_expires_at'] ?? ''));
            $maintenanceEndDate = trim((string) ($state['last_license_maintenance_end_date'] ?? $state['license_maintenance_end_date'] ?? ''));
            $licenseValid = (bool) ($state['license_valid'] ?? false);

            if ($effectiveStatus !== '' && system_monitoring_license_is_blocked_status($effectiveStatus)) {
                return [
                    'required' => true,
                    'redirect' => true,
                    'reason' => 'expired_license',
                    'license' => $license,
                    'effective_status' => $effectiveStatus,
                    'subscription_type' => $subscription,
                    'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                    'maintenance_end_date' => $maintenanceEndDate !== '' ? $maintenanceEndDate : null,
                    'service_entitlement' => ! system_monitoring_maintenance_is_expired($maintenanceEndDate),
                    'state' => $state,
                    'config' => $config,
                ];
            }

            if ($expiresAt !== '') {
                try {
                    $expires = new DateTimeImmutable($expiresAt);
                    if ($expires->getTimestamp() <= time()) {
                        return [
                            'required' => true,
                            'redirect' => true,
                            'reason' => 'expired_license',
                            'license' => $license,
                            'effective_status' => $effectiveStatus !== '' ? $effectiveStatus : null,
                            'subscription_type' => $subscription !== '' ? $subscription : null,
                            'expires_at' => $expiresAt,
                            'maintenance_end_date' => $maintenanceEndDate !== '' ? $maintenanceEndDate : null,
                            'service_entitlement' => ! system_monitoring_maintenance_is_expired($maintenanceEndDate),
                            'state' => $state,
                            'config' => $config,
                        ];
                    }
                } catch (Throwable) {
                    // Ignore malformed expiry timestamps and fall back to the cached validity flag.
                }
            }

            if (! $licenseValid && ($state['last_license_check_at'] ?? null) !== null) {
                return [
                    'required' => true,
                    'redirect' => true,
                    'reason' => 'invalid_license',
                    'license' => $license,
                    'effective_status' => $effectiveStatus !== '' ? $effectiveStatus : null,
                    'subscription_type' => $subscription !== '' ? $subscription : null,
                    'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                    'maintenance_end_date' => $maintenanceEndDate !== '' ? $maintenanceEndDate : null,
                    'service_entitlement' => ! system_monitoring_maintenance_is_expired($maintenanceEndDate),
                    'state' => $state,
                    'config' => $config,
                ];
            }

            $maintenanceExpired = system_monitoring_maintenance_is_expired($maintenanceEndDate);

            return [
                'required' => true,
                'redirect' => false,
                'reason' => $maintenanceExpired ? 'maintenance_expired' : 'active_license',
                'license' => $license,
                'effective_status' => $effectiveStatus !== '' ? $effectiveStatus : null,
                'subscription_type' => $subscription !== '' ? $subscription : null,
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                'maintenance_end_date' => $maintenanceEndDate !== '' ? $maintenanceEndDate : null,
                'service_entitlement' => ! $maintenanceExpired,
                'state' => $state,
                'config' => $config,
            ];
        });
    }
}

if (! function_exists('system_monitoring_license_is_blocked_status')) {
    function system_monitoring_license_is_blocked_status(string $status): bool
    {
        return in_array($status, [
            'expired',
            'inactive',
            'disabled',
            'revoked',
            'suspended',
            'blocked',
            'invalid',
        ], true);
    }
}

if (! function_exists('system_monitoring_maintenance_is_expired')) {
    function system_monitoring_maintenance_is_expired(?string $maintenanceEndDate): bool
    {
        $maintenanceEndDate = trim((string) $maintenanceEndDate);

        if ($maintenanceEndDate === '') {
            return false;
        }

        try {
            $date = new DateTimeImmutable($maintenanceEndDate);

            return $date->setTime(23, 59, 59)->getTimestamp() < time();
        } catch (Throwable) {
            return false;
        }
    }
}

if (! function_exists('system_monitoring_file_signature')) {
    function system_monitoring_file_signature(string $path): string
    {
        if (! is_file($path)) {
            return 'missing';
        }

        $hash = @sha1_file($path);
        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        return (string) (filemtime($path) ?: 0) . ':' . (string) filesize($path);
    }
}

if (! function_exists('system_monitoring_load_cached_update_state')) {
    function system_monitoring_load_cached_update_state(string $path): array
    {
        $signature = system_monitoring_file_signature($path);
        $cacheKey = 'system_monitoring.update_state.' . sha1($path . '|' . $signature);

        return system_monitoring_cache_remember($cacheKey, 60, static function () use ($path): array {
            return UpdateState::load($path);
        });
    }
}
