<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Services\CreditLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class GrantCreditsCommandTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_grants_to_one_organization_by_id(): void
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => 100]);

        $this->artisan('credits:grant', [
            'amount' => 500,
            '--org' => $organization->id,
            '--description' => 'Rollout grant',
        ])->assertSuccessful();

        $this->assertSame(600, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 500,
            'description' => 'Rollout grant',
        ]);
    }

    public function test_all_grants_to_every_organization(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        DB::table('organizations')->whereIn('id', [$orgA->id, $orgB->id])->update(['credit_balance' => 0]);

        $this->artisan('credits:grant', ['amount' => 250, '--all' => true])->assertSuccessful();

        $this->assertSame(250, $orgA->fresh()->credit_balance);
        $this->assertSame(250, $orgB->fresh()->credit_balance);
        $this->assertSame(2, CreditTransaction::where('type', CreditTransaction::TYPE_GRANT)->where('amount', 250)->count());
    }

    public function test_all_includes_soft_deleted_organizations(): void
    {
        $live = $this->createOrganization();
        $trashed = $this->createOrganization();
        $trashed->delete();

        $this->artisan('credits:grant', ['amount' => 100, '--all' => true])->assertSuccessful();

        $this->assertSame(100, $live->fresh()->credit_balance);
        $this->assertSame(100, $trashed->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $trashed->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 100,
        ]);
    }

    public function test_all_skips_orgs_that_already_hold_a_grant_with_the_same_description(): void
    {
        $funded = $this->createOrganization();
        $unfunded = $this->createOrganization();
        // Half the fleet got the grant before the command died (or was
        // funded by hand from the dashboard with the same description).
        app(CreditLedgerService::class)->grant($funded, 250, null, 'Launch funding');

        $this->artisan('credits:grant', ['amount' => 250, '--all' => true, '--description' => 'Launch funding'])
            ->expectsOutputToContain("Skipped organization #{$funded->id}")
            ->expectsOutputToContain('skipped 1 already granted')
            ->assertSuccessful();

        $this->assertSame(250, $funded->fresh()->credit_balance);
        $this->assertSame(250, $unfunded->fresh()->credit_balance);
        $this->assertSame(1, CreditTransaction::where('organization_id', $funded->id)->where('type', CreditTransaction::TYPE_GRANT)->count());
    }

    public function test_all_rerun_with_the_same_description_grants_nothing_more(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();

        $this->artisan('credits:grant', ['amount' => 100, '--all' => true, '--description' => 'Rollout grant'])->assertSuccessful();
        $this->artisan('credits:grant', ['amount' => 100, '--all' => true, '--description' => 'Rollout grant'])
            ->expectsOutputToContain('skipped 2 already granted')
            ->assertSuccessful();

        $this->assertSame(100, $orgA->fresh()->credit_balance);
        $this->assertSame(100, $orgB->fresh()->credit_balance);
        $this->assertSame(2, CreditTransaction::where('type', CreditTransaction::TYPE_GRANT)->count());
    }

    public function test_all_with_a_different_description_grants_again(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('credits:grant', ['amount' => 100, '--all' => true, '--description' => 'January top-up'])->assertSuccessful();
        $this->artisan('credits:grant', ['amount' => 100, '--all' => true, '--description' => 'February top-up'])->assertSuccessful();

        $this->assertSame(200, $organization->fresh()->credit_balance);
    }

    public function test_org_option_finds_a_soft_deleted_organization(): void
    {
        $trashed = $this->createOrganization();
        $trashed->delete();

        $this->artisan('credits:grant', ['amount' => 100, '--org' => $trashed->id])
            ->assertSuccessful();

        $this->assertSame(100, $trashed->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $trashed->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 100,
        ]);
    }

    public function test_rejects_zero_amount(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('credits:grant', ['amount' => 0, '--org' => $organization->id])
            ->assertFailed();

        $this->assertSame(0, $organization->fresh()->credit_balance);
    }

    public function test_rejects_negative_amount(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('credits:grant', ['amount' => -10, '--org' => $organization->id])
            ->assertFailed();

        $this->assertSame(0, $organization->fresh()->credit_balance);
    }

    public function test_rejects_when_neither_target_given(): void
    {
        $this->artisan('credits:grant', ['amount' => 100])->assertFailed();

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_rejects_when_both_targets_given(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('credits:grant', [
            'amount' => 100,
            '--org' => $organization->id,
            '--all' => true,
        ])->assertFailed();

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_rejects_unknown_org_id(): void
    {
        $this->artisan('credits:grant', ['amount' => 100, '--org' => 999999])
            ->assertFailed();

        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_prints_final_count(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();

        $this->artisan('credits:grant', ['amount' => 10, '--all' => true])
            ->expectsOutputToContain('2 organization')
            ->assertSuccessful();
    }
}
