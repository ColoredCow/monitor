<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Services\CreditLedgerService;
use App\Services\CreditMeteringService;
use App\Services\MonitorCheckLogService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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

    public function test_rollup_handles_soft_deleted_orgs_without_aborting_the_loop(): void
    {
        $trashed = $this->createOrganization();
        $live = $this->createOrganization();
        DB::table('organizations')->whereIn('id', [$trashed->id, $live->id])->update(['credit_balance' => 500]);
        $trashedMonitor = Monitor::factory()->forOrganization($trashed)->create();
        $liveMonitor = Monitor::factory()->forOrganization($live)->create();

        CreditUsageDaily::create(['organization_id' => $trashed->id, 'monitor_id' => $trashedMonitor->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => '2026-07-09', 'credits' => 20]);
        CreditUsageDaily::create(['organization_id' => $live->id, 'monitor_id' => $liveMonitor->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => '2026-07-09', 'credits' => 30]);

        $trashed->delete();

        $this->artisan('credits:rollup-usage', ['--date' => '2026-07-09'])->assertSuccessful();

        $this->assertDatabaseHas('credit_transactions', ['organization_id' => $trashed->id, 'type' => CreditTransaction::TYPE_USAGE_DEBIT, 'amount' => -20]);
        $this->assertDatabaseHas('credit_transactions', ['organization_id' => $live->id, 'type' => CreditTransaction::TYPE_USAGE_DEBIT, 'amount' => -30]);
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

    public function test_default_run_sweeps_every_missed_date_before_today(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        foreach ([3, 2, 1] as $daysAgo) {
            CreditUsageDaily::create([
                'organization_id' => $organization->id,
                'monitor_id' => $monitor->id,
                'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
                'date' => now('UTC')->subDays($daysAgo)->toDateString(),
                'credits' => $daysAgo * 10,
            ]);
        }

        // Today's rows belong to tomorrow's run.
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_DOMAIN,
            'date' => now('UTC')->toDateString(),
            'credits' => 5,
        ]);

        $this->artisan('credits:rollup-usage')->assertSuccessful();

        $debits = CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->get();
        $this->assertCount(3, $debits);
        $this->assertEqualsCanonicalizing([-30, -20, -10], $debits->pluck('amount')->all());
        $this->assertEqualsCanonicalizing(
            [
                now('UTC')->subDays(3)->toDateString(),
                now('UTC')->subDays(2)->toDateString(),
                now('UTC')->subDay()->toDateString(),
            ],
            $debits->map(fn (CreditTransaction $debit) => $debit->metadata['date'])->all()
        );
    }

    public function test_default_run_skips_dates_already_rolled_up(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        $rolledUpDate = now('UTC')->subDays(4)->toDateString();
        $missedDate = now('UTC')->subDays(3)->toDateString();

        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => $rolledUpDate,
            'credits' => 40,
        ]);
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => $missedDate,
            'credits' => 30,
        ]);

        $this->artisan('credits:rollup-usage', ['--date' => $rolledUpDate])->assertSuccessful();
        $this->assertSame(1, CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->count());

        $this->artisan('credits:rollup-usage')->assertSuccessful();

        $this->assertSame(2, CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->count());
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_USAGE_DEBIT,
            'amount' => -30,
        ]);

        // A further default run finds nothing left to write.
        $this->artisan('credits:rollup-usage')->assertSuccessful();
        $this->assertSame(2, CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->count());
    }

    public function test_one_failing_org_is_skipped_and_a_later_default_run_heals_it(): void
    {
        $bad = $this->createOrganization();
        $good = $this->createOrganization();
        DB::table('organizations')->whereIn('id', [$bad->id, $good->id])->update(['credit_balance' => 500]);
        $badMonitor = Monitor::factory()->forOrganization($bad)->create();
        $goodMonitor = Monitor::factory()->forOrganization($good)->create();
        $date = now('UTC')->subDay()->toDateString();

        CreditUsageDaily::create(['organization_id' => $bad->id, 'monitor_id' => $badMonitor->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => $date, 'credits' => 10]);
        CreditUsageDaily::create(['organization_id' => $good->id, 'monitor_id' => $goodMonitor->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => $date, 'credits' => 20]);

        $real = new CreditLedgerService;
        $this->mock(CreditLedgerService::class, function ($mock) use ($real, $bad) {
            $mock->shouldReceive('recordUsageDebit')
                ->andReturnUsing(function ($organization, $credits, $rollupDate) use ($real, $bad) {
                    if ($organization->id === $bad->id) {
                        throw new RuntimeException('db timeout');
                    }

                    return $real->recordUsageDebit($organization, $credits, $rollupDate);
                });
        });

        $this->artisan('credits:rollup-usage')->assertFailed();

        $this->assertDatabaseHas('credit_transactions', ['organization_id' => $good->id, 'type' => CreditTransaction::TYPE_USAGE_DEBIT, 'amount' => -20]);
        $this->assertDatabaseMissing('credit_transactions', ['organization_id' => $bad->id, 'type' => CreditTransaction::TYPE_USAGE_DEBIT]);

        // Self-healing: once the org stops throwing, the next default run
        // picks the date back up with no --date needed.
        $this->app->instance(CreditLedgerService::class, $real);
        $this->artisan('credits:rollup-usage')->assertSuccessful();

        $this->assertDatabaseHas('credit_transactions', ['organization_id' => $bad->id, 'type' => CreditTransaction::TYPE_USAGE_DEBIT, 'amount' => -10]);
        $this->assertSame(2, CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->count());
    }

    public function test_ledger_invariant_sum_of_transactions_equals_balance_as_of_midnight(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        app(CreditLedgerService::class)->grant($organization, 1000, null, 'Initial grant');

        $metering = app(CreditMeteringService::class);

        // Real metered checks on a day the old default (yesterday-only) would
        // never roll up after a one-night outage.
        $this->travelTo(now('UTC')->subDays(2)->setTime(12, 0));
        foreach (range(1, 5) as $i) {
            $metering->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        }
        $this->travelBack();

        // Burn after last midnight stays out of the ledger until tomorrow.
        foreach (range(1, 3) as $i) {
            $metering->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        }

        $this->artisan('credits:rollup-usage')->assertSuccessful();

        $todayBurn = (int) CreditUsageDaily::where('organization_id', $organization->id)
            ->where('date', now('UTC')->toDateString())
            ->sum('credits');
        $balanceAsOfMidnight = $organization->fresh()->credit_balance + $todayBurn;

        $this->assertSame(995, $balanceAsOfMidnight);
        $this->assertSame(
            $balanceAsOfMidnight,
            (int) CreditTransaction::where('organization_id', $organization->id)->sum('amount')
        );
    }

    public function test_rollup_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'credits:rollup-usage')));
    }
}
