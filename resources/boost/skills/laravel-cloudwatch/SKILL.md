---
name: laravel-cloudwatch
description: Configure, debug, and extend the rouxtaccess/laravel-cloudwatch package, which ships Laravel logs to AWS CloudWatch Logs through a cache-buffered `cloudwatch` log channel and a scheduled `cloudwatch:ship` command. Activate when the user configures the cloudwatch channel or `config/cloudwatch.php`, runs or schedules `cloudwatch:ship`, investigates missing or delayed CloudWatch logs, or writes a custom formatter or buffer backend.
license: MIT
metadata:
  author: John Roux
---

# Laravel CloudWatch

## Overview

Laravel CloudWatch ships application logs to AWS CloudWatch Logs without blocking the request path. The `cloudwatch` log channel writes each record into a fast local buffer (any Laravel cache store), and a scheduled `cloudwatch:ship` command drains the buffer to CloudWatch in batches. No AWS call ever happens inside a request or job, and there is no server agent; the only moving parts are the scheduler and a cache store the app already has.

## When to activate

- The user adds or tunes the `cloudwatch` channel in `config/logging.php`, or edits `config/cloudwatch.php`.
- The user runs, schedules, or asks about `php artisan cloudwatch:ship`.
- Logs are missing, delayed, or duplicated in CloudWatch and the user wants to know why.
- The user wants a custom Monolog formatter or a custom buffer backend.

## Configuring

Add a `cloudwatch` channel and put it in the stack:

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

Then enable shipping:

```dotenv
CLOUDWATCH_ENABLED=true
CLOUDWATCH_LOG_GROUP=my-app-production
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-west-1
```

- `CLOUDWATCH_ENABLED=false` (the default) makes the ship command exit without touching AWS while the channel keeps buffering (capped), so it can be flipped on without losing recent records.
- Credentials fall back to the SDK default provider chain (instance roles, shared credentials) when no key is set. `CLOUDWATCH_AWS_*` variants override the plain `AWS_*` ones when the app needs separate CloudWatch credentials.
- `CLOUDWATCH_LOG_STREAM` names the stream; it defaults to the host name so multiple servers shipping into one group do not interleave within a stream.
- Buffer store: `CLOUDWATCH_BUFFER_CACHE_STORE` picks any store from `config/cache.php` (null uses the default store). Stores with atomic increments (redis, memcached, database, dynamodb) are safe under concurrent writes; avoid the file store outside local development.
- Safety valves: `CLOUDWATCH_BUFFER_CAP` (default 100000) caps pending records, and `CLOUDWATCH_BUFFER_RECORD_TTL` (default 259200 seconds, three days) expires stragglers. Over the cap, new records are dropped until the shipper catches up.
- Schedule: `CLOUDWATCH_SHIP_SCHEDULE` accepts a plain number (every N minutes, 1 to 59) or a five part cron expression; the command self-schedules with overlap protection. `CLOUDWATCH_AUTO_SCHEDULE=false` turns that off so the app schedules `cloudwatch:ship` itself. An idle run costs two cache reads and no AWS call.
- Group management: on the first shipment the command creates the log group and stream and asserts `CLOUDWATCH_RETENTION_DAYS` retention (default 731, two years), re-checked once a day through a cache lock. Set `CLOUDWATCH_CREATE_LOG_GROUP=false` when the group is provisioned externally (Terraform, CloudFormation); then only `logs:PutLogEvents` is required, otherwise the IAM policy also needs `logs:CreateLogGroup`, `logs:CreateLogStream`, `logs:PutRetentionPolicy`, and `logs:DescribeLogGroups`.

Records are JSON lines by default, so CloudWatch Logs Insights can query fields directly (`filter level_name = "ERROR"`, `fields context.order_id`).

## How it works

