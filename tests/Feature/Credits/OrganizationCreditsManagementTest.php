<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationCreditsManagementTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_super_admin_can_view_the_credits_panel(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();

        $this->get("/organizations/{$organization->id}/credits")
            ->assertInertia(fn ($page) => $page
                ->component('Organizations/Credits')
                ->where('organization.id', $organization->id)
                ->where('organization.credit_balance', 0)
                ->has('transactions'));
    }

    public function test_super_admin_can_grant_credits(): void
    {
        $organization = $this->createOrganization();
        $superAdmin = $this->actingAsSuperAdmin();

        $this->post("/organizations/{$organization->id}/credits", [
            'amount' => 500000,
            'description' => 'Annual top-up',
        ])->assertRedirect("/organizations/{$organization->id}/credits");

        $this->assertSame(500000, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 500000,
            'created_by' => $superAdmin->id,
        ]);
    }

    public function test_negative_amount_records_an_adjustment(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();
        $this->post("/organizations/{$organization->id}/credits", ['amount' => 1000]);

        $this->post("/organizations/{$organization->id}/credits", [
            'amount' => -200,
            'description' => 'Billing correction',
        ]);

        $this->assertSame(800, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'type' => CreditTransaction::TYPE_ADJUSTMENT,
            'amount' => -200,
        ]);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();

        $this->post("/organizations/{$organization->id}/credits", ['amount' => 0])
            ->assertSessionHasErrors('amount');
    }

    public function test_org_admins_cannot_view_or_grant(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->get("/organizations/{$organization->id}/credits")->assertForbidden();
        $this->post("/organizations/{$organization->id}/credits", ['amount' => 1000])->assertForbidden();
        $this->assertSame(0, $organization->fresh()->credit_balance);
    }
}
