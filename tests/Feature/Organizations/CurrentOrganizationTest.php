<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_null_and_can_be_set(): void
    {
        $current = app(CurrentOrganization::class);
        $this->assertNull($current->get());
        $this->assertNull($current->id());

        $organization = Organization::factory()->create();
        $current->set($organization);

        $this->assertTrue($organization->is(app(CurrentOrganization::class)->get()));
        $this->assertSame($organization->id, app(CurrentOrganization::class)->id());
    }

    public function test_resolve_for_honors_membership_and_falls_back(): void
    {
        $current = app(CurrentOrganization::class);
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $orgA->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        // Valid session org the user belongs to.
        $this->assertSame($orgA->id, $current->resolveFor($user, $orgA->id)?->id);
        // Stale/foreign session org -> falls back to a real membership.
        $this->assertSame($orgA->id, $current->resolveFor($user, $orgB->id)?->id);
        // No session org -> first membership.
        $this->assertSame($orgA->id, $current->resolveFor($user, null)?->id);
    }
}
