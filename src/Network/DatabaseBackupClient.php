<?php

declare(strict_types=1);

namespace SystemMonitoring\Network;

use DateTimeImmutable;
use SystemMonitoring\Support\HttpClient;
use SystemMonitoring\Support\MonitorLogger;
use SystemMonitoring\Support\UpdateState;

final class DatabaseBackupClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
        private readonly MonitorLogger $logger
    ) {
    }

    public function backup(array &$state, bool $force = false): array
    {
        if (! $force && ! ($this->config['auto_database_backup'] ?? false)) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Automatic database backup is disabled.'];
        }

        if (($this->config['backup_initialize_url'] ?? '') === '' || ($this->config['backup_chunk_url'] ?? '') === '' || ($this->config['backup_complete_url'] ?? '') === '') {
            return ['ok' => false, 'skipped' => true, 'message' => 'Backup API URL is not configured.'];
        }

        $slot = $this->resolveDueSlot($state, $force);
        if ($slot === null) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Database backup is not due yet.'];
        }

        $this->logger->info('Database backup scheduled.', [
            'slot' => $slot['slot'],
            'force' => $force,
        ]);

        $dumpResult = $this->createDatabaseDump($slot);
        if (! ($dumpResult['ok'] ?? false)) {
            $message = (string) ($dumpResult['message'] ?? 'Database dump failed.');
            $this->logger->error('Database dump failed.', [
                'slot' => $slot['slot'],
                'message' => $message,
            ]);
            $this->markSlotFailure($state, $slot, 'dump_failed', $message);
            UpdateState::appendHistory($state, 'database_backup_failed', [
                'slot' => $slot['slot'],
                'message' => $message,
            ]);

            return ['ok' => false, 'message' => $message, 'slot' => $slot['slot']];
        }

        $filePath = (string) $dumpResult['file_path'];
        $fileName = basename($filePath);
        $uploadResult = $this->sendBackup($filePath, $fileName, $slot);

        if (! ($uploadResult['ok'] ?? false)) {
            $message = (string) ($uploadResult['message'] ?? 'Backup upload failed.');
            $this->logger->error('Database backup upload failed.', [
                'slot' => $slot['slot'],
                'message' => $message,
            ]);
            $this->markSlotFailure($state, $slot, $this->isBusyStatus($uploadResult) ? 'busy' : 'upload_failed', $message);
            UpdateState::appendHistory($state, 'database_backup_failed', [
                'slot' => $slot['slot'],
                'message' => $message,
            ]);

            return ['ok' => false, 'message' => $message, 'slot' => $slot['slot']];
        }

        $state['last_database_backup_at'] = gmdate('c');
        $state['last_database_backup_success_at'] = gmdate('c');
        $state['last_database_backup_slot'] = $slot['slot_key'];
        $state['last_database_backup_remote_id'] = $uploadResult['backup_id'] ?? null;
        $state['last_database_backup_remote_path'] = $uploadResult['metadata_path'] ?? null;
        $state['last_database_backup_local_path'] = $filePath;
        $state['last_database_backup_status'] = 'completed';
        $this->markSlotSuccess($state, $slot);
        UpdateState::appendHistory($state, 'database_backup_completed', [
            'slot' => $slot['slot'],
            'backup_id' => $uploadResult['backup_id'] ?? null,
            'metadata_path' => $uploadResult['metadata_path'] ?? null,
        ]);

        $this->logger->info('Database backup completed.', [
            'slot' => $slot['slot'],
            'backup_id' => $uploadResult['backup_id'] ?? null,
            'metadata_path' => $uploadResult['metadata_path'] ?? null,
        ]);

        if (! ($this->config['database_backup_keep_local'] ?? false)) {
            $this->cleanupLocalBackup($filePath);
        }

        return [
            'ok' => true,
            'slot' => $slot['slot'],
            'backup_id' => $uploadResult['backup_id'] ?? null,
            'metadata_path' => $uploadResult['metadata_path'] ?? null,
        ];
    }

    private function resolveDueSlot(array $state, bool $force): ?array
    {
        $now = new DateTimeImmutable('now');

        if ($force) {
            return [
                'slot' => $now->format('Y-m-d H:i:s') . ' forced',
                'slot_key' => 'forced-' . $now->format('YmdHis'),
            ];
        }

        $times = $this->parseScheduleTimes((string) ($this->config['database_backup_times'] ?? '12:00,22:00'));
        $slots = $this->buildSlots($times, $now);
        $minimumGapHours = max(1, (int) ($this->config['database_backup_min_gap_hours'] ?? 6));
        $lastSuccessAt = $state['last_database_backup_success_at'] ?? null;

        if (is_string($lastSuccessAt) && $lastSuccessAt !== '') {
            try {
                $lastSuccess = new DateTimeImmutable($lastSuccessAt);
                if ($now < $lastSuccess->modify('+' . $minimumGapHours . ' hours')) {
                    return null;
                }
            } catch (\Throwable) {
                // Ignore malformed timestamps and continue scheduling.
            }
        }

        foreach ($slots as $slot) {
            $slotState = $state['database_backup_slots'][$slot['slot_key']] ?? [];

            if (($slotState['status'] ?? null) === 'completed') {
                continue;
            }

            if (($slotState['next_retry_at'] ?? null) !== null) {
                $nextRetryAt = new DateTimeImmutable((string) $slotState['next_retry_at']);
                if ($now < $nextRetryAt) {
                    continue;
                }
            }

            if (($slotState['status'] ?? null) === 'deferred') {
                continue;
            }

            if ($slot['start_at'] > $now) {
                continue;
            }

            return $slot;
        }

        return null;
    }

    /**
     * @param array<int, string> $times
     * @return array<int, array{slot: string, slot_key: string, start_at: DateTimeImmutable}>
     */
    private function buildSlots(array $times, DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $slots = [];

        foreach ($times as $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time, 2));
            $slotStart = $now->setTime($hour, $minute, 0);
            $slotKey = $today . '|' . $time;
            $slots[] = [
                'slot' => $time,
                'slot_key' => $slotKey,
                'start_at' => $slotStart,
            ];
        }

        return $slots;
    }

    /**
     * @return array<int, string>
     */
    private function parseScheduleTimes(string $value): array
    {
        $times = [];

        foreach (preg_split('/\r\n|\r|\n|,/', $value) ?: [] as $item) {
            $candidate = trim($item);
            if ($candidate === '') {
                continue;
            }

            if (! preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $candidate)) {
                continue;
            }

            $times[] = $candidate;
        }

        return $times !== [] ? array_values(array_unique($times)) : ['12:00', '22:00'];
    }

    private function createDatabaseDump(array $slot): array
    {
        $envPath = (string) ($this->config['env_path'] ?? '');
        $envData = is_file($envPath) ? system_monitoring_load_env($envPath) : [];
        $dbConfig = $this->resolveDatabaseConfig($envData);

        $driver = strtolower((string) ($dbConfig['driver'] ?? 'mysql'));
        if (in_array($driver, ['mysqli', 'pdo_mysql'], true)) {
            $driver = 'mysql';
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return ['ok' => false, 'message' => 'Only MySQL/MariaDB database backup is supported.'];
        }

        $database = trim((string) ($dbConfig['database'] ?? ''));
        $username = trim((string) ($dbConfig['username'] ?? ''));
        $password = (string) ($dbConfig['password'] ?? '');
        $host = trim((string) ($dbConfig['host'] ?? '127.0.0.1'));
        $port = trim((string) ((string) ($dbConfig['port'] ?? '3306')));
        $charset = trim((string) ($dbConfig['charset'] ?? 'utf8'));

        if ($database === '' || $username === '') {
            return ['ok' => false, 'message' => 'Database credentials are missing from .env or application/config/database.php.'];
        }

        $binary = $this->findMysqlDumpBinary();
        if ($binary === null) {
            return ['ok' => false, 'message' => 'mysqldump command is not available.'];
        }

        $root = rtrim((string) ($this->config['database_backup_root'] ?? ''), "\\/");
        if ($root === '') {
            return ['ok' => false, 'message' => 'Database backup root is not configured.'];
        }

        $slotPath = $root
            . DIRECTORY_SEPARATOR
            . date('Y-m-d')
            . DIRECTORY_SEPARATOR
            . $this->safeSegment($slot['slot_key']);

        @mkdir($slotPath, 0777, true);

        $filePath = $slotPath . DIRECTORY_SEPARATOR . 'database.sql';
        $dumpCommand = $this->buildDumpCommand($binary, $filePath, $database, $username, $password, $host, $port, $charset);
        $output = [];
        $exitCode = 0;
        @exec($dumpCommand . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || ! is_file($filePath) || filesize($filePath) <= 0) {
            $message = trim(implode(PHP_EOL, $output));
            return [
                'ok' => false,
                'message' => $message !== '' ? $message : 'Database dump command failed.',
            ];
        }

        return [
            'ok' => true,
            'file_path' => $filePath,
            'file_size' => (int) filesize($filePath),
        ];
    }

    private function resolveDatabaseConfig(array $envData): array
    {
        $config = [
            'driver' => strtolower((string) ($envData['DB_CONNECTION'] ?? $envData['DB_DRIVER'] ?? '')),
            'host' => $envData['DB_HOST'] ?? null,
            'port' => $envData['DB_PORT'] ?? null,
            'database' => $envData['DB_DATABASE'] ?? null,
            'username' => $envData['DB_USERNAME'] ?? null,
            'password' => $envData['DB_PASSWORD'] ?? null,
            'charset' => $envData['DB_CHARSET'] ?? $envData['DB_CHARSET'] ?? null,
        ];

        if (
            trim((string) ($config['database'] ?? '')) !== ''
            && trim((string) ($config['username'] ?? '')) !== ''
            && trim((string) ($config['host'] ?? '')) !== ''
        ) {
            return $config;
        }

        $ciConfig = $this->loadCodeIgniterDatabaseConfig($this->config['project_root'] . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php');

        if ($ciConfig !== []) {
            $config = array_merge($config, $ciConfig);
        }

        if (($config['driver'] ?? '') === '') {
            $config['driver'] = 'mysql';
        }

        if (($config['charset'] ?? '') === '') {
            $config['charset'] = 'utf8';
        }

        if (($config['port'] ?? '') === '') {
            $config['port'] = '3306';
        }

        if (($config['host'] ?? '') === '') {
            $config['host'] = '127.0.0.1';
        }

        return $config;
    }

    private function loadCodeIgniterDatabaseConfig(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $db = [];
        $active_group = null;
        $query_builder = null;

        if (! defined('BASEPATH')) {
            define('BASEPATH', __DIR__);
        }

        try {
            include $path;
        } catch (\Throwable) {
            return $this->parseCodeIgniterDatabaseConfig((string) file_get_contents($path));
        }

        if (! isset($db['default']) || ! is_array($db['default'])) {
            return $this->parseCodeIgniterDatabaseConfig((string) file_get_contents($path));
        }

        $default = $db['default'];
        $driver = strtolower((string) ($default['dbdriver'] ?? $default['dbdriver1'] ?? 'mysql'));

        return [
            'driver' => $driver,
            'host' => $default['hostname'] ?? '127.0.0.1',
            'port' => $default['port'] ?? '3306',
            'database' => $default['database'] ?? '',
            'username' => $default['username'] ?? '',
            'password' => $default['password'] ?? '',
            'charset' => $default['char_set'] ?? $default['charset'] ?? 'utf8',
        ];
    }

    private function parseCodeIgniterDatabaseConfig(string $contents): array
    {
        $result = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8',
        ];

        foreach ([
            'driver' => '/[\'"]dbdriver[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/i',
            'host' => '/[\'"]hostname[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
            'port' => '/[\'"]port[\'"]\s*=>\s*([0-9]+)/i',
            'database' => '/[\'"]database[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
            'username' => '/[\'"]username[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
            'password' => '/[\'"]password[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
            'charset' => '/[\'"]char_set[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/i',
        ] as $key => $pattern) {
            if (preg_match($pattern, $contents, $matches) === 1) {
                $result[$key] = $matches[1];
            }
        }

        return $result;
    }

    private function buildDumpCommand(string $binary, string $filePath, string $database, string $username, string $password, string $host, string $port, string $charset): string
    {
        $command = [
            escapeshellarg($binary),
            '--host=' . escapeshellarg($host),
            '--port=' . escapeshellarg($port),
            '--user=' . escapeshellarg($username),
            '--default-character-set=' . escapeshellarg($charset),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--hex-blob',
            '--result-file=' . escapeshellarg($filePath),
        ];

        if ($password !== '') {
            $command[] = '--password=' . escapeshellarg($password);
        }

        $command[] = escapeshellarg($database);

        return implode(' ', $command);
    }

    private function findMysqlDumpBinary(): ?string
    {
        $candidates = [];

        $which = @shell_exec('where mysqldump 2>NUL');
        if (is_string($which)) {
            foreach (preg_split('/\r\n|\r|\n/', $which) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && is_file($line)) {
                    return $line;
                }
            }
        }

        $whichExe = @shell_exec('where mysqldump.exe 2>NUL');
        if (is_string($whichExe)) {
            foreach (preg_split('/\r\n|\r|\n/', $whichExe) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && is_file($line)) {
                    return $line;
                }
            }
        }

        foreach (glob('C:\\wamp64\\bin\\mysql\\*\\bin\\mysqldump.exe') ?: [] as $path) {
            $candidates[] = $path;
        }

        foreach (glob('C:\\xampp\\mysql\\bin\\mysqldump.exe') ?: [] as $path) {
            $candidates[] = $path;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function sendBackup(string $filePath, string $fileName, array $slot): array
    {
        $chunkSize = (int) ($this->config['database_backup_chunk_size'] ?? 2097152);
        $timeout = (int) ($this->config['database_backup_timeout'] ?? 60);
        $software = (string) ($this->config['software_id'] ?? '');
        $license = (string) ($this->config['license'] ?? '');
        $deviceId = (string) ($this->config['device_id'] ?? '');
        $fileSize = (int) filesize($filePath);
        $totalChunks = max(1, (int) ceil(max($fileSize, 1) / $chunkSize));

        $initializePayload = [
            'software' => $software,
            'license_key' => $license,
            'file_name' => $fileName,
            'total_chunks' => $totalChunks,
            'version_type' => 'partial',
            'device_id' => $deviceId,
            'domain' => $this->config['request_domain'] ?? '',
        ];

        $initialize = $this->http->request('POST', (string) $this->config['backup_initialize_url'], [
            'timeout' => $timeout,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query($initializePayload),
        ]);

        if (! ($initialize['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => $this->responseMessage($initialize, 'Backup initialize request failed.'),
                'status' => $initialize['status'] ?? 0,
                'busy' => $this->isBusyStatus($initialize),
            ];
        }

        $initJson = $initialize['json'] ?? [];
        $backupId = (string) ($initJson['backup_id'] ?? '');
        if ($backupId === '') {
            return ['ok' => false, 'message' => 'Backup session id missing.'];
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return ['ok' => false, 'message' => 'Unable to open backup file.'];
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    return ['ok' => false, 'message' => 'Unable to read backup file chunk.'];
                }

                $chunkPayload = [
                    'software' => $software,
                    'license_key' => $license,
                    'backup_id' => $backupId,
                    'chunk_index' => $index,
                    'total_chunks' => $totalChunks,
                    'chunk_data' => base64_encode($chunk),
                    'device_id' => $deviceId,
                    'domain' => $this->config['request_domain'] ?? '',
                ];

                $chunkResponse = $this->http->request('POST', (string) $this->config['backup_chunk_url'], [
                    'timeout' => $timeout,
                    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                    'body' => http_build_query($chunkPayload),
                ]);

                if (! ($chunkResponse['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'message' => $this->responseMessage($chunkResponse, 'Backup chunk upload failed.'),
                        'status' => $chunkResponse['status'] ?? 0,
                        'chunk' => $index,
                        'busy' => $this->isBusyStatus($chunkResponse),
                    ];
                }

                $percent = round((($index + 1) / $totalChunks) * 100, 2);
                $this->logger->info('Database backup upload progress.', [
                    'backup_id' => $backupId,
                    'chunk' => $index + 1,
                    'total_chunks' => $totalChunks,
                    'percent' => $percent,
                ]);
            }
        } finally {
            fclose($handle);
        }

        $completePayload = [
            'software' => $software,
            'license_key' => $license,
            'backup_id' => $backupId,
            'device_id' => $deviceId,
            'domain' => $this->config['request_domain'] ?? '',
        ];

        $complete = $this->http->request('POST', (string) $this->config['backup_complete_url'], [
            'timeout' => $timeout,
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query($completePayload),
        ]);

        if (! ($complete['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => $this->responseMessage($complete, 'Backup complete request failed.'),
                'status' => $complete['status'] ?? 0,
                'backup_id' => $backupId,
                'busy' => $this->isBusyStatus($complete),
            ];
        }

        $completeJson = $complete['json'] ?? [];

        return [
            'ok' => true,
            'backup_id' => $backupId,
            'metadata_path' => $completeJson['metadata_path'] ?? null,
        ];
    }

    private function cleanupLocalBackup(string $filePath): void
    {
        $boundary = rtrim((string) ($this->config['database_backup_root'] ?? ''), "\\/");

        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $directory = dirname($filePath);
        while (is_dir($directory)) {
            if ($boundary !== '' && rtrim($directory, "\\/") === $boundary) {
                break;
            }

            $items = @scandir($directory);
            if (! is_array($items) || count(array_diff($items, ['.', '..'])) > 0) {
                break;
            }

            @rmdir($directory);
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }
    }

    private function safeSegment(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'unknown';

        return trim($value, '.-_') ?: 'unknown';
    }

    private function markSlotSuccess(array &$state, array $slot): void
    {
        $state['database_backup_slots'] ??= [];
        $state['database_backup_slots'][$slot['slot_key']] = [
            'status' => 'completed',
            'completed_at' => gmdate('c'),
            'last_attempt_at' => gmdate('c'),
        ];
    }

    private function markSlotFailure(array &$state, array $slot, string $reason, string $message): void
    {
        $state['database_backup_slots'] ??= [];
        $slotState = $state['database_backup_slots'][$slot['slot_key']] ?? [];
        $retryMinutes = $this->resolveRetryMinutes($state);
        $now = new DateTimeImmutable('now');

        $slotState['status'] = $reason === 'busy' ? 'blocked' : 'failed';
        $slotState['reason'] = $reason;
        $slotState['message'] = $message;
        $slotState['last_attempt_at'] = $now->format(DATE_ATOM);
        $slotState['last_failed_at'] = $now->format(DATE_ATOM);

        if ($reason === 'busy') {
            $slotState['status'] = 'deferred';
            $slotState['deferred_until'] = $this->resolveNextScheduleAt($slot['slot'], $now)->format(DATE_ATOM);
        } else {
            $slotState['next_retry_at'] = $now->modify('+' . $retryMinutes . ' minutes')->format(DATE_ATOM);
        }

        $state['database_backup_slots'][$slot['slot_key']] = $slotState;
        $state['last_database_backup_status'] = $reason;
        $state['last_database_backup_error'] = $message;
        $state['last_database_backup_slot'] = $slot['slot_key'];
        $state['last_database_backup_at'] = $now->format(DATE_ATOM);
        $state['last_database_backup_attempt_at'] = $now->format(DATE_ATOM);
    }

    private function resolveNextScheduleAt(string $slotTime, DateTimeImmutable $now): DateTimeImmutable
    {
        $times = $this->parseScheduleTimes((string) ($this->config['database_backup_times'] ?? '12:00,22:00'));
        $currentIndex = array_search($slotTime, $times, true);

        if ($currentIndex === false || count($times) === 0) {
            return $now->modify('+1 day');
        }

        $nextIndex = $currentIndex + 1;
        $nextDate = $nextIndex < count($times) ? $now : $now->modify('+1 day');
        $nextTime = $times[$nextIndex % count($times)];
        [$hour, $minute] = array_map('intval', explode(':', $nextTime, 2));

        return $nextDate->setTime($hour, $minute, 0);
    }

    private function resolveRetryMinutes(array $state): int
    {
        $defaultRetryMinutes = max(1, (int) ($this->config['database_backup_retry_minutes'] ?? 30));
        $staleRetryMinutes = max(1, (int) ($this->config['database_backup_stale_retry_minutes'] ?? 10));
        $minimumGapHours = max(1, (int) ($this->config['database_backup_min_gap_hours'] ?? 6));
        $lastSuccessAt = $state['last_database_backup_success_at'] ?? null;

        if (! is_string($lastSuccessAt) || $lastSuccessAt === '') {
            return $staleRetryMinutes;
        }

        try {
            $lastSuccess = new DateTimeImmutable($lastSuccessAt);
        } catch (\Throwable) {
            return $staleRetryMinutes;
        }

        return time() >= $lastSuccess->modify('+' . $minimumGapHours . ' hours')->getTimestamp()
            ? $staleRetryMinutes
            : $defaultRetryMinutes;
    }

    private function isBusyStatus(array $response): bool
    {
        $status = (int) ($response['status'] ?? 0);
        if (in_array($status, [423, 429], true)) {
            return true;
        }

        $message = strtolower((string) ($response['message'] ?? ($response['json']['message'] ?? '')));

        return str_contains($message, 'busy') || str_contains($message, 'already running');
    }

    private function responseMessage(array $response, string $fallback): string
    {
        $message = trim((string) ($response['message'] ?? ($response['json']['message'] ?? '')));
        if ($message !== '') {
            return $message;
        }

        if (is_array($response['json'] ?? null)) {
            $json = json_encode($response['json'], JSON_UNESCAPED_SLASHES);
            if (is_string($json) && $json !== '') {
                return $json;
            }
        }

        return $fallback;
    }
}
