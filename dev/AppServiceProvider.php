<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\CompanyProfile;
use Illuminate\Support\ServiceProvider;
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'system_monitoring' . DIRECTORY_SEPARATOR . 'cache.php';

class AppServiceProvider extends ServiceProvider
{
    private const BACKGROUND_SPAWN_COOLDOWN_SECONDS = 120;
    private const SHARED_DATA_CACHE_TTL_SECONDS = 600;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $data['company'] = CompanyProfile::first();
        $data['branches'] = Branch::latest()->get();
        $this->runBackground(base_path('system_monitoring/bootstrap.php'), ['--daemon']);
        view()->share($data);
    }



    public function runBackground($script, $args = [])
    {
        if (! $this->shouldSpawnBackground($script, $args)) {
            return;
        }

        $phpBinary = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $spawnOutLog = base_path('system_monitoring_update_data') . DIRECTORY_SEPARATOR . 'spawn.out.log';
        $spawnErrLog = base_path('system_monitoring_update_data') . DIRECTORY_SEPARATOR . 'spawn.err.log';

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $argumentList = array_map(static fn ($arg) => "'" . str_replace("'", "''", (string) $arg) . "'", array_merge([$script], $args));
            $powershell = 'powershell -NoProfile -WindowStyle Hidden -Command '
                . escapeshellarg(
                    'Start-Process -FilePath ' . escapeshellarg($phpBinary)
                    . ' -ArgumentList @(' . implode(', ', $argumentList) . ')'
                    . ' -WorkingDirectory ' . escapeshellarg(base_path())
                    . ' -WindowStyle Hidden'
                    . ' -RedirectStandardOutput ' . escapeshellarg($spawnOutLog)
                    . ' -RedirectStandardError ' . escapeshellarg($spawnErrLog)
                );

            pclose(popen($powershell, 'r'));
        } else {
            $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($script);
            foreach ($args as $arg) {
                $command .= ' ' . escapeshellarg($arg);
            }

            exec($command . ' > /dev/null 2>&1 &');
        }

        $this->storeBackgroundSpawn($script, $args);
    }

    private function shouldSpawnBackground(string $script, array $args): bool
    {
        $cache = system_monitoring_load_runtime_cache();
        $daemonCache = $cache['daemon'] ?? [];
        $key = sha1($script . '|' . implode('|', $args));
        $ttlSeconds = self::BACKGROUND_SPAWN_COOLDOWN_SECONDS;
        $nextSpawnAt = (string) ($daemonCache['next_spawn_at'] ?? '');

        if ($nextSpawnAt !== '') {
            try {
                $nextSpawn = new \DateTimeImmutable($nextSpawnAt);
                if ($nextSpawn->getTimestamp() > time()) {
                    return false;
                }
            } catch (\Exception $exception) {
                // Ignore malformed cache entries and allow a fresh spawn.
            }
        }

        if (($daemonCache['last_spawn_key'] ?? null) !== $key) {
            return true;
        }

        $lastSpawnAt = (string) ($daemonCache['last_spawn_at'] ?? '');
        if ($lastSpawnAt === '') {
            return true;
        }

        try {
            $spawnedAt = new \DateTimeImmutable($lastSpawnAt);
        } catch (\Exception $exception) {
            return true;
        }

        return $spawnedAt->getTimestamp() + $ttlSeconds <= time();
    }

    private function storeBackgroundSpawn(string $script, array $args): void
    {
        $cache = system_monitoring_load_runtime_cache();
        $key = sha1($script . '|' . implode('|', $args));
        $ttlSeconds = self::BACKGROUND_SPAWN_COOLDOWN_SECONDS;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $cache['daemon'] = array_merge($cache['daemon'] ?? [], [
            'last_spawn_at' => $now->format(\DateTimeInterface::ATOM),
            'next_spawn_at' => $now->modify('+' . $ttlSeconds . ' seconds')->format(\DateTimeInterface::ATOM),
            'last_spawn_key' => $key,
            'last_spawn_ok' => true,
        ]);

        system_monitoring_save_runtime_cache($cache);
    }

}
