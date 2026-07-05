<?php

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/src/Core/HealthChecker.php';
require_once __DIR__ . '/src/Core/FailureDetector.php';
require_once __DIR__ . '/src/Core/RecoveryManager.php';

require_once __DIR__ . '/src/Network/PingClient.php';
require_once __DIR__ . '/src/Network/RecoveryClient.php';

use SystemMonitoring\Core\HealthChecker;
use SystemMonitoring\Core\FailureDetector;
use SystemMonitoring\Core\RecoveryManager;

HealthChecker::check();

if (FailureDetector::isFailed()) {
    RecoveryManager::recover();
}
