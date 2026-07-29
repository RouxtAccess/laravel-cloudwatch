<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;

function scheduledShipEvents(): Collection
{
    app()->forgetInstance(Schedule::class);

    return collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'cloudwatch:ship'))
        ->values();
}

it('schedules the ship command every minute by default', function () {
    $events = scheduledShipEvents();

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

it('interprets a numeric schedule as every n minutes', function () {
    config()->set('cloudwatch.ship.schedule', '5');

    expect(scheduledShipEvents()->first()->expression)->toBe('*/5 * * * *');
});

it('interprets an integer schedule value as every n minutes', function () {
    config()->set('cloudwatch.ship.schedule', 10);

    expect(scheduledShipEvents()->first()->expression)->toBe('*/10 * * * *');
});

it('accepts a full cron expression', function () {
    config()->set('cloudwatch.ship.schedule', '15 3 * * *');

    expect(scheduledShipEvents()->first()->expression)->toBe('15 3 * * *');
});

it('does not schedule when auto schedule is disabled', function () {
    config()->set('cloudwatch.ship.auto_schedule', false);

    expect(scheduledShipEvents())->toHaveCount(0);
});

it('does not schedule when the package is disabled', function () {
    config()->set('cloudwatch.enabled', false);

    expect(scheduledShipEvents())->toHaveCount(0);
});