- `CacheBuffer` is a FIFO queue on two counters: `tail` increments atomically per pushed record (stored under its sequence number) and `head` marks the last shipped sequence. Only the ship command moves `head`, so concurrent producers contend on nothing but the atomic increment. A sequence with no stored record (dropped over cap, expired, or a crash between increment and write) is skipped when peeking.
- The channel is a Monolog handler wrapped in `WhatFailureGroupHandler`, so a broken cache store degrades logging silently instead of taking down the app.
- The ship command peeks `cloudwatch.ship.chunk_size` records at a time, splits them into batches that respect every `PutLogEvents` limit (chronological order, roughly 1MB per call counting 26 bytes overhead per event, no more than 24 hours between first and last event), ships, then acknowledges. Acknowledge happens only after CloudWatch accepts the batch.
- Delivery is at least once. A failed run logs an error and keeps the buffer for the next run. A crash between a successful `PutLogEvents` call and the acknowledgement can duplicate a batch. If CloudWatch rejects individual events (for example events older than 14 days), the rejection info is logged as a warning.

## Debugging missing logs

Work through the pipeline in order:

1. Is `CLOUDWATCH_ENABLED=true` and is the `cloudwatch` channel actually in the active `LOG_STACK`?
2. Is the scheduler running, and is `cloudwatch:ship` on it (`php artisan schedule:list`)? With `CLOUDWATCH_AUTO_SCHEDULE=false` the app must schedule it itself.
3. Run `php artisan cloudwatch:ship` by hand; it reports either the shipped count, an empty buffer, or the AWS error.
4. Check the buffer: resolve `Rouxtaccess\CloudWatch\Buffer\Buffer` and call `pendingCount()`. A large and growing count means shipping is failing (look for the "CloudWatch log shipping failed" error log); a zero count means records never reach the buffer (channel or level misconfigured).
5. Records dropped silently usually mean the cap was hit during an outage or the record TTL expired before shipping resumed.

## Extending

Extend through config, never by editing the package.

### Custom formatter

The channel accepts a `formatter` option, any Monolog formatter class resolved through the container. The default is a `JsonFormatter`, the most useful format for Logs Insights; swapping it away loses per-record timestamps (the shipper reads the JSON `datetime` field and falls back to ship time) and field-level querying.

```php
'cloudwatch' => [
    'driver' => 'cloudwatch',
    'level' => 'info',
    'formatter' => Monolog\Formatter\LineFormatter::class,
],
```

### Custom buffer backend

Implement `Rouxtaccess\CloudWatch\Buffer\Buffer` (four methods) and point `cloudwatch.buffer.implementation` at the class; it is resolved from the container as a singleton, so constructor injection works.

```php
use Rouxtaccess\CloudWatch\Buffer\Buffer;
use Rouxtaccess\CloudWatch\Buffer\BufferBatch;

class NativeRedisListBuffer implements Buffer
{
    public function push(string $record): void { /* enqueue */ }

    public function peek(int $limit): BufferBatch
    {
        // Records keyed by sequence number, plus the last sequence covered.
        return new BufferBatch([1 => '{"message":"..."}'], 1);
    }

    public function acknowledge(BufferBatch $batch): void { /* remove through lastSequence */ }

    public function pendingCount(): int { return 0; }
}
```

- `peek` must return records keyed by their sequence number and set `lastSequence` to the highest sequence covered (even when some records in that range are gone). `BufferBatch::empty()` signals nothing pending.
- `acknowledge` removes everything up to and including `lastSequence`; the shipper calls it only after CloudWatch accepted the batch, so removal must not happen earlier.
- The push path runs inside the application's logging hot path. Keep it to a few store operations and never let it throw for routine conditions (over cap means drop, not error).

## Testing an extension

- The package test suite (`composer test`, Pest) runs on the array cache store and needs no external services; `composer analyse` (PHPStan) and `composer format` (Pint) must also stay green.
- Buffer implementations: drive `push`/`peek`/`acknowledge` directly and assert `pendingCount()` plus batch contents, including a gap in sequences (forget a record key mid-range, then peek across it).
- Shipping behaviour: bind a Mockery mock of `Aws\CloudWatchLogs\CloudWatchLogsClient` into the container, buffer some records through `Log::channel('cloudwatch')`, run `artisan('cloudwatch:ship')`, and assert on the captured `putLogEvents` payloads and the emptied buffer.
- Do not call real AWS from tests. If real-shipping verification is needed, ask the user to run it in an environment with credentials.
