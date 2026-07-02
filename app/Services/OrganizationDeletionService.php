<?php

namespace App\Services;

use App\Exceptions\OrganizationRestoreBlockedException;
use App\Models\Group;
use App\Models\Monitor;
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
