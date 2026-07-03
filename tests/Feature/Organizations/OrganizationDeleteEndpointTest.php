<?php

namespace Tests\Feature\Organizations;

use App\Services\OrganizationDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationDeleteEndpointTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_super_admin_can_soft_delete_an_organization(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();

        $this->delete(route('organizations.destroy', $organization))
            ->assertRedirect(route('organizations.index'));

        $this->assertSoftDeleted($organization);
    }

    public function test_org_admin_cannot_delete_an_organization(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->delete(route('organizations.destroy', $organization))->assertForbidden();

        $this->assertNotSoftDeleted($organization);
    }

    public function test_index_lists_deleted_organizations_with_purge_countdown(): void
    {
        $live = $this->createOrganization(['name' => 'Live Org']);
        $trashed = $this->createOrganization(['name' => 'Gone Org']);
        app(OrganizationDeletionService::class)->delete($trashed);
        $this->actingAsSuperAdmin();

        $this->get(route('organizations.index'))->assertInertia(fn ($page) => $page
            ->has('organizations', 1)
            ->has('deletedOrganizations', 1)
            ->where('deletedOrganizations.0.name', 'Gone Org')
            ->where('deletedOrganizations.0.days_until_purge', 60)
            ->where('purgeAfterDays', 60));
    }

    public function test_super_admin_can_restore_a_deleted_organization(): void
    {
        $organization = $this->createOrganization();
        app(OrganizationDeletionService::class)->delete($organization);
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.restore', $organization->id))
            ->assertRedirect(route('organizations.index'));

        $this->assertNotSoftDeleted($organization);
    }

    public function test_restore_blocked_by_slug_conflict_flashes_an_error(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'slug' => 'acme']);
        app(OrganizationDeletionService::class)->delete($organization);
        $this->createOrganization(['name' => 'Acme Two', 'slug' => 'acme']);
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.restore', $organization->id))
            ->assertRedirect(route('organizations.index'))
            ->assertSessionHasErrors('restore');

        $this->assertSoftDeleted($organization);
    }
}
