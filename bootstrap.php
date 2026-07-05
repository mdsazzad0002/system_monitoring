<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

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
    'daemon' => in_array('--daemon', $argv ?? [], true),
];

exit(CycleRunner::run($options));
