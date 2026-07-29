<?php

namespace Rouxtaccess\CloudWatch\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rouxtaccess\CloudWatch\CloudWatchServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            CloudWatchServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('cache.default', 'array');
        config()->set('cloudwatch.enabled', true);
        config()->set('cloudwatch.group.name', 'testing-group');
        config()->set('cloudwatch.stream', 'testing-stream');
        config()->set('logging.channels.cloudwatch', [
            'driver' => 'cloudwatch',
            'level' => 'info',
        ]);
    }
}
