<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Monitor;
use App\Models\Organization;
use App\Services\OrganizationDeletionService;
use Illuminate\Console\Command;

class PurgeDeletedOrganizations extends Command
{
    protected $signature = 'organizations:purge-deleted
        {--older-than-days= : Override the retention period in days}
        {--dry-run : List what would be purged without deleting anything}';

    protected $description = 'Hard-delete organizations (and their cascaded data) soft-deleted longer than the retention period';

    public function handle(OrganizationDeletionService $service): int
    {
        $days = max(1, (int) ($this->option('older-than-days') ?: config('organizations.purge_after_days', 60)));
        $cutoff = now()->subDays($days);

        $organizations = Organization::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('deleted_at')
            ->get();

        foreach ($organizations as $organization) {
            $monitors = Monitor::withTrashed()->where('organization_id', $organization->id)->count();
            $groups = Group::withTrashed()->where('organization_id', $organization->id)->count();

            if ($this->option('dry-run')) {
                $this->info("[dry-run] Would purge '{$organization->name}' ({$monitors} monitors, {$groups} groups).");

                continue;
            }

            $service->purge($organization);
            $this->info("Purged '{$organization->name}' ({$monitors} monitors, {$groups} groups).");
        }

        // Orphan pass: trashed monitors/groups of LIVE orgs (individually
        // deleted, or skipped by a restore) past the same cutoff.
        if ($this->option('dry-run')) {
            $orphanMonitors = Monitor::onlyTrashed()->where('deleted_at', '<=', $cutoff)->whereHas('organization')->count();
            $orphanGroups = Group::onlyTrashed()->where('deleted_at', '<=', $cutoff)->whereHas('organization')
                ->whereDoesntHave('monitors', fn ($q) => $q->withTrashed())->count();

            if ($orphanMonitors > 0 || $orphanGroups > 0) {
                $this->info("[dry-run] Would purge {$orphanMonitors} orphaned monitors and {$orphanGroups} orphaned groups.");
            }

            if ($organizations->isEmpty() && $orphanMonitors === 0 && $orphanGroups === 0) {
                $this->info('Nothing to purge.');
            }

            return self::SUCCESS;
        }

        $orphans = $service->purgeOrphanedChildren($cutoff);
        if ($orphans['monitors'] > 0 || $orphans['groups'] > 0) {
            $this->info("Purged {$orphans['monitors']} orphaned monitors and {$orphans['groups']} orphaned groups.");
        }

        if ($organizations->isEmpty() && $orphans['monitors'] === 0 && $orphans['groups'] === 0) {
            $this->info('Nothing to purge.');
        }

        return self::SUCCESS;
    }
}
