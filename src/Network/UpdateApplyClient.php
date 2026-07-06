<?php

declare(strict_types=1);

namespace SystemMonitoring\Network;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;
use SystemMonitoring\Support\MonitorLogger;
use SystemMonitoring\Support\UpdateState;

final class UpdateApplyClient
{
    public function __construct(
        private readonly array $config,
        private readonly MonitorLogger $logger,
        private array &$state
    ) {
    }

    public function apply(string $packagePath, array $updateInfo = []): array
    {
        $targetRoot = (string) ($this->config['update_target_root'] ?? '');
        if ($targetRoot === '') {
            return [
                'ok' => false,
                'message' => 'Update target root is not configured.',
            ];
        }

        if (! is_file($packagePath)) {
            return [
                'ok' => false,
                'message' => 'Update package not found.',
            ];
        }

        $extractRoot = dirname($packagePath) . DIRECTORY_SEPARATOR . 'extracted';
        $this->deleteDirectory($extractRoot);
        @mkdir($extractRoot, 0777, true);

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            return [
                'ok' => false,
                'message' => 'Unable to open update archive.',
            ];
        }

        try {
            if (! $zip->extractTo($extractRoot)) {
                return [
                    'ok' => false,
                    'message' => 'Failed to extract update archive.',
                ];
            }
        } finally {
            $zip->close();
        }

        $sourceRoot = $this->detectSourceRoot($extractRoot);
        if ($sourceRoot === null) {
            return [
                'ok' => false,
                'message' => 'Extracted update is empty.',
            ];
        }

        @mkdir($targetRoot, 0777, true);
        $copied = $this->syncDirectory($sourceRoot, $targetRoot);
        $postActions = $this->runPostUpdateActions($updateInfo);

        if (! ($postActions['ok'] ?? false)) {
            $this->state['last_post_update_actions_at'] = gmdate('c');
            $this->state['last_post_update_actions_status'] = 'failed';
            $this->state['last_post_update_actions_error'] = $postActions['message'] ?? 'Post-update actions failed.';
            $this->state['last_post_update_actions_sql'] = $postActions['sql'] ?? [];
            $this->state['last_post_update_actions_commands'] = $postActions['commands'] ?? [];
            UpdateState::appendHistory($this->state, 'post_update_actions_failed', [
                'message' => $postActions['message'] ?? 'Post-update actions failed.',
                'sql' => $postActions['sql'] ?? [],
                'commands' => $postActions['commands'] ?? [],
            ]);

            $this->logger->error('Post-update actions failed.', [
                'message' => $postActions['message'] ?? 'Post-update actions failed.',
                'sql' => $postActions['sql'] ?? [],
                'commands' => $postActions['commands'] ?? [],
            ]);

            return [
                'ok' => false,
                'message' => $postActions['message'] ?? 'Post-update actions failed.',
                'copied_files' => $copied,
                'post_actions' => $postActions,
                'target_root' => $targetRoot,
                'extract_root' => $extractRoot,
                'source_root' => $sourceRoot,
            ];
        }

        $this->state['last_applied_package'] = $packagePath;
        $this->state['last_applied_at'] = gmdate('c');
        $this->state['last_applied_target_root'] = $targetRoot;
        $this->state['last_applied_version'] = $this->state['last_download_version'] ?? null;
        $this->state['last_post_update_actions_at'] = gmdate('c');
        $this->state['last_post_update_actions_status'] = 'completed';
        $this->state['last_post_update_actions_error'] = null;
        $this->state['last_post_update_actions_sql'] = $postActions['sql'] ?? [];
        $this->state['last_post_update_actions_commands'] = $postActions['commands'] ?? [];
        UpdateState::appendHistory($this->state, 'apply_completed', [
            'package_path' => $packagePath,
            'target_root' => $targetRoot,
            'copied_files' => $copied,
            'version' => $this->state['last_download_version'] ?? null,
            'sql' => $postActions['sql'] ?? [],
            'commands' => $postActions['commands'] ?? [],
        ]);

