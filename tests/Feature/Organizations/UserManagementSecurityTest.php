<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class UserManagementSecurityTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_admin_cannot_add_a_super_admin_to_their_org(): void
    {
        $organization = $this->createOrganization();
        $superAdmin = User::factory()->superAdmin()->create(['email' => 'root@platform.test']);
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'Whatever',
            'email' => 'root@platform.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_ADMIN,
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_admin_cannot_edit_or_update_a_super_admin(): void
    {
        $organization = $this->createOrganization();
        $superAdmin = User::factory()->superAdmin()->create();
        // Even if a super-admin somehow shares the org, they are not manageable.
        $organization->users()->attach($superAdmin->id, ['role' => Organization::ROLE_MEMBER]);
        $this->actingAsAdmin($organization);

        $this->get(route('users.edit', $superAdmin))->assertNotFound();
        $this->put(route('users.update', $superAdmin), [
            'name' => 'Hacked',
            'email' => $superAdmin->email,
            'password' => 'hacked-pass',
            'role' => Organization::ROLE_MEMBER,
        ])->assertNotFound();

        $this->assertTrue($superAdmin->fresh()->isSuperAdmin());
    }

    public function test_admin_cannot_reset_credentials_of_a_user_shared_with_another_org(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $victim = User::factory()->create([
            'email' => 'victim@a.test',
            'password' => Hash::make('original-password'),
        ]);
        $orgA->users()->attach($victim->id, ['role' => Organization::ROLE_ADMIN]);

        // Attacker is an admin of org B; they link the org-A user into their org,
        $this->actingAsAdmin($orgB);
        $this->post(route('users.store'), [
            'name' => 'x',
            'email' => 'victim@a.test',
            'password' => 'irrelevant',
            'role' => Organization::ROLE_MEMBER,
        ])->assertRedirect(route('users.index'));

        // ...then try to overwrite the victim's global credentials.
        $this->put(route('users.update', $victim), [
            'name' => 'Pwned',
            'email' => 'attacker@evil.test',
            'password' => 'attacker-password',
            'role' => Organization::ROLE_MEMBER,
        ])->assertRedirect(route('users.index'));

        $victim = $victim->fresh();
        $this->assertSame('victim@a.test', $victim->email);        // email unchanged
        $this->assertNotSame('Pwned', $victim->name);              // name unchanged
        $this->assertTrue(Hash::check('original-password', $victim->password)); // password unchanged
        $this->assertFalse(Hash::check('attacker-password', $victim->password));
    }

    public function test_admin_can_still_edit_a_user_owned_solely_by_this_org(): void
    {
        $organization = $this->createOrganization();
        $member = User::factory()->create(['name' => 'Old Name']);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);
        $this->actingAsAdmin($organization);

        $this->put(route('users.update', $member), [
            'name' => 'New Name',
            'email' => $member->email,
            'password' => 'fresh-password',
            'role' => Organization::ROLE_MEMBER,
        ])->assertRedirect(route('users.index'));

        $member = $member->fresh();
        $this->assertSame('New Name', $member->name);
        $this->assertTrue(Hash::check('fresh-password', $member->password));
    }

    public function test_last_admin_cannot_demote_themselves(): void
    {
        $organization = $this->createOrganization();
        $admin = $this->actingAsAdmin($organization);

        $this->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => Organization::ROLE_MEMBER,
        ])->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->isAdminOf($organization));
    }

    public function test_last_admin_cannot_remove_themselves(): void
    {
        $organization = $this->createOrganization();
        $admin = $this->actingAsAdmin($organization);

        $this->delete(route('users.destroy', $admin))->assertSessionHasErrors('user');

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'role' => Organization::ROLE_ADMIN,
        ]);
    }

    public function test_adding_an_existing_member_does_not_silently_change_their_role(): void
    {
        $organization = $this->createOrganization();
        $member = User::factory()->create(['email' => 'member@org.test']);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'x',
            'email' => 'member@org.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_ADMIN,
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => Organization::ROLE_MEMBER,
        ]);
    }
}
