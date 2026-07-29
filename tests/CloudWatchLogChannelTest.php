<?php

use Illuminate\Support\Facades\Log;
use Rouxtaccess\CloudWatch\Buffer\Buffer;
use Rouxtaccess\CloudWatch\Buffer\CacheBuffer;

it('buffers log records as json', function () {
    Log::channel('cloudwatch')->info('Buffered message', ['card' => 'Black Lotus']);

    $batch = app(Buffer::class)->peek(10);

    expect($batch->records)->toHaveCount(1);

    $decodedRecord = json_decode(array_values($batch->records)[0], true);

    expect($decodedRecord['message'])->toBe('Buffered message')
        ->and($decodedRecord['context']['card'])->toBe('Black Lotus')
        ->and($decodedRecord['level_name'])->toBe('INFO')
        ->and($decodedRecord)->toHaveKey('datetime');
});

it('does not buffer records below the configured level', function () {
    Log::channel('cloudwatch')->debug('Below the configured info level');

    expect(app(Buffer::class)->pendingCount())->toBe(0);
});

it('never throws when the buffer backend fails', function () {
    config()->set('cloudwatch.buffer.cache_store', 'this-store-does-not-exist');
    app()->forgetInstance(Buffer::class);
    app()->forgetInstance(CacheBuffer::class);
    Log::forgetChannel('cloudwatch');

    Log::channel('cloudwatch')->info('This must not explode');

    expect(true)->toBeTrue();
});
