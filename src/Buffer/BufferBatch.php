<?php

namespace Rouxtaccess\CloudWatch\Buffer;

class BufferBatch
{
    /**
     * @param  array<int, string>  $records  keyed by buffer sequence number
     */
    public function __construct(
        public readonly array $records,
        public readonly int $lastSequence,
    ) {}

    public static function empty(): self
    {
        return new self([], 0);
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function coversNothing(): bool
    {
        return $this->lastSequence === 0;
    }
}
