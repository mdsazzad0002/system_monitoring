<?php

namespace SystemMonitoring\Core;

class FailureDetector
{
    private static $failed = false;

    public static function markFailed()
    {
        self::$failed = true;
    }

    public static function isFailed()
    {
        return self::$failed;
    }
}
