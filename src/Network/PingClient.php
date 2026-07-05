<?php

namespace SystemMonitoring\Network;

class PingClient
{
    public static function ping()
    {
        $config = require __DIR__ . '/../../config.php';

        $ch = curl_init($config['ping_url']);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return $http === 200;
    }
}
