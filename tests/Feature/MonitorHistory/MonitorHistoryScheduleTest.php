<?php

namespace Tests\Feature\MonitorHistory;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MonitorHistoryScheduleTest extends TestCase
{
    private function findEvent(Schedule $schedule, string $command): ?Event
    {
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }

    public function test_history_schedules_are_gated_by_the_feature_flag(): void
    {
        $schedule = app(Schedule::class);

        $aggregate = $this->findEvent($schedule, 'monitor:aggregate-check-metrics');
        $prune = $this->findEvent($schedule, 'monitor:prune-check-history');
        $uptime = $this->findEvent($schedule, 'monitor:check-uptime');

        $this->assertNotNull($aggregate, 'aggregate command should be scheduled');
        $this->assertNotNull($prune, 'prune command should be scheduled');
        $this->assertNotNull($uptime, 'core uptime check should be scheduled');

        config(['monitor-history.enabled' => false]);
        $this->assertFalse($aggregate->filtersPass($this->app), 'aggregation must not run when history is disabled');
        $this->assertFalse($prune->filtersPass($this->app), 'pruning must not run when history is disabled');
        $this->assertTrue($uptime->filtersPass($this->app), 'core monitoring must always run');

        config(['monitor-history.enabled' => true]);
        $this->assertTrue($aggregate->filtersPass($this->app), 'aggregation must run when history is enabled');
        $this->assertTrue($prune->filtersPass($this->app), 'pruning must run when history is enabled');
    }
}
