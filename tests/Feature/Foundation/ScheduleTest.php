<?php

namespace Tests\Feature\Foundation;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** The host disables proc_open: scheduled tasks must never start a child process. */
class ScheduleTest extends TestCase
{
    public function test_every_task_runs_inside_the_scheduler_process(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertSame(
            ['app:maintenance', 'queue:work', 'db:backup'],
            array_map(fn ($e) => $e->description, $events),
        );
        foreach ($events as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event, "{$event->description} would need proc_open");
        }
    }

    public function test_frequencies(): void
    {
        $byName = collect(app(Schedule::class)->events())->keyBy('description');

        $this->assertSame('0 * * * *', $byName['app:maintenance']->expression);
        $this->assertSame('* * * * *', $byName['queue:work']->expression);
        $this->assertTrue($byName['queue:work']->withoutOverlapping);
        $this->assertSame(30, $byName['queue:work']->expiresAt);
        $this->assertSame('0 3 * * *', $byName['db:backup']->expression);
    }
}
