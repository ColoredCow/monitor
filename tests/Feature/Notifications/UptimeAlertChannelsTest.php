<?php

namespace Tests\Feature\Notifications;

use App\Channels\OrganizationSlackChannel;
use App\Exceptions\AlertChannelFailed;
use App\Models\Monitor;
use App\Models\Organization;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Psr\Http\Message\RequestInterface;
use Spatie\UptimeMonitor\Events\UptimeCheckFailed;
use Spatie\UptimeMonitor\Events\UptimeCheckRecovered;
use Spatie\UptimeMonitor\Helpers\Period;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;
use Symfony\Component\Mime\Exception\LogicException as MimeLogicException;
use Tests\TestCase;
use Throwable;

class UptimeAlertChannelsTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_CHAT_URL = 'https://chat.example.test/v1/spaces/alerts/messages';

    private const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/secret';

    /** Webhook requests sent during the test. */
    private array $sent = [];

    /** Status code the fake webhook host answers with, per URL. */
    private array $statusFor = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.from.address' => 'monitor@example.test',
            'uptime-monitor.notifications.mail.to' => ['alerts@example.test'],
            'uptime-monitor.notifications.google_chat.webhook_url' => self::GOOGLE_CHAT_URL,
        ]);

        // Every webhook call is recorded and answered with a 200 unless a test says otherwise.
        $stack = HandlerStack::create(fn (RequestInterface $request) => Create::promiseFor(
            new Response($this->statusFor[(string) $request->getUri()] ?? 200)
        ));
        $stack->push(Middleware::history($this->sent));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    public function test_an_organization_with_a_slack_webhook_gets_its_down_and_recovered_alerts(): void
    {
        $organization = Organization::factory()->create(['slack_webhook_url' => self::SLACK_URL]);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['url' => 'https://shop.example.test']);

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');
        $this->recoverMonitor($monitor);

        $texts = $this->textsSentTo(self::SLACK_URL);
        $this->assertCount(2, $texts);
        $this->assertSame("https://shop.example.test seems down\nReason: cURL error 28: Operation timed out", $texts[0]);
        $this->assertStringStartsWith('https://shop.example.test has recovered after', $texts[1]);

        // Slack needs a JSON content type; the shared Google Chat space still gets both alerts.
        $this->assertSame('application/json', $this->requestsTo(self::SLACK_URL)[0]->getHeaderLine('Content-Type'));
        $this->assertCount(2, $this->textsSentTo(self::GOOGLE_CHAT_URL));
    }

    public function test_another_organizations_alerts_never_reach_the_webhook(): void
    {
        Organization::factory()->create(['slack_webhook_url' => self::SLACK_URL]);
        $monitor = Monitor::factory()->create();

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');

        $this->assertSame([], $this->requestsTo(self::SLACK_URL));
        $this->assertCount(1, $this->textsSentTo(self::GOOGLE_CHAT_URL));
    }

    public function test_an_organization_without_a_webhook_sends_nothing_to_slack(): void
    {
        $monitor = Monitor::factory()->create();

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');

        $this->assertCount(1, $this->sent);
        $this->assertCount(1, $this->textsSentTo(self::GOOGLE_CHAT_URL));
    }

    public function test_a_broken_slack_webhook_is_reported_without_its_url(): void
    {
        Exceptions::fake();
        $this->statusFor[self::SLACK_URL] = 500;
        $organization = Organization::factory()->create(['slack_webhook_url' => self::SLACK_URL]);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');

        $this->assertCount(1, $this->requestsTo(self::SLACK_URL));
        $this->assertCount(1, $this->textsSentTo(self::GOOGLE_CHAT_URL));
        Exceptions::assertReported(fn (AlertChannelFailed $e) => $e->getMessage()
            === "Uptime alert via OrganizationSlackChannel failed for organization {$organization->id} (HTTP 500).");
        Exceptions::assertNotReported(fn (Throwable $e) => str_contains($e->getMessage(), 'hooks.slack.com'));
    }

    public function test_a_broken_google_chat_webhook_does_not_stop_the_slack_alert(): void
    {
        Exceptions::fake();
        $this->statusFor[self::GOOGLE_CHAT_URL] = 500;
        $organization = Organization::factory()->create(['slack_webhook_url' => self::SLACK_URL]);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');

        $this->assertCount(1, $this->textsSentTo(self::SLACK_URL));
        Exceptions::assertReported(fn (AlertChannelFailed $e) => str_contains($e->getMessage(), 'GoogleChatChannel'));
        Exceptions::assertNotReported(fn (Throwable $e) => str_contains($e->getMessage(), 'chat.example.test'));
    }

    public function test_a_failing_mail_transport_does_not_stop_the_chat_alerts(): void
    {
        Exceptions::fake();
        config(['mail.from.address' => null]);
        $organization = Organization::factory()->create(['slack_webhook_url' => self::SLACK_URL]);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');

        $this->assertCount(1, $this->textsSentTo(self::GOOGLE_CHAT_URL));
        $this->assertCount(1, $this->textsSentTo(self::SLACK_URL));
        Exceptions::assertReported(MimeLogicException::class);
    }

    public function test_an_unreadable_webhook_skips_slack_without_breaking_alerting(): void
    {
        Log::spy();
        $organization = Organization::factory()->create(['slack_webhook_url' => self::SLACK_URL]);
        // What APP_KEY changing without APP_PREVIOUS_KEYS leaves behind.
        DB::table('organizations')->where('id', $organization->id)->update(['slack_webhook_url' => 'not-decryptable']);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');

        $this->assertSame([], $this->requestsTo(self::SLACK_URL));
        $this->assertCount(1, $this->textsSentTo(self::GOOGLE_CHAT_URL));
        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $message) => str_contains($message, "organization {$organization->id} can't be decrypted"));
    }

    public function test_mentions_in_the_failure_reason_cannot_ping_anyone(): void
    {
        $organization = Organization::factory()->create(['slack_webhook_url' => self::SLACK_URL]);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['url' => 'https://shop.example.test']);

        $this->failMonitor($monitor, 'Forbidden: <!channel> <users/all> & more');

        $this->assertSame(
            ["https://shop.example.test seems down\nReason: Forbidden: &lt;!channel&gt; &lt;users/all&gt; &amp; more"],
            $this->textsSentTo(self::SLACK_URL)
        );
        $this->assertSame(
            ["https://shop.example.test seems down\nReason: Forbidden: <\u{200B}!channel> <\u{200B}users/all> & more"],
            $this->textsSentTo(self::GOOGLE_CHAT_URL)
        );
    }

    public function test_the_slack_channel_skips_notifications_it_cannot_format(): void
    {
        NotificationFacade::route('organizationSlack', self::SLACK_URL)->notify(new class extends Notification
        {
            public function via($notifiable): array
            {
                return [OrganizationSlackChannel::class];
            }
        });

        $this->assertSame([], $this->sent);
    }

    public function test_the_google_chat_alert_includes_the_failure_reason(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://shop.example.test']);

        $this->failMonitor($monitor, 'Client error: `GET https://shop.example.test` resulted in a `403 Forbidden` response');

        $this->assertSame(
            ["https://shop.example.test seems down\nReason: Client error: `GET https://shop.example.test` resulted in a `403 Forbidden` response"],
            $this->textsSentTo(self::GOOGLE_CHAT_URL)
        );
    }

    public function test_the_google_chat_alert_without_a_reason_is_unchanged(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://shop.example.test']);

        $this->failMonitor($monitor, null);

        $this->assertSame(['https://shop.example.test seems down'], $this->textsSentTo(self::GOOGLE_CHAT_URL));
    }

    public function test_a_long_failure_reason_is_shortened(): void
    {
        $monitor = Monitor::factory()->create(['url' => 'https://shop.example.test']);

        $this->failMonitor($monitor, str_repeat('x', 1000));

        $text = $this->textsSentTo(self::GOOGLE_CHAT_URL)[0];
        $this->assertStringEndsWith('Reason: '.str_repeat('x', 300).'...', $text);
    }

    public function test_google_chat_posts_verify_tls_and_time_out(): void
    {
        $monitor = Monitor::factory()->create();

        $this->failMonitor($monitor, 'cURL error 28: Operation timed out');

        $options = $this->optionsFor(self::GOOGLE_CHAT_URL);
        $this->assertTrue($options['verify']);
        $this->assertSame(10, $options['timeout']);
    }

    private function failMonitor(Monitor $monitor, ?string $reason): void
    {
        $monitor->update([
            'uptime_status' => UptimeStatus::DOWN,
            'uptime_check_failure_reason' => $reason,
        ]);

        event(new UptimeCheckFailed($monitor, new Period(now()->subMinutes(5), now())));
    }

    private function recoverMonitor(Monitor $monitor): void
    {
        $monitor->update(['uptime_status' => UptimeStatus::UP]);

        event(new UptimeCheckRecovered($monitor, new Period(now()->subMinutes(5), now())));
    }

    /** @return RequestInterface[] */
    private function requestsTo(string $url): array
    {
        return collect($this->sent)
            ->map(fn (array $call) => $call['request'])
            ->filter(fn (RequestInterface $request) => (string) $request->getUri() === $url)
            ->values()
            ->all();
    }

    /** The Guzzle options of the first request sent to the given URL. */
    private function optionsFor(string $url): array
    {
        return collect($this->sent)
            ->first(fn (array $call) => (string) $call['request']->getUri() === $url)['options'];
    }

    /** The "text" of every webhook message sent to the given URL. */
    private function textsSentTo(string $url): array
    {
        return collect($this->requestsTo($url))
            ->map(fn (RequestInterface $request) => json_decode((string) $request->getBody(), true)['text'])
            ->all();
    }
}