        $this->logger->info('Update applied with replace-only sync.', [
            'package_path' => $packagePath,
            'target_root' => $targetRoot,
            'copied_files' => $copied,
            'version' => $this->state['last_download_version'] ?? null,
            'sql' => $postActions['sql'] ?? [],
            'commands' => $postActions['commands'] ?? [],
        ]);

        $this->cleanupArtifacts($packagePath, $extractRoot, $sourceRoot);

        return [
            'ok' => true,
            'copied_files' => $copied,
            'post_actions' => $postActions,
            'target_root' => $targetRoot,
            'extract_root' => $extractRoot,
            'source_root' => $sourceRoot,
        ];
    }

    private function runPostUpdateActions(array $updateInfo): array
    {
        $sqlStatements = $this->normalizeActionList(
            $updateInfo['update_sql_commands'] ?? $updateInfo['update_sql'] ?? null
        );
        $shellCommands = $this->normalizeActionList(
            $updateInfo['environment_command_list'] ?? $updateInfo['environment_commands'] ?? null
        );

        $result = [
            'ok' => true,
            'sql' => [],
            'commands' => [],
        ];

        if ($sqlStatements !== []) {
            $sqlResult = $this->runSqlStatements($sqlStatements);
            $result['sql'] = $sqlResult['executed'] ?? [];

            if (! ($sqlResult['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => $sqlResult['message'] ?? 'SQL execution failed.',
                    'sql' => $result['sql'],
                    'commands' => [],
                ];
            }
        }

        if ($shellCommands !== []) {
            $commandResult = $this->runShellCommands($shellCommands);
            $result['commands'] = $commandResult['executed'] ?? [];

            if (! ($commandResult['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => $commandResult['message'] ?? 'Command execution failed.',
                    'sql' => $result['sql'],
                    'commands' => $result['commands'],
                    'failed_command' => $commandResult['failed_command'] ?? null,
                    'output' => $commandResult['output'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<int, string> $statements
     */
    private function runSqlStatements(array $statements): array
    {
        $statements = $this->normalizeActionList($statements);

        if ($statements === []) {
            return ['ok' => true, 'executed' => []];
        }

        $envPath = (string) ($this->config['env_path'] ?? '');
        $envData = is_file($envPath) ? \system_monitoring_load_env($envPath) : [];
        $database = $this->resolveDatabaseConfig($envData);

        $driver = strtolower((string) ($database['driver'] ?? 'mysql'));
        if (in_array($driver, ['mysqli', 'pdo_mysql'], true)) {
            $driver = 'mysql';
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return [
                'ok' => false,
                'message' => 'SQL execution supports only MySQL/MariaDB databases.',
                'executed' => [],
            ];
        }

        if (! class_exists(PDO::class)) {
            return [
                'ok' => false,
                'message' => 'PDO is not available for SQL execution.',
                'executed' => [],
            ];
        }

        $databaseName = trim((string) ($database['database'] ?? ''));
        $username = trim((string) ($database['username'] ?? ''));
        $password = (string) ($database['password'] ?? '');
        $host = trim((string) ($database['host'] ?? '127.0.0.1'));
        $port = trim((string) ($database['port'] ?? '3306'));
        $charset = trim((string) ($database['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';

        if ($databaseName === '' || $username === '') {
            return [
                'ok' => false,
                'message' => 'Database credentials are missing from the updater environment.',
                'executed' => [],
            ];
        }

        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $host,
                    $port !== '' ? $port : '3306',
                    $databaseName,
                    $charset
                ),
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'message' => 'Unable to connect to the database: ' . $throwable->getMessage(),
                'executed' => [],
            ];
        }

        $executed = [];
        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $executed[] = $statement;
            } catch (\Throwable $throwable) {
                return [
                    'ok' => false,
                    'message' => 'SQL execution failed: ' . $throwable->getMessage(),
                    'executed' => $executed,
                    'failed_statement' => $statement,
                ];
            }
        }

        return [
            'ok' => true,
            'executed' => $executed,
        ];
    }

    /**
     * @param array<int, string> $commands
     */
    private function runShellCommands(array $commands): array
    {
        $commands = $this->normalizeActionList($commands);

        if ($commands === []) {
            return ['ok' => true, 'executed' => []];
        }

        $executed = [];

        foreach ($commands as $command) {
            $output = [];
            $exitCode = 0;
            @exec($command . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                return [
                    'ok' => false,
                    'message' => 'Command execution failed: ' . $command,
                    'executed' => $executed,
                    'failed_command' => $command,
                    'output' => implode(PHP_EOL, $output),
                ];
            }

            $executed[] = $command;
        }

        return [
            'ok' => true,
            'executed' => $executed,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeActionList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function resolveDatabaseConfig(array $envData): array
    {
        return [
            'driver' => strtolower((string) ($envData['DB_CONNECTION'] ?? $envData['DB_DRIVER'] ?? '')),
            'host' => $envData['DB_HOST'] ?? null,
            'port' => $envData['DB_PORT'] ?? null,
            'database' => $envData['DB_DATABASE'] ?? null,
            'username' => $envData['DB_USERNAME'] ?? null,
            'password' => $envData['DB_PASSWORD'] ?? null,
            'charset' => $envData['DB_CHARSET'] ?? 'utf8mb4',
        ];
    }

    private function detectSourceRoot(string $extractRoot): ?string
    {
        $items = array_values(array_diff(scandir($extractRoot) ?: [], ['.', '..']));
        if ($items === []) {
            return null;
        }

        if (count($items) === 1 && is_dir($extractRoot . DIRECTORY_SEPARATOR . $items[0])) {
            return $extractRoot . DIRECTORY_SEPARATOR . $items[0];
        }

        return $extractRoot;
    }

    private function syncDirectory(string $sourceDirectory, string $targetDirectory): int
    {
        $sourceDirectory = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourceDirectory), "\\/");
        $targetDirectory = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetDirectory), "\\/");
        $count = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourceDirectory) + 1);
            $destinationPath = $targetDirectory . DIRECTORY_SEPARATOR . $relativePath;

            if ($this->isUnsafePath($destinationPath, $targetDirectory)) {
                continue;
            }

            if ($item->isDir()) {
                @mkdir($destinationPath, 0777, true);
                continue;
            }

            @mkdir(dirname($destinationPath), 0777, true);
            copy($item->getPathname(), $destinationPath);
            $count++;
        }

        return $count;
    }

    private function isUnsafePath(string $path, string $root): bool
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), "\\/");
        $normalized = $this->normalizePath($path);
        $normalizedRoot = $this->normalizePath($root);

        return ! str_starts_with($normalized, $normalizedRoot . DIRECTORY_SEPARATOR) && $normalized !== $normalizedRoot;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $segments = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $prefix = preg_match('/^[A-Za-z]:$/', $segments[0] ?? '') === 1 ? array_shift($segments) . DIRECTORY_SEPARATOR : '';

        return $prefix . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function cleanupArtifacts(string $packagePath, string $extractRoot, string $sourceRoot): void
    {
        $this->deleteDirectory($extractRoot);

        if (is_file($packagePath)) {
            @unlink($packagePath);
        }

        $chunksDirectory = dirname($packagePath) . DIRECTORY_SEPARATOR . 'chunks';
        if (is_dir($chunksDirectory)) {
            $this->deleteDirectory($chunksDirectory);
        }

        $versionRoot = dirname($packagePath);
        $downloadRoot = (string) ($this->config['download_root'] ?? '');

        if ($downloadRoot !== '') {
            $this->pruneEmptyParents($versionRoot, $downloadRoot);
        }
    }

    private function pruneEmptyParents(string $directory, string $stopAt): void
    {
        $directory = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), "\\/");
        $stopAt = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $stopAt), "\\/");

        while ($directory !== '' && $directory !== $stopAt) {
            if (! is_dir($directory)) {
                $directory = dirname($directory);
                continue;
            }

            $items = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
            if ($items !== []) {
                break;
            }

            @rmdir($directory);
            $directory = dirname($directory);
        }

        if ($directory === $stopAt && is_dir($directory)) {
            $items = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
            if ($items === []) {
                @rmdir($directory);
            }
        }
    }
}
