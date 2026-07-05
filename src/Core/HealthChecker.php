<?php

declare(strict_types=1);

namespace SystemMonitoring\Core;

use SystemMonitoring\Network\PingClient;

final class HealthChecker
{
    public function __construct(
        private readonly PingClient $pingClient
    ) {
    }

    public function check(): array
    {
        return $this->pingClient->ping();
    }
}
