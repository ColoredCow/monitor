<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\UptimeMonitor\MonitorRepository;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class SoftDeleteFoundationsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_trashed_monitor_is_excluded_from_spatie_repository(): void
    {
        $organization = $this->createOrganization(['credit_balance' => 100]);
        $kept = Monitor::factory()->forOrganization($organization)->create();
        $trashed = Monitor::factory()->forOrganization($organization)->create();

        $trashed->delete();

        $enabled = MonitorRepository::getEnabled();
        $this->assertTrue($enabled->contains('id', $kept->id));
        $this->assertFalse($enabled->contains('id', $trashed->id));
        $this->assertNull(MonitorRepository::findByUrl((string) $trashed->getRawOriginal('url')));
        $this->assertSoftDeleted($trashed); // row survives — this is what distinguishes soft from hard delete
    }

    public function test_soft_deleting_a_monitor_keeps_its_check_logs(): void
    {
        $monitor = Monitor::factory()->forOrganization($this->createOrganization(['credit_balance' => 100]))->create();
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $monitor->delete();

        $this->assertSoftDeleted($monitor);
        $this->assertDatabaseCount('monitor_check_logs', 1);
    }

    public function test_trashed_user_cannot_log_in(): void
    {
        $organization = $this->createOrganization(['credit_balance' => 100]);
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        $user->delete();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $this->assertSoftDeleted($user); // account retained for the restore window
    }

    public function test_trashed_organization_disappears_from_switcher_and_resolution(): void
    {
        $orgA = $this->createOrganization(['name' => 'Alpha', 'credit_balance' => 100]);
        $orgB = $this->createOrganization(['name' => 'Beta', 'credit_balance' => 100]);
        $user = User::factory()->create();
        $orgA->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        $orgB->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $orgB->delete();

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $orgB->id])
            ->get('/monitors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('auth.organizations', 1)
                ->where('auth.activeOrganization.id', $orgA->id));

        $this->assertSoftDeleted($orgB); // row survives — soft, not hard, delete
    }
}
