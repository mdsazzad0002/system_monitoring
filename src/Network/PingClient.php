<?php

declare(strict_types=1);

namespace SystemMonitoring\Network;

use SystemMonitoring\Support\HttpClient;

final class PingClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config
    ) {
    }

    public function ping(): array
    {
        if (($this->config['ping_url'] ?? '') === '') {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Ping URL is not configured.',
                'url' => '',
            ];
        }

        $response = $this->http->request('GET', $this->config['ping_url'], [
            'timeout' => $this->config['request_timeout'] ?? 30,
            'verify_ssl' => (bool) ($this->config['verify_ssl'] ?? true),
        ]);

        if (! $response['ok'] && (int) ($response['status'] ?? 0) >= 500) {
            usleep(250000);
            $retry = $this->http->request('GET', $this->config['ping_url'], [
                'timeout' => $this->config['request_timeout'] ?? 30,
                'verify_ssl' => (bool) ($this->config['verify_ssl'] ?? true),
            ]);

            if ($retry['ok']) {
                $response = $retry;
                $response['retry'] = true;
            }
        }

        return [
            'ok' => $response['ok'] && ($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300,
            'status' => $response['status'] ?? 0,
            'url' => $this->config['ping_url'],
            'body' => $response['body'] ?? '',
            'json' => $response['json'] ?? null,
            'error' => $response['error'] ?? null,
            'retry' => (bool) ($response['retry'] ?? false),
        ];
    }
}
