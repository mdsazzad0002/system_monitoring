<?php

declare(strict_types=1);

namespace SystemMonitoring\Support;

final class UpdateState
{
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function save(string $path, array $state): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
    }

    public static function appendHistory(array &$state, string $event, array $payload = []): void
    {
        $state['history'] ??= [];
        $state['history'][] = array_merge([
            'at' => gmdate('c'),
            'event' => $event,
        ], $payload);
    }
}
