<?php

use Illuminate\Support\Facades\Cache;
use Rouxtaccess\CloudWatch\Buffer\CacheBuffer;

function makeBuffer(int $capSize = 100): CacheBuffer
{
    return new CacheBuffer(Cache::store('array'), 'test:buffer', $capSize);
}

it('returns pushed records in order', function () {
    $buffer = makeBuffer();

    $buffer->push('first');
    $buffer->push('second');
    $buffer->push('third');

    $batch = $buffer->peek(10);

    expect(array_values($batch->records))->toBe(['first', 'second', 'third'])
        ->and($buffer->pendingCount())->toBe(3);
});

it('limits peek to the requested number of records', function () {
    $buffer = makeBuffer();

    $buffer->push('first');
    $buffer->push('second');
    $buffer->push('third');

    $batch = $buffer->peek(2);

    expect(array_values($batch->records))->toBe(['first', 'second']);
});

it('removes acknowledged records and keeps the rest', function () {
    $buffer = makeBuffer();

    $buffer->push('first');
    $buffer->push('second');
    $buffer->push('third');

    $buffer->acknowledge($buffer->peek(2));

    expect(array_values($buffer->peek(10)->records))->toBe(['third'])
        ->and($buffer->pendingCount())->toBe(1);
});

it('drops new records once the cap is reached', function () {
    $buffer = makeBuffer(capSize: 2);

    $buffer->push('first');
    $buffer->push('second');
    $buffer->push('dropped');

    $batch = $buffer->peek(10);

    expect(array_values($batch->records))->toBe(['first', 'second']);

    $buffer->acknowledge($batch);

    expect($buffer->pendingCount())->toBe(0)
        ->and($buffer->peek(10)->isEmpty())->toBeTrue();
});

it('accepts records again after a full buffer is drained', function () {
    $buffer = makeBuffer(capSize: 2);

    $buffer->push('first');
    $buffer->push('second');
    $buffer->push('dropped');

    $buffer->acknowledge($buffer->peek(10));
    $buffer->push('fourth');

    expect(array_values($buffer->peek(10)->records))->toBe(['fourth']);
});

it('reports an empty batch when there is nothing pending', function () {
    $buffer = makeBuffer();

    $batch = $buffer->peek(10);

    expect($batch->isEmpty())->toBeTrue()
        ->and($batch->coversNothing())->toBeTrue()
        ->and($buffer->pendingCount())->toBe(0);
});
