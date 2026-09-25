<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use App\Services\DomainService;
use Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\SslCertificate\SslCertificate;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditMeteringCallSitesTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function orgWithBalance(int $balance): Organization
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);

        return $organization->fresh();
    }

    public function test_failed_uptime_check_is_charged(): void
    {
        Notification::fake(); // vendor hooks fire uptime events; keep them quiet
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $monitor->uptimeRequestFailed('connection timed out');

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'uptime',
            'credits' => 1,
        ]);
    }

    public function test_successful_uptime_check_is_charged(): void
    {
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $monitor->uptimeRequestSucceeded(new Response(200, [], 'ok'));

        $this->assertSame(9, $organization->fresh()->credit_balance);
    }

    public function test_uptime_check_on_monitor_with_uptime_disabled_is_still_charged_when_hook_fires(): void
    {
        // The hook only fires when Spatie actually executed a request; the
        // existing early-return guards only skip HISTORY logging, not billing.
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['uptime_check_enabled' => false]);

        $monitor->uptimeRequestFailed('connection timed out');

        $this->assertSame(9, $organization->fresh()->credit_balance);
    }

    public function test_certificate_failure_path_is_charged(): void
    {
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['certificate_check_enabled' => true]);

        $monitor->setCertificateException(new Exception('could not download certificate'));

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'certificate',
            'credits' => 1,
        ]);
    }

    public function test_certificate_success_path_is_charged(): void
    {
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['certificate_check_enabled' => true]);

        $monitor->setCertificate(new SslCertificate([
            'subject' => ['CN' => 'example.com'],
            'issuer' => ['CN' => 'Test CA'],
            'validFrom_time_t' => now()->subDay()->timestamp,
            'validTo_time_t' => now()->addYear()->timestamp,
        ]));

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'certificate',
            'credits' => 1,
        ]);
    }

    public function test_domain_expiration_command_charges_each_checked_monitor(): void
    {
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['domain_check_enabled' => true]);

        $this->mock(DomainService::class)
            ->shouldReceive('verifyDomainExpiration')
            ->once()
            ->andReturn(['notified' => false]);

        $this->artisan('monitor:check-domain-expiration')->assertSuccessful();

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'domain',
            'credits' => 1,
        ]);
    }
}
