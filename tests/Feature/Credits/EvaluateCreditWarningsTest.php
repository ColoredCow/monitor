<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CreditBalanceCritical;
use App\Notifications\CreditBalanceLow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
