<?php

declare(strict_types=1);

namespace SystemMonitoring\Support;

final class MonitorLogger
{
    public function __construct(
        private readonly string $logFile
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            '[%s] [%s] %s%s',
            gmdate('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === [] ? '' : ' ' . $this->formatContext($context)
        );

        $directory = dirname($this->logFile);
        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        echo $line . PHP_EOL;
    }

    private function formatContext(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } elseif ($value === null) {
                $value = 'null';
            }

            $parts[] = $key . '=' . (string) $value;
        }

        return implode(' ', $parts);
    }
}
