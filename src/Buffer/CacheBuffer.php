<?php

namespace Rouxtaccess\CloudWatch\Buffer;

use Illuminate\Contracts\Cache\Repository;

/**
 * A FIFO buffer built on any Laravel cache store.
 *
 * Two counters track the queue: `tail` is incremented atomically for every
 * pushed record (the record is stored under its sequence number) and `head`
 * marks the last shipped sequence. Only the ship command moves `head`, so
 * concurrent producers never contend on anything but the atomic increment.
 * A sequence without a stored record (dropped over cap, expired, or a crash
 * between increment and write) is simply skipped when peeking.
 */
class CacheBuffer implements Buffer
{
    public function __construct(
        protected Repository $cache,
        protected string $prefix = 'cloudwatch:buffer',
        protected int $capSize = 100000,
        protected ?int $recordTtlSeconds = 259200,
    ) {}

    public function push(string $record): void
    {
        $this->cache->add($this->tailKey(), 0);
        $this->cache->add($this->headKey(), 0);

        $sequence = (int) $this->cache->increment($this->tailKey());
        $head = (int) $this->cache->get($this->headKey(), 0);

        if ($sequence - $head > $this->capSize) {
            return;
        }

        $this->cache->put($this->recordKey($sequence), $record, $this->recordTtlSeconds);
    }

    public function peek(int $limit): BufferBatch
    {
        $head = (int) $this->cache->get($this->headKey(), 0);
        $tail = (int) $this->cache->get($this->tailKey(), 0);

        if ($head >= $tail) {
            return BufferBatch::empty();
        }

        $sequences = range($head + 1, min($head + $limit, $tail));
        $keys = array_map(fn (int $sequence) => $this->recordKey($sequence), $sequences);
        $storedRecords = $this->cache->many($keys);

        $records = [];

        foreach ($sequences as $index => $sequence) {
            $record = $storedRecords[$keys[$index]] ?? null;

            if (is_string($record)) {
                $records[$sequence] = $record;
            }
        }

        return new BufferBatch($records, $sequences[array_key_last($sequences)]);
    }

    public function acknowledge(BufferBatch $batch): void
    {
        if ($batch->coversNothing()) {
            return;
        }

        $head = (int) $this->cache->get($this->headKey(), 0);

        foreach (range($head + 1, $batch->lastSequence) as $sequence) {
            $this->cache->forget($this->recordKey($sequence));
        }

        $this->cache->put($this->headKey(), max($head, $batch->lastSequence));
    }

    public function pendingCount(): int
    {
        $head = (int) $this->cache->get($this->headKey(), 0);
        $tail = (int) $this->cache->get($this->tailKey(), 0);

        return max(0, $tail - $head);
    }

    protected function headKey(): string
    {
        return "{$this->prefix}:head";
    }

    protected function tailKey(): string
    {
        return "{$this->prefix}:tail";
    }

    protected function recordKey(int $sequence): string
    {
        return "{$this->prefix}:record:{$sequence}";
    }
}
