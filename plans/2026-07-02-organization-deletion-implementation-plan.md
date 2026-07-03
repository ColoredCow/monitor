# Organization Deletion (Soft Delete + 60-Day Purge) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Super-admins can soft-delete an organization (cascading to its monitors, groups, and sole-org users), restore it within 60 days, after which a scheduled command hard-purges it — without touching how Spatie's background checks work.

**Architecture:** Standard Laravel `SoftDeletes` on `Organization`/`Monitor`/`Group`/`User` (Spatie's repository reads through the configured model, so trashed monitors stop being checked automatically). One explicit `OrganizationDeletionService` owns cascade delete/restore/purge with a shared-`deleted_at`-timestamp cascade marker. A dedicated `organizations:purge-deleted` command runs the FK-ordered hard purge daily. Live-only uniqueness for `monitors.url`/`organizations.slug` via MySQL functional indexes; `users.email` stays plainly unique with restore-and-link semantics.

**Tech Stack:** Laravel 12 (PHP 8.2), MySQL ≥ 8.0.13 (local 8.0.19), Inertia + React, spatie/laravel-uptime-monitor 4.5.1, PHPUnit 11 (`RefreshDatabase` on MySQL `monitor_test`).

**Spec:** [plans/2026-07-02-organization-deletion-design.md](2026-07-02-organization-deletion-design.md) · **PR:** #82 (same branch `feat/issue-23-organization-dashboard`)

## Global Constraints

- **No tenant-keyed global scope** — unchanged rule from v1. `SoftDeletingScope` is fine (deterministic, session-independent; skipping trashed rows in console IS the desired behavior).
- **Retention period** is exactly `config('organizations.purge_after_days', 60)` — never a hard-coded 60 outside `config/organizations.php`.
- **Cascade marker:** all rows soft-deleted by one org deletion share the **exact same `deleted_at` timestamp** (a single `now()` captured once). Restore matches on `deleted_at` equality.
- **User cascade rule:** only users with `is_super_admin = false` AND no other **live** org membership. Pivot (`organization_user`) rows are never touched by soft delete/restore.
- **`users.email` keeps its plain unique index.** Only `monitors.url` and `organizations.slug` become live-only functional indexes.
- Roles via `Organization::ROLE_*`; session key `active_organization_id`; run `./vendor/bin/pint` on changed PHP before committing; PHP tests via `php artisan test`; frontend build needs Node 22: `export PATH="/Users/vaibhav/.nvm/versions/node/v22.22.2/bin:$PATH"` before any `npm` command.
- Migration prefixes continue from `2026_06_25_*`: use `2026_07_02_0001xx` in the order given.
- The full suite is currently **116 passing** — it must stay green at the end of every task.

---

### Task 1: SoftDeletes columns + traits + Spatie/auth exclusion regression tests

**Files:**
- Create: `database/migrations/2026_07_02_000100_add_soft_deletes_columns.php`
- Modify: `app/Models/Organization.php`, `app/Models/User.php`, `app/Models/Monitor.php`, `app/Models/Group.php`
- Test: `tests/Feature/Organizations/SoftDeleteFoundationsTest.php`

**Interfaces:**
- Consumes: existing models/factories; `Spatie\UptimeMonitor\MonitorRepository`.
- Produces: `deleted_at` (indexed) on `organizations`, `users`, `monitors`, `groups`; all four models use `Illuminate\Database\Eloquent\SoftDeletes` (so `->delete()` is soft, `withTrashed()/onlyTrashed()/restore()/forceDelete()` exist everywhere downstream tasks need them).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\UptimeMonitor\MonitorRepository;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class SoftDeleteFoundationsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_trashed_monitor_is_excluded_from_spatie_repository(): void
    {
        $organization = $this->createOrganization();
        $kept = Monitor::factory()->forOrganization($organization)->create();
        $trashed = Monitor::factory()->forOrganization($organization)->create();

        $trashed->delete();

        $enabled = MonitorRepository::getEnabled();
        $this->assertTrue($enabled->contains('id', $kept->id));
        $this->assertFalse($enabled->contains('id', $trashed->id));
        $this->assertNull(MonitorRepository::findByUrl((string) $trashed->getRawOriginal('url')));
        $this->assertSoftDeleted($trashed); // row survives — this is what distinguishes soft from hard delete
    }

    public function test_soft_deleting_a_monitor_keeps_its_check_logs(): void
    {
        $monitor = Monitor::factory()->forOrganization($this->createOrganization())->create();
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $monitor->delete();

        $this->assertSoftDeleted($monitor);
        $this->assertDatabaseCount('monitor_check_logs', 1);
    }

    public function test_trashed_user_cannot_log_in(): void
    {
        $organization = $this->createOrganization();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        $user->delete();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $this->assertSoftDeleted($user); // account retained for the restore window
    }

    public function test_trashed_organization_disappears_from_switcher_and_resolution(): void
    {
        $orgA = $this->createOrganization(['name' => 'Alpha']);
        $orgB = $this->createOrganization(['name' => 'Beta']);
        $user = User::factory()->create();
        $orgA->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        $orgB->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $orgB->delete();

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $orgB->id])
            ->get('/monitors')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('auth.organizations', 1)
                ->where('auth.activeOrganization.id', $orgA->id));

        $this->assertSoftDeleted($orgB); // row survives — soft, not hard, delete
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SoftDeleteFoundationsTest`
Expected: FAIL — all four tests error on their `assertSoftDeleted(...)` call with a `QueryException` ("Unknown column 'deleted_at'"). Without those assertions the other checks would pass even with hard deletes — the `assertSoftDeleted` lines are what make these tests lock in soft-delete semantics.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_07_02_000100_add_soft_deletes_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['organizations', 'users', 'monitors', 'groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->softDeletes();
                $table->index('deleted_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['organizations', 'users', 'monitors', 'groups'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['deleted_at']);
                $table->dropSoftDeletes();
            });
        }
    }
};
```

- [ ] **Step 4: Add the trait to the four models**

