<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class SharedCreditPropsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_active_org_pages_share_credit_props(): void
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => 2880]);
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5, // 288/day
            'domain_check_enabled' => false,
        ]);

        $this->actingAsMember($organization);

        $this->get('/monitors')->assertInertia(fn ($page) => $page
            ->where('auth.credits.balance', 2880)
            ->where('auth.credits.dailyBurn', 288)
            ->where('auth.credits.warningLevel', 'none'));
    }

    public function test_credits_prop_is_null_without_an_active_org(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/organizations')->assertInertia(fn ($page) => $page
            ->where('auth.credits', null));
    }
}
