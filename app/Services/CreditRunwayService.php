<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\Organization;

class CreditRunwayService
{
    /**
     * Projected credits/day from the org's CURRENT monitor configuration.
     * Computed on read — never stored — so monitor edits change it on the
     * very next render with nothing to invalidate.
     */
    public function dailyBurnFor(Organization $organization): int
    {
        return (int) $organization->monitors()
            ->get(['id', 'uptime_check_enabled', 'uptime_check_interval_in_minutes', 'certificate_check_enabled', 'domain_check_enabled'])
            ->sum(fn (Monitor $monitor) => $this->dailyBurnForMonitor($monitor));
    }

    public function dailyBurnForMonitor(Monitor $monitor): int
    {
        $burn = 0;

        if ($monitor->uptime_check_enabled) {
            // Interval is a string column; floor at 1 to avoid division blowups.
            $interval = max(1, (int) $monitor->uptime_check_interval_in_minutes);
            $burn += intdiv(1440, $interval);
        }

        if ($monitor->certificate_check_enabled) {
            $burn += 1; // daily schedule
        }

        if ($monitor->domain_check_enabled) {
            $burn += 1; // daily schedule
        }

        return $burn;
    }

    /**
     * Days until the balance runs out at the current configuration.
     * Null when nothing is consuming credits.
     */
    public function runwayDaysFor(Organization $organization): ?float
    {
        $burn = $this->dailyBurnFor($organization);

        if ($burn <= 0) {
            return null;
        }

        return max(0, $organization->credit_balance) / $burn;
    }
}
