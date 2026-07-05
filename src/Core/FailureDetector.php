<?php

declare(strict_types=1);

namespace SystemMonitoring\Core;

final class FailureDetector
{
    public function hasFailed(array $healthResult): bool
    {
        return ! ($healthResult['ok'] ?? false);
    }
}
