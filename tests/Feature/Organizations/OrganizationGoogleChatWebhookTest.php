<?php

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationGoogleChatWebhookTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private const CHAT_URL = 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=k&token=t';

    private const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/secret';

    public function test_an_admin_can_set_a_google_chat_webhook_and_it_is_encrypted_at_rest(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'slack_webhook_url' => self::SLACK_URL]);
        $this->actingAsAdmin($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme',
            'google_chat_webhook_url' => self::CHAT_URL,
        ])->assertRedirect(route('organizations.index'));

        $organization->refresh();
        $this->assertSame(self::CHAT_URL, $organization->google_chat_webhook_url);
        $this->assertSame(self::SLACK_URL, $organization->slack_webhook_url);
        $stored = DB::table('organizations')->where('id', $organization->id)->value('google_chat_webhook_url');
        $this->assertStringNotContainsString('chat.googleapis.com', $stored);
    }

    public function test_the_edit_page_says_whether_a_webhook_is_set_without_sending_it(): void
    {
        $organization = $this->createOrganization(['google_chat_webhook_url' => self::CHAT_URL]);
        $this->actingAsAdmin($organization);

        $this->get(route('organizations.edit', $organization))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('organization.has_google_chat_webhook', true)
                ->where('organization.google_chat_webhook_unreadable', false)
                ->where('organization.has_slack_webhook', false)
                ->missing('organization.google_chat_webhook_url'));

        $this->assertStringNotContainsString(self::CHAT_URL, json_encode($organization->fresh()));
    }

    public function test_a_blank_url_keeps_the_webhook_and_the_checkbox_goes_back_to_the_default_space(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'google_chat_webhook_url' => self::CHAT_URL]);
        $this->actingAsAdmin($organization);

        $this->put(route('organizations.update', $organization), ['name' => 'Acme', 'google_chat_webhook_url' => '']);
        $this->assertSame(self::CHAT_URL, $organization->fresh()->google_chat_webhook_url);

        $this->put(route('organizations.update', $organization), ['name' => 'Acme', 'remove_google_chat_webhook' => true]);
        $this->assertNull($organization->fresh()->google_chat_webhook_url);
    }

    public function test_only_google_chat_webhook_urls_are_accepted(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $this->actingAsAdmin($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme',
            'google_chat_webhook_url' => self::SLACK_URL,
        ])->assertSessionHasErrors('google_chat_webhook_url');

        $this->assertNull($organization->fresh()->google_chat_webhook_url);
    }

    public function test_a_member_cannot_change_the_webhook(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $this->actingAsMember($organization);

        $this->put(route('organizations.update', $organization), [
            'name' => 'Acme',
            'google_chat_webhook_url' => self::CHAT_URL,
        ])->assertForbidden();

        $this->assertNull($organization->fresh()->google_chat_webhook_url);
    }
}
