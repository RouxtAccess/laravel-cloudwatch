<?php

namespace Rouxtaccess\CloudWatch\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Rouxtaccess\CloudWatch\Buffer\Buffer;

class BufferHandler extends AbstractProcessingHandler
{
    public function __construct(
        protected Buffer $buffer,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer->push((string) $record->formatted);
    }
}
