<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Services\MonitorCheckLogService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RollupCreditUsageTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_rollup_writes_one_debit_per_org_and_is_idempotent(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        DB::table('organizations')->whereIn('id', [$orgA->id, $orgB->id])->update(['credit_balance' => 1000]);
        $monitorA = Monitor::factory()->forOrganization($orgA)->create();
        $monitorB = Monitor::factory()->forOrganization($orgB)->create();

        CreditUsageDaily::create(['organization_id' => $orgA->id, 'monitor_id' => $monitorA->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => '2026-07-09', 'credits' => 288]);
        CreditUsageDaily::create(['organization_id' => $orgA->id, 'monitor_id' => $monitorA->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_DOMAIN, 'date' => '2026-07-09', 'credits' => 1]);
        CreditUsageDaily::create(['organization_id' => $orgB->id, 'monitor_id' => $monitorB->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => '2026-07-09', 'credits' => 144]);
        // A different day must NOT be picked up.
        CreditUsageDaily::create(['organization_id' => $orgB->id, 'monitor_id' => $monitorB->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_CERTIFICATE, 'date' => '2026-07-08', 'credits' => 1]);

        $this->artisan('credits:rollup-usage', ['--date' => '2026-07-09'])->assertSuccessful();

        $debits = CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->get();
        $this->assertCount(2, $debits);
        $this->assertSame(-289, $debits->firstWhere('organization_id', $orgA->id)->amount);
        $this->assertSame(-144, $debits->firstWhere('organization_id', $orgB->id)->amount);
        // Balance untouched — it was decremented live.
        $this->assertSame(1000, $orgA->fresh()->credit_balance);

        // Second run: no duplicates.
        $this->artisan('credits:rollup-usage', ['--date' => '2026-07-09'])->assertSuccessful();
        $this->assertSame(2, CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->count());
    }

    public function test_rollup_defaults_to_yesterday_utc(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->subDay()->toDateString(),
            'credits' => 10,
        ]);

        $this->artisan('credits:rollup-usage')->assertSuccessful();

        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_USAGE_DEBIT,
            'amount' => -10,
        ]);
    }

    public function test_rollup_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'credits:rollup-usage')));
    }
}
