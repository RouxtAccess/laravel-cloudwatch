<?php

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Rouxtaccess\CloudWatch\Buffer\Buffer;

function bufferRecord(string $message, string $datetime): void
{
    app(Buffer::class)->push(json_encode([
        'message' => $message,
        'context' => [],
        'level' => 200,
        'level_name' => 'INFO',
        'channel' => 'cloudwatch',
        'datetime' => $datetime,
        'extra' => [],
    ]));
}

function mockCloudWatchClient(): MockInterface
{
    $mock = Mockery::mock(CloudWatchLogsClient::class);
    app()->instance(CloudWatchLogsClient::class, $mock);

    return $mock;
}

function expectLogGroupSetup(MockInterface $mock, bool $groupAlreadyExists = true): void
{
    $existingGroups = $groupAlreadyExists
        ? ['logGroups' => [['logGroupName' => 'testing-group']]]
        : ['logGroups' => []];

    $mock->shouldReceive('describeLogGroups')->once()->andReturn($existingGroups);
    $mock->shouldReceive('createLogGroup')->times($groupAlreadyExists ? 0 : 1);
    $mock->shouldReceive('putRetentionPolicy')->once();
    $mock->shouldReceive('createLogStream')->once();
}

it('makes no aws calls when the buffer is empty', function () {
    $mock = mockCloudWatchClient();
    $mock->shouldNotReceive('describeLogGroups');
    $mock->shouldNotReceive('putLogEvents');

    $this->artisan('cloudwatch:ship')->assertSuccessful();
});

it('does nothing when shipping is disabled', function () {
    config()->set('cloudwatch.enabled', false);

    $mock = mockCloudWatchClient();
    $mock->shouldNotReceive('describeLogGroups');
    $mock->shouldNotReceive('putLogEvents');

    $this->artisan('cloudwatch:ship')->assertSuccessful();
});

it('ships buffered records in chronological order and clears the buffer', function () {
    bufferRecord('Newer record', now()->toIso8601String());
    bufferRecord('Older record', now()->subMinutes(5)->toIso8601String());

    $mock = mockCloudWatchClient();
    expectLogGroupSetup($mock);

    $mock->shouldReceive('putLogEvents')->once()->withArgs(function (array $arguments) {
        $timestamps = array_column($arguments['logEvents'], 'timestamp');
        $sortedTimestamps = $timestamps;
        sort($sortedTimestamps);

        return $arguments['logGroupName'] === 'testing-group'
            && $arguments['logStreamName'] === 'testing-stream'
            && count($arguments['logEvents']) === 2
            && str_contains($arguments['logEvents'][0]['message'], 'Older record')
            && $timestamps === $sortedTimestamps;
    })->andReturn([]);

    $this->artisan('cloudwatch:ship')->assertSuccessful();

    expect(app(Buffer::class)->pendingCount())->toBe(0);
});

it('keeps the buffer intact when shipping fails', function () {
    bufferRecord('Unshipped record', now()->toIso8601String());

    $mock = mockCloudWatchClient();
    expectLogGroupSetup($mock);
    $mock->shouldReceive('putLogEvents')->once()->andThrow(new RuntimeException('CloudWatch is down'));

    $this->artisan('cloudwatch:ship')->assertFailed();

    expect(app(Buffer::class)->pendingCount())->toBe(1);
});

it('creates the log group with the configured retention', function () {
    config()->set('cloudwatch.group.retention_days', 731);
    bufferRecord('Retention check record', now()->toIso8601String());

    $mock = mockCloudWatchClient();
    $mock->shouldReceive('describeLogGroups')->once()->andReturn(['logGroups' => []]);
    $mock->shouldReceive('createLogGroup')->once()->withArgs(fn (array $arguments) => $arguments['logGroupName'] === 'testing-group');
    $mock->shouldReceive('putRetentionPolicy')->once()->withArgs(fn (array $arguments) => $arguments['retentionInDays'] === 731);
    $mock->shouldReceive('createLogStream')->once();
    $mock->shouldReceive('putLogEvents')->once()->andReturn([]);

    $this->artisan('cloudwatch:ship')->assertSuccessful();
});

it('skips group management when create is disabled', function () {
    config()->set('cloudwatch.group.create', false);
    bufferRecord('Record for an externally managed group', now()->toIso8601String());

    $mock = mockCloudWatchClient();
    $mock->shouldNotReceive('describeLogGroups');
    $mock->shouldNotReceive('createLogGroup');
    $mock->shouldNotReceive('putRetentionPolicy');
    $mock->shouldReceive('putLogEvents')->once()->andReturn([]);

    $this->artisan('cloudwatch:ship')->assertSuccessful();
});

it('logs a warning when cloudwatch rejects events', function () {
    bufferRecord('Ancient record', now()->toIso8601String());

    $mock = mockCloudWatchClient();
    expectLogGroupSetup($mock);
    $mock->shouldReceive('putLogEvents')->once()->andReturn([
        'rejectedLogEventsInfo' => ['tooOldLogEventEndIndex' => 1],
    ]);

    Log::shouldReceive('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'rejected'));

    $this->artisan('cloudwatch:ship')->assertSuccessful();
});
