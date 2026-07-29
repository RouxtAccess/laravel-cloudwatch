<?php

namespace Rouxtaccess\CloudWatch\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;
use Rouxtaccess\CloudWatch\Buffer\Buffer;

class CreateCloudWatchLogger
{
    /**
     * @param array{
     *     level?: string,
     *     formatter?: class-string<FormatterInterface>,
     *     name?: string
     * } $config
     */
    public function __invoke(array $config): Logger
    {
        $bufferHandler = new BufferHandler(
            app(Buffer::class),
            Logger::toMonologLevel($config['level'] ?? 'debug'),
        );

        $bufferHandler->setFormatter($this->resolveFormatter($config));

        return new Logger(
            $config['name'] ?? 'cloudwatch',
            [new WhatFailureGroupHandler([$bufferHandler])],
        );
    }

    /**
     * @param  array{formatter?: class-string<FormatterInterface>}  $config
     */
    protected function resolveFormatter(array $config): FormatterInterface
    {
        if (isset($config['formatter'])) {
            return app($config['formatter']);
        }

        return new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, false);
    }
}
