<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use App\Services\OrganizationDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class PurgeDeletedOrganizationsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_purge_respects_the_retention_window(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        $group = Group::factory()->forOrganization($organization)->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        app(OrganizationDeletionService::class)->delete($organization);

        $this->travel(59)->days();
        $this->artisan('organizations:purge-deleted')->assertSuccessful();
        $this->assertSoftDeleted($organization); // still inside the window

        $this->travel(2)->days(); // day 61
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($organization);
        $this->assertModelMissing($monitor);
        $this->assertModelMissing($group);
        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id]);
        $this->assertDatabaseCount('monitor_check_logs', 0);
    }

    public function test_purge_keeps_multi_org_users(): void
    {
        $organization = $this->createOrganization();
        $otherOrg = $this->createOrganization();
        $multiUser = User::factory()->create();
        $organization->users()->attach($multiUser->id, ['role' => Organization::ROLE_MEMBER]);
        $otherOrg->users()->attach($multiUser->id, ['role' => Organization::ROLE_MEMBER]);

        app(OrganizationDeletionService::class)->delete($organization);
        $this->travel(61)->days();
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($organization);
        $this->assertNotSoftDeleted($multiUser); // never cascaded, never purged
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $otherOrg->id, 'user_id' => $multiUser->id,
        ]);
    }

    public function test_dry_run_purges_nothing(): void
    {
        $organization = $this->createOrganization();
        app(OrganizationDeletionService::class)->delete($organization);
        $this->travel(61)->days();

        $this->artisan('organizations:purge-deleted', ['--dry-run' => true])->assertSuccessful();

        $this->assertSoftDeleted($organization);
    }

    public function test_older_than_days_option_overrides_config(): void
    {
        $organization = $this->createOrganization();
        app(OrganizationDeletionService::class)->delete($organization);
        $this->travel(3)->days();

        $this->artisan('organizations:purge-deleted', ['--older-than-days' => 2])->assertSuccessful();

        $this->assertModelMissing($organization);
    }

    public function test_purging_one_org_never_claims_users_from_another_orgs_cascade(): void
    {
        // U is a member of A and B. B is deleted first (U kept — A is live).
        // A is deleted a month later, cascading U with A's marker. When B's
        // window lapses, purging B must NOT destroy U — U belongs to A's
        // still-restorable cascade.
        $orgA = $this->createOrganization(['name' => 'Alpha']);
        $orgB = $this->createOrganization(['name' => 'Beta']);
        $user = User::factory()->create();
        $orgA->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        $orgB->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        app(OrganizationDeletionService::class)->delete($orgB);
        $this->assertNotSoftDeleted($user); // A is still live

        $this->travel(30)->days();
        app(OrganizationDeletionService::class)->delete($orgA); // cascades U with A's marker

        $this->travel(31)->days(); // B is 61 days old; A only 31
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($orgB);
        $this->assertSoftDeleted($user); // survived B's purge

        app(OrganizationDeletionService::class)->restore($orgA->fresh());
        $this->assertNotSoftDeleted($user->fresh()); // A's restore resurrects U
    }

    public function test_orphaned_trashed_children_of_live_orgs_are_purged_after_the_window(): void
    {
        $organization = $this->createOrganization();
        $oldMonitor = Monitor::factory()->forOrganization($organization)->create();
        $emptyGroup = Group::factory()->forOrganization($organization)->create();
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $oldMonitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $oldMonitor->delete();   // individually deleted — org stays live
        $emptyGroup->delete();

        $this->travel(30)->days();
        $recentMonitor = Monitor::factory()->forOrganization($organization)->create();
        $recentMonitor->delete();

        $this->travel(31)->days(); // old orphans are 61 days; recent one 31
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($oldMonitor);
        $this->assertModelMissing($emptyGroup);
        $this->assertDatabaseCount('monitor_check_logs', 0);
        $this->assertSoftDeleted($recentMonitor); // still inside its window
        $this->assertNotSoftDeleted($organization); // live org untouched
    }
}
