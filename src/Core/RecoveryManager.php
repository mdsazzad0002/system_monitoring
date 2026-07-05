<?php

namespace SystemMonitoring\Core;

use SystemMonitoring\Network\RecoveryClient;

class RecoveryManager
{
    public static function recover()
    {
        $config = require __DIR__ . '/../../config.php';

        if (!$config['auto_recovery']) {
            return;
        }

        RecoveryClient::trigger($config['restore_url']);
    }
}
