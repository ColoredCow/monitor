<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Services\CreditRunwayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditRunwayServiceTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_daily_burn_across_configurations(): void
    {
        $organization = $this->createOrganization();
        $service = app(CreditRunwayService::class);

        // 5-min uptime only: 1440/5 = 288
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5,
            'domain_check_enabled' => false,
        ]);
        $this->assertSame(288, $service->dailyBurnFor($organization));

        // + 1-min uptime with certificate and domain: 1440 + 1 + 1
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 1,
            'certificate_check_enabled' => true,
            'domain_check_enabled' => true,
        ]);
        $this->assertSame(288 + 1442, $service->dailyBurnFor($organization));
    }

    public function test_disabled_and_soft_deleted_monitors_burn_nothing(): void
    {
        $organization = $this->createOrganization();
        $service = app(CreditRunwayService::class);

        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_enabled' => false,
            'certificate_check_enabled' => false,
            'domain_check_enabled' => false,
        ]);
        $this->assertSame(0, $service->dailyBurnFor($organization));

        $deleted = Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5,
        ]);
        $deleted->delete();
        $this->assertSame(0, $service->dailyBurnFor($organization));
    }

    public function test_runway_days(): void
    {
        $organization = $this->createOrganization();
        $service = app(CreditRunwayService::class);

        // No burn -> no runway concept.
        $this->assertNull($service->runwayDaysFor($organization));

        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5, // 288/day
            'domain_check_enabled' => false,
        ]);

        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => 2880]);
        $this->assertEqualsWithDelta(10.0, $service->runwayDaysFor($organization->fresh()), 0.001);

        // Negative balances clamp to zero runway.
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => -5]);
        $this->assertSame(0.0, $service->runwayDaysFor($organization->fresh()));
    }

    public function test_interval_is_floored_at_one_minute(): void
    {
        $organization = $this->createOrganization();
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 0,
            'domain_check_enabled' => false,
        ]);

        $this->assertSame(1440, app(CreditRunwayService::class)->dailyBurnFor($organization));
    }
}
