<?php

namespace Tests\Feature\MonitorHistory;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_suite_runs_against_the_dedicated_test_database(): void
    {
        $this->assertSame('monitor_test', DB::connection()->getDatabaseName());
    }
}
