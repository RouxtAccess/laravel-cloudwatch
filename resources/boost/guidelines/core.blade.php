# Laravel CloudWatch

- Laravel CloudWatch ships logs to AWS CloudWatch Logs through a `cloudwatch` log channel that buffers records into a Laravel cache store. The request path never touches AWS; a scheduled `cloudwatch:ship` command drains the buffer in batches.
- Enable with `CLOUDWATCH_ENABLED=true` plus a `cloudwatch` channel in `config/logging.php` (usually added to `LOG_STACK`). The scheduler must be running for shipping to happen.
- The ship command schedules itself (with overlap protection) per `CLOUDWATCH_SHIP_SCHEDULE`: a plain number means every N minutes, or provide a five part cron expression. Set `CLOUDWATCH_AUTO_SCHEDULE=false` to schedule `cloudwatch:ship` yourself.
- Point the buffer at a cache store with `CLOUDWATCH_BUFFER_CACHE_STORE` (any store from `config/cache.php`). Stores with atomic increments (redis, memcached, database, dynamodb) are safe under concurrency; avoid the file store outside local development.
- The buffer is capped (`CLOUDWATCH_BUFFER_CAP`, default 100000) and records carry a TTL. Once the cap is reached new records are dropped until the shipper catches up, so an outage cannot grow the cache store unbounded.
- The first shipment creates the log group with `CLOUDWATCH_RETENTION_DAYS` retention (default 731, two years). For least privilege IAM set `CLOUDWATCH_CREATE_LOG_GROUP=false` (group provisioned externally); then only `logs:PutLogEvents` is required.
- Records are JSON lines by default, directly queryable with CloudWatch Logs Insights. Delivery is at least once: a failed run keeps the buffer for the next run.
- Extend through config, never by editing the package: a `formatter` option on the channel swaps the Monolog formatter, and `cloudwatch.buffer.implementation` swaps the whole buffer backend (implement the `Rouxtaccess\CloudWatch\Buffer\Buffer` contract).
- IMPORTANT: activate the `laravel-cloudwatch` skill when configuring, debugging, or extending Laravel CloudWatch.
