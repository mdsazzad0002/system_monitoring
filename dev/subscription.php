<?php

use Illuminate\Http\Request;
use SystemMonitoring\Network\VerifyLicenseClient;
use SystemMonitoring\Support\HttpClient;
use SystemMonitoring\Support\UpdateState;
use Illuminate\Support\Facades\Route;

Route::redirect('/subscription', '/subscription/', 302);

Route::get('/subscription/', function () {
    return view('system-monitoring.subscription');
})->name('subscription.index');

Route::get('/subscription/data', function () {
    require_once base_path('system_monitoring/license.php');

    $status = system_monitoring_license_status();
    $state = is_array($status['state'] ?? null) ? $status['state'] : [];
    $expiresAt = (string) ($status['expires_at'] ?? $state['last_license_expires_at'] ?? $state['license_expires_at'] ?? '');
    $subscriptionType = (string) ($status['subscription_type'] ?? $state['last_license_subscription_type'] ?? $state['license_subscription_type'] ?? '');
    $effectiveStatus = (string) ($status['effective_status'] ?? $state['last_license_effective_status'] ?? $state['license_effective_status'] ?? '');
    $maintenanceEndDate = (string) ($status['maintenance_end_date'] ?? $state['last_license_maintenance_end_date'] ?? $state['license_maintenance_end_date'] ?? '');
    $serviceEntitlement = (bool) ($status['service_entitlement'] ?? ! system_monitoring_maintenance_is_expired($maintenanceEndDate));

    return response()->json([
        'message' => ($status['redirect'] ?? false)
            ? 'Subscription required.'
            : ($serviceEntitlement ? 'Subscription active.' : 'Maintenance expired.'),
        'license_required' => (bool) ($status['required'] ?? true),
        'license_reason' => $status['reason'] ?? null,
        'license' => $status['license'] ?? '',
        'license_effective_status' => $effectiveStatus !== '' ? $effectiveStatus : null,
        'subscription_type' => $subscriptionType !== '' ? $subscriptionType : null,
        'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        'maintenance_end_date' => $maintenanceEndDate !== '' ? $maintenanceEndDate : null,
        'service_entitlement' => $serviceEntitlement,
        'expires_at_label' => $expiresAt !== '' ? (function () use ($expiresAt) {
            try {
                return (new DateTimeImmutable($expiresAt))->format('d M Y, h:i A');
            } catch (Throwable) {
                return $expiresAt;
            }
        })() : null,
        'maintenance_end_date_label' => $maintenanceEndDate !== '' ? (function () use ($maintenanceEndDate) {
            try {
                return (new DateTimeImmutable($maintenanceEndDate))->format('d M Y');
            } catch (Throwable) {
                return $maintenanceEndDate;
            }
        })() : null,
    ]);
})->name('subscription.data');

Route::post('/subscription/save', function (Request $request) {
    $request->validate([
        'license' => ['required', 'string', 'max:255'],
    ]);

    $path = base_path('system_monitoring_update_data/system_monitoring.json');
    require_once base_path('system_monitoring/license.php');

    \system_monitoring_update_json_config($path, [
        'license' => trim((string) $request->input('license')),
    ]);

    $systemConfig = system_monitoring_config();
    $licenseClient = new VerifyLicenseClient(new HttpClient(), $systemConfig);
    $verification = $licenseClient->verify();
    $verificationData = is_array($verification['json'] ?? null) ? $verification['json'] : [];

    $state = UpdateState::load($systemConfig['state_file']);
    $state['last_license_check_at'] = gmdate('c');
    $state['last_license_check_http'] = (int) ($verification['status'] ?? 0);
    $state['license_valid'] = (bool) ($verification['ok'] ?? false);
    $state['last_license_subscription_type'] = $verificationData['subscription_type'] ?? null;
    $state['last_license_effective_status'] = $verificationData['effective_status'] ?? null;
    $state['last_license_expires_at'] = $verificationData['expires_at'] ?? null;
    $state['last_license_maintenance_end_date'] = $verificationData['maintenance_end_date'] ?? null;
    $state['license_subscription_type'] = $verificationData['subscription_type'] ?? null;
    $state['license_effective_status'] = $verificationData['effective_status'] ?? null;
    $state['license_expires_at'] = $verificationData['expires_at'] ?? null;
    $state['license_maintenance_end_date'] = $verificationData['maintenance_end_date'] ?? null;
    UpdateState::save($systemConfig['state_file'], $state);

    return response()->json([
        'status' => true,
        'message' => 'License updated successfully.',
        'verification' => [
            'ok' => (bool) ($verification['ok'] ?? false),
            'subscription_type' => $verificationData['subscription_type'] ?? null,
            'effective_status' => $verificationData['effective_status'] ?? null,
            'expires_at' => $verificationData['expires_at'] ?? null,
            'maintenance_end_date' => $verificationData['maintenance_end_date'] ?? null,
            'expires_at_label' => isset($verificationData['expires_at']) && $verificationData['expires_at'] !== null
                ? (function () use ($verificationData) {
                    try {
                        return (new DateTimeImmutable((string) $verificationData['expires_at']))->format('d M Y, h:i A');
                    } catch (Throwable) {
                        return (string) $verificationData['expires_at'];
                    }
                })()
                : null,
        ],
    ]);
})->name('subscription.save');

Route::match(['get', 'post'], '/subscription/run', function (Request $request) {
    require_once base_path('system_monitoring/license.php');

    $status = system_monitoring_license_status();
    return response()->json([
        'status' => false,
        'message' => 'Subscription page is read-only.',
        'reason' => $status['reason'] ?? 'missing_license',
        'command' => $request->input('command'),
        'output' => 'License validation is required before running updater commands.',
    ], 403);
})->name('subscription.run');
