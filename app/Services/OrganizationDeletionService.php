<?php

namespace App\Services;

use App\Exceptions\OrganizationRestoreBlockedException;
use App\Models\Group;
use App\Models\Monitor;
use App\Models\MonitorCheckLog;
use App\Models\MonitorDailyCheckMetric;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganizationDeletionService
{
    /**
     * Cascade soft delete. Every row deleted here shares ONE timestamp,
     * which restore() uses to resurrect exactly this deletion — and nothing
     * that was already individually deleted before it.
     */
    public function delete(Organization $organization): void
    {
        if ($organization->trashed()) {
            return; // idempotent — re-stamping would orphan children from their cascade marker
        }

        DB::transaction(function () use ($organization) {
            $timestamp = now();

            // Relations exclude already-trashed rows, so previously deleted
            // monitors/groups keep their original deleted_at (the marker).
            $organization->monitors()->update(['deleted_at' => $timestamp]);
            $organization->groups()->update(['deleted_at' => $timestamp]);

            $this->soleMemberUsers($organization)->update(['deleted_at' => $timestamp]);

            $organization->forceFill(['deleted_at' => $timestamp])->save();
        });
    }

    /**
     * @return array{skipped_monitors: string[]} monitors left trashed because
     *                                           a live monitor now holds their URL
     */
    public function restore(Organization $organization): array
    {
        if (! $organization->trashed()) {
            return ['skipped_monitors' => []]; // idempotent — double-submit of Restore is a no-op
        }

        if (Organization::where('slug', $organization->slug)
            ->whereKeyNot($organization->getKey())
            ->exists()) {
            throw new OrganizationRestoreBlockedException(
                "Cannot restore: the slug '{$organization->slug}' is now used by another organization."
            );
        }

        return DB::transaction(function () use ($organization) {
            $timestamp = $organization->deleted_at;

            $organization->restore();

            $cascaded = Monitor::onlyTrashed()
                ->where('organization_id', $organization->id)
                ->where('deleted_at', $timestamp);

            // toBase(): raw url values (the model accessor wraps url in an object).
            $urls = (clone $cascaded)->toBase()->pluck('url');
            $conflictedUrls = Monitor::whereIn('url', $urls)->toBase()->pluck('url');

            $skipped = (clone $cascaded)->whereIn('url', $conflictedUrls)->pluck('name')->all();
            (clone $cascaded)->whereNotIn('url', $conflictedUrls)->restore();

            Group::onlyTrashed()
                ->where('organization_id', $organization->id)
                ->where('deleted_at', $timestamp)
                ->restore();

            User::onlyTrashed()
                ->where('deleted_at', $timestamp)
                ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $organization->id))
                ->restore();

            return ['skipped_monitors' => $skipped];
        });
    }

    /**
     * Hard-delete a trashed organization and everything that belonged to it.
     * Idempotent; FK-safe order (children before RESTRICT parents). The big
     * leaf tables are trimmed in chunks OUTSIDE the transaction — the org is
     * already invisible, and one giant implicit cascade would hold locks.
     */
    public function purge(Organization $organization): void
    {
        if (! $organization->trashed()) {
            throw new \LogicException('Refusing to purge a live organization.');
        }

        $monitorIds = Monitor::withTrashed()
            ->where('organization_id', $organization->id)
            ->pluck('id');

        foreach ($monitorIds->chunk(500) as $ids) {
            while (MonitorCheckLog::whereIn('monitor_id', $ids)->limit(5000)->delete() > 0) {
                // batches until the chunk's logs are gone
            }
            MonitorDailyCheckMetric::whereIn('monitor_id', $ids)->delete();
        }

        DB::transaction(function () use ($organization) {
            Monitor::withTrashed()->where('organization_id', $organization->id)->forceDelete();
            Group::withTrashed()->where('organization_id', $organization->id)->forceDelete();

            // Users cascaded BY THIS org's deletion (cascade-marker match) that
            // are still trashed and have no other live membership. Without the
            // deleted_at filter, purging THIS org could permanently destroy a
            // user who was cascaded with a DIFFERENT org that is still inside
            // its own restore window. The org subquery must see the trashed org.
            User::onlyTrashed()
                ->where('deleted_at', $organization->deleted_at)
                ->where('is_super_admin', false)
                ->whereHas('organizations', fn ($q) => $q->withTrashed()->where('organizations.id', $organization->id))
                ->whereDoesntHave('organizations', fn ($q) => $q->where('organizations.id', '!=', $organization->id))
                ->forceDelete();

            $organization->forceDelete(); // organization_user rows drop via FK cascade
        });
    }

    /**
     * Hard-delete trashed monitors/groups past the cutoff whose organization is
     * LIVE: individually deleted records, and monitors a restore skipped due to
     * URL conflicts. Without this pass they would be retained forever (the org
     * purge only reaches children of trashed orgs). Groups still referenced by
     * any monitor row (even a trashed one — the FK is RESTRICT) are skipped and
     * picked up on a later run once those monitors have purged.
     *
     * @return array{monitors: int, groups: int}
     */
    public function purgeOrphanedChildren(\DateTimeInterface $cutoff): array
    {
        $monitorIds = Monitor::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->whereHas('organization')
            ->pluck('id');

        foreach ($monitorIds->chunk(500) as $ids) {
            while (MonitorCheckLog::whereIn('monitor_id', $ids)->limit(5000)->delete() > 0) {
                // batches until the chunk's logs are gone
            }
            MonitorDailyCheckMetric::whereIn('monitor_id', $ids)->delete();
        }

        $monitors = $monitorIds->isEmpty()
            ? 0
            : Monitor::onlyTrashed()->whereKey($monitorIds)->forceDelete();

        $groups = Group::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->whereHas('organization')
            ->whereDoesntHave('monitors', fn ($q) => $q->withTrashed())
            ->forceDelete();

        return ['monitors' => (int) $monitors, 'groups' => (int) $groups];
    }

    private function soleMemberUsers(Organization $organization)
    {
        return User::query()
            ->where('is_super_admin', false)
            ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $organization->id))
            ->whereDoesntHave('organizations', fn ($q) => $q->where('organizations.id', '!=', $organization->id));
        // whereDoesntHave sees only LIVE orgs (SoftDeletingScope), so "their
        // only remaining live org is this one" is exactly the cascade rule.
    }
}
