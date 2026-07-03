<?php

namespace Database\Seeders;

use App\Models\Monitor;
use App\Models\Organization;
use App\Services\DomainService;
use App\Services\MonitorCheckLogService;
use App\Services\MonitorDailyCheckMetricsAggregator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds demo monitors with synthetic, multi-day check history so the monitor
 * detail page (heatmaps, tooltips, totals) has realistic data to render locally.
 *
 * Run with: php artisan db:seed --class=MonitorHistorySeeder
 * Remember to set MONITOR_HISTORY_ENABLED=true to see the history UI.
 *
 * The monitor URLs are placeholders — the history is fabricated, so live uptime
 * checks against them will not match the seeded data.
 */
class MonitorHistorySeeder extends Seeder
{
    /**
     * Number of uptime checks fabricated per day (spread across the day).
     */
    private const UPTIME_CHECKS_PER_DAY = 8;

    private ?Organization $organization = null;

    /**
     * Demo monitors and the health profile used to fabricate their history.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $specs = [
        [
            'name' => 'Healthy API',
            'url' => 'https://healthy.monitor-demo.test',
            'uptime' => true,
            'domain' => true,
            'certificate' => true,
            'profile' => 'healthy',
            'domain_expires_in_days' => 220,
        ],
        [
            'name' => 'Flaky Service',
            'url' => 'https://flaky.monitor-demo.test',
            'uptime' => true,
            'domain' => false,
            'certificate' => false,
            'profile' => 'flaky',
        ],
        [
            'name' => 'Expiring Domain',
            'url' => 'https://expiring.monitor-demo.test',
            'uptime' => true,
            'domain' => true,
            'certificate' => false,
            'profile' => 'domain_expiring',
            'domain_expires_in_days' => 12,
        ],
        [
            'name' => 'Recovered Outage',
            'url' => 'https://outage.monitor-demo.test',
            'uptime' => true,
            'domain' => false,
            'certificate' => false,
            'profile' => 'outage',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('MonitorHistorySeeder skipped: not allowed in production.');

            return;
        }

        $this->organization = Organization::firstOrCreate(
            ['slug' => 'coloredcow'],
            ['name' => 'ColoredCow']
        );

        $days = max(1, (int) config('monitor-history.seed_days', 90));
        $timezone = config('monitor-history.timezone') ?: config('app.timezone', 'UTC');
        $logService = app(MonitorCheckLogService::class);
        $domainService = app(DomainService::class);

        $now = Carbon::now('UTC');
        $rangeStart = $now->copy()->subDays($days - 1)->startOfDay();

        foreach ($this->specs as $spec) {
            $monitor = $this->createMonitor($spec, $now);

            // Make the seed re-runnable: clear previously seeded history for this monitor.
            $monitor->checkLogs()->delete();
            $monitor->dailyCheckMetrics()->delete();

            for ($dayOffset = 0; $dayOffset < $days; $dayOffset++) {
                $day = $rangeStart->copy()->addDays($dayOffset);

                if ($monitor->uptime_check_enabled) {
                    $this->seedUptimeDay($logService, $monitor, $day, $dayOffset, $days, $spec['profile']);
                }

                if (! empty($spec['domain'])) {
                    $this->seedDomainDay($logService, $domainService, $monitor, $day, $spec);
                }

                if (! empty($spec['certificate'])) {
                    $this->seedCertificateDay($logService, $monitor, $day);
                }
            }
        }

        $rows = app(MonitorDailyCheckMetricsAggregator::class)->aggregate(
            $rangeStart,
            $now,
            $timezone
        );

        $this->command?->info(sprintf(
            'Seeded %d demo monitors with %d days of history (%d daily metric buckets, timezone %s).',
            count($this->specs),
            $days,
            $rows,
            $timezone
        ));
        $this->command?->info('Set MONITOR_HISTORY_ENABLED=true to view the history UI.');
    }

    private function createMonitor(array $spec, Carbon $now): Monitor
    {
        $domainExpiresAt = isset($spec['domain_expires_in_days'])
            ? $now->copy()->addDays((int) $spec['domain_expires_in_days'])
            : null;

        return Monitor::updateOrCreate(
            ['url' => $spec['url']],
            [
                'name' => $spec['name'],
                'uptime_check_enabled' => (bool) $spec['uptime'],
                'domain_check_enabled' => (bool) $spec['domain'],
                'certificate_check_enabled' => (bool) $spec['certificate'],
                'domain_expires_at' => $domainExpiresAt,
                'organization_id' => $this->organization->id,
            ]
        );
    }

    private function seedUptimeDay(
        MonitorCheckLogService $logService,
        Monitor $monitor,
        Carbon $day,
        int $dayOffset,
        int $totalDays,
        string $profile
    ): void {
        // failureCount = how many of the day's checks fail (deterministic per profile/day).
        $failureCount = $this->uptimeFailuresForDay($profile, $dayOffset, $totalDays);
        $intervalMinutes = (int) floor((24 * 60) / self::UPTIME_CHECKS_PER_DAY);

        for ($checkIndex = 0; $checkIndex < self::UPTIME_CHECKS_PER_DAY; $checkIndex++) {
            $checkedAt = $day->copy()->addMinutes($checkIndex * $intervalMinutes);
            if ($checkedAt->greaterThan(Carbon::now('UTC'))) {
                break;
            }

            $isFailure = $checkIndex < $failureCount;
            $status = $isFailure
                ? MonitorCheckLogService::STATUS_FAILED
                : MonitorCheckLogService::STATUS_SUCCESS;

            $logService->logCheck(
                monitor: $monitor,
                checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
                status: $status,
                checkedAt: $checkedAt,
                message: $isFailure ? 'Uptime check failed.' : 'Uptime check succeeded.',
                failureReason: $isFailure ? 'Connection timed out (seeded).' : null,
                responseTimeMs: $isFailure ? null : 120 + (($checkIndex * 17) % 90),
            );
        }
    }

    private function uptimeFailuresForDay(string $profile, int $dayOffset, int $totalDays): int
    {
        return match ($profile) {
            // Mostly healthy, with an occasional single blip.
            'healthy' => ($dayOffset % 13 === 0) ? 1 : 0,
            // Frequently degraded: ~1/3 of days lose several checks.
            'flaky' => ($dayOffset % 3 === 0) ? 4 : (($dayOffset % 5 === 0) ? 1 : 0),
            // A contiguous full outage in the middle of the window, healthy otherwise.
            'outage' => $this->isInOutageWindow($dayOffset, $totalDays) ? self::UPTIME_CHECKS_PER_DAY : 0,
            default => 0,
        };
    }

    private function isInOutageWindow(int $dayOffset, int $totalDays): bool
    {
        $start = intdiv($totalDays, 2);

        return $dayOffset >= $start && $dayOffset < $start + 3;
    }

    private function seedDomainDay(
        MonitorCheckLogService $logService,
        DomainService $domainService,
        Monitor $monitor,
        Carbon $day,
        array $spec
    ): void {
        $checkedAt = $day->copy()->setTime(2, 0);
        if ($checkedAt->greaterThan(Carbon::now('UTC'))) {
            return;
        }

        $expiresInDays = (int) ($spec['domain_expires_in_days'] ?? 200);
        // Expiry is a fixed future date; on an earlier day there are more days left,
        // so the value shrinks toward $expiresInDays as the history approaches today.
        $daysUntilExpiration = $expiresInDays + (int) $checkedAt->diffInDays(Carbon::now('UTC'));

        $outcome = $domainService->resolveDomainExpirationOutcome($daysUntilExpiration);

        $logService->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_DOMAIN,
            status: $outcome['status'],
            checkedAt: $checkedAt,
            message: $outcome['message'],
            metadata: [
                'days_until_expiration' => $daysUntilExpiration,
                'source' => 'seed',
            ],
        );
    }

    private function seedCertificateDay(
        MonitorCheckLogService $logService,
        Monitor $monitor,
        Carbon $day
    ): void {
        $checkedAt = $day->copy()->setTime(3, 0);
        if ($checkedAt->greaterThan(Carbon::now('UTC'))) {
            return;
        }

        $logService->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_CERTIFICATE,
            status: MonitorCheckLogService::STATUS_SUCCESS,
            checkedAt: $checkedAt,
            message: 'Certificate is valid.',
            metadata: ['source' => 'seed'],
        );
    }
}
