<?php

namespace Tests\Feature\Organizations;

use App\Exceptions\OrganizationRestoreBlockedException;
use App\Models\Group;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use App\Services\OrganizationDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationDeletionServiceTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function service(): OrganizationDeletionService
    {
        return app(OrganizationDeletionService::class);
    }

    public function test_delete_cascades_to_monitors_groups_and_sole_org_users(): void
    {
        $organization = $this->createOrganization();
        $otherOrg = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        $group = Group::factory()->forOrganization($organization)->create();

        $soleUser = User::factory()->create();
        $organization->users()->attach($soleUser->id, ['role' => Organization::ROLE_MEMBER]);

        $multiUser = User::factory()->create();
        $organization->users()->attach($multiUser->id, ['role' => Organization::ROLE_ADMIN]);
        $otherOrg->users()->attach($multiUser->id, ['role' => Organization::ROLE_MEMBER]);

        $superAdmin = User::factory()->superAdmin()->create();
        $organization->users()->attach($superAdmin->id, ['role' => Organization::ROLE_MEMBER]);

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $this->service()->delete($organization);

        $this->assertSoftDeleted($organization);
        $this->assertSoftDeleted($monitor);
        $this->assertSoftDeleted($group);
        $this->assertSoftDeleted($soleUser);
        $this->assertNotSoftDeleted($multiUser);
        $this->assertNotSoftDeleted($superAdmin);
        // Pivot rows and check logs survive soft delete.
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id, 'user_id' => $soleUser->id,
        ]);
        $this->assertDatabaseCount('monitor_check_logs', 1);
    }

    public function test_restore_resurrects_only_what_the_deletion_took(): void
    {
        $organization = $this->createOrganization();
        $earlier = Monitor::factory()->forOrganization($organization)->create(['name' => 'Earlier']);
        $with = Monitor::factory()->forOrganization($organization)->create(['name' => 'With']);
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $earlier->delete();               // individually deleted BEFORE the org deletion
        $this->travel(1)->minutes();      // distinct deleted_at (second precision)
        $this->service()->delete($organization);
        $this->service()->restore($organization->fresh());

        $this->assertNotSoftDeleted($organization);
        $this->assertNotSoftDeleted($with);
        $this->assertNotSoftDeleted($user->fresh());
        $this->assertSoftDeleted($earlier); // stays trashed

        // Restored user can log in again.
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticated();
    }

    public function test_restore_is_blocked_when_a_live_org_holds_the_slug(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'slug' => 'acme']);
        $this->service()->delete($organization);
        $this->createOrganization(['name' => 'Acme Two', 'slug' => 'acme']); // allowed by live-only index

        $this->expectException(OrganizationRestoreBlockedException::class);
        $this->service()->restore($organization->fresh());
    }

    public function test_restore_skips_monitors_whose_url_is_now_live_elsewhere(): void
    {
        $organization = $this->createOrganization();
        $conflicted = Monitor::factory()->forOrganization($organization)
            ->create(['name' => 'Conflicted', 'url' => 'https://contested.test']);
        $clean = Monitor::factory()->forOrganization($organization)->create(['name' => 'Clean']);

        $this->service()->delete($organization);

        Monitor::factory()->forOrganization($this->createOrganization())
            ->create(['url' => 'https://contested.test']); // someone re-used the URL

        $result = $this->service()->restore($organization->fresh());

        $this->assertSame(['Conflicted'], $result['skipped_monitors']);
        $this->assertSoftDeleted($conflicted);
        $this->assertNotSoftDeleted($clean);
    }

    public function test_delete_is_a_no_op_on_an_already_trashed_organization(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $this->service()->delete($organization);
        $marker = $organization->fresh()->deleted_at;

        $this->travel(1)->minutes();
        $this->service()->delete($organization->fresh()); // must NOT re-stamp

        $this->assertEquals($marker, $organization->fresh()->deleted_at);
        $this->assertEquals($marker, $monitor->fresh()->deleted_at);
    }

    public function test_restore_is_a_no_op_on_a_live_organization(): void
    {
        $organization = $this->createOrganization();
        $this->service()->delete($organization);
        $this->service()->restore($organization->fresh());

        // Second restore (double-submit): no exception, nothing skipped.
        $result = $this->service()->restore($organization->fresh());

        $this->assertSame(['skipped_monitors' => []], $result);
        $this->assertNotSoftDeleted($organization);
    }
}
