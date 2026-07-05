<?php

declare(strict_types=1);

namespace SystemMonitoring\Core;

use SystemMonitoring\Network\RecoveryClient;
use SystemMonitoring\Support\MonitorLogger;
use SystemMonitoring\Support\UpdateState;

final class RecoveryManager
{
    public function __construct(
        private readonly RecoveryClient $recoveryClient,
        private readonly MonitorLogger $logger,
        private array &$state
    ) {
    }

    public function recover(array $context = []): array
    {
        $this->logger->warn('Recovery flow started.', $context);

        $result = $this->recoveryClient->recover($context);

        $this->state['last_recovery_at'] = gmdate('c');
        $this->state['last_recovery_status'] = $result['ok'] ?? false;
        UpdateState::appendHistory($this->state, 'recovery', [
            'status' => $result['ok'] ?? false,
            'message' => $result['message'] ?? null,
        ]);

        if ($result['ok'] ?? false) {
            $this->logger->info('Recovery request completed.');
        } else {
            $this->logger->info('Recovery URL is not configured or recovery skipped.', [
                'message' => $result['message'] ?? 'Unknown',
            ]);
        }

        return $result;
    }
}
