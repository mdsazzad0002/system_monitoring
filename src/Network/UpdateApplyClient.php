<?php

declare(strict_types=1);

namespace SystemMonitoring\Network;

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

    public function apply(string $packagePath): array
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

        $this->state['last_applied_package'] = $packagePath;
        $this->state['last_applied_at'] = gmdate('c');
        $this->state['last_applied_target_root'] = $targetRoot;
        $this->state['last_applied_version'] = $this->state['last_download_version'] ?? null;
        UpdateState::appendHistory($this->state, 'apply_completed', [
            'package_path' => $packagePath,
            'target_root' => $targetRoot,
            'copied_files' => $copied,
            'version' => $this->state['last_download_version'] ?? null,
        ]);

        $this->logger->info('Update applied with replace-only sync.', [
            'package_path' => $packagePath,
            'target_root' => $targetRoot,
            'copied_files' => $copied,
            'version' => $this->state['last_download_version'] ?? null,
        ]);

        $this->cleanupArtifacts($packagePath, $extractRoot, $sourceRoot);

        return [
            'ok' => true,
            'copied_files' => $copied,
            'target_root' => $targetRoot,
            'extract_root' => $extractRoot,
            'source_root' => $sourceRoot,
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
