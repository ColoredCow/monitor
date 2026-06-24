<?php

namespace Tests\Unit;

use App\Services\DomainService;
use App\Services\MonitorCheckLogService;
use PHPUnit\Framework\TestCase;

class DomainExpirationOutcomeTest extends TestCase
{
    private function outcome(int $days): array
    {
        return (new DomainService)->resolveDomainExpirationOutcome($days);
    }

    public function test_expires_today_produces_the_today_message(): void
    {
        $outcome = $this->outcome(0);

        $this->assertSame(MonitorCheckLogService::STATUS_WARNING, $outcome['status']);
        $this->assertSame('Domain expires today.', $outcome['message']);
    }

    public function test_already_expired_is_failed(): void
    {
        $outcome = $this->outcome(-1);

        $this->assertSame(MonitorCheckLogService::STATUS_FAILED, $outcome['status']);
        $this->assertSame('Domain has expired.', $outcome['message']);
    }

    public function test_within_thirty_days_is_a_warning(): void
    {
        $outcome = $this->outcome(15);

        $this->assertSame(MonitorCheckLogService::STATUS_WARNING, $outcome['status']);
        $this->assertSame('Domain expires in 15 day(s).', $outcome['message']);
    }

    public function test_comfortably_in_the_future_is_a_success(): void
    {
        $outcome = $this->outcome(60);

        $this->assertSame(MonitorCheckLogService::STATUS_SUCCESS, $outcome['status']);
        $this->assertSame('Domain expires in 60 day(s).', $outcome['message']);
    }
}
