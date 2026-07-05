<?php

namespace SystemMonitoring\Core;

use SystemMonitoring\Network\PingClient;

class HealthChecker
{
    public static function check()
    {
        $ping = PingClient::ping();

        if (!$ping) {
            FailureDetector::markFailed();
        }
    }
}
