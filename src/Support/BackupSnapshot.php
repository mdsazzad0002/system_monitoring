<?php

declare(strict_types=1);

namespace SystemMonitoring\Support;

final class BackupSnapshot
{
    public static function create(array $config, array $updateInfo, array $state): array
    {
        $version = (string) ($updateInfo['latest_version'] ?? $updateInfo['version'] ?? 'unknown');
        $versionSegment = self::safeSegment($version);
        $mode = strtolower((string) ($updateInfo['version_type'] ?? $updateInfo['update_type'] ?? $config['update_mode'] ?? 'partial'));
        if (! in_array($mode, ['full', 'partial'], true)) {
            $mode = 'partial';
        }

        $timestamp = gmdate('YmdHis');
        $root = rtrim($config['backup_root'], "\\/");
        $backupDir = $root . DIRECTORY_SEPARATOR . $versionSegment . DIRECTORY_SEPARATOR . $mode . DIRECTORY_SEPARATOR . $timestamp;
        $envPath = (string) ($config['env_path'] ?? '');
        $envContents = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        $envData = self::parseEnv($envContents);

        $snapshot = [
            'created_at' => gmdate('c'),
            'software_id' => $config['software_id'] ?? null,
            'current_version' => $state['current_version'] ?? $config['current_version'] ?? null,
            'target_version' => $version,
            'version_type' => $mode,
            'paths' => [
                'backup_root' => $root,
                'backup_directory' => $backupDir,
                'env_path' => $envPath,
            ],
            'environment' => $envData,
            'database' => self::databaseSnapshot($envData),
            'update' => [
                'download_url' => $updateInfo['download_url'] ?? $updateInfo['file_url'] ?? $updateInfo['update_link'] ?? null,
                'force_update' => (bool) ($updateInfo['force_update'] ?? false),
                'changelog' => $updateInfo['changelog'] ?? null,
            ],
            'state' => [
                'last_boot_at' => $state['last_boot_at'] ?? null,
                'last_ping_ok' => $state['last_ping_ok'] ?? null,
                'last_update_check_at' => $state['last_update_check_at'] ?? null,
                'last_known_version' => $state['last_known_version'] ?? null,
            ],
        ];

        @mkdir($backupDir, 0777, true);
        file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'backup.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);

        if ($envContents !== '') {
            file_put_contents($backupDir . DIRECTORY_SEPARATOR . '.env.backup', $envContents, LOCK_EX);
        }

        return [
            'ok' => true,
            'backup_directory' => $backupDir,
            'backup_json' => $backupDir . DIRECTORY_SEPARATOR . 'backup.json',
            'version_type' => $mode,
            'target_version' => $version,
        ];
    }

    private static function parseEnv(string $contents): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            if (
                $value !== ''
                && (
                    (($value[0] ?? '') === '"' && substr($value, -1) === '"')
                    || (($value[0] ?? '') === "'" && substr($value, -1) === "'")
                )
            ) {
                $value = substr($value, 1, -1);
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private static function databaseSnapshot(array $envData): array
    {
        return [
            'connection' => $envData['DB_CONNECTION'] ?? null,
            'host' => $envData['DB_HOST'] ?? null,
            'port' => $envData['DB_PORT'] ?? null,
            'database' => $envData['DB_DATABASE'] ?? null,
            'username' => $envData['DB_USERNAME'] ?? null,
            'password' => $envData['DB_PASSWORD'] ?? null,
            'charset' => $envData['DB_CHARSET'] ?? null,
            'collation' => $envData['DB_COLLATION'] ?? null,
        ];
    }

    private static function safeSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unknown';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'unknown';

        return trim($value, '.-_') ?: 'unknown';
    }
}
