<?php

namespace Rouxtaccess\CloudWatch\Buffer;

interface Buffer
{
    public function push(string $record): void;

    public function peek(int $limit): BufferBatch;

    public function acknowledge(BufferBatch $batch): void;

    public function pendingCount(): int;
}
