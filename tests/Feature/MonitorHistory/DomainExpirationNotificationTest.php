<?php

namespace Tests\Feature\MonitorHistory;

use App\Services\DomainService;
use Tests\TestCase;

class DomainExpirationNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin the thresholds the test asserts against, independent of env config.
        config(['domain-expiration.domain_check_time_period' => [
            '30_days_warning' => ['days' => 30],
            '7_days_warning' => ['days' => 7],
            '1_day_warning' => ['days' => 1],
        ]]);
    }

    private function notifications(int $days): array
    {
        return (new DomainService)->resolveExpirationNotifications($days);
    }

    public function test_it_notifies_exactly_on_a_configured_threshold_day(): void
    {
        $notifications = $this->notifications(30);

        $this->assertCount(1, $notifications);
        $this->assertSame(30, $notifications[0]['days']);
        $this->assertSame('Domain expires in 30 days!', $notifications[0]['message']);
    }

    public function test_the_final_day_warning_is_singular(): void
    {
        $notifications = $this->notifications(1);

        $this->assertCount(1, $notifications);
        $this->assertSame('Domain expires in 1 day!', $notifications[0]['message']);
    }

    public function test_it_does_not_notify_between_thresholds(): void
    {
        $this->assertSame([], $this->notifications(29));
        $this->assertSame([], $this->notifications(8));
    }

    public function test_it_does_not_notify_once_the_domain_has_expired(): void
    {
        // Negative days (already expired) must never produce a "expires in N days" notice.
        $this->assertSame([], $this->notifications(-1));
        $this->assertSame([], $this->notifications(-30));
    }

    public function test_it_does_not_notify_on_expiry_day_when_no_zero_threshold_is_configured(): void
    {
        $this->assertSame([], $this->notifications(0));
    }
}
