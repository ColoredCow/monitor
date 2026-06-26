<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\UptimeMonitor\MonitorRepository;
use Tests\TestCase;

/**
 * Regression guard: the scheduled uptime-check enumeration entry point must
 * return monitors across ALL organizations when no active organization is bound
 * (i.e. when running in a console/queue context).
 *
 * Entry point used: MonitorRepository::getEnabled()
 *
 * Why: MonitorRepository::getEnabled() is the exact method called by Spatie's
 * CheckUptime command to enumerate which monitors to probe. It delegates to
 * MonitorRepository::query() → $modelClass::enabled(). Our App\Models\Monitor
 * extends SpatieMonitor and uses the BelongsToOrganization trait, which adds
 * only a `creating` hook and a local `scopeForOrganization` — NO global scope.
 * If a developer ever adds a global org scope to Monitor, this test will fail
 * because getEnabled() would return 1 instead of 2.
 */
class ConsoleScopeRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_uptime_check_entry_point_sees_monitors_across_all_orgs(): void
    {
        // Create two separate organizations with one enabled monitor each.
        // We use Monitor::factory() with state directly to avoid relying on
        // an active organization — no user is authenticated, no CurrentOrganization bound.
        $orgA = Organization::factory()->create(['name' => 'Org A', 'slug' => 'org-a']);
        $orgB = Organization::factory()->create(['name' => 'Org B', 'slug' => 'org-b']);

        $monitorA = Monitor::factory()->forOrganization($orgA)->create(['uptime_check_enabled' => true]);
        $monitorB = Monitor::factory()->forOrganization($orgB)->create(['uptime_check_enabled' => true]);

        // No CurrentOrganization bound, no acting user — simulates console/queue context.
        $enabled = MonitorRepository::getEnabled();

        $this->assertCount(2, $enabled, 'MonitorRepository::getEnabled() must return monitors from all organizations in console context.');
        $this->assertTrue(
            $enabled->contains('id', $monitorA->id),
            'Monitor from Org A must be returned by getEnabled().'
        );
        $this->assertTrue(
            $enabled->contains('id', $monitorB->id),
            'Monitor from Org B must be returned by getEnabled().'
        );
    }
}
