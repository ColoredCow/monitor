<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationDeletionService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class PostReviewMediumFixesTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    /** #4 — a monitor must not be assignable to a soft-deleted group. */
    public function test_monitor_cannot_be_assigned_a_trashed_group(): void
    {
        $organization = $this->createOrganization();
        $group = Group::factory()->forOrganization($organization)->create();
        $group->delete();
        $this->actingAsAdmin($organization);

        $this->post('/monitors', [
            'name' => 'M',
            'url' => 'https://m.test',
            'monitorUptime' => true,
            'monitorDomain' => false,
            'uptimeCheckInterval' => 5,
            'monitorGroupId' => $group->id,
        ])->assertSessionHasErrors('monitorGroupId');

        $this->assertDatabaseMissing('monitors', ['url' => 'https://m.test']);
    }

    /** #5 — the flashed status is shared to the Organizations page. */
    public function test_organizations_index_exposes_flashed_status(): void
    {
        $this->actingAsSuperAdmin();

        $this->withSession(['status' => 'Restored something.'])
            ->get(route('organizations.index'))
            ->assertInertia(fn ($page) => $page->where('status', 'Restored something.'));
    }

    /** #8 — the seeded default user is a super-admin so a fresh install is usable. */
    public function test_seeder_makes_default_user_a_super_admin(): void
    {
        $this->seed(UserSeeder::class);

        $user = User::where('email', config('constants.default.user.email'))->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
    }

    /** #9 — restoring an org resurrects a member cascaded by a DIFFERENT org's deletion. */
    public function test_restore_resurrects_a_member_trashed_by_another_orgs_cascade(): void
    {
        $orgA = $this->createOrganization(['name' => 'Alpha']);
        $orgB = $this->createOrganization(['name' => 'Beta']);
        $user = User::factory()->create();
        $orgA->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        $orgB->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $service = app(OrganizationDeletionService::class);

        $service->delete($orgB);            // A still live -> user survives
        $this->assertNotSoftDeleted($user->fresh());

        $service->delete($orgA);            // user's last live org gone -> trashed with A's marker
        $this->assertSoftDeleted($user->fresh());

        $service->restore($orgB->fresh());  // B live again -> member must be restored

        $this->assertNotSoftDeleted($user->fresh());
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticated();
    }
}
