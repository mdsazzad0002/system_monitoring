<?php

declare(strict_types=1);

namespace SystemMonitoring\Hooks;

use SystemMonitoring\Core\RecoveryManager;

final class OnFail
{
    public static function handle(RecoveryManager $recoveryManager, array $context = []): array
    {
        return $recoveryManager->recover($context);
    }
}