In each of `app/Models/Organization.php`, `app/Models/User.php`, `app/Models/Group.php`: add the import `use Illuminate\Database\Eloquent\SoftDeletes;` and extend the class-level `use` line, e.g. `use HasFactory, SoftDeletes;` (Group: `use BelongsToOrganization, HasFactory, SoftDeletes;`; User: `use HasFactory, Notifiable, SoftDeletes;`).

In `app/Models/Monitor.php`: add the import and, inside the class body next to `use BelongsToOrganization;`, add `use SoftDeletes;`. (The custom constructor is safe: trait initializers run in `parent::__construct($attributes)`, and the existing casts merge preserves the `deleted_at` cast.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SoftDeleteFoundationsTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS — 120 tests (116 + 4). If any existing test hard-deleted a record and asserted `assertDatabaseMissing`, adjust that test to `assertSoftDeleted` ONLY if its intent was "user-facing delete works" (check `GroupsController`/`MonitorsController` destroy tests); report any such change in your summary.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Models/
git add database/migrations/2026_07_02_000100_add_soft_deletes_columns.php app/Models/ tests/Feature/Organizations/SoftDeleteFoundationsTest.php
git commit -m "feat: add SoftDeletes to Organization, User, Monitor, Group"
```

---

### Task 2: Live-only unique indexes (monitors.url, organizations.slug) + URL validation

**Files:**
- Create: `database/migrations/2026_07_02_000110_convert_unique_indexes_to_live_only.php`
- Modify: `app/Http/Requests/MonitorRequest.php` (url rule)
- Test: `tests/Feature/Organizations/LiveOnlyUniqueIndexTest.php`

**Interfaces:**
- Consumes: SoftDeletes from Task 1.
- Produces: `monitors.url` and `organizations.slug` unique **among live rows only** (functional indexes `monitors_url_active_unique`, `organizations_slug_active_unique`); `MonitorRequest` rejects duplicate live URLs with a validation error. `users.email` untouched.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class LiveOnlyUniqueIndexTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function monitorPayload(string $url): array
    {
        return [
            'name' => 'Example',
            'url' => $url,
            'monitorUptime' => true,
            'monitorDomain' => false,
            'uptimeCheckInterval' => 5,
            'monitorGroupId' => null,
        ];
    }

    public function test_url_of_a_trashed_monitor_can_be_reused(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $old = Monitor::factory()->forOrganization($orgA)->create(['url' => 'https://reuse-me.test']);
        $old->delete();

        $this->actingAsAdmin($orgB);
        $this->post('/monitors', $this->monitorPayload('https://reuse-me.test'))
            ->assertRedirect(route('monitors.index'));

        $this->assertSame(2, Monitor::withTrashed()->where('url', 'https://reuse-me.test')->count());
        $this->assertSame(1, Monitor::where('url', 'https://reuse-me.test')->count());
    }

    public function test_duplicate_live_url_is_a_validation_error(): void
    {
        $organization = $this->createOrganization();
        Monitor::factory()->forOrganization($organization)->create(['url' => 'https://taken.test']);

        $this->actingAsAdmin($organization);
        $this->post('/monitors', $this->monitorPayload('https://taken.test'))
            ->assertSessionHasErrors('url');

        $this->assertSame(1, Monitor::withTrashed()->where('url', 'https://taken.test')->count());
    }

    public function test_slug_of_a_trashed_organization_can_be_reused(): void
    {
        $old = $this->createOrganization(['name' => 'Acme', 'slug' => 'acme']);
        $old->delete();
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.store'), [
            'name' => 'Acme',
            'admin_name' => 'Ada',
            'admin_email' => 'ada@acme.test',
            'admin_password' => 'secret123',
        ])->assertRedirect(route('organizations.index'));

        $this->assertSame(1, Organization::where('slug', 'acme')->count());
        $this->assertSame(2, Organization::withTrashed()->where('slug', 'acme')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LiveOnlyUniqueIndexTest`
Expected: FAIL — the reuse tests throw `QueryException` (duplicate entry, plain unique index still present); the live-duplicate test fails with a `CannotSaveMonitor` exception instead of a validation error.

- [ ] **Step 3: Create the index migration**

`database/migrations/2026_07_02_000110_convert_unique_indexes_to_live_only.php` (raw statements — Blueprint cannot express functional indexes; requires MySQL ≥ 8.0.13):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Uniqueness among LIVE rows only: trashed rows index as NULL (always allowed).
        DB::statement('ALTER TABLE monitors ADD UNIQUE INDEX monitors_url_active_unique ((IF(deleted_at IS NULL, url, NULL)))');
        DB::statement('ALTER TABLE monitors DROP INDEX monitors_url_unique');

        DB::statement('ALTER TABLE organizations ADD UNIQUE INDEX organizations_slug_active_unique ((IF(deleted_at IS NULL, slug, NULL)))');
        DB::statement('ALTER TABLE organizations DROP INDEX organizations_slug_unique');
    }

    public function down(): void
    {
        // Best effort: fails if trashed rows now duplicate a live value.
        DB::statement('ALTER TABLE monitors ADD UNIQUE INDEX monitors_url_unique (url)');
        DB::statement('ALTER TABLE monitors DROP INDEX monitors_url_active_unique');

        DB::statement('ALTER TABLE organizations ADD UNIQUE INDEX organizations_slug_unique (slug)');
        DB::statement('ALTER TABLE organizations DROP INDEX organizations_slug_active_unique');
    }
};
```

If `DROP INDEX monitors_url_unique` fails, run `SHOW INDEX FROM monitors` to get the actual index name and adjust (same for organizations).

- [ ] **Step 4: Add the live-unique URL validation rule**

In `app/Http/Requests/MonitorRequest.php`, replace the `'url' => 'required|url',` line with:

```php
            'url' => [
                'required',
                'url',
                Rule::unique('monitors', 'url')
                    ->withoutTrashed()
                    ->ignore($this->route('monitor')?->id),
            ],
