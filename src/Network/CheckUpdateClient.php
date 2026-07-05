<?php

declare(strict_types=1);

namespace SystemMonitoring\Network;

use SystemMonitoring\Support\HttpClient;

final class CheckUpdateClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config
    ) {
    }

    public function check(string $version): array
    {
        if (($this->config['check_update_url'] ?? '') === '') {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Update check URL is not configured.',
            ];
        }

        $payload = [
            'software' => $this->config['software_id'] ?? '',
            'version' => $version,
            'license_key' => $this->config['license'] ?? '',
        ];

        $response = $this->http->request('POST', $this->config['check_update_url'], [
            'timeout' => $this->config['request_timeout'] ?? 30,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query($payload),
        ]);

        return [
            'ok' => $response['ok'],
            'status' => $response['status'] ?? 0,
            'json' => $response['json'] ?? null,
            'body' => $response['body'] ?? '',
            'message' => $response['json']['message'] ?? null,
        ];
    }
}
