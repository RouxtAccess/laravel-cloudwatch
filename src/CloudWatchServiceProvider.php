<?php

namespace Rouxtaccess\CloudWatch;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Rouxtaccess\CloudWatch\Buffer\Buffer;
use Rouxtaccess\CloudWatch\Buffer\CacheBuffer;
use Rouxtaccess\CloudWatch\Commands\ShipLogsCommand;
use Rouxtaccess\CloudWatch\Logging\CreateCloudWatchLogger;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CloudWatchServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-cloudwatch')
            ->hasConfigFile('cloudwatch')
            ->hasCommand(ShipLogsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(CacheBuffer::class, function () {
            return new CacheBuffer(
                Cache::store(config('cloudwatch.buffer.cache_store')),
                config('cloudwatch.buffer.prefix', 'cloudwatch:buffer'),
                (int) config('cloudwatch.buffer.cap_size', 100000),
                (int) config('cloudwatch.buffer.record_ttl', 259200),
            );
        });

        $this->app->singleton(Buffer::class, function ($app) {
            return $app->make(config('cloudwatch.buffer.implementation', CacheBuffer::class));
        });

        $this->app->singleton(CloudWatchLogsClient::class, function () {
            $clientConfig = [
                'version' => 'latest',
                'region' => config('cloudwatch.region'),
            ];

            if (config('cloudwatch.key')) {
                $clientConfig['credentials'] = [
                    'key' => config('cloudwatch.key'),
                    'secret' => config('cloudwatch.secret'),
                ];
            }

            return new CloudWatchLogsClient($clientConfig);
        });
    }

    public function packageBooted(): void
    {
        Log::extend('cloudwatch', function ($app, array $config) {
            return (new CreateCloudWatchLogger)($config);
        });

        $this->scheduleShipCommand();
    }

    protected function scheduleShipCommand(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! config('cloudwatch.enabled')) {
                return;
            }

            if (! config('cloudwatch.ship.auto_schedule')) {
                return;
            }

            $schedule->command(ShipLogsCommand::class)
                ->cron($this->resolveShipCronExpression())
                ->withoutOverlapping();
        });
    }

    protected function resolveShipCronExpression(): string
    {
        $schedule = trim((string) config('cloudwatch.ship.schedule', '* * * * *'));

        if (! is_numeric($schedule)) {
            return $schedule;
        }

        $minutes = (int) min(59, max(1, (int) $schedule));

        if ($minutes === 1) {
            return '* * * * *';
        }

        return "*/{$minutes} * * * *";
    }
}