```

(`Rule` is already imported. `ignore` keeps updates of a monitor's own URL valid; on create the route param is null.)

No change to `uniqueSlug()` — its live-only `exists()` check already matches the functional index semantics.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=LiveOnlyUniqueIndexTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS — 123 tests.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Http/Requests/MonitorRequest.php
git add database/migrations/2026_07_02_000110_convert_unique_indexes_to_live_only.php app/Http/Requests/MonitorRequest.php tests/Feature/Organizations/LiveOnlyUniqueIndexTest.php
git commit -m "feat: live-only unique indexes for monitor url and organization slug"
```

---

### Task 3: OrganizationDeletionService — cascade delete + precise restore

**Files:**
- Create: `app/Services/OrganizationDeletionService.php`
- Create: `app/Exceptions/OrganizationRestoreBlockedException.php`
- Test: `tests/Feature/Organizations/OrganizationDeletionServiceTest.php`

**Interfaces:**
- Consumes: SoftDeletes (Task 1), live-only indexes (Task 2), `Organization::ROLE_*`, `User::isSuperAdmin`.
- Produces:
  - `App\Services\OrganizationDeletionService::delete(Organization $organization): void` — transactional cascade soft delete with ONE shared timestamp.
  - `::restore(Organization $organization): array` — returns `['skipped_monitors' => string[]]` (names of monitors left trashed due to live URL conflicts); throws `App\Exceptions\OrganizationRestoreBlockedException` when a live org now holds the slug.
  - (`purge()` is added in Task 4.)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Exceptions\OrganizationRestoreBlockedException;
