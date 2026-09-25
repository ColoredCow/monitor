<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditSchemaTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_organization_credit_defaults(): void
    {
        $organization = $this->createOrganization();

        $this->assertSame(0, $organization->fresh()->credit_balance);
        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $organization->fresh()->credit_warning_level);
        $this->assertFalse($organization->fresh()->hasCredits());
    }

    public function test_credit_balance_is_not_mass_assignable(): void
    {
        // Plain create(), NOT a factory — factories run unguarded and would
        // bypass the $fillable protection this test asserts.
        $organization = Organization::create([
            'name' => 'Guarded Org',
            'slug' => 'guarded-org',
            'credit_balance' => 999999,
        ]);

        $this->assertSame(0, $organization->fresh()->credit_balance);
    }

    public function test_transaction_and_usage_relationships(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $transaction = CreditTransaction::create([
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 100,
            'balance_after' => 100,
            'description' => 'Initial grant',
            'metadata' => ['source' => 'test'],
        ]);

        $usage = CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => '2026-07-01',
            'credits' => 5,
        ]);

        $this->assertTrue($organization->creditTransactions()->whereKey($transaction->id)->exists());
        $this->assertTrue($organization->creditUsage()->whereKey($usage->id)->exists());
        $this->assertSame(['source' => 'test'], $transaction->fresh()->metadata);
    }

    public function test_usage_survives_monitor_hard_delete_with_null_monitor_id(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => '2026-07-01',
            'credits' => 5,
        ]);

        $monitor->forceDelete();

        $this->assertDatabaseHas('credit_usage_daily', [
            'organization_id' => $organization->id,
            'monitor_id' => null,
            'credits' => 5,
        ]);
    }

    public function test_admins_relationship_only_returns_admins(): void
    {
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);

        $this->assertSame([$admin->id], $organization->admins()->pluck('users.id')->all());
    }
}
