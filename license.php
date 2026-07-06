<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/config.php';

use SystemMonitoring\Support\UpdateState;

if (! function_exists('system_monitoring_license_status')) {
    function system_monitoring_license_status(): array
    {
        $config = system_monitoring_config();
        $state = UpdateState::load($config['state_file']);

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
                'state' => $state,
                'config' => $config,
            ];
        }

        return [
            'required' => true,
            'redirect' => false,
            'reason' => 'active_license',
            'license' => $license,
            'effective_status' => $effectiveStatus !== '' ? $effectiveStatus : null,
            'subscription_type' => $subscription !== '' ? $subscription : null,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'state' => $state,
            'config' => $config,
        ];
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