use App\Models\Group;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use App\Services\OrganizationDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationDeletionServiceTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function service(): OrganizationDeletionService
    {
        return app(OrganizationDeletionService::class);
    }

    public function test_delete_cascades_to_monitors_groups_and_sole_org_users(): void
    {
        $organization = $this->createOrganization();
        $otherOrg = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        $group = Group::factory()->forOrganization($organization)->create();

        $soleUser = User::factory()->create();
        $organization->users()->attach($soleUser->id, ['role' => Organization::ROLE_MEMBER]);

        $multiUser = User::factory()->create();
        $organization->users()->attach($multiUser->id, ['role' => Organization::ROLE_ADMIN]);
        $otherOrg->users()->attach($multiUser->id, ['role' => Organization::ROLE_MEMBER]);

        $superAdmin = User::factory()->superAdmin()->create();
        $organization->users()->attach($superAdmin->id, ['role' => Organization::ROLE_MEMBER]);

        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $this->service()->delete($organization);

        $this->assertSoftDeleted($organization);
        $this->assertSoftDeleted($monitor);
        $this->assertSoftDeleted($group);
        $this->assertSoftDeleted($soleUser);
        $this->assertNotSoftDeleted($multiUser);
        $this->assertNotSoftDeleted($superAdmin);
        // Pivot rows and check logs survive soft delete.
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id, 'user_id' => $soleUser->id,
        ]);
        $this->assertDatabaseCount('monitor_check_logs', 1);
    }

    public function test_restore_resurrects_only_what_the_deletion_took(): void
    {
        $organization = $this->createOrganization();
        $earlier = Monitor::factory()->forOrganization($organization)->create(['name' => 'Earlier']);
        $with = Monitor::factory()->forOrganization($organization)->create(['name' => 'With']);
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $earlier->delete();               // individually deleted BEFORE the org deletion
        $this->travel(1)->minutes();      // distinct deleted_at (second precision)
        $this->service()->delete($organization);
        $this->service()->restore($organization->fresh());

        $this->assertNotSoftDeleted($organization);
        $this->assertNotSoftDeleted($with);
        $this->assertNotSoftDeleted($user->fresh());
        $this->assertSoftDeleted($earlier); // stays trashed

        // Restored user can log in again.
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticated();
    }

    public function test_restore_is_blocked_when_a_live_org_holds_the_slug(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'slug' => 'acme']);
        $this->service()->delete($organization);
        $this->createOrganization(['name' => 'Acme Two', 'slug' => 'acme']); // allowed by live-only index

        $this->expectException(OrganizationRestoreBlockedException::class);
        $this->service()->restore($organization->fresh());
    }

    public function test_restore_skips_monitors_whose_url_is_now_live_elsewhere(): void
    {
        $organization = $this->createOrganization();
        $conflicted = Monitor::factory()->forOrganization($organization)
            ->create(['name' => 'Conflicted', 'url' => 'https://contested.test']);
        $clean = Monitor::factory()->forOrganization($organization)->create(['name' => 'Clean']);

        $this->service()->delete($organization);

        Monitor::factory()->forOrganization($this->createOrganization())
            ->create(['url' => 'https://contested.test']); // someone re-used the URL

        $result = $this->service()->restore($organization->fresh());

        $this->assertSame(['Conflicted'], $result['skipped_monitors']);
        $this->assertSoftDeleted($conflicted);
        $this->assertNotSoftDeleted($clean);
    }

    public function test_delete_is_a_no_op_on_an_already_trashed_organization(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $this->service()->delete($organization);
        $marker = $organization->fresh()->deleted_at;

        $this->travel(1)->minutes();
        $this->service()->delete($organization->fresh()); // must NOT re-stamp

        $this->assertEquals($marker, $organization->fresh()->deleted_at);
        $this->assertEquals($marker, $monitor->fresh()->deleted_at);
    }

    public function test_restore_is_a_no_op_on_a_live_organization(): void
    {
        $organization = $this->createOrganization();
        $this->service()->delete($organization);
        $this->service()->restore($organization->fresh());

        // Second restore (double-submit): no exception, nothing skipped.
        $result = $this->service()->restore($organization->fresh());

        $this->assertSame(['skipped_monitors' => []], $result);
        $this->assertNotSoftDeleted($organization);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrganizationDeletionServiceTest`
Expected: FAIL — `Class "App\Services\OrganizationDeletionService" not found`.

- [ ] **Step 3: Create the exception**

`app/Exceptions/OrganizationRestoreBlockedException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class OrganizationRestoreBlockedException extends RuntimeException
{
}
```

- [ ] **Step 4: Create the service**

`app/Services/OrganizationDeletionService.php`:

```php
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
```

Note the ordering inside `restore()`: the org is restored FIRST so the user `whereHas('organizations', ...)` subquery (live-only) can match it.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=OrganizationDeletionServiceTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS — 129 tests.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Services/OrganizationDeletionService.php app/Exceptions/OrganizationRestoreBlockedException.php
git add app/Services/OrganizationDeletionService.php app/Exceptions/OrganizationRestoreBlockedException.php tests/Feature/Organizations/OrganizationDeletionServiceTest.php
git commit -m "feat: cascade org soft delete and precise restore via OrganizationDeletionService"
```

---

### Task 4: Purge — `purge()` + `organizations:purge-deleted` command + config + schedule

**Files:**
- Create: `config/organizations.php`
- Modify: `app/Services/OrganizationDeletionService.php` (add `purge()`)
- Create: `app/Console/Commands/PurgeDeletedOrganizations.php`
- Modify: `routes/console.php` (schedule)
- Test: `tests/Feature/Organizations/PurgeDeletedOrganizationsTest.php`

**Interfaces:**
- Consumes: Task 3's service; FK map (monitors/groups → organizations are `RESTRICT`; check logs/metrics → monitors and pivot → org/user are `CASCADE`).
- Produces: `OrganizationDeletionService::purge(Organization $organization): void` (idempotent, FK-ordered, throws `LogicException` for a live org, and only purges users whose `deleted_at` matches THIS org's cascade marker); `::purgeOrphanedChildren(\DateTimeInterface $cutoff): array{monitors:int,groups:int}` (trashed children of LIVE orgs past the cutoff); command `organizations:purge-deleted {--older-than-days=} {--dry-run}`; `config('organizations.purge_after_days')` (default 60); daily schedule.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use App\Services\OrganizationDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class PurgeDeletedOrganizationsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_purge_respects_the_retention_window(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        $group = Group::factory()->forOrganization($organization)->create();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        app(OrganizationDeletionService::class)->delete($organization);

        $this->travel(59)->days();
        $this->artisan('organizations:purge-deleted')->assertSuccessful();
        $this->assertSoftDeleted($organization); // still inside the window

        $this->travel(2)->days(); // day 61
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($organization);
        $this->assertModelMissing($monitor);
        $this->assertModelMissing($group);
        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id]);
        $this->assertDatabaseCount('monitor_check_logs', 0);
    }

    public function test_purge_keeps_multi_org_users(): void
    {
        $organization = $this->createOrganization();
        $otherOrg = $this->createOrganization();
        $multiUser = User::factory()->create();
        $organization->users()->attach($multiUser->id, ['role' => Organization::ROLE_MEMBER]);
        $otherOrg->users()->attach($multiUser->id, ['role' => Organization::ROLE_MEMBER]);

        app(OrganizationDeletionService::class)->delete($organization);
        $this->travel(61)->days();
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($organization);
        $this->assertNotSoftDeleted($multiUser); // never cascaded, never purged
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $otherOrg->id, 'user_id' => $multiUser->id,
        ]);
    }

    public function test_dry_run_purges_nothing(): void
    {
        $organization = $this->createOrganization();
        app(OrganizationDeletionService::class)->delete($organization);
        $this->travel(61)->days();

        $this->artisan('organizations:purge-deleted', ['--dry-run' => true])->assertSuccessful();

        $this->assertSoftDeleted($organization);
    }

    public function test_older_than_days_option_overrides_config(): void
    {
        $organization = $this->createOrganization();
        app(OrganizationDeletionService::class)->delete($organization);
        $this->travel(3)->days();

        $this->artisan('organizations:purge-deleted', ['--older-than-days' => 2])->assertSuccessful();

        $this->assertModelMissing($organization);
    }

    public function test_purging_one_org_never_claims_users_from_another_orgs_cascade(): void
    {
        // U is a member of A and B. B is deleted first (U kept — A is live).
        // A is deleted a month later, cascading U with A's marker. When B's
        // window lapses, purging B must NOT destroy U — U belongs to A's
        // still-restorable cascade.
        $orgA = $this->createOrganization(['name' => 'Alpha']);
        $orgB = $this->createOrganization(['name' => 'Beta']);
        $user = User::factory()->create();
        $orgA->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);
        $orgB->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        app(OrganizationDeletionService::class)->delete($orgB);
        $this->assertNotSoftDeleted($user); // A is still live

        $this->travel(30)->days();
        app(OrganizationDeletionService::class)->delete($orgA); // cascades U with A's marker

        $this->travel(31)->days(); // B is 61 days old; A only 31
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($orgB);
        $this->assertSoftDeleted($user); // survived B's purge

        app(OrganizationDeletionService::class)->restore($orgA->fresh());
        $this->assertNotSoftDeleted($user->fresh()); // A's restore resurrects U
    }

    public function test_orphaned_trashed_children_of_live_orgs_are_purged_after_the_window(): void
    {
        $organization = $this->createOrganization();
        $oldMonitor = Monitor::factory()->forOrganization($organization)->create();
        $emptyGroup = Group::factory()->forOrganization($organization)->create();
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $oldMonitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $oldMonitor->delete();   // individually deleted — org stays live
        $emptyGroup->delete();

        $this->travel(30)->days();
        $recentMonitor = Monitor::factory()->forOrganization($organization)->create();
        $recentMonitor->delete();

        $this->travel(31)->days(); // old orphans are 61 days; recent one 31
        $this->artisan('organizations:purge-deleted')->assertSuccessful();

        $this->assertModelMissing($oldMonitor);
        $this->assertModelMissing($emptyGroup);
        $this->assertDatabaseCount('monitor_check_logs', 0);
        $this->assertSoftDeleted($recentMonitor); // still inside its window
        $this->assertNotSoftDeleted($organization); // live org untouched
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PurgeDeletedOrganizationsTest`
Expected: FAIL — command `organizations:purge-deleted` not found.

- [ ] **Step 3: Create the config**

`config/organizations.php`:

```php
<?php

return [
    // Days a soft-deleted organization (and everything cascaded with it)
    // remains restorable before the scheduled purge hard-deletes it.
    'purge_after_days' => (int) env('ORGANIZATIONS_PURGE_AFTER_DAYS', 60),
];
```

- [ ] **Step 4: Add `purge()` to the service**

Append to `app/Services/OrganizationDeletionService.php` (inside the class, after `restore()`); also add the two imports `use App\Models\MonitorCheckLog;` and `use App\Models\MonitorDailyCheckMetric;`:

```php
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
```

- [ ] **Step 5: Create the command**

`app/Console/Commands/PurgeDeletedOrganizations.php`:

```php
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
```

- [ ] **Step 6: Schedule it**

Append to `routes/console.php`:

```php
// Organization retention: hard-purge organizations soft-deleted beyond the
// configurable window (organizations.purge_after_days).
Schedule::command('organizations:purge-deleted')
    ->dailyAt('02:30')
    ->withoutOverlapping();
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=PurgeDeletedOrganizationsTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS — 135 tests.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint app/Services/OrganizationDeletionService.php app/Console/Commands/PurgeDeletedOrganizations.php config/organizations.php
git add config/organizations.php app/Services/OrganizationDeletionService.php app/Console/Commands/PurgeDeletedOrganizations.php routes/console.php tests/Feature/Organizations/PurgeDeletedOrganizationsTest.php
git commit -m "feat: scheduled 60-day purge of soft-deleted organizations"
```

---

### Task 5: Delete/restore endpoints + Organizations page UI

**Files:**
- Modify: `app/Http/Controllers/OrganizationsController.php` (`index` deleted list, `destroy`, `restore`)
- Modify: `routes/web.php` (destroy in resource, restore route `withTrashed`)
- Modify: `resources/js/Pages/Organizations/Index.jsx` (Delete buttons + Deleted section)
- Test: `tests/Feature/Organizations/OrganizationDeleteEndpointTest.php`

**Interfaces:**
- Consumes: `OrganizationDeletionService::{delete,restore}`, `OrganizationRestoreBlockedException`, gate `manage-organizations`, `config('organizations.purge_after_days')`.
- Produces: `DELETE /organizations/{organization}` (`organizations.destroy`), `POST /organizations/{organization}/restore` (`organizations.restore`, trashed-aware binding); Inertia props on Organizations Index: existing `organizations` plus `deletedOrganizations` (`[{id,name,deleted_at,days_until_purge}]`) and `purgeAfterDays` (int).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Services\OrganizationDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationDeleteEndpointTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_super_admin_can_soft_delete_an_organization(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();

        $this->delete(route('organizations.destroy', $organization))
            ->assertRedirect(route('organizations.index'));

        $this->assertSoftDeleted($organization);
    }

    public function test_org_admin_cannot_delete_an_organization(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->delete(route('organizations.destroy', $organization))->assertForbidden();

        $this->assertNotSoftDeleted($organization);
    }

    public function test_index_lists_deleted_organizations_with_purge_countdown(): void
    {
        $live = $this->createOrganization(['name' => 'Live Org']);
        $trashed = $this->createOrganization(['name' => 'Gone Org']);
        app(OrganizationDeletionService::class)->delete($trashed);
        $this->actingAsSuperAdmin();

        $this->get(route('organizations.index'))->assertInertia(fn ($page) => $page
            ->has('organizations', 1)
            ->has('deletedOrganizations', 1)
            ->where('deletedOrganizations.0.name', 'Gone Org')
            ->where('deletedOrganizations.0.days_until_purge', 60)
            ->where('purgeAfterDays', 60));
    }

    public function test_super_admin_can_restore_a_deleted_organization(): void
    {
        $organization = $this->createOrganization();
        app(OrganizationDeletionService::class)->delete($organization);
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.restore', $organization->id))
            ->assertRedirect(route('organizations.index'));

        $this->assertNotSoftDeleted($organization);
    }

    public function test_restore_blocked_by_slug_conflict_flashes_an_error(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme', 'slug' => 'acme']);
        app(OrganizationDeletionService::class)->delete($organization);
        $this->createOrganization(['name' => 'Acme Two', 'slug' => 'acme']);
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.restore', $organization->id))
            ->assertRedirect(route('organizations.index'))
            ->assertSessionHasErrors('restore');

        $this->assertSoftDeleted($organization);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrganizationDeleteEndpointTest`
Expected: FAIL — `Route [organizations.destroy] not defined`.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, extend the resource `only` list and add the restore route directly below it:

```php
    Route::resource('organizations', OrganizationsController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    Route::post('/organizations/{organization}/restore', [OrganizationsController::class, 'restore'])
        ->name('organizations.restore')
        ->withTrashed();
```

- [ ] **Step 4: Extend the controller**

In `app/Http/Controllers/OrganizationsController.php`:

Add imports:

```php
use App\Exceptions\OrganizationRestoreBlockedException;
use App\Services\OrganizationDeletionService;
```

Replace `index()` with:

```php
    public function index()
    {
        $this->authorize('manage-organizations');

        $purgeAfterDays = (int) config('organizations.purge_after_days', 60);

        return Inertia::render('Organizations/Index', [
            'organizations' => Organization::withCount('users', 'monitors')->orderBy('name')->get(),
            'deletedOrganizations' => Organization::onlyTrashed()->orderBy('deleted_at')->get()
                ->map(fn (Organization $organization) => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'deleted_at' => $organization->deleted_at->toDateString(),
                    'days_until_purge' => max(0, $purgeAfterDays - (int) $organization->deleted_at->diffInDays(now())),
                ])->values(),
            'purgeAfterDays' => $purgeAfterDays,
        ]);
    }
```

Add the two actions (after `update()`):

```php
    public function destroy(Organization $organization): RedirectResponse
    {
        $this->authorize('manage-organizations');

        app(OrganizationDeletionService::class)->delete($organization);

        return redirect()->route('organizations.index');
    }

    public function restore(Organization $organization): RedirectResponse
    {
        $this->authorize('manage-organizations');

        try {
            $result = app(OrganizationDeletionService::class)->restore($organization);
        } catch (OrganizationRestoreBlockedException $exception) {
            return redirect()->route('organizations.index')
                ->withErrors(['restore' => $exception->getMessage()]);
        }

        $status = "Restored '{$organization->name}'.";
        if ($result['skipped_monitors'] !== []) {
            $status .= ' Skipped monitors with URLs now in use: '.implode(', ', $result['skipped_monitors']).'.';
        }

        return redirect()->route('organizations.index')->with('status', $status);
    }
```

- [ ] **Step 5: Update the Organizations page**

Replace `resources/js/Pages/Organizations/Index.jsx` with:

```jsx
import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import { PlusIcon } from "@heroicons/react/24/solid";

export default function Index() {
    const {
        auth,
        organizations,
        deletedOrganizations = [],
        purgeAfterDays = 60,
        errors = {},
    } = usePage().props;

    const handleDelete = (org) => {
        if (
            confirm(
                `Delete "${org.name}"? Its ${org.monitors_count} monitors, groups, and members whose only organization this is will be soft-deleted. It can be restored for ${purgeAfterDays} days.`
            )
        ) {
            router.delete(route("organizations.destroy", org.id));
        }
    };

    const handleRestore = (org) => {
        router.post(route("organizations.restore", org.id));
    };

    return (
        <Authenticated auth={auth}>
            <Head title="Organizations" />
            <PageHeader>
                <div className="flex justify-between items-center">
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        Organizations
                    </h1>
                    <Link href={route("organizations.create")}>
                        <Button>
                            <PlusIcon className="h-4 w-4" />
                            <span>Onboard</span>
                        </Button>
                    </Link>
                </div>
            </PageHeader>
            <div className="max-w-3xl mx-auto py-8 px-6 lg:px-8 space-y-3">
                {errors.restore && (
                    <div className="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
                        {errors.restore}
                    </div>
                )}

                {organizations.map((org) => (
                    <div
                        key={org.id}
                        className="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex justify-between items-center"
                    >
                        <div>
                            <div className="font-semibold text-gray-900">{org.name}</div>
                            <div className="text-xs text-gray-500 mt-0.5">
                                {org.users_count} users · {org.monitors_count} monitors
                            </div>
                        </div>
                        <div className="flex items-center gap-4">
                            <Link
                                href={route("organizations.edit", org.id)}
                                className="text-sm text-purple-600 hover:text-purple-800"
                            >
                                Rename
                            </Link>
                            <button
                                type="button"
                                onClick={() => handleDelete(org)}
                                className="text-sm text-red-600 hover:text-red-800"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                ))}

                {deletedOrganizations.length > 0 && (
                    <div className="pt-8">
                        <h2 className="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">
                            Deleted
                        </h2>
                        <div className="space-y-3">
                            {deletedOrganizations.map((org) => (
                                <div
                                    key={org.id}
                                    className="bg-gray-50 rounded-xl border border-dashed border-gray-300 p-5 flex justify-between items-center"
                                >
                                    <div>
                                        <div className="font-semibold text-gray-500">
                                            {org.name}
                                        </div>
                                        <div className="text-xs text-gray-400 mt-0.5">
                                            Deleted {org.deleted_at} ·{" "}
                                            {org.days_until_purge} days until permanent
                                            deletion
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => handleRestore(org)}
                                        className="text-sm text-purple-600 hover:text-purple-800"
                                    >
                                        Restore
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </Authenticated>
    );
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OrganizationDeleteEndpointTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Full suite + build**

Run: `php artisan test` → PASS (140 tests).
Run: `export PATH="/Users/vaibhav/.nvm/versions/node/v22.22.2/bin:$PATH" && npm run build` → succeeds.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/OrganizationsController.php
git add app/Http/Controllers/OrganizationsController.php routes/web.php resources/js/Pages/Organizations/Index.jsx tests/Feature/Organizations/OrganizationDeleteEndpointTest.php
git commit -m "feat: super-admin delete and restore for organizations"
```

---

### Task 6: Email restore-and-link + seeder guard + group-delete guard

**Files:**
- Modify: `app/Http/Controllers/UsersController.php` (`store`)
- Modify: `app/Http/Controllers/OrganizationsController.php` (`store`)
- Modify: `database/seeders/UserSeeder.php`
- Modify: `app/Http/Controllers/GroupsController.php` (`destroy`)
- Test: `tests/Feature/Organizations/RestoreAndLinkTest.php`

**Interfaces:**
- Consumes: SoftDeletes on User/Group; `Organization::ROLE_*`.
- Produces: adding a user (org user-management or onboarding) whose email belongs to a **trashed** account restores that account and links it (no duplicate row, name/password untouched); `GroupsController@destroy` refuses to delete a group that still has live monitors (validation error on key `group`), preserving today's UX now that the FK no longer fires on soft delete.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RestoreAndLinkTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_adding_a_trashed_email_restores_and_links_the_account(): void
    {
        $organization = $this->createOrganization();
        $ghost = User::factory()->create(['email' => 'ghost@x.test', 'name' => 'Original Name']);
        $ghost->delete();
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'Ignored New Name',
            'email' => 'ghost@x.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_MEMBER,
        ])->assertRedirect(route('users.index'));

        $this->assertSame(1, User::withTrashed()->where('email', 'ghost@x.test')->count());
        $restored = User::where('email', 'ghost@x.test')->firstOrFail();
        $this->assertSame('Original Name', $restored->name); // identity untouched
        $this->assertTrue($restored->hasRoleInOrganization($organization, Organization::ROLE_MEMBER));
    }

    public function test_onboarding_with_a_trashed_admin_email_restores_and_links(): void
    {
        $ghost = User::factory()->create(['email' => 'admin@x.test']);
        $ghost->delete();
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.store'), [
            'name' => 'Fresh Org',
            'admin_name' => 'Ignored',
            'admin_email' => 'admin@x.test',
            'admin_password' => 'secret123',
        ])->assertRedirect(route('organizations.index'));

        $this->assertSame(1, User::withTrashed()->where('email', 'admin@x.test')->count());
        $organization = Organization::where('name', 'Fresh Org')->firstOrFail();
        $this->assertTrue($ghost->fresh()->isAdminOf($organization));
        $this->assertNotSoftDeleted($ghost->fresh());
    }

    public function test_deleting_a_group_with_live_monitors_is_refused(): void
    {
        $organization = $this->createOrganization();
        $group = Group::factory()->forOrganization($organization)->create();
        Monitor::factory()->forOrganization($organization)->create(['group_id' => $group->id]);
        $this->actingAsAdmin($organization);

        $this->delete(route('groups.destroy', $group))->assertSessionHasErrors('group');

        $this->assertNotSoftDeleted($group);
    }

    public function test_deleting_an_empty_group_soft_deletes_it(): void
    {
        $organization = $this->createOrganization();
        $group = Group::factory()->forOrganization($organization)->create();
        $this->actingAsAdmin($organization);

        $this->delete(route('groups.destroy', $group))->assertRedirect(route('groups.index'));

        $this->assertSoftDeleted($group);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RestoreAndLinkTest`
Expected: FAIL (3 of 4) — the two trashed-email tests throw duplicate-key `QueryException` (plain unique index on `users.email`); `test_deleting_a_group_with_live_monitors_is_refused` fails because destroy currently soft-deletes and redirects instead of raising a validation error. `test_deleting_an_empty_group_soft_deletes_it` already passes — it is a regression guard proving the new `monitors()` check doesn't break empty-group deletion; do not chase it.

- [ ] **Step 3: Restore-and-link in UsersController::store**

In `app/Http/Controllers/UsersController.php`, replace the block from the `// Link an existing user...` comment through `$user->save();`'s closing brace (currently `User::firstOrNew(...)` + the `if (! $user->exists)` block) with:

```php
        // Link an existing user if their email already exists; a soft-deleted
        // account (e.g. removed with a deleted organization) is restored and
        // linked rather than duplicated. Name and password are intentionally
        // NOT overwritten for existing accounts — only new accounts get the
        // values from the form.
        $user = User::withTrashed()->firstOrNew(['email' => $validated['email']]);

        if ($user->trashed()) {
            $user->restore();
        }

        if (! $user->exists) {
            $user->name = $validated['name'];
            $user->password = bcrypt($validated['password']);
            $user->email_verified_at = now();
            $user->save();
        }
```

- [ ] **Step 4: Restore-and-link in OrganizationsController::store**

In `app/Http/Controllers/OrganizationsController.php`, replace the `$admin = User::firstOrNew(...)` block (through `$admin->save();`) with:

```php
        // Link an existing user if their email already exists; a soft-deleted
        // account is restored and linked rather than duplicated. Name and
        // password are intentionally NOT overwritten for existing accounts.
        $admin = User::withTrashed()->firstOrNew(
            ['email' => $validated['admin_email']],
            [
                'name' => $validated['admin_name'],
                'password' => Hash::make($validated['admin_password']),
            ]
        );

        if ($admin->trashed()) {
            $admin->restore();
        }

        if (! $admin->exists) {
            $admin->email_verified_at = now();
        }

        $admin->save();
```

- [ ] **Step 5: Guard the seeder**

In `database/seeders/UserSeeder.php`, replace the `User::updateOrCreate(...)` call with:

```php
        $user = User::withTrashed()->updateOrCreate([
            'email' => config('constants.default.user.email'),
        ], [
            'name' => config('constants.default.user.email'),
            'password' => Hash::make(config('constants.default.user.password')),
        ]);

        if ($user->trashed()) {
            $user->restore();
        }
```

- [ ] **Step 6: Guard group deletion**

In `app/Http/Controllers/GroupsController.php`, add the import `use Illuminate\Validation\ValidationException;` and change `destroy()` to:

```php
    public function destroy(Group $group)
    {
        $this->authorize('delete', $group);

        // The FK no longer blocks this (soft delete is an UPDATE), so enforce
        // explicitly: a group with live monitors must not disappear from under them.
        if ($group->monitors()->exists()) {
            throw ValidationException::withMessages([
                'group' => 'This group still has monitors. Move or delete them before deleting the group.',
            ]);
        }

        $group->delete();

        return redirect()->route('groups.index');
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=RestoreAndLinkTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS — 144 tests.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/UsersController.php app/Http/Controllers/OrganizationsController.php app/Http/Controllers/GroupsController.php database/seeders/UserSeeder.php
git add app/Http/Controllers/UsersController.php app/Http/Controllers/OrganizationsController.php app/Http/Controllers/GroupsController.php database/seeders/UserSeeder.php tests/Feature/Organizations/RestoreAndLinkTest.php
git commit -m "feat: restore-and-link trashed accounts by email; guard group deletion"
```

---

### Task 7: Soft-delete-aware `monitor:delete` override + vendor-command guardrail note

**Files:**
- Create: `app/Console/Commands/DeleteMonitor.php`
- Modify: `app/Providers/AppServiceProvider.php` (rebind `command.monitor:delete`)
- Modify: `config/uptime-monitor.php` (guardrail comment at `monitor_model`)
- Test: `tests/Feature/Organizations/DeleteMonitorCommandTest.php`

**Interfaces:**
- Consumes: SoftDeletes on Monitor; `config('uptime-monitor.monitor_model')`.
- Produces: `php artisan monitor:delete {url}` **soft**-deletes through the configured model (vendor version hard-deletes via the base Spatie model and would destroy check history). The vendor binds `command.monitor:delete` in its service provider; our app-level rebind wins at resolution time.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class DeleteMonitorCommandTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_monitor_delete_command_soft_deletes_and_keeps_history(): void
    {
        $monitor = Monitor::factory()->forOrganization($this->createOrganization())
            ->create(['url' => 'https://cli-delete.test']);
        app(MonitorCheckLogService::class)->logCheck(
            monitor: $monitor,
            checkType: MonitorCheckLogService::CHECK_TYPE_UPTIME,
            status: MonitorCheckLogService::STATUS_SUCCESS,
        );

        $this->artisan('monitor:delete', ['url' => 'https://cli-delete.test'])
            ->expectsConfirmation('Are you sure you want stop monitoring https://cli-delete.test?', 'yes')
            ->assertSuccessful();

        $this->assertSoftDeleted($monitor);
        $this->assertDatabaseCount('monitor_check_logs', 1); // history preserved
    }

    public function test_monitor_delete_command_reports_unknown_url(): void
    {
        $this->artisan('monitor:delete', ['url' => 'https://nope.test'])
            ->expectsOutputToContain('is not configured')
            ->assertFailed();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DeleteMonitorCommandTest`
Expected: FAIL — the vendor command hard-deletes: `assertSoftDeleted` fails (row missing) and `monitor_check_logs` count is 0. (If the confirmation text differs, match the vendor's exact string.)

- [ ] **Step 3: Create the override command**

`app/Console/Commands/DeleteMonitor.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Overrides spatie/laravel-uptime-monitor's monitor:delete, which hardcodes
 * the base Spatie model and issues a HARD delete (destroying check history
 * and bypassing the soft-delete retention window). This version resolves the
 * configured monitor model, so delete() is a soft delete.
 */
class DeleteMonitor extends Command
{
    protected $signature = 'monitor:delete {url}';

    protected $description = 'Soft-delete a monitor (restorable until the retention purge)';

    public function handle(): int
    {
        $modelClass = config('uptime-monitor.monitor_model');
        $url = $this->argument('url');

        $monitor = $modelClass::where('url', $url)->first();

        if (! $monitor) {
            $this->error("Monitor {$url} is not configured");

            return self::FAILURE;
        }

        if ($this->confirm("Are you sure you want stop monitoring {$monitor->url}?")) {
            $monitor->delete();

            $this->warn("{$monitor->url} will not be monitored anymore");
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Rebind the vendor's container binding**

In `app/Providers/AppServiceProvider.php` `register()`, after the existing singleton, add:

```php
        // The vendor command hard-deletes via the base Spatie model; rebind it
        // to our soft-delete-aware version (app providers register after
        // package providers, so this binding wins at resolution time).
        $this->app->bind('command.monitor:delete', \App\Console\Commands\DeleteMonitor::class);
```

- [ ] **Step 5: Add the guardrail comment**

In `config/uptime-monitor.php`, directly above the `'monitor_model' => ...` line, add:

```php
    /*
     * NOTE: the vendor commands monitor:create and monitor:sync-file operate on
     * the BASE Spatie model — they bypass organization assignment AND soft
     * deletes (sync-file --delete-missing HARD-deletes, destroying check
     * history). Manage monitors through the app UI; monitor:delete is
     * overridden in-app to soft-delete.
     */
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=DeleteMonitorCommandTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS — 146 tests.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Console/Commands/DeleteMonitor.php app/Providers/AppServiceProvider.php
git add app/Console/Commands/DeleteMonitor.php app/Providers/AppServiceProvider.php config/uptime-monitor.php tests/Feature/Organizations/DeleteMonitorCommandTest.php
git commit -m "feat: soft-delete-aware monitor:delete override"
```

---

## Final verification

- [ ] `php artisan test` → all green (~146).
- [ ] `export PATH="/Users/vaibhav/.nvm/versions/node/v22.22.2/bin:$PATH" && npm run build` → succeeds; `npm run test:js` → 68 passing.
- [ ] Smoke (tinker): create 2 orgs with monitors, `OrganizationDeletionService->delete()` one, then `php artisan monitor:check-uptime` — only the live org's monitors are checked; `organizations:purge-deleted --dry-run` lists the trashed org.
- [ ] Manual: super-admin deletes an org (confirm dialog) → it moves to the Deleted section with a countdown → Restore brings it back; a deleted sole-org member cannot log in until restore.

## Self-review notes (for the author)

- **Spec coverage:** §4 schema → Tasks 1–2; §5 service (delete/restore/purge + marker + collision rules) → Tasks 3–4; §6 command/schedule/config → Task 4; §7 controller/UX/email-link/group guard → Tasks 5–6; §8 CLI guardrail → Task 7; §9 behavior notes need no code; §10 testing → distributed per task + Final verification; §12 MySQL ≥ 8.0.13 noted in Task 2.
- **Type consistency:** service API `delete(Organization): void` / `restore(Organization): array{skipped_monitors}` / `purge(Organization): void`; exception `OrganizationRestoreBlockedException`; config key `organizations.purge_after_days`; command `organizations:purge-deleted {--older-than-days=} {--dry-run}`; routes `organizations.destroy` / `organizations.restore`; Inertia props `deletedOrganizations` / `purgeAfterDays` — used identically across tasks.
- **Ordering-sensitive details flagged inline:** restore() restores the org before the users subquery; purge trims leaf tables outside the transaction; `toBase()` avoids Spatie's Url accessor in plucks; `whereHas` in purge adds `withTrashed()` for the already-trashed org.
- **Adversarial-review fixes incorporated:** (1) purge()'s user query filters on the org's cascade marker — without it, purging org B could permanently destroy a user cascaded with org A inside A's restore window (HIGH); (2) orphan pass in the purge command — individually deleted monitors/groups of live orgs would otherwise be retained forever; (3) delete()/restore() are idempotent and purge() refuses live orgs (state guards); (4) restore()'s slug check excludes self (double-submit showed a bogus conflict); (5) Task 1/6 tests hardened with `assertSoftDeleted` so they genuinely fail-before (hard deletes produced identical observable behavior).
- **Accepted limitation (documented in spec §9):** the cascade marker has second precision — two org deletions within the same wall-clock second could conflate their users in restore/purge matching. Bulk same-second deletions don't occur in the UI; accepted for v1.
