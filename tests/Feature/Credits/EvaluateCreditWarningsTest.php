<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CreditBalanceCritical;
use App\Notifications\CreditBalanceLow;
use App\Notifications\MonitoringPaused;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class EvaluateCreditWarningsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    /** @return array{0: Organization, 1: User} */
    private function orgBurning288PerDay(int $balance): array
    {
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5, // 288 credits/day
            'domain_check_enabled' => false,
        ]);
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);

        return [$organization->fresh(), $admin];
    }

    public function test_low_runway_escalates_and_emails_once(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(288 * 5); // 5 days < low threshold (7)

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_LOW, $organization->fresh()->credit_warning_level);
        Notification::assertSentToTimes($admin, CreditBalanceLow::class, 1);

        // Re-running at the same level must not re-email.
        $this->artisan('credits:evaluate-warnings')->assertSuccessful();
        Notification::assertSentToTimes($admin, CreditBalanceLow::class, 1);
    }

    public function test_critical_runway_escalates_past_low(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(288); // 1 day < critical threshold (2)

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_CRITICAL, $organization->fresh()->credit_warning_level);
        Notification::assertSentToTimes($admin, CreditBalanceCritical::class, 1);
        Notification::assertNotSentTo($admin, CreditBalanceLow::class);
    }

    public function test_healthy_runway_clears_stale_warning_silently(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(288 * 100); // 100 days
        DB::table('organizations')->where('id', $organization->id)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_LOW]);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $organization->fresh()->credit_warning_level);
        Notification::assertNothingSent();
    }

    public function test_stale_none_level_on_a_zero_balance_org_is_repaired_once(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(0);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $organization->fresh()->credit_warning_level);
        Notification::assertSentToTimes($admin, MonitoringPaused::class, 1);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();
        Notification::assertSentToTimes($admin, MonitoringPaused::class, 1);
    }

    public function test_failed_send_keeps_level_unchanged_and_continues_to_next_org(): void
    {
        Log::spy();
        [$first] = $this->orgBurning288PerDay(288 * 5);
        [$second] = $this->orgBurning288PerDay(288 * 5);

        Notification::shouldReceive('send')->twice()
            ->andReturnUsing(function () {
                static $calls = 0;
                if (++$calls === 1) {
                    throw new RuntimeException('mail down');
                }
            });

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $first->fresh()->credit_warning_level);
        $this->assertSame(Organization::CREDIT_LEVEL_LOW, $second->fresh()->credit_warning_level);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_critical_level_steps_down_to_low_silently_when_runway_improves(): void
    {
        Notification::fake();
        [$organization] = $this->orgBurning288PerDay(288 * 5); // 5 days: low band, not critical
        DB::table('organizations')->where('id', $organization->id)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_CRITICAL]);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_LOW, $organization->fresh()->credit_warning_level);
        Notification::assertNothingSent();
    }

    public function test_exhausted_level_is_never_stepped_down_by_the_warning_job(): void
    {
        Notification::fake();
        // Positive balance with a stale exhausted flag: only a grant may
        // clear exhaustion, so the step-down must leave it alone.
        [$organization] = $this->orgBurning288PerDay(288 * 5);
        DB::table('organizations')->where('id', $organization->id)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_EXHAUSTED]);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $organization->fresh()->credit_warning_level);
        Notification::assertNothingSent();
    }

    public function test_unexpected_stored_level_does_not_kill_the_run(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(288 * 5);
        DB::table('organizations')->where('id', $organization->id)
            ->update(['credit_warning_level' => 'bogus']);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_LOW, $organization->fresh()->credit_warning_level);
        Notification::assertSentToTimes($admin, CreditBalanceLow::class, 1);
    }

    public function test_org_with_no_monitors_clears_stale_low_level_silently(): void
    {
        Notification::fake();
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)
            ->update(['credit_balance' => 100, 'credit_warning_level' => Organization::CREDIT_LEVEL_LOW]);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $organization->fresh()->credit_warning_level);
        Notification::assertNothingSent();
    }

    public function test_exhausted_and_zero_burn_orgs_are_skipped(): void
    {
        Notification::fake();

        // Exhausted org: pause/resume flow owns it, not the warning job.
        [$exhausted] = $this->orgBurning288PerDay(0);
        DB::table('organizations')->where('id', $exhausted->id)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_EXHAUSTED]);

        // Zero-burn org with a tiny balance: no runway concept, no warning.
        $idle = $this->createOrganization();
        DB::table('organizations')->where('id', $idle->id)->update(['credit_balance' => 3]);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $exhausted->fresh()->credit_warning_level);
        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $idle->fresh()->credit_warning_level);
        Notification::assertNothingSent();
    }

    public function test_warnings_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'credits:evaluate-warnings')));
    }
}
