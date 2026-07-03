<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_id_is_required_on_monitors(): void
    {
        $this->expectException(QueryException::class);

        // No organization bound and none provided -> NOT NULL violation.
        Monitor::query()->create(['url' => 'https://needs-org.test', 'name' => 'NoOrg']);
    }

    public function test_monitor_with_organization_saves(): void
    {
        $monitor = Monitor::factory()->forOrganization(Organization::factory()->create())->create();

        $this->assertNotNull($monitor->organization_id);
    }

    public function test_backfill_creates_default_org_and_super_admin_without_the_orm(): void
    {
        // The backfill runs on real data via the query builder only (no Eloquent
        // models, whose SoftDeletingScope would reference deleted_at before that
        // column exists). Run its up() against a data-present DB and assert
        // behaviour + idempotency.
        $defaultEmail = config('constants.default.user.email');
        $admin = User::factory()->create(['email' => $defaultEmail]);
        $member = User::factory()->create();

        $migration = require database_path('migrations/2026_06_25_000200_backfill_default_organization.php');
        $migration->up();
        $migration->up(); // idempotent: no duplicate org, no duplicate memberships

        $organization = Organization::where('slug', 'coloredcow')->firstOrFail();
        $this->assertSame(1, Organization::where('slug', 'coloredcow')->count());
        $this->assertTrue($admin->fresh()->isSuperAdmin());
        $this->assertTrue($admin->fresh()->isAdminOf($organization));
        $this->assertTrue($member->fresh()->isAdminOf($organization));
        $this->assertSame(2, $organization->users()->count());
    }
}
