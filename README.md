# Laravel CloudWatch

[![Latest Version on Packagist](https://img.shields.io/packagist/v/rouxtaccess/laravel-cloudwatch.svg?style=flat-square)](https://packagist.org/packages/rouxtaccess/laravel-cloudwatch)
[![GitHub Tests Action Status](https://github.com/RouxtAccess/laravel-cloudwatch/actions/workflows/run-tests.yml/badge.svg)](https://github.com/RouxtAccess/laravel-cloudwatch/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/rouxtaccess/laravel-cloudwatch.svg?style=flat-square)](https://packagist.org/packages/rouxtaccess/laravel-cloudwatch)

Ship Laravel logs to AWS CloudWatch Logs without blocking your application.

For years, CloudWatch logging from PHP has meant choosing between two kinds of pain. The existing log handlers are synchronous, calling the AWS API from inside your PHP process, where a slow or unavailable CloudWatch endpoint can slow down or outright break your requests. The alternative is a CloudWatch agent living on every server, with file watchers, per-host configuration, and one more piece of infrastructure for Ops to install, monitor, and keep patched. This package exists to remove both. Logging never touches AWS inside your request path, and there is nothing to run beyond a normal Laravel install, using the scheduler and a cache store you already have.

The log channel writes each record to a fast local buffer (any Laravel cache store), and a scheduled command ships the buffer to CloudWatch in batches, outside of any request or job.

Highlights:

- The hot path is a few cache store operations per log record. No AWS calls, ever.
- The buffer lives in any cache store you choose (Redis, Memcached, database, DynamoDB).
- The ship command creates the log group for you and applies a retention policy (two years by default), so there is nothing to provision by hand.
- A CloudWatch outage never breaks your app. Records queue up (capped) and ship when CloudWatch is reachable again.
- Batches respect all `PutLogEvents` limits: chronological order, the 1MB payload cap, and the 24 hour batch time span.

## Installation

Install via composer:

```bash
composer require rouxtaccess/laravel-cloudwatch
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="cloudwatch-config"
```

## Usage

Add a `cloudwatch` channel to `config/logging.php`:

```php
'channels' => [

    'stack' => [
        'driver' => 'stack',
        'channels' => explode(',', env('LOG_STACK', 'daily,cloudwatch')),
    ],

    'cloudwatch' => [
        'driver' => 'cloudwatch',
        'level' => env('LOG_CLOUDWATCH_LEVEL', 'info'),
    ],

],
```

Then enable shipping in your `.env`:

```dotenv
CLOUDWATCH_ENABLED=true
CLOUDWATCH_LOG_GROUP=my-app-production
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-west-1
```

That is everything. The package schedules `cloudwatch:ship` every minute (with overlap protection) as long as your scheduler is running. On the first shipment it creates the log group with the configured retention.

Logging works like any other channel:

```php
Log::info('Order shipped', ['order_id' => $order->id]);
Log::channel('cloudwatch')->warning('Only to CloudWatch');
```

Records are stored as JSON lines, which makes them directly queryable with CloudWatch Logs Insights:

```
fields @timestamp, message, context.order_id
| filter level_name = "ERROR"
| sort @timestamp desc
```

## Configuration

### Choosing the buffer store

By default the buffer uses your application's default cache store. Point it at any store from `config/cache.php` with:

```dotenv
CLOUDWATCH_BUFFER_CACHE_STORE=redis
```

Stores with atomic increments (Redis, Memcached, database, DynamoDB) are safe under concurrent writes. Avoid the file store outside local development, since its counters are not atomic across processes.

### Log group, stream and retention

```dotenv
CLOUDWATCH_LOG_GROUP=my-app-production
CLOUDWATCH_LOG_STREAM=web-1        # defaults to the host name
CLOUDWATCH_RETENTION_DAYS=731      # two years, must be a value CloudWatch accepts
```

If your IAM policy is locked down and the group is provisioned externally (Terraform, CloudFormation), disable group management:

```dotenv
CLOUDWATCH_CREATE_LOG_GROUP=false
```

With group management enabled, the IAM user or role needs:

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "logs:CreateLogGroup",
                "logs:CreateLogStream",
                "logs:PutRetentionPolicy",
                "logs:DescribeLogGroups",
                "logs:PutLogEvents"
            ],
            "Resource": "arn:aws:logs:*:*:log-group:my-app-production*"
        }
    ]
}
```

With `CLOUDWATCH_CREATE_LOG_GROUP=false` only `logs:PutLogEvents` is required.

### Buffer safety valves

The buffer is capped so an extended outage cannot grow your cache store unbounded. Once the cap is reached new records are dropped until the shipper catches up. Buffered records also carry a TTL as a second safety net.

```dotenv
CLOUDWATCH_BUFFER_CAP=100000
CLOUDWATCH_BUFFER_RECORD_TTL=259200
```

### Shipping frequency

By default the ship command runs every minute. Projects that do not log much can ship less often. A plain number means "every N minutes", or provide a full five-part cron expression:

```dotenv
CLOUDWATCH_SHIP_SCHEDULE=5              # every five minutes
CLOUDWATCH_SHIP_SCHEDULE="*/10 * * * *" # same idea, as a cron expression
```

An idle run is nearly free either way. When the buffer is empty the command exits after two cache reads, without contacting AWS. When shipping less frequently, make sure `CLOUDWATCH_BUFFER_CAP` comfortably holds the records your app produces between runs.

### Scheduling yourself

Set `CLOUDWATCH_AUTO_SCHEDULE=false` and schedule the command however you prefer:

```php
Schedule::command('cloudwatch:ship')->everyMinute()->withoutOverlapping();
```

### Custom formatter

The channel accepts a `formatter` option, any Monolog formatter class resolved through the container:

```php
'cloudwatch' => [
    'driver' => 'cloudwatch',
    'level' => 'info',
    'formatter' => Monolog\Formatter\LineFormatter::class,
],
```

The default is a `JsonFormatter`, which is the most useful format for Logs Insights queries.

### Custom buffer backend

The buffer is bound through the `Rouxtaccess\CloudWatch\Buffer\Buffer` contract. To store records somewhere else entirely, implement the contract (four methods: `push`, `peek`, `acknowledge`, `pendingCount`) and point the config at your class:

```php
'buffer' => [
    'implementation' => App\Logging\NativeRedisListBuffer::class,
],
```

## Delivery semantics

Delivery is at least once. The shipper only acknowledges (removes) records after CloudWatch accepts them, so a failure keeps everything buffered for the next run. A crash between a successful `PutLogEvents` call and the acknowledgement can duplicate a batch, and a hard crash of the cache store can lose records that have not shipped yet. If CloudWatch rejects individual events (for example events older than 14 days), the rejection info is logged as a warning.

## Testing

```bash
composer test
composer analyse
composer format
```

The test suite runs on the array cache store, so it needs no external services.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [John Roux](https://github.com/JohnRoux)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
