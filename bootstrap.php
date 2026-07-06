<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'SystemMonitoring\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use SystemMonitoring\Core\CycleRunner;

$options = [
    'manual' => in_array('--manual', $argv ?? [], true),
    'download' => in_array('--download-update', $argv ?? [], true),
    'force_update_check' => in_array('--force-update-check', $argv ?? [], true),
    'backup_now' => in_array('--backup-now', $argv ?? [], true),
    'ping_only' => in_array('--ping-only', $argv ?? [], true),
    'license_only' => in_array('--license-only', $argv ?? [], true),
    'update_check_only' => in_array('--update-check-only', $argv ?? [], true),
    'daemon' => in_array('--daemon', $argv ?? [], true),
];

exit(CycleRunner::run($options));
