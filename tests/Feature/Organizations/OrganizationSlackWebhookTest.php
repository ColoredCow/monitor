<?php

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationSlackWebhookTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/secret';

    public function test_an_admin_can_set_a_slack_webhook_and_it_is_encrypted_at_rest(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $this->actingAsAdmin($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme',
            'slack_webhook_url' => self::SLACK_URL,
        ])->assertRedirect(route('organizations.index'));

        $this->assertSame(self::SLACK_URL, $organization->fresh()->slack_webhook_url);
        $stored = DB::table('organizations')->where('id', $organization->id)->value('slack_webhook_url');
        $this->assertNotSame(self::SLACK_URL, $stored);
        $this->assertStringNotContainsString('hooks.slack.com', $stored);
    }

    public function test_the_edit_page_says_whether_a_webhook_is_set_without_sending_it(): void
    {
        $organization = $this->createOrganization(['slack_webhook_url' => self::SLACK_URL]);
        $this->actingAsAdmin($organization);

        $this->get(route('organizations.edit', $organization))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Organizations/Edit')
                ->where('organization.has_slack_webhook', true)
                ->missing('organization.slack_webhook_url'));

        $this->assertStringNotContainsString(self::SLACK_URL, json_encode($organization->fresh()));
    }

    public function test_a_blank_url_keeps_the_current_webhook(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'slack_webhook_url' => self::SLACK_URL]);
        $this->actingAsAdmin($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme Inc',
            'slack_webhook_url' => '',
        ])->assertRedirect(route('organizations.index'));

        $organization->refresh();
        $this->assertSame('Acme Inc', $organization->name);
        $this->assertSame(self::SLACK_URL, $organization->slack_webhook_url);
    }

    public function test_an_admin_can_turn_slack_alerts_off(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'slack_webhook_url' => self::SLACK_URL]);
        $this->actingAsAdmin($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme',
            'remove_slack_webhook' => true,
        ])->assertRedirect(route('organizations.index'));

        $this->assertNull($organization->fresh()->slack_webhook_url);
    }

    public function test_only_slack_webhook_urls_are_accepted(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $this->actingAsAdmin($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme',
            'slack_webhook_url' => 'https://attacker.example.test/collect',
        ])->assertSessionHasErrors('slack_webhook_url');

        $this->assertNull($organization->fresh()->slack_webhook_url);
    }

    public function test_a_member_cannot_change_the_webhook(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $this->actingAsMember($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme',
            'slack_webhook_url' => self::SLACK_URL,
        ])->assertForbidden();

        $this->assertNull($organization->fresh()->slack_webhook_url);
    }
}
