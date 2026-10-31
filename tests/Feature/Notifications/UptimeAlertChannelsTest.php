<?php

namespace Tests\Feature\Notifications;

use App\Models\Monitor;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\UptimeMonitor\Events\UptimeCheckFailed;
use Spatie\UptimeMonitor\Helpers\Period;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;
use Tests\TestCase;

class UptimeAlertChannelsTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_CHAT_URL = 'https://chat.example.test/v1/spaces/alerts/messages';

    /** Webhook requests sent during the test. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.from.address' => 'monitor@example.test',
            'uptime-monitor.notifications.mail.to' => ['alerts@example.test'],
            'uptime-monitor.notifications.google_chat.webhook_url' => self::GOOGLE_CHAT_URL,
        ]);

        // Every webhook call is recorded and answered with a 200.
        $stack = HandlerStack::create(fn () => Create::promiseFor(new Response(200)));
        $stack->push(Middleware::history($this->sent));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
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

    private function failMonitor(Monitor $monitor, ?string $reason): void
    {
        $monitor->update([
            'uptime_status' => UptimeStatus::DOWN,
            'uptime_check_failure_reason' => $reason,
        ]);

        event(new UptimeCheckFailed($monitor, new Period(now()->subMinutes(5), now())));
    }

    /** The "text" of every webhook message sent to the given URL. */
    private function textsSentTo(string $url): array
    {
        return collect($this->sent)
            ->filter(fn (array $call) => (string) $call['request']->getUri() === $url)
            ->map(fn (array $call) => json_decode((string) $call['request']->getBody(), true)['text'])
            ->values()
            ->all();
    }
}
