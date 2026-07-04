<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class DefaultGrantTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function onboardOrganization(): Organization
    {
        $this->post(route('organizations.store'), [
            'name' => 'Fresh Org',
            'admin_name' => 'Ada Admin',
            'admin_email' => 'ada@fresh.test',
            'admin_password' => 'secret-password',
        ])->assertRedirect(route('organizations.index'));

        return Organization::where('name', 'Fresh Org')->firstOrFail();
    }

    public function test_new_org_receives_the_default_grant(): void
    {
        config(['credits.default_grant' => 500000]);
        $superAdmin = $this->actingAsSuperAdmin();

        $organization = $this->onboardOrganization();

        $this->assertSame(500000, $organization->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 500000,
            'description' => 'Initial grant',
            'created_by' => $superAdmin->id,
        ]);
    }

    public function test_zero_default_grant_is_disabled(): void
    {
        config(['credits.default_grant' => 0]);
        $this->actingAsSuperAdmin();

        $organization = $this->onboardOrganization();

        $this->assertSame(0, $organization->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }
}
