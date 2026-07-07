<?php

declare(strict_types=1);

namespace SystemMonitoring\Network;

use SystemMonitoring\Support\HttpClient;

final class RecoveryClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config
    ) {
    }

    public function recover(array $context = []): array
    {
        $recoveryUrl = (string) ($this->config['recovery_url'] ?? '');

        if ($recoveryUrl === '') {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Recovery URL is not configured.',
            ];
        }

        $payload = array_merge([
            'software' => $this->config['software_id'] ?? '',
            'license_key' => $this->config['license'] ?? '',
            'device_id' => $this->config['device_id'] ?? '',
            'domain' => $this->config['request_domain'] ?? '',
        ], $context);

        $response = $this->http->request('POST', $recoveryUrl, [
            'timeout' => $this->config['request_timeout'] ?? 30,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query($payload),
            'verify_ssl' => (bool) ($this->config['verify_ssl'] ?? true),
        ]);

        return [
            'ok' => $response['ok'],
            'status' => $response['status'] ?? 0,
            'body' => $response['body'] ?? '',
            'json' => $response['json'] ?? null,
        ];
    }
}
