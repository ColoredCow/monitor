<?php

namespace Tests\Feature\MonitorHistory;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MonitorHistoryCommandsRegisteredTest extends TestCase
{
    public function test_monitor_history_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('monitor:aggregate-check-metrics', $commands);
        $this->assertContains('monitor:backfill-check-history', $commands);
        $this->assertContains('monitor:prune-check-history', $commands);
    }
}
