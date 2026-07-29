<?php

namespace Rouxtaccess\CloudWatch\Commands;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Rouxtaccess\CloudWatch\Buffer\Buffer;
use Rouxtaccess\CloudWatch\Buffer\BufferBatch;
use Throwable;

class ShipLogsCommand extends Command
{
    protected $signature = 'cloudwatch:ship';

    protected $description = 'Ship buffered log records to CloudWatch Logs';

    protected const int MaxBatchBytes = 1000000;

    protected const int PerEventOverheadBytes = 26;

    protected const int MaxBatchTimeSpanMs = 23 * 60 * 60 * 1000;

    public function handle(CloudWatchLogsClient $cloudWatchClient, Buffer $buffer): int
    {
        if (! config('cloudwatch.enabled')) {
            $this->info('CloudWatch shipping is disabled, nothing to do.');

            return self::SUCCESS;
        }

        try {
            if ($buffer->pendingCount() === 0) {
                $this->info('The log buffer is empty, nothing to ship.');

                return self::SUCCESS;
            }

            $this->ensureLogGroupAndStreamExist($cloudWatchClient);

            $shippedCount = $this->shipPendingRecords($cloudWatchClient, $buffer);

            $this->info("Shipped {$shippedCount} log records to CloudWatch.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error("Failed to ship logs to CloudWatch: {$throwable->getMessage()}");

            Log::error('CloudWatch log shipping failed, the buffer is kept for the next run.', [
                'error' => $throwable->getMessage(),
                'pending_records' => $buffer->pendingCount(),
            ]);

            return self::FAILURE;
        }
    }

    protected function shipPendingRecords(CloudWatchLogsClient $cloudWatchClient, Buffer $buffer): int
    {
        $chunkSize = (int) config('cloudwatch.ship.chunk_size', 500);
        $shippedCount = 0;

        while (true) {
            $batch = $buffer->peek($chunkSize);

            if ($batch->coversNothing()) {
                break;
            }

            foreach ($this->buildEventBatches($batch) as $events) {
                $this->putLogEvents($cloudWatchClient, $events);
            }

            $buffer->acknowledge($batch);
            $shippedCount += count($batch->records);
        }

        return $shippedCount;
    }

    /**
     * @param  array<int, array{timestamp: int, message: string}>  $events
     */
    protected function putLogEvents(CloudWatchLogsClient $cloudWatchClient, array $events): void
    {
        $result = $cloudWatchClient->putLogEvents([
            'logGroupName' => config('cloudwatch.group.name'),
            'logStreamName' => $this->streamName(),
            'logEvents' => $events,
        ]);

        $rejectedInfo = $result['rejectedLogEventsInfo'] ?? null;

        if ($rejectedInfo !== null) {
            Log::warning('CloudWatch rejected some log events.', ['rejected_log_events_info' => $rejectedInfo]);
        }
    }

    protected function ensureLogGroupAndStreamExist(CloudWatchLogsClient $cloudWatchClient): void
    {
        if (! config('cloudwatch.group.create')) {
            return;
        }

        $group = config('cloudwatch.group.name');
        $stream = $this->streamName();

        Cache::remember("cloudwatch:group-ready:{$group}:{$stream}", now()->addDay(), function () use ($cloudWatchClient, $group, $stream) {
            $existingGroups = $cloudWatchClient->describeLogGroups(['logGroupNamePrefix' => $group])['logGroups'] ?? [];
            $groupExists = collect($existingGroups)->contains(fn (array $logGroup) => $logGroup['logGroupName'] === $group);

            if (! $groupExists) {
                $cloudWatchClient->createLogGroup(['logGroupName' => $group]);
            }

            $cloudWatchClient->putRetentionPolicy([
                'logGroupName' => $group,
                'retentionInDays' => (int) config('cloudwatch.group.retention_days'),
            ]);

            try {
                $cloudWatchClient->createLogStream([
                    'logGroupName' => $group,
                    'logStreamName' => $stream,
                ]);
            } catch (CloudWatchLogsException $exception) {
                if ($exception->getAwsErrorCode() !== 'ResourceAlreadyExistsException') {
                    throw $exception;
                }
            }

            return true;
        });
    }

    /**
     * Batches must respect the PutLogEvents limits: chronological order, at
     * most ~1MB per call (message bytes plus 26 bytes overhead per event) and
     * no more than 24 hours between the first and last event in a batch.
     *
     * @return array<int, array<int, array{timestamp: int, message: string}>>
     */
    protected function buildEventBatches(BufferBatch $batch): array
    {
        $events = collect($batch->records)
            ->map(fn (string $record) => [
                'timestamp' => $this->resolveTimestampMs($record),
                'message' => $record,
            ])
            ->sortBy('timestamp')
            ->values();

        $eventBatches = [];
        $currentBatch = [];
        $currentBatchBytes = 0;
        $currentBatchStartTimestamp = null;

        foreach ($events as $event) {
            $eventBytes = strlen($event['message']) + self::PerEventOverheadBytes;
            $exceedsBytes = $currentBatchBytes + $eventBytes > self::MaxBatchBytes;
            $exceedsTimeSpan = $currentBatchStartTimestamp !== null
                && $event['timestamp'] - $currentBatchStartTimestamp > self::MaxBatchTimeSpanMs;

            if ($currentBatch !== []) {
                if ($exceedsBytes || $exceedsTimeSpan) {
                    $eventBatches[] = $currentBatch;
                    $currentBatch = [];
                    $currentBatchBytes = 0;
                    $currentBatchStartTimestamp = null;
                }
            }

            $currentBatch[] = $event;
            $currentBatchBytes += $eventBytes;
            $currentBatchStartTimestamp ??= $event['timestamp'];
        }

        if ($currentBatch !== []) {
            $eventBatches[] = $currentBatch;
        }

        return $eventBatches;
    }

    protected function resolveTimestampMs(string $record): int
    {
        $decodedRecord = json_decode($record, true);
        $datetime = is_array($decodedRecord) ? ($decodedRecord['datetime'] ?? null) : null;

        if ($datetime === null) {
            return now()->getTimestampMs();
        }

        try {
            return Carbon::parse($datetime)->getTimestampMs();
        } catch (Throwable) {
            return now()->getTimestampMs();
        }
    }

    protected function streamName(): string
    {
        return config('cloudwatch.stream') ?: gethostname();
    }
}
