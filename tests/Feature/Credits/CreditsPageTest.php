<?php

namespace Tests\Feature\Credits;

use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Services\CreditLedgerService;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditsPageTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_member_sees_balance_usage_and_transactions(): void
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => 1000]);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['name' => 'Main site']);
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->toDateString(),
            'credits' => 288,
        ]);
        app(CreditLedgerService::class)->grant($organization->fresh(), 500, null, 'Top up');

        $this->actingAsMember($organization);

        $this->get('/credits')->assertInertia(fn ($page) => $page
            ->component('Credits/Index')
            ->where('balance', 1500)
            ->where('usageByDay.0.total', 288)
            ->where('topMonitors.0.name', 'Main site')
            ->where('topMonitors.0.credits', 288)
            ->where('transactions.0.type', 'grant')
            ->where('transactions.0.amount', 500));
    }

    public function test_usage_is_scoped_to_the_active_org(): void
    {
        $mine = $this->createOrganization();
        $theirs = $this->createOrganization();
        $theirMonitor = Monitor::factory()->forOrganization($theirs)->create();
        CreditUsageDaily::create([
            'organization_id' => $theirs->id,
            'monitor_id' => $theirMonitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->toDateString(),
            'credits' => 99,
        ]);

        $this->actingAsMember($mine);

        $this->get('/credits')->assertInertia(fn ($page) => $page
            ->component('Credits/Index')
            ->has('usageByDay', 0)
            ->has('transactions', 0));
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/credits')->assertRedirect('/login');
    }
}
