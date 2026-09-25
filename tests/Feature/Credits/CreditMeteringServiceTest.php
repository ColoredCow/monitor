<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MonitoringPaused;
use App\Services\CreditMeteringService;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditMeteringServiceTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function setBalance(Organization $organization, int $balance): void
    {
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);
    }

    public function test_record_check_decrements_balance_and_upserts_daily_usage(): void
    {
        $organization = $this->createOrganization();
        $this->setBalance($organization, 10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        $this->assertSame(8, $organization->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_usage_daily', 1); // same day+type upserts in place
        $this->assertDatabaseHas('credit_usage_daily', [
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->toDateString(),
            'credits' => 2,
        ]);
    }

    public function test_each_check_type_gets_its_own_usage_row(): void
    {
        $organization = $this->createOrganization();
        $this->setBalance($organization, 10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_CERTIFICATE);
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_DOMAIN);

        $this->assertSame(7, $organization->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_usage_daily', 3);
    }

    public function test_zero_crossing_pauses_org_and_emails_admins_exactly_once(): void
    {
        Notification::fake();
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $this->setBalance($organization, 1);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        $fresh = $organization->fresh();
        $this->assertSame(0, $fresh->credit_balance);
        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $fresh->credit_warning_level);
        Notification::assertSentToTimes($admin, MonitoringPaused::class, 1);

        // An in-flight check after exhaustion still meters but must not re-notify.
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        $this->assertSame(-1, $organization->fresh()->credit_balance);
        Notification::assertSentToTimes($admin, MonitoringPaused::class, 1);
    }

    public function test_monitor_without_organization_is_a_noop(): void
    {
        $monitor = new Monitor;
        $monitor->id = 4242;
        $monitor->organization_id = null;

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        $this->assertDatabaseCount('credit_usage_daily', 0);
    }

    public function test_paused_notification_failure_is_isolated_and_does_not_read_as_metering_failure(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp down'));
        Log::spy();

        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $this->setBalance($organization, 1);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        $fresh = $organization->fresh();
        $this->assertSame(0, $fresh->credit_balance);
        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $fresh->credit_warning_level);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('MonitoringPaused notification failed to send.', \Mockery::on(function ($context) use ($organization) {
                return $context['organization_id'] === $organization->id
                    && $context['error'] === 'smtp down';
            }));
    }

    public function test_metering_failure_is_swallowed_and_logged(): void
    {
        Log::spy();

        // Unsaved monitor pointing at a nonexistent org: the usage upsert
        // violates the FK constraint and throws inside the service.
        $monitor = new Monitor;
        $monitor->id = 4242;
        $monitor->organization_id = 999999;

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        Log::shouldHaveReceived('error')->once();
        $this->assertDatabaseCount('credit_usage_daily', 0);
    }
}
