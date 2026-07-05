<?php

declare(strict_types=1);

namespace SystemMonitoring\Network;

use SystemMonitoring\Support\HttpClient;
use SystemMonitoring\Support\MonitorLogger;
use SystemMonitoring\Support\UpdateState;

final class UpdateDownloadClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
        private readonly MonitorLogger $logger,
        private array &$state
    ) {
    }

    public function download(array $updateInfo): array
    {
        $downloadUrl = (string) ($updateInfo['download_url'] ?? $updateInfo['file_url'] ?? $updateInfo['update_link'] ?? '');
        if ($downloadUrl === '') {
            return ['ok' => false, 'message' => 'Update download URL is missing.'];
        }

        $version = (string) ($updateInfo['latest_version'] ?? 'unknown');
        $downloadRoot = rtrim($this->config['download_root'], "\\/");
        $safeVersion = $this->safeSegment($version);
        $versionRoot = $downloadRoot . DIRECTORY_SEPARATOR . $safeVersion;
        $chunksDirectory = $versionRoot . DIRECTORY_SEPARATOR . 'chunks';
        $packagePath = $versionRoot . DIRECTORY_SEPARATOR . 'update.zip';

        if (is_file($packagePath) && filesize($packagePath) > 0) {
            $this->logger->info('Version package already exists. Reusing cached download.', [
                'version' => $version,
                'package_path' => $packagePath,
            ]);

            $this->state['last_download_job_id'] = 'cached-' . $safeVersion;
            $this->state['last_download_url'] = $downloadUrl;
            $this->state['last_download_path'] = $packagePath;
            $this->state['last_download_chunks_directory'] = $chunksDirectory;
            $this->state['last_download_at'] = gmdate('c');
            $this->state['last_download_version'] = $version;
            UpdateState::appendHistory($this->state, 'download_reused', [
                'version' => $version,
                'package_path' => $packagePath,
            ]);

            return [
                'ok' => true,
                'job_id' => 'cached-' . $safeVersion,
                'package_path' => $packagePath,
                'chunks_directory' => $chunksDirectory,
                'total_chunks' => 0,
                'total_size' => (int) filesize($packagePath),
                'download_url' => $downloadUrl,
                'version' => $version,
                'reused' => true,
            ];
        }

        @mkdir($chunksDirectory, 0777, true);

        $totalSize = $this->resolveSize($downloadUrl);
        $chunkSize = (int) $this->config['download_chunk_size'];
        $totalChunks = max(1, (int) ceil(max($totalSize, 1) / $chunkSize));
        $downloadedBytes = 0;
        $jobId = 'download-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

        $this->logger->info('Starting chunked download.', [
            'url' => $downloadUrl,
            'job_id' => $jobId,
            'version' => $version,
            'chunks' => $totalChunks,
            'chunk_size' => $chunkSize,
        ]);

        for ($index = 0; $index < $totalChunks; $index++) {
            $start = $index * $chunkSize;
            $end = min($start + $chunkSize - 1, max($totalSize - 1, $start));
            $chunkPath = $chunksDirectory . DIRECTORY_SEPARATOR . $index . '.part';

            $response = $this->http->request('GET', $downloadUrl, [
                'timeout' => $this->config['download_timeout'] ?? 30,
                'range' => $totalSize > 0 ? ($start . '-' . $end) : null,
                'download_to' => $chunkPath,
                'expect_json' => false,
            ]);

            if (! ($response['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => 'Chunk download failed.',
                    'chunk' => $index,
                    'status' => $response['status'] ?? 0,
                    'error' => $response['error'] ?? null,
                ];
            }

            $chunkBytes = is_file($chunkPath) ? (int) filesize($chunkPath) : 0;
            if ($totalSize <= 0 && $chunkBytes > 0) {
                $totalSize = $chunkBytes;
                $totalChunks = 1;
            }
            $downloadedBytes += $chunkBytes;
            $percent = $totalSize > 0 ? round(($downloadedBytes / $totalSize) * 100, 2) : round((($index + 1) / $totalChunks) * 100, 2);

            $this->logger->info('Download progress.', [
                'chunk' => $index + 1,
                'total_chunks' => $totalChunks,
                'percent' => $percent,
                'downloaded_bytes' => $downloadedBytes,
                'total_size' => $totalSize,
            ]);

            $this->state['last_download_job_id'] = $jobId;
            $this->state['last_download_url'] = $downloadUrl;
            $this->state['last_download_chunk'] = $index;
            $this->state['last_download_total_chunks'] = $totalChunks;
            $this->state['last_download_bytes'] = $downloadedBytes;
            $this->state['last_download_total_size'] = $totalSize;
            $this->state['last_download_percent'] = $percent;
            $this->state['last_download_at'] = gmdate('c');
            UpdateState::appendHistory($this->state, 'download_progress', [
                'chunk' => $index,
                'total_chunks' => $totalChunks,
                'percent' => $percent,
                'downloaded_size' => $downloadedBytes,
                'total_size' => $totalSize,
            ]);
        }

        $stream = fopen($packagePath, 'wb');
        if ($stream === false) {
            return ['ok' => false, 'message' => 'Unable to create merged package.'];
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkPath = $chunksDirectory . DIRECTORY_SEPARATOR . $index . '.part';
                fwrite($stream, (string) file_get_contents($chunkPath));
            }
        } finally {
            fclose($stream);
        }

        $this->state['last_download_path'] = $packagePath;
        $this->state['last_download_chunks_directory'] = $chunksDirectory;
        $this->state['last_download_version'] = $version;
        UpdateState::appendHistory($this->state, 'download_completed', [
            'package_path' => $packagePath,
            'chunks_directory' => $chunksDirectory,
            'total_chunks' => $totalChunks,
            'total_size' => $totalSize,
            'version' => $version,
        ]);

        $this->logger->info('Chunked download completed.', [
            'job_id' => $jobId,
            'package_path' => $packagePath,
        ]);

        return [
            'ok' => true,
            'job_id' => $jobId,
            'package_path' => $packagePath,
            'chunks_directory' => $chunksDirectory,
            'total_chunks' => $totalChunks,
            'total_size' => $totalSize,
            'download_url' => $downloadUrl,
            'version' => $version,
        ];
    }

    private function resolveSize(string $url): int
    {
        $response = $this->http->request('HEAD', $url, [
            'timeout' => $this->config['download_timeout'] ?? 30,
            'expect_json' => false,
        ]);

        if (isset($response['headers']['content-length'])) {
            return max(0, (int) $response['headers']['content-length']);
        }

        return 0;
    }

    private function safeSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unknown';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?: 'unknown';

        return trim($value, '.-_') ?: 'unknown';
    }
}
