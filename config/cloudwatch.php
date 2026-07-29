<?php

use Rouxtaccess\CloudWatch\Buffer\CacheBuffer;

return [

    /*
     * When disabled, the ship command exits immediately without touching AWS.
     * The log channel keeps buffering (capped), so you can flip this on at any
     * time without losing recent records.
     */
    'enabled' => env('CLOUDWATCH_ENABLED', false),

    /*
     * AWS connection details. When no key is set the SDK falls back to its
     * default credential provider chain (instance roles, shared credentials).
     */
    'region' => env('CLOUDWATCH_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    'key' => env('CLOUDWATCH_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
    'secret' => env('CLOUDWATCH_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),

    'group' => [
        /*
         * The CloudWatch log group to ship into.
         */
        'name' => env('CLOUDWATCH_LOG_GROUP', env('APP_NAME', 'laravel')),

        /*
         * When true, the ship command creates the log group and stream if they
         * are missing and asserts the retention policy once a day. Disable this
         * for least-privilege IAM setups where the group is provisioned
         * externally; then only logs:PutLogEvents is required.
         */
        'create' => (bool) env('CLOUDWATCH_CREATE_LOG_GROUP', true),

        /*
         * Retention applied when the package manages the group.
         * Must be one of the values CloudWatch accepts, 731 is two years.
         */
        'retention_days' => (int) env('CLOUDWATCH_RETENTION_DAYS', 731),
    ],

    /*
     * The log stream name. Defaults to the host name so multiple servers
     * shipping into one group do not interleave within a stream.
     */
    'stream' => env('CLOUDWATCH_LOG_STREAM'),

    'buffer' => [
        /*
         * The class implementing the Buffer contract. Swap this to change how
         * records are buffered between the log channel and the ship command.
         */
        'implementation' => CacheBuffer::class,

        /*
         * The cache store used to hold buffered records, any store from
         * config/cache.php. Null uses your default cache store. Stores with
         * atomic increments (redis, memcached, database, dynamodb) are safe
         * under concurrency; avoid the file store outside local development.
         */
        'cache_store' => env('CLOUDWATCH_BUFFER_CACHE_STORE'),

        /*
         * Prefix for all cache keys the buffer uses.
         */
        'prefix' => env('CLOUDWATCH_BUFFER_PREFIX', 'cloudwatch:buffer'),

        /*
         * Maximum number of records held while shipping is unavailable.
         * Beyond this, new records are dropped so the store cannot grow
         * unbounded during an outage.
         */
        'cap_size' => (int) env('CLOUDWATCH_BUFFER_CAP', 100000),

        /*
         * Seconds a buffered record may wait before the cache store expires
         * it, a safety net when shipping is off for a long time.
         */
        'record_ttl' => (int) env('CLOUDWATCH_BUFFER_RECORD_TTL', 259200),
    ],

    'ship' => [
        /*
         * When true (and the package is enabled) the ship command schedules
         * itself every minute with overlap protection. Set false to schedule
         * cloudwatch:ship yourself.
         */
        'auto_schedule' => (bool) env('CLOUDWATCH_AUTO_SCHEDULE', true),

        /*
         * How often the ship command runs when auto scheduled. A plain number
         * means "every N minutes" (1 to 59), or provide a full five-part cron
         * expression for anything else. Lower volume projects can comfortably
         * ship every 5 or 10 minutes; size the buffer cap accordingly.
         */
        'schedule' => env('CLOUDWATCH_SHIP_SCHEDULE', '* * * * *'),

        /*
         * How many buffered records are pulled from the cache store per
         * shipping round trip.
         */
        'chunk_size' => 500,
    ],

];
