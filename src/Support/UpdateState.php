<?php

declare(strict_types=1);

namespace SystemMonitoring\Support;

final class UpdateState
{
    private const HISTORY_LIMIT = 30;

    public static function load(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        return self::normalize($decoded);
    }

    public static function save(string $path, array $state): void
    {
        $state = self::normalize($state);
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

        $historyCount = count($state['history']);
        if ($historyCount > self::HISTORY_LIMIT) {
            $state['history'] = array_slice($state['history'], -self::HISTORY_LIMIT);
        }
    }

    private static function normalize(array $state): array
    {
        if (isset($state['history']) && is_array($state['history']) && count($state['history']) > self::HISTORY_LIMIT) {
            $state['history'] = array_slice($state['history'], -self::HISTORY_LIMIT);
        }

        return $state;
    }
}
