<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\UptimeMonitor\MonitorRepository;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditEnforcementTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function orgWithBalance(int $balance): Organization
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);

        return $organization->fresh();
    }

    public function test_uptime_selection_excludes_zero_balance_orgs(): void
    {
        $funded = Monitor::factory()->forOrganization($this->orgWithBalance(100))->create();
        Monitor::factory()->forOrganization($this->orgWithBalance(0))->create();

        $selected = MonitorRepository::getForUptimeCheck();

        $this->assertSame([$funded->id], $selected->pluck('id')->all());
    }

    public function test_certificate_selection_excludes_zero_balance_orgs(): void
    {
        $funded = Monitor::factory()->forOrganization($this->orgWithBalance(100))
            ->create(['certificate_check_enabled' => true]);
        Monitor::factory()->forOrganization($this->orgWithBalance(0))
            ->create(['certificate_check_enabled' => true]);

        $selected = MonitorRepository::getForCertificateCheck();

        $this->assertSame([$funded->id], $selected->pluck('id')->all());
    }

    public function test_domain_selection_excludes_zero_balance_orgs(): void
    {
        $funded = Monitor::factory()->forOrganization($this->orgWithBalance(100))
            ->create(['domain_check_enabled' => true]);
        Monitor::factory()->forOrganization($this->orgWithBalance(0))
            ->create(['domain_check_enabled' => true]);

        $selected = Monitor::domainCheckEnabled();

        $this->assertSame([$funded->id], $selected->pluck('id')->all());
    }

    public function test_check_uptime_command_checks_zero_monitors_when_all_orgs_are_exhausted(): void
    {
        Monitor::factory()->forOrganization($this->orgWithBalance(0))->create();

        // Safe to run for real: with every org at zero, no HTTP request is made.
        $this->artisan('monitor:check-uptime')
            ->expectsOutputToContain('Start checking the uptime of 0 monitors')
            ->assertSuccessful();
    }

    public function test_soft_deleted_org_monitors_are_also_excluded(): void
    {
        $organization = $this->orgWithBalance(100);
        Monitor::factory()->forOrganization($organization)->create();
        $organization->delete();

        $this->assertCount(0, MonitorRepository::getForUptimeCheck());
    }
}
