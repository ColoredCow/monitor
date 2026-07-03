<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class LiveOnlyUniqueIndexTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function monitorPayload(string $url): array
    {
        return [
            'name' => 'Example',
            'url' => $url,
            'monitorUptime' => true,
            'monitorDomain' => false,
            'uptimeCheckInterval' => 5,
            'monitorGroupId' => null,
        ];
    }

    public function test_url_of_a_trashed_monitor_can_be_reused(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $old = Monitor::factory()->forOrganization($orgA)->create(['url' => 'https://reuse-me.test']);
        $old->delete();

        $this->actingAsAdmin($orgB);
        $this->post('/monitors', $this->monitorPayload('https://reuse-me.test'))
            ->assertRedirect(route('monitors.index'));

        $this->assertSame(2, Monitor::withTrashed()->where('url', 'https://reuse-me.test')->count());
        $this->assertSame(1, Monitor::where('url', 'https://reuse-me.test')->count());
    }

    public function test_duplicate_live_url_is_a_validation_error(): void
    {
        $organization = $this->createOrganization();
        Monitor::factory()->forOrganization($organization)->create(['url' => 'https://taken.test']);

        $this->actingAsAdmin($organization);
        $this->post('/monitors', $this->monitorPayload('https://taken.test'))
            ->assertSessionHasErrors('url');

        $this->assertSame(1, Monitor::withTrashed()->where('url', 'https://taken.test')->count());
    }

    public function test_slug_of_a_trashed_organization_can_be_reused(): void
    {
        $old = $this->createOrganization(['name' => 'Acme', 'slug' => 'acme']);
        $old->delete();
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.store'), [
            'name' => 'Acme',
            'admin_name' => 'Ada',
            'admin_email' => 'ada@acme.test',
            'admin_password' => 'secret123',
        ])->assertRedirect(route('organizations.index'));

        $this->assertSame(1, Organization::where('slug', 'acme')->count());
        $this->assertSame(2, Organization::withTrashed()->where('slug', 'acme')->count());
    }
}
