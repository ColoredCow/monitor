<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationOnboardingTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_super_admin_can_onboard_org_with_first_admin(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.store'), [
            'name' => 'Beta Corp',
            'admin_name' => 'Ada',
            'admin_email' => 'ada@beta.test',
            'admin_password' => 'secret123',
        ])->assertRedirect(route('organizations.index'));

        $organization = Organization::where('name', 'Beta Corp')->firstOrFail();
        $admin = User::where('email', 'ada@beta.test')->firstOrFail();

        $this->assertTrue($admin->isAdminOf($organization));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_non_super_admin_cannot_onboard(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->post(route('organizations.store'), [
            'name' => 'Nope Inc',
            'admin_name' => 'X',
            'admin_email' => 'x@nope.test',
            'admin_password' => 'secret123',
        ])->assertForbidden();
    }

    public function test_existing_email_is_linked_not_duplicated(): void
    {
        $this->actingAsSuperAdmin();
        $existing = User::factory()->create(['email' => 'shared@x.test']);

        $this->post(route('organizations.store'), [
            'name' => 'Gamma',
            'admin_name' => 'Shared',
            'admin_email' => 'shared@x.test',
            'admin_password' => 'secret123',
        ])->assertRedirect();

        $this->assertSame(1, User::where('email', 'shared@x.test')->count());
        $this->assertTrue($existing->fresh()->isAdminOf(Organization::where('name', 'Gamma')->first()));
    }
}
