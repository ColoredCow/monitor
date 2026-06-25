# Organization Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the monitor app multi-tenant — every monitor/group belongs to an `Organization`, users see only their active organization's dashboard, with org-admin/member roles and a platform super-admin tier — without breaking the background uptime/cert/domain checks.

**Architecture:** A many-to-many `organization_user` membership pivot carries a per-membership role (`admin`/`member`). The active org lives in the session and is resolved per web request by middleware into a request-scoped `CurrentOrganization` service. Monitors and groups carry a direct `organization_id`; web paths scope explicitly via a `forOrganization()` query scope and org-scoped route-model binding, while **no global scope** is added — so console/scheduled commands keep enumerating every monitor across all orgs.

**Tech Stack:** Laravel 12 (PHP 8.2), Inertia + React (JSX), Ziggy `route()` helper, Tailwind, Spatie Uptime Monitor, PHPUnit 11 (MySQL `monitor_test`, `RefreshDatabase`).

**Spec:** [plans/2026-06-25-organization-dashboard-design.md](2026-06-25-organization-dashboard-design.md) · **Issue:** [#23](https://github.com/ColoredCow/monitor/issues/23)

## Global Constraints

- **No global Eloquent scope** on `Monitor` or `Group`. Scoping is applied explicitly on web paths only. Console paths must keep seeing all orgs' monitors. (Spatie's `MonitorRepository::query()` runs in console with no session.)
- **Roles** are exactly two strings: `Organization::ROLE_ADMIN = 'admin'` and `Organization::ROLE_MEMBER = 'member'`. Use these constants everywhere — never bare strings.
- **Session key** for the active org is exactly `'active_organization_id'`.
- **Super-admin** is the boolean `users.is_super_admin`; `Gate::before` grants super-admins every ability.
- Admin-created users are created **email-verified** (`email_verified_at => now()`) so they pass the `verified` middleware.
- Run `./vendor/bin/pint` on changed PHP files before each commit. Run tests with `php artisan test`.
- Migration timestamp prefixes continue after the latest existing migration (`2026_02_14_101500_*`); use the `2026_06_25_0001xx` range in the order given.

---

### Task 1: Organization model, tables, and factory

**Files:**
- Create: `database/migrations/2026_06_25_000100_create_organizations_table.php`
- Create: `database/migrations/2026_06_25_000110_create_organization_user_table.php`
- Create: `app/Models/Organization.php`
- Create: `database/factories/OrganizationFactory.php`
- Test: `tests/Feature/Organizations/OrganizationModelTest.php`

**Interfaces:**
- Produces: `App\Models\Organization` with consts `ROLE_ADMIN='admin'`, `ROLE_MEMBER='member'`; relations `monitors(): HasMany`, `groups(): HasMany`, `users(): BelongsToMany` (pivot `role`, timestamps). Table `organizations(id, name, slug unique, timestamps)`. Pivot `organization_user(id, organization_id, user_id, role, timestamps, unique(organization_id,user_id))`. `OrganizationFactory` (default `name`, unique `slug`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_has_users_with_roles(): void
    {
        $organization = Organization::factory()->create(['name' => 'Acme']);
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);

        $this->assertNotEmpty($organization->slug);
        $this->assertCount(2, $organization->users);
        $this->assertSame(
            Organization::ROLE_ADMIN,
            $organization->users()->whereKey($admin->id)->first()->pivot->role
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrganizationModelTest`
Expected: FAIL — `Class "App\Models\Organization" not found`.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_06_25_000100_create_organizations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
```

`database/migrations/2026_06_25_000110_create_organization_user_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
```

- [ ] **Step 4: Create the model**

`app/Models/Organization.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    protected $fillable = ['name', 'slug'];

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
```

- [ ] **Step 5: Create the factory**

`database/factories/OrganizationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OrganizationModelTest`
Expected: PASS (2 assertions across the test).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Models/Organization.php database/factories/OrganizationFactory.php
git add app/Models/Organization.php database/factories/OrganizationFactory.php database/migrations/2026_06_25_0001*_*.php tests/Feature/Organizations/OrganizationModelTest.php
git commit -m "feat: add Organization model, tables, and factory"
```

---

### Task 2: User memberships, super-admin flag, and test helpers

**Files:**
- Create: `database/migrations/2026_06_25_000120_add_is_super_admin_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Create: `tests/Concerns/InteractsWithOrganizations.php`
- Test: `tests/Feature/Organizations/UserMembershipTest.php`

**Interfaces:**
- Consumes: `Organization` + `OrganizationFactory` (Task 1).
- Produces:
  - `users.is_super_admin` boolean (default false), cast to bool.
  - `User::organizations(): BelongsToMany` (pivot `role`); `User::isSuperAdmin(): bool`; `User::hasRoleInOrganization(Organization|int $organization, string $role): bool`; `User::isAdminOf(Organization|int $organization): bool`.
  - `UserFactory::superAdmin()` state.
  - Trait `Tests\Concerns\InteractsWithOrganizations` with: `createOrganization(array $attrs = []): Organization`, `actingAsAdmin(Organization $org): User`, `actingAsMember(Organization $org): User`, `actingAsSuperAdmin(): User`. Each `actingAs*` logs in the user **and** sets `session(['active_organization_id' => $org->id])` (for admin/member).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class UserMembershipTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_role_helpers(): void
    {
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);

        $this->assertTrue($admin->isAdminOf($organization));
        $this->assertFalse($member->isAdminOf($organization));
        $this->assertTrue($member->hasRoleInOrganization($organization, Organization::ROLE_MEMBER));
    }

    public function test_super_admin_flag(): void
    {
        $this->assertFalse(User::factory()->create()->isSuperAdmin());
        $this->assertTrue(User::factory()->superAdmin()->create()->isSuperAdmin());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserMembershipTest`
Expected: FAIL — `Call to undefined method App\Models\User::isAdminOf()` (and missing trait).

- [ ] **Step 3: Create the migration**

`database/migrations/2026_06_25_000120_add_is_super_admin_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });
    }
};
```

- [ ] **Step 4: Update the User model**

Replace `app/Models/User.php` with:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function hasRoleInOrganization(Organization|int $organization, string $role): bool
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->organizations()
            ->where('organizations.id', $organizationId)
            ->wherePivot('role', $role)
            ->exists();
    }

    public function isAdminOf(Organization|int $organization): bool
    {
        return $this->hasRoleInOrganization($organization, Organization::ROLE_ADMIN);
    }
}
```

- [ ] **Step 5: Add the factory state**

In `database/factories/UserFactory.php`, add this method inside the class (after `definition()`):

```php
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }
```

- [ ] **Step 6: Create the test helper trait**

`tests/Concerns/InteractsWithOrganizations.php`:

```php
<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\User;

trait InteractsWithOrganizations
{
    protected function createOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    protected function actingAsAdmin(Organization $organization): User
    {
        return $this->actingAsMemberWithRole($organization, Organization::ROLE_ADMIN);
    }

    protected function actingAsMember(Organization $organization): User
    {
        return $this->actingAsMemberWithRole($organization, Organization::ROLE_MEMBER);
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user);

        return $user;
    }

    private function actingAsMemberWithRole(Organization $organization, string $role): User
    {
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user);
        session(['active_organization_id' => $organization->id]);

        return $user;
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=UserMembershipTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Models/User.php database/factories/UserFactory.php tests/Concerns/InteractsWithOrganizations.php
git add app/Models/User.php database/factories/UserFactory.php database/migrations/2026_06_25_000120_*.php tests/Concerns/InteractsWithOrganizations.php tests/Feature/Organizations/UserMembershipTest.php
git commit -m "feat: add user-organization memberships, roles, and super-admin flag"
```

---

### Task 3: CurrentOrganization request-scoped service

**Files:**
- Create: `app/Support/CurrentOrganization.php`
- Modify: `app/Providers/AppServiceProvider.php` (register binding)
- Test: `tests/Feature/Organizations/CurrentOrganizationTest.php`

**Interfaces:**
- Produces: `App\Support\CurrentOrganization` bound as a container **singleton**, with `set(?Organization $organization): void`, `get(): ?Organization`, `id(): ?int` (null when unset), and a **pure** `resolveFor(User $user, ?int $sessionOrgId): ?Organization` (validates the session org against membership/super-admin, else falls back to the user's first org — performs **no** session writes). `resolveFor` is the single resolution path shared by the middleware (Task 5) and the route binding (Task 6), which is what makes the feature independent of middleware ordering.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_null_and_can_be_set(): void
    {
        $current = app(CurrentOrganization::class);
        $this->assertNull($current->get());
        $this->assertNull($current->id());

        $organization = Organization::factory()->create();
        $current->set($organization);

        $this->assertTrue($organization->is(app(CurrentOrganization::class)->get()));
        $this->assertSame($organization->id, app(CurrentOrganization::class)->id());
    }

    public function test_resolve_for_honors_membership_and_falls_back(): void
    {
        $current = app(CurrentOrganization::class);
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $user = User::factory()->create();
        $orgA->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        // Valid session org the user belongs to.
        $this->assertSame($orgA->id, $current->resolveFor($user, $orgA->id)?->id);
        // Stale/foreign session org -> falls back to a real membership.
        $this->assertSame($orgA->id, $current->resolveFor($user, $orgB->id)?->id);
        // No session org -> first membership.
        $this->assertSame($orgA->id, $current->resolveFor($user, null)?->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CurrentOrganizationTest`
Expected: FAIL — `Class "App\Support\CurrentOrganization" not found`.

- [ ] **Step 3: Create the service**

`app/Support/CurrentOrganization.php`:

```php
<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    /**
     * Pure resolution: validate the session org against the user's
     * memberships (super-admins may use any org), else fall back to the
     * user's first org by name. Performs NO session writes — callers
     * decide whether to persist. Shared by the middleware and route binding
     * so resolution is identical regardless of middleware ordering.
     */
    public function resolveFor(User $user, ?int $sessionOrgId): ?Organization
    {
        if ($sessionOrgId) {
            $candidate = Organization::find($sessionOrgId);
            if ($candidate && ($user->isSuperAdmin() || $user->organizations()->whereKey($candidate->id)->exists())) {
                return $candidate;
            }
        }

        return $user->isSuperAdmin()
            ? Organization::orderBy('name')->first()
            : $user->organizations()->orderBy('name')->first();
    }
}
```

- [ ] **Step 4: Register the singleton**

In `app/Providers/AppServiceProvider.php`, replace the `register()` body:

```php
    public function register()
    {
        $this->app->singleton(\App\Support\CurrentOrganization::class);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CurrentOrganizationTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint app/Support/CurrentOrganization.php app/Providers/AppServiceProvider.php
git add app/Support/CurrentOrganization.php app/Providers/AppServiceProvider.php tests/Feature/Organizations/CurrentOrganizationTest.php
git commit -m "feat: add CurrentOrganization request-scoped service"
```

---

### Task 4: organization_id on monitors/groups + BelongsToOrganization trait + factories

**Files:**
- Create: `database/migrations/2026_06_25_000130_add_organization_id_to_monitors_table.php`
- Create: `database/migrations/2026_06_25_000140_add_organization_id_to_groups_table.php`
- Create: `app/Models/Concerns/BelongsToOrganization.php`
- Modify: `app/Models/Monitor.php` (use trait + `organization()` already provided by trait)
- Modify: `app/Models/Group.php` (use trait, add `organization_id` to fillable)
- Create: `database/factories/MonitorFactory.php`
- Create: `database/factories/GroupFactory.php`
- Test: `tests/Feature/Organizations/BelongsToOrganizationTest.php`

**Interfaces:**
- Consumes: `CurrentOrganization` (Task 3), `Organization` (Task 1).
- Produces:
  - Nullable `monitors.organization_id` and `groups.organization_id` (FK, indexed).
  - Trait `App\Models\Concerns\BelongsToOrganization`: `organization(): BelongsTo`, `scopeForOrganization(Builder $query, int $organizationId): Builder`, and a `creating` hook that fills `organization_id` from `CurrentOrganization::id()` when empty and a current org is bound.
  - `MonitorFactory` and `GroupFactory`, each defaulting `organization_id` to a fresh `Organization::factory()` and exposing `forOrganization(Organization $org): static`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BelongsToOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_filters_by_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        Monitor::factory()->forOrganization($orgA)->create();
        Monitor::factory()->forOrganization($orgB)->create();

        $this->assertCount(1, Monitor::forOrganization($orgA->id)->get());
    }

    public function test_creating_hook_fills_bound_organization(): void
    {
        $organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($organization);

        $monitor = Monitor::create(['url' => 'https://hooked.test', 'name' => 'Hooked']);

        $this->assertSame($organization->id, $monitor->organization_id);
    }

    public function test_no_global_scope_leaks_into_console_context(): void
    {
        // No CurrentOrganization bound (simulates console/scheduler).
        Monitor::factory()->forOrganization(Organization::factory()->create())->create();
        Monitor::factory()->forOrganization(Organization::factory()->create())->create();

        $this->assertCount(2, Monitor::all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BelongsToOrganizationTest`
Expected: FAIL — `Call to undefined method ...::forOrganization()` / missing `MonitorFactory`.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_06_25_000130_add_organization_id_to_monitors_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
```

`database/migrations/2026_06_25_000140_add_organization_id_to_groups_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
```

- [ ] **Step 4: Create the trait**

`app/Models/Concerns/BelongsToOrganization.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->organization_id)) {
                $organizationId = app(CurrentOrganization::class)->id();
                if ($organizationId !== null) {
                    $model->organization_id = $organizationId;
                }
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where($this->getTable().'.organization_id', $organizationId);
    }
}
```

- [ ] **Step 5: Wire the trait into Monitor and Group**

In `app/Models/Monitor.php`, add the import and `use` the trait inside the class:

```php
use App\Models\Concerns\BelongsToOrganization;
```

and inside the class body (top of class):

```php
    use BelongsToOrganization;
```

(`Monitor` extends Spatie's model which is unguarded, so `organization_id` is mass-assignable.)

Replace `app/Models/Group.php` with:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = ['name', 'organization_id'];

    public function monitors()
    {
        return $this->hasMany(Monitor::class);
    }
}
```

- [ ] **Step 6: Create the factories**

`database/factories/MonitorFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    protected $model = Monitor::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->domainWord(),
            'url' => 'https://'.fake()->unique()->domainName(),
            'uptime_check_enabled' => true,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }
}
```

`database/factories/GroupFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->word(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=BelongsToOrganizationTest`
Expected: PASS (3 tests). The third proves no global scope was added.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Models/ database/factories/MonitorFactory.php database/factories/GroupFactory.php
git add app/Models/Concerns/BelongsToOrganization.php app/Models/Monitor.php app/Models/Group.php database/migrations/2026_06_25_0001[34]0_*.php database/factories/MonitorFactory.php database/factories/GroupFactory.php tests/Feature/Organizations/BelongsToOrganizationTest.php
git commit -m "feat: add organization_id to monitors/groups with explicit scoping trait"
```

---

### Task 5: Active-org resolution — middleware, login hook, no-org page, Inertia props

**Files:**
- Create: `app/Http/Middleware/SetActiveOrganization.php`
- Modify: `bootstrap/app.php` (register `active.organization` alias)
- Modify: `routes/web.php` (wrap app routes; add no-org route)
- Modify: `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (set session org on login)
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (share org props)
- Create: `resources/js/Pages/NoOrganization.jsx`
- Test: `tests/Feature/Organizations/ActiveOrganizationTest.php`
- Modify (test migration — see Step 9): `tests/Feature/MonitorHistory/MonitorHistoryShowTest.php`, `MonitorHistorySummaryTest.php`, `MonitorHistoryGraphTest.php`, `MonitorHistoryRecentChecksTest.php`

**Interfaces:**
- Consumes: `CurrentOrganization`, `Organization`, `User` helpers.
- Produces:
  - Middleware alias `active.organization` → `SetActiveOrganization`. It binds `CurrentOrganization` from `session('active_organization_id')`, validating membership (super-admins may use any org; fall back to first org). Non-super-admin with no usable org → redirect to `route('no-organization')`. Super-admin with no org at all → redirect to `route('organizations.index')` (defined in Task 10).
  - Route `GET /no-organization` name `no-organization` (renders Inertia `NoOrganization`).
  - On login, `session('active_organization_id')` is set to the user's first org (by name).
  - Inertia shared `auth` is a **closure** resolving `{ user, isSuperAdmin, organizations:[{id,name,role}], activeOrganization:{id,name}|null }` at render time (after middleware).

> **Ordering note (verified on Laravel 12.48):** `SetActiveOrganization` is a route-level alias and runs *after* `SubstituteBindings` and Inertia's `share()`. We deliberately do **not** fight the priority list. Instead the design is ordering-independent: the binding (Task 6) calls `CurrentOrganization::resolveFor()` itself, the `auth` prop is a lazy closure (evaluated post-middleware), and policies (Task 7) re-check ownership in the controller (which always runs after all middleware).
>
> `route('organizations.index')` is created in Task 10. Until then the super-admin-with-zero-orgs branch can't be exercised; the test below covers the member path and the stale-org fallback only.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ActiveOrganizationTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_login_sets_first_organization_as_active(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertSame($organization->id, session('active_organization_id'));
    }

    public function test_user_without_organization_is_redirected_to_no_org_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/monitors')->assertRedirect(route('no-organization'));
    }

    public function test_stale_active_org_falls_back_to_a_membership(): void
    {
        $organization = $this->createOrganization();
        $user = User::factory()->create();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_MEMBER]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => 999999])
            ->get('/monitors')
            ->assertOk();

        $this->assertSame($organization->id, session('active_organization_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActiveOrganizationTest`
Expected: FAIL — `Route [no-organization] not defined` / `/monitors` not redirecting.

- [ ] **Step 3: Create the middleware**

`app/Http/Middleware/SetActiveOrganization.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActiveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $current = app(CurrentOrganization::class);

        $organization = $current->resolveFor($user, $request->session()->get('active_organization_id'));

        if (! $organization) {
            return $user->isSuperAdmin()
                ? redirect()->route('organizations.index')
                : redirect()->route('no-organization');
        }

        // Persist the resolved org so it survives the request and corrects any stale value.
        $request->session()->put('active_organization_id', $organization->id);
        $current->set($organization);

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

In `bootstrap/app.php`, inside the `withMiddleware` closure (after the `$middleware->web(...)` call), add:

```php
        $middleware->alias([
            'active.organization' => \App\Http\Middleware\SetActiveOrganization::class,
        ]);
```

- [ ] **Step 5: Restructure web routes**

Replace the route group in `routes/web.php` (lines 20-26) with:

```php
Route::permanentRedirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/no-organization', fn () => \Inertia\Inertia::render('NoOrganization'))
        ->name('no-organization');

    Route::middleware('active.organization')->group(function () {
        Route::resource('monitors', MonitorsController::class);
        Route::resource('groups', GroupsController::class);
        Route::resource('users', UsersController::class);
    });
});
```

- [ ] **Step 6: Set the active org on login**

In `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, change `store()` to:

```php
    public function store(LoginRequest $request)
    {
        $request->authenticate();

        $request->session()->regenerate();

        $organization = $request->user()->organizations()->orderBy('name')->first();
        if ($organization) {
            $request->session()->put('active_organization_id', $organization->id);
        }

        return redirect()->intended('/monitors');
    }
```

- [ ] **Step 7: Share organization props with Inertia**

Replace the `share()` method in `app/Http/Middleware/HandleInertiaRequests.php`:

```php
    public function share(Request $request)
    {
        // `auth` is a CLOSURE so Inertia resolves it at response-render time —
        // i.e. AFTER SetActiveOrganization has run and bound CurrentOrganization.
        // (share() itself runs early in the middleware pipeline, before route
        // middleware, so reading the resolved org eagerly here would be null.)
        return array_merge(parent::share($request), [
            'auth' => function () use ($request) {
                $user = $request->user();
                $active = app(\App\Support\CurrentOrganization::class)->get();

                return [
                    'user' => $user,
                    'isSuperAdmin' => (bool) $user?->isSuperAdmin(),
                    'organizations' => $user
                        ? $user->organizations()->orderBy('name')->get()
                            ->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'role' => $o->pivot->role])
                            ->values()
                        : [],
                    'activeOrganization' => $active
                        ? ['id' => $active->id, 'name' => $active->name]
                        : null,
                ];
            },
            'features' => [
                'monitorHistory' => config('monitor-history.enabled'),
            ],
        ]);
    }
```

- [ ] **Step 8: Create the no-organization page**

`resources/js/Pages/NoOrganization.jsx`:

```jsx
import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";

export default function NoOrganization() {
    const { auth } = usePage().props;

    return (
        <Authenticated auth={auth}>
            <Head title="No organization" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                    No organization yet
                </h1>
            </PageHeader>
            <div className="max-w-xl mx-auto py-12 px-6 text-center text-gray-600">
                You're not a member of any organization yet. Please contact your
                administrator to be added to one.
            </div>
        </Authenticated>
    );
}
```

- [ ] **Step 9: Migrate the MonitorHistory HTTP test suites (keep the suite green)**

Wrapping `/monitors/*` in `active.organization` means any **authenticated HTTP** test now needs the acting user to be a member of an org with that org active in the session, and the monitor under test must belong to that same org. Four suites make authenticated requests to monitor routes and must be migrated now (the non-HTTP service/command suites — `MonitorCheckLogServiceTest`, `MonitorHistoryFeatureFlagTest`, `MonitorDailyCheckMetricsAggregatorTest`, `BackfillMonitorCheckHistoryTest` — call services/commands directly with no HTTP request and are handled in Task 12):

- `tests/Feature/MonitorHistory/MonitorHistoryShowTest.php`
- `tests/Feature/MonitorHistory/MonitorHistorySummaryTest.php`
- `tests/Feature/MonitorHistory/MonitorHistoryGraphTest.php`
- `tests/Feature/MonitorHistory/MonitorHistoryRecentChecksTest.php`

In each of these files apply this pattern:
  1. Add `use Tests\Concerns\InteractsWithOrganizations;` (trait) and `use App\Models\Organization;`.
  2. In `setUp()` (create one if absent, calling `parent::setUp()` first), add `$this->organization = Organization::factory()->create();` and declare a `protected $organization;` property.
  3. In the `makeMonitor()` helper's base/default attributes, set `'organization_id' => $this->organization->id`.
  4. Replace each `$user = User::factory()->create();` + `$this->actingAs($user)` with `$this->actingAsMember($this->organization);` (use `actingAsAdmin` only if the test exercises a write). `actingAsMember` both attaches membership and sets `session('active_organization_id')`.
  5. **Leave the guest test untouched** — `MonitorHistoryShowTest`'s "guests cannot access" test must still expect the login redirect.

- [ ] **Step 10: Run the targeted and full suites to verify green**

Run: `php artisan test --filter=ActiveOrganizationTest`
Expected: PASS (3 tests).

Run: `php artisan test`
Expected: PASS — entire suite green, including the migrated MonitorHistory HTTP tests. (The non-HTTP MonitorHistory suites still create org-less monitors; that's fine while `organization_id` is nullable — Task 12 finishes them.)

- [ ] **Step 11: Commit**

```bash
./vendor/bin/pint app/Http/Middleware/SetActiveOrganization.php app/Http/Middleware/HandleInertiaRequests.php app/Http/Controllers/Auth/AuthenticatedSessionController.php bootstrap/app.php
git add app/Http/Middleware/SetActiveOrganization.php bootstrap/app.php routes/web.php app/Http/Controllers/Auth/AuthenticatedSessionController.php app/Http/Middleware/HandleInertiaRequests.php resources/js/Pages/NoOrganization.jsx tests/Feature/Organizations/ActiveOrganizationTest.php tests/Feature/MonitorHistory/
git commit -m "feat: resolve and share the active organization per request"
```

---

### Task 6: Scope route-model binding and controller queries

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (scoped bindings for `monitor`, `group`)
- Modify: `app/Http/Controllers/MonitorsController.php` (`index`, `create`, `edit` scoping)
- Modify: `app/Http/Controllers/GroupsController.php` (`index` scoping)
- Modify: `app/Http/Requests/MonitorRequest.php` (org-scoped group rule)
- Test: `tests/Feature/Organizations/TenantIsolationTest.php`

**Interfaces:**
- Consumes: `CurrentOrganization`, `forOrganization()` scope, factories, `InteractsWithOrganizations`.
- Produces: `{monitor}` and `{group}` route params resolve **within the active org** (cross-org id ⇒ 404). `MonitorsController@index/create/edit` and `GroupsController@index` only ever load the active org's records. `MonitorRequest` rejects a `monitorGroupId` from another org.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Group;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_index_only_shows_active_org_monitors(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $mine = Monitor::factory()->forOrganization($orgA)->create(['name' => 'Mine']);
        Monitor::factory()->forOrganization($orgB)->create(['name' => 'Theirs']);

        $this->actingAsMember($orgA);

        $this->get('/monitors')
            ->assertInertia(fn ($page) => $page
                ->component('Monitors/Index')
                ->where('groups.0.monitors.0.name', 'Mine'));
    }

    public function test_cannot_open_another_orgs_monitor(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $theirs = Monitor::factory()->forOrganization($orgB)->create();

        $this->actingAsMember($orgA);

        $this->get("/monitors/{$theirs->id}")->assertNotFound();
    }

    public function test_cannot_open_another_orgs_group(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $theirs = Group::factory()->forOrganization($orgB)->create();

        $this->actingAsMember($orgA);

        $this->get("/groups/{$theirs->id}")->assertNotFound();
    }
}
```

> The first test assumes ungrouped monitors render under an "Ungrouped Monitors" pseudo-group at `groups.0`. If the monitor under test is grouped, adjust the assertion path; here `Mine` is ungrouped so it lands in the pushed group at index 0.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TenantIsolationTest`
Expected: FAIL — cross-org monitor/group resolve (200, not 404) and index shows both orgs.

- [ ] **Step 3: Add scoped route-model bindings**

In `app/Providers/AppServiceProvider.php`, add to the top `use` block:

```php
use App\Models\Group;
use App\Models\Monitor;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Route;
```

and append to `boot()`:

```php
        // {monitor} and {group} are ALWAYS behind the active.organization group,
        // so a missing org here is a bug -> 404. We resolve the org INSIDE the
        // binding (not relying on a bound CurrentOrganization) because the
        // SubstituteBindings middleware runs before SetActiveOrganization.
        $resolveOrganizationId = function (): int {
            $user = request()->user();
            abort_if($user === null, 404);

            $current = app(CurrentOrganization::class);
            $organization = $current->get()
                ?? $current->resolveFor($user, session('active_organization_id'));
            abort_if($organization === null, 404);

            return $organization->id;
        };

        Route::bind('monitor', fn ($value) => Monitor::forOrganization($resolveOrganizationId())->findOrFail($value));
        Route::bind('group', fn ($value) => Group::forOrganization($resolveOrganizationId())->findOrFail($value));
```

- [ ] **Step 4: Scope the MonitorsController read queries**

In `app/Http/Controllers/MonitorsController.php`, add the import:

```php
use App\Support\CurrentOrganization;
```

Replace `index()` body (lines 33-55) with:

```php
    public function index()
    {
        $organizationId = app(CurrentOrganization::class)->id();

        $groups = Group::forOrganization($organizationId)
            ->with(['monitors' => function ($query) use ($organizationId) {
                $query->forOrganization($organizationId)->orderBy('name');
            }])
            ->has('monitors')
            ->orderBy('name')->get();

        $monitorWithNoGroups = Monitor::forOrganization($organizationId)
            ->whereNull('group_id')->orderBy('name')->get();

        if ($monitorWithNoGroups->count()) {
            $groups = collect($groups);
            $groups->push([
                'id' => null,
                'name' => 'Ungrouped Monitors',
                'monitors' => $monitorWithNoGroups,
            ]);
        }

        return Inertia::render('Monitors/Index', [
            'groups' => $groups,
        ]);
    }
```

In `create()` replace `Group::orderBy('name')->get()` with:

```php
        $groups = Group::forOrganization(app(CurrentOrganization::class)->id())->orderBy('name')->get();
```

In `edit()` replace `Group::orderBy('name')->get()` with the same scoped query:

```php
        $groups = Group::forOrganization(app(CurrentOrganization::class)->id())->orderBy('name')->get();
```

(`store()` needs no change: the `BelongsToOrganization` creating-hook fills `organization_id` from the bound current org.)

- [ ] **Step 5: Scope the GroupsController index**

In `app/Http/Controllers/GroupsController.php`, add:

```php
use App\Support\CurrentOrganization;
```

and replace the `index()` query:

```php
    public function index()
    {
        return Inertia::render('Groups/Index', [
            'groups' => Group::forOrganization(app(CurrentOrganization::class)->id())
                ->with('monitors')->orderBy('name')->get(),
        ]);
    }
```

- [ ] **Step 6: Reject cross-org groups in MonitorRequest**

Replace `app/Http/Requests/MonitorRequest.php` `rules()` with:

```php
    public function rules(): array
    {
        $organizationId = app(\App\Support\CurrentOrganization::class)->id();

        return [
            'name' => 'required|string',
            'url' => 'required|url',
            'monitorUptime' => 'required',
            'monitorDomain' => 'required',
            'uptimeCheckInterval' => 'required',
            'monitorGroupId' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('groups', 'id')
                    ->where('organization_id', $organizationId),
            ],
        ];
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=TenantIsolationTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Run the full suite to catch regressions**

Run: `php artisan test`
Expected: PASS. The MonitorHistory HTTP suites were already migrated in Task 5 (they now act as org members), so the scoped binding resolves their monitors correctly. The non-HTTP MonitorHistory suites don't resolve `{monitor}` route params, so they're unaffected here.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint app/Providers/AppServiceProvider.php app/Http/Controllers/MonitorsController.php app/Http/Controllers/GroupsController.php app/Http/Requests/MonitorRequest.php
git add app/Providers/AppServiceProvider.php app/Http/Controllers/MonitorsController.php app/Http/Controllers/GroupsController.php app/Http/Requests/MonitorRequest.php tests/Feature/Organizations/TenantIsolationTest.php
git commit -m "feat: scope monitor/group binding and queries to the active organization"
```

---

### Task 7: Authorization — super-admin gate, role gates, and policies

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (`Gate::before` + gates)
- Create: `app/Policies/MonitorPolicy.php`
- Create: `app/Policies/GroupPolicy.php`
- Modify: `app/Http/Controllers/MonitorsController.php` (`authorize` in mutating actions)
- Modify: `app/Http/Controllers/GroupsController.php` (`authorize` in mutating actions)
- Test: `tests/Feature/Organizations/RoleAuthorizationTest.php`

**Interfaces:**
- Consumes: `CurrentOrganization`, `User::isAdminOf`, `User::isSuperAdmin`.
- Produces:
  - `Gate::before` returns `true` for super-admins.
  - Gates `manage-organizations` (defined as always-false → only super-admins pass via `before`) and `manage-org-users` (true when the user is admin of the active org).
  - `MonitorPolicy` / `GroupPolicy`: `viewAny` → true; `view` → model belongs to the active org; `create` → admin of the active org; `update`/`delete` → admin of the active org **and** model belongs to it (ownership re-check is an independent isolation barrier per spec §7).
  - `MonitorsController` and `GroupsController` call `$this->authorize(...)` in `show`/`create`/`store`/`edit`/`update`/`destroy`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Example',
            'url' => 'https://example-new.test',
            'monitorUptime' => true,
            'monitorDomain' => false,
            'uptimeCheckInterval' => 5,
            'monitorGroupId' => null,
        ], $overrides);
    }

    public function test_member_cannot_create_monitor(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsMember($organization);

        $this->post('/monitors', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_admin_can_create_monitor(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->post('/monitors', $this->payload())->assertRedirect(route('monitors.index'));
        $this->assertDatabaseHas('monitors', [
            'url' => 'https://example-new.test',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_member_cannot_delete_monitor(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        $this->actingAsMember($organization);

        $this->delete("/monitors/{$monitor->id}")->assertForbidden();
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RoleAuthorizationTest`
Expected: FAIL — member create currently succeeds (no authorization yet).

- [ ] **Step 3: Define the gates and super-admin bypass**

In `app/Providers/AppServiceProvider.php`, add to the `use` block:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;
```

and append to `boot()`:

```php
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        Gate::define('manage-organizations', fn (User $user) => false);

        Gate::define('manage-org-users', function (User $user) {
            $organization = app(CurrentOrganization::class)->get();

            return $organization !== null && $user->isAdminOf($organization);
        });
```

- [ ] **Step 4: Create the policies**

`app/Policies/MonitorPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Monitor;
use App\Models\User;
use App\Support\CurrentOrganization;

class MonitorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Monitor $monitor): bool
    {
        return $this->belongsToActiveOrg($monitor);
    }

    public function create(User $user): bool
    {
        return $this->isActiveOrgAdmin($user);
    }

    public function update(User $user, Monitor $monitor): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($monitor);
    }

    public function delete(User $user, Monitor $monitor): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($monitor);
    }

    private function isActiveOrgAdmin(User $user): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && $user->isAdminOf($organization);
    }

    private function belongsToActiveOrg(Monitor $monitor): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && (int) $monitor->organization_id === $organization->id;
    }
}
```

`app/Policies/GroupPolicy.php` (identical shape, `Group` instead of `Monitor`):

```php
<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;
use App\Support\CurrentOrganization;

class GroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Group $group): bool
    {
        return $this->belongsToActiveOrg($group);
    }

    public function create(User $user): bool
    {
        return $this->isActiveOrgAdmin($user);
    }

    public function update(User $user, Group $group): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($group);
    }

    public function delete(User $user, Group $group): bool
    {
        return $this->isActiveOrgAdmin($user) && $this->belongsToActiveOrg($group);
    }

    private function isActiveOrgAdmin(User $user): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && $user->isAdminOf($organization);
    }

    private function belongsToActiveOrg(Group $group): bool
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && (int) $group->organization_id === $organization->id;
    }
}
```

(Laravel 12 auto-discovers `App\Policies\MonitorPolicy` for `App\Models\Monitor`, so no manual registration is needed.)

- [ ] **Step 5: Authorize in the controllers**

In `app/Http/Controllers/MonitorsController.php`:
- `show(Request $request, Monitor $monitor)`: add as first line `$this->authorize('view', $monitor);`
- `create()`: add as first line `$this->authorize('create', Monitor::class);`
- `store()`: add as first line `$this->authorize('create', Monitor::class);`
- `edit(Monitor $monitor)`: add as first line `$this->authorize('update', $monitor);`
- `update(MonitorRequest $request, Monitor $monitor)`: add as first line `$this->authorize('update', $monitor);`
- `destroy(Monitor $monitor)`: add as first line `$this->authorize('delete', $monitor);`

In `app/Http/Controllers/GroupsController.php`:
- `show(Group $group)`: `$this->authorize('view', $group);`
- `create()`: `$this->authorize('create', Group::class);`
- `store()`: `$this->authorize('create', Group::class);`
- `edit(Group $group)`: `$this->authorize('update', $group);`
- `update(GroupRequest $request, Group $group)`: `$this->authorize('update', $group);`
- `destroy(Group $group)`: `$this->authorize('delete', $group);`

(Verified: `app/Http/Controllers/Controller.php` already uses `AuthorizesRequests` and extends `Illuminate\Routing\Controller`, so `$this->authorize(...)` is available with no further wiring.)

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=RoleAuthorizationTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint app/Providers/AppServiceProvider.php app/Policies/ app/Http/Controllers/MonitorsController.php app/Http/Controllers/GroupsController.php
git add app/Providers/AppServiceProvider.php app/Policies/MonitorPolicy.php app/Policies/GroupPolicy.php app/Http/Controllers/MonitorsController.php app/Http/Controllers/GroupsController.php tests/Feature/Organizations/RoleAuthorizationTest.php
git commit -m "feat: enforce admin/member authorization with super-admin bypass"
```

---

### Task 8: Organization switcher (endpoint + nav UI)

**Files:**
- Create: `app/Http/Controllers/OrganizationSwitchController.php`
- Modify: `routes/web.php` (add switch route)
- Modify: `resources/js/Layouts/Authenticated.jsx` (desktop + mobile switcher)
- Test: `tests/Feature/Organizations/OrganizationSwitchTest.php`

**Interfaces:**
- Consumes: `Organization`, `auth.organizations`/`auth.activeOrganization` Inertia props.
- Produces: `POST /organizations/switch` name `organizations.switch`, body `organization_id`; sets `session('active_organization_id')` after verifying membership (or super-admin); 403 otherwise; redirects back. Switcher dropdown in the nav, shown when `auth.organizations.length > 1` or `auth.isSuperAdmin`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationSwitchTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_member_can_switch_to_another_membership(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $user = $this->actingAsMember($orgA);
        $orgB->users()->attach($user->id, ['role' => \App\Models\Organization::ROLE_MEMBER]);

        $this->post(route('organizations.switch'), ['organization_id' => $orgB->id])
            ->assertRedirect();

        $this->assertSame($orgB->id, session('active_organization_id'));
    }

    public function test_cannot_switch_to_non_member_org(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        $this->actingAsMember($orgA);

        $this->post(route('organizations.switch'), ['organization_id' => $orgB->id])
            ->assertForbidden();

        $this->assertSame($orgA->id, session('active_organization_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrganizationSwitchTest`
Expected: FAIL — `Route [organizations.switch] not defined`.

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/OrganizationSwitchController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $organization = Organization::findOrFail($validated['organization_id']);

        abort_unless(
            $user->isSuperAdmin() || $user->organizations()->whereKey($organization->id)->exists(),
            403
        );

        $request->session()->put('active_organization_id', $organization->id);

        return back();
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, inside the `['auth', 'verified']` group but **outside** the `active.organization` sub-group, add:

```php
    Route::post('/organizations/switch', OrganizationSwitchController::class)
        ->name('organizations.switch');
```

and add the import at the top:

```php
use App\Http\Controllers\OrganizationSwitchController;
```

- [ ] **Step 5: Add the switcher to the nav**

In `resources/js/Layouts/Authenticated.jsx`, change the function signature to also read page props and post switches:

Replace line 6 import block additions — add at top with the other imports:

```jsx
import { router, usePage } from "@inertiajs/react";
```

(keep the existing `import { Link } from "@inertiajs/react";` or merge into one import line.)

Inside the component, after the `useState` line, add:

```jsx
    const { organizations = [], activeOrganization, isSuperAdmin } =
        usePage().props.auth;

    const switchOrganization = (id) => {
        router.post(route("organizations.switch"), { organization_id: id });
    };

    const showSwitcher = organizations.length > 1 || isSuperAdmin;
```

In the desktop nav, immediately before the existing user `Dropdown` block (line 47-48, the `<div className="hidden sm:flex sm:items-center">`), insert a switcher dropdown:

```jsx
                        {showSwitcher && (
                            <div className="hidden sm:flex sm:items-center mr-2">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 rounded-lg hover:bg-gray-50 transition-colors"
                                        >
                                            <span className="truncate max-w-[12rem]">
                                                {activeOrganization?.name ?? "Select organization"}
                                            </span>
                                            <svg className="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                                                <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                            </svg>
                                        </button>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content>
                                        {organizations.map((org) => (
                                            <button
                                                key={org.id}
                                                type="button"
                                                onClick={() => switchOrganization(org.id)}
                                                className={
                                                    "block w-full text-left px-4 py-2 text-sm transition-colors " +
                                                    (activeOrganization?.id === org.id
                                                        ? "bg-purple-50 text-purple-700 font-medium"
                                                        : "text-gray-700 hover:bg-gray-50")
                                                }
                                            >
                                                {org.name}
                                            </button>
                                        ))}
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        )}
```

In the mobile menu, inside the user profile section (after the `<div className="px-4 flex items-center gap-3 mb-4">...</div>` block at line 158-170, before the Log Out block), add:

```jsx
                        {showSwitcher && (
                            <div className="px-4 pb-3">
                                <div className="text-xs font-semibold text-gray-400 uppercase mb-2">
                                    Organization
                                </div>
                                {organizations.map((org) => (
                                    <button
                                        key={org.id}
                                        type="button"
                                        onClick={() => switchOrganization(org.id)}
                                        className={
                                            "block w-full text-left py-2 text-sm " +
                                            (activeOrganization?.id === org.id
                                                ? "text-purple-700 font-medium"
                                                : "text-gray-700")
                                        }
                                    >
                                        {org.name}
                                    </button>
                                ))}
                            </div>
                        )}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OrganizationSwitchTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Build assets and sanity-check the UI**

Run: `npm run build`
Expected: Vite build succeeds with no errors. (Manually verify the switcher appears when a user belongs to >1 org.)

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/OrganizationSwitchController.php
git add app/Http/Controllers/OrganizationSwitchController.php routes/web.php resources/js/Layouts/Authenticated.jsx tests/Feature/Organizations/OrganizationSwitchTest.php
git commit -m "feat: add organization switcher endpoint and nav UI"
```

---

### Task 9: Disable open self-registration

**Files:**
- Modify: `routes/auth.php` (remove register routes)
- Delete: `app/Http/Controllers/Auth/RegisteredUserController.php`
- Delete: `resources/js/Pages/Auth/Register.jsx`
- Rewrite: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Produces: `GET /register` and `POST /register` both return 404; no `register` named route exists.

- [ ] **Step 1: Rewrite the test to assert registration is gone**

Replace `tests/Feature/Auth/RegistrationTest.php` with:

```php
<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_endpoint_is_disabled(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RegistrationTest`
Expected: FAIL — `/register` currently returns 200 / redirects.

- [ ] **Step 3: Remove the register routes**

In `routes/auth.php`, delete the `RegisteredUserController` import (line 9) and the two register route blocks (the `GET /register` and `POST /register` definitions, lines 13-18).

- [ ] **Step 4: Delete the controller and page**

```bash
git rm app/Http/Controllers/Auth/RegisteredUserController.php resources/js/Pages/Auth/Register.jsx
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=RegistrationTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Rebuild assets (Register page reference removed)**

Run: `npm run build`
Expected: Build succeeds (no remaining `route('register')` references).

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint routes/auth.php
git add routes/auth.php app/Http/Controllers/Auth/RegisteredUserController.php resources/js/Pages/Auth/Register.jsx tests/Feature/Auth/RegistrationTest.php
git commit -m "feat: disable open self-registration"
```

---

### Task 10: Super-admin organization onboarding

**Files:**
- Create: `app/Http/Controllers/OrganizationsController.php`
- Create: `app/Policies/OrganizationPolicy.php`
- Modify: `routes/web.php` (organizations resource routes)
- Create: `resources/js/Pages/Organizations/Index.jsx`
- Create: `resources/js/Pages/Organizations/Create.jsx`
- Create: `resources/js/Pages/Organizations/Edit.jsx`
- Test: `tests/Feature/Organizations/OrganizationOnboardingTest.php`

**Interfaces:**
- Consumes: gate `manage-organizations` (super-admin only), `Organization`, `User`, `Organization::ROLE_ADMIN`.
- Produces:
  - `OrganizationsController@index` (list all orgs — super-admin), `@create`/`@store` (create org + first admin — super-admin), `@edit`/`@update` (rename — super-admin or org-admin).
  - `store` validates `name, admin_name, admin_email, admin_password`; creates the org with a unique slug; find-or-creates the admin user (email-verified) and attaches them as `admin`.
  - `OrganizationPolicy@update` → user is admin of that org (super-admins pass via `Gate::before`).
  - Routes: `organizations.index/create/store/edit/update` (no `active.organization` middleware).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationOnboardingTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_super_admin_can_onboard_org_with_first_admin(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('organizations.store'), [
            'name' => 'Beta Corp',
            'admin_name' => 'Ada',
            'admin_email' => 'ada@beta.test',
            'admin_password' => 'secret123',
        ])->assertRedirect(route('organizations.index'));

        $organization = Organization::where('name', 'Beta Corp')->firstOrFail();
        $admin = User::where('email', 'ada@beta.test')->firstOrFail();

        $this->assertTrue($admin->isAdminOf($organization));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_non_super_admin_cannot_onboard(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->post(route('organizations.store'), [
            'name' => 'Nope Inc',
            'admin_name' => 'X',
            'admin_email' => 'x@nope.test',
            'admin_password' => 'secret123',
        ])->assertForbidden();
    }

    public function test_existing_email_is_linked_not_duplicated(): void
    {
        $this->actingAsSuperAdmin();
        $existing = User::factory()->create(['email' => 'shared@x.test']);

        $this->post(route('organizations.store'), [
            'name' => 'Gamma',
            'admin_name' => 'Shared',
            'admin_email' => 'shared@x.test',
            'admin_password' => 'secret123',
        ])->assertRedirect();

        $this->assertSame(1, User::where('email', 'shared@x.test')->count());
        $this->assertTrue($existing->fresh()->isAdminOf(Organization::where('name', 'Gamma')->first()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrganizationOnboardingTest`
Expected: FAIL — `Route [organizations.store] not defined`.

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/OrganizationsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class OrganizationsController extends Controller
{
    public function index()
    {
        $this->authorize('manage-organizations');

        return Inertia::render('Organizations/Index', [
            'organizations' => Organization::withCount('users', 'monitors')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        $this->authorize('manage-organizations');

        return Inertia::render('Organizations/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-organizations');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:6',
        ]);

        $organization = Organization::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
        ]);

        $admin = User::firstOrCreate(
            ['email' => $validated['admin_email']],
            [
                'name' => $validated['admin_name'],
                'password' => Hash::make($validated['admin_password']),
                'email_verified_at' => now(),
            ]
        );

        $organization->users()->syncWithoutDetaching([
            $admin->id => ['role' => Organization::ROLE_ADMIN],
        ]);

        return redirect()->route('organizations.index');
    }

    public function edit(Organization $organization)
    {
        $this->authorize('update', $organization);

        return Inertia::render('Organizations/Edit', [
            'organization' => $organization->only('id', 'name', 'slug'),
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorize('update', $organization);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $organization->update([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name'], $organization->id),
        ]);

        return redirect()->route('organizations.index');
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Organization::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
```

- [ ] **Step 4: Create the policy**

`app/Policies/OrganizationPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function update(User $user, Organization $organization): bool
    {
        return $user->isAdminOf($organization);
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\OrganizationsController;
```

and inside the `['auth', 'verified']` group (outside `active.organization`), add:

```php
    Route::resource('organizations', OrganizationsController::class)
        ->only(['index', 'create', 'store', 'edit', 'update']);
```

- [ ] **Step 6: Create the React pages**

`resources/js/Pages/Organizations/Create.jsx`:

```jsx
import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";

export default function Create() {
    const { auth } = usePage().props;
    const [form, setForm] = useState({
        name: "",
        admin_name: "",
        admin_email: "",
        admin_password: "",
    });

    const handleChange = (e) =>
        setForm((p) => ({ ...p, [e.target.name]: e.target.value }));

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post(route("organizations.store"), form);
    };

    return (
        <Authenticated auth={auth}>
            <Head title="Onboard organization" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                    Onboard organization
                </h1>
            </PageHeader>
            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label forInput="name" value="Organization name" />
                            <Input name="name" value={form.name} required handleChange={handleChange} />
                        </div>
                        <div className="pt-6 border-t border-gray-200 space-y-6">
                            <p className="text-sm font-medium text-gray-700">First admin</p>
                            <div>
                                <Label forInput="admin_name" value="Admin name" />
                                <Input name="admin_name" value={form.admin_name} required handleChange={handleChange} />
                            </div>
                            <div>
                                <Label forInput="admin_email" value="Admin email" />
                                <Input name="admin_email" type="email" value={form.admin_email} required handleChange={handleChange} />
                            </div>
                            <div>
                                <Label forInput="admin_password" value="Admin password" />
                                <Input name="admin_password" type="password" value={form.admin_password} required handleChange={handleChange} />
                            </div>
                        </div>
                        <div className="pt-6 border-t border-gray-200">
                            <Button>Create organization</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Authenticated>
    );
}
```

`resources/js/Pages/Organizations/Index.jsx`:

```jsx
import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import { PlusIcon } from "@heroicons/react/24/solid";

export default function Index() {
    const { auth, organizations } = usePage().props;

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
                        <Link
                            href={route("organizations.edit", org.id)}
                            className="text-sm text-purple-600 hover:text-purple-800"
                        >
                            Rename
                        </Link>
                    </div>
                ))}
            </div>
        </Authenticated>
    );
}
```

`resources/js/Pages/Organizations/Edit.jsx`:

```jsx
import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Label from "@/Components/Label";
import Input from "@/Components/Input";

export default function Edit() {
    const { auth, organization } = usePage().props;
    const [form, setForm] = useState({ name: organization.name });

    const handleChange = (e) =>
        setForm((p) => ({ ...p, [e.target.name]: e.target.value }));

    const handleSubmit = (e) => {
        e.preventDefault();
        router.put(route("organizations.update", organization.id), form);
    };

    return (
        <Authenticated auth={auth}>
            <Head title="Rename organization" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                    Rename organization
                </h1>
            </PageHeader>
            <div className="max-w-xl mx-auto py-8 px-6 lg:px-8">
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <Label forInput="name" value="Organization name" />
                            <Input name="name" value={form.name} required handleChange={handleChange} />
                        </div>
                        <div className="pt-6 border-t border-gray-200">
                            <Button>Save</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Authenticated>
    );
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=OrganizationOnboardingTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Build assets**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/OrganizationsController.php app/Policies/OrganizationPolicy.php
git add app/Http/Controllers/OrganizationsController.php app/Policies/OrganizationPolicy.php routes/web.php resources/js/Pages/Organizations/ tests/Feature/Organizations/OrganizationOnboardingTest.php
git commit -m "feat: super-admin organization onboarding and rename"
```

---

### Task 11: Org-scoped user management

**Files:**
- Modify: `app/Http/Controllers/UsersController.php`
- Modify: `app/Http/Requests/UserRequest.php` (role + relax email on create)
- Modify: `resources/js/Pages/Users/Index.jsx` (show role)
- Modify: `resources/js/Pages/Users/Create.jsx` (role select)
- Modify: `resources/js/Pages/Users/Edit.jsx` (role select)
- Modify: `resources/js/Components/UserCard.jsx` (render role)
- Test: `tests/Feature/Organizations/OrgUserManagementTest.php`

**Interfaces:**
- Consumes: `CurrentOrganization`, gate `manage-org-users`, `Organization::ROLE_*`.
- Produces:
  - `UsersController@index` lists only the active org's members (each with `role`); `@create` renders the form; `@store` find-or-creates by email (email-verified) and attaches with the chosen role; `@edit`/`@update` edit a member of the active org (name/password/role) — 404 if the target isn't a member of the active org; `@destroy` detaches the membership (keeps the account).
  - `UserRequest` requires `role` ∈ {admin, member}; on create, email is `required|email` (no global unique — existing accounts get linked).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrgUserManagementTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_admin_adds_new_member(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'New Person',
            'email' => 'new@org.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_MEMBER,
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'new@org.test')->firstOrFail();
        $this->assertTrue($user->hasRoleInOrganization($organization, Organization::ROLE_MEMBER));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_adding_existing_email_links_account(): void
    {
        $organization = $this->createOrganization();
        $existing = User::factory()->create(['email' => 'exists@org.test']);
        $this->actingAsAdmin($organization);

        $this->post(route('users.store'), [
            'name' => 'Ignored',
            'email' => 'exists@org.test',
            'password' => 'secret123',
            'role' => Organization::ROLE_ADMIN,
        ])->assertRedirect();

        $this->assertSame(1, User::where('email', 'exists@org.test')->count());
        $this->assertTrue($existing->fresh()->isAdminOf($organization));
    }

    public function test_remove_detaches_membership_keeps_account(): void
    {
        $organization = $this->createOrganization();
        $member = User::factory()->create();
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);
        $this->actingAsAdmin($organization);

        $this->delete(route('users.destroy', $member->id))->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseMissing('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_member_cannot_manage_users(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsMember($organization);

        $this->get(route('users.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrgUserManagementTest`
Expected: FAIL — users index is global / no role handling / member not forbidden.

- [ ] **Step 3: Update UserRequest**

Replace `app/Http/Requests/UserRequest.php` `rules()` with:

```php
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string',
            'role' => ['required', \Illuminate\Validation\Rule::in([
                \App\Models\Organization::ROLE_ADMIN,
                \App\Models\Organization::ROLE_MEMBER,
            ])],
        ];

        if ($this->isMethod('post')) {
            $rules['email'] = 'required|email';
            $rules['password'] = 'required|string|min:6';
        } else {
            $rules['email'] = 'required|email|unique:users,email,'.$this->user->id;
            $rules['password'] = 'nullable|string|min:6';
        }

        return $rules;
    }
```

- [ ] **Step 4: Rewrite UsersController**

Replace `app/Http/Controllers/UsersController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UsersController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();

        $users = $organization->users()->orderBy('name')->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }

    public function create()
    {
        $this->authorize('manage-org-users');

        return Inertia::render('Users/Create');
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();
        $validated = $request->validated();

        $user = User::firstOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'password' => bcrypt($validated['password']),
                'email_verified_at' => now(),
            ]
        );

        $organization->users()->syncWithoutDetaching([
            $user->id => ['role' => $validated['role']],
        ]);

        return redirect()->route('users.index');
    }

    public function edit(User $user)
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $organization->users()->whereKey($user->id)->first()->pivot->role,
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);
        $validated = $request->validated();

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'] ? bcrypt($validated['password']) : $user->password,
        ]);

        $organization->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return redirect()->route('users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('manage-org-users');
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization->users()->whereKey($user->id)->exists(), 404);
        $organization->users()->detach($user->id);

        return redirect()->route('users.index');
    }
}
```

- [ ] **Step 5: Surface role in the frontend**

In `resources/js/Components/UserCard.jsx`, inside the name/email block (after the `<span>` showing `user.email`, before its closing `</div>` at line 28), add:

```jsx
                            {user.role && (
                                <span className="mt-1 inline-block w-fit text-[10px] font-semibold uppercase tracking-wide px-2 py-0.5 rounded bg-purple-50 text-purple-700">
                                    {user.role}
                                </span>
                            )}
```

In `resources/js/Pages/Users/Create.jsx`, add `role: "member"` to the initial `form` state, and add this field before the closing `</form>`'s submit `<div>` (after the password field block):

```jsx
                            <div>
                                <Label forInput="role" value="Role" />
                                <select
                                    name="role"
                                    value={form.role}
                                    onChange={handleChange}
                                    className="mt-1 block w-full rounded-lg border-gray-300 focus:border-purple-500 focus:ring-purple-500 text-sm"
                                >
                                    <option value="member">Member (view only)</option>
                                    <option value="admin">Admin (full access)</option>
                                </select>
                            </div>
```

In `resources/js/Pages/Users/Edit.jsx`, add `role: user.role` to the initial `form` state and add the same `<select>` block before the submit `<div>`.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=OrgUserManagementTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Build assets**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint app/Http/Controllers/UsersController.php app/Http/Requests/UserRequest.php
git add app/Http/Controllers/UsersController.php app/Http/Requests/UserRequest.php resources/js/Pages/Users/ resources/js/Components/UserCard.jsx tests/Feature/Organizations/OrgUserManagementTest.php
git commit -m "feat: org-scoped user management with roles"
```

---

### Task 12: Backfill existing data and make organization_id required

**Files:**
- Create: `database/migrations/2026_06_25_000200_backfill_default_organization.php`
- Create: `database/migrations/2026_06_25_000210_make_organization_id_required.php`
- Modify: the non-HTTP monitor-history test files — `MonitorCheckLogServiceTest`, `MonitorHistoryFeatureFlagTest`, `MonitorDailyCheckMetricsAggregatorTest`, `BackfillMonitorCheckHistoryTest` (add `organization_id` to each `Monitor::create`). (The HTTP suites were migrated in Task 5.)
- Modify: `database/seeders/MonitorHistorySeeder.php` (set `organization_id` via an instance property)
- Test: `tests/Feature/Organizations/BackfillMigrationTest.php`

**Interfaces:**
- Consumes: `Organization`, `Organization::ROLE_ADMIN`, `config('constants.default.user.email')`.
- Produces: a default "ColoredCow" org owning all pre-existing monitors/groups, all pre-existing users attached as admins, the configured default user flagged super-admin; `monitors.organization_id` and `groups.organization_id` become `NOT NULL`. The backfill migration **no-ops on an empty database** (so tests aren't polluted).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Organizations;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_id_is_required_on_monitors(): void
    {
        $this->expectException(QueryException::class);

        // No organization bound and none provided -> NOT NULL violation.
        Monitor::query()->create(['url' => 'https://needs-org.test', 'name' => 'NoOrg']);
    }

    public function test_monitor_with_organization_saves(): void
    {
        $monitor = Monitor::factory()->forOrganization(Organization::factory()->create())->create();

        $this->assertNotNull($monitor->organization_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackfillMigrationTest`
Expected: FAIL — `test_organization_id_is_required_on_monitors` does not throw (column still nullable).

- [ ] **Step 3: Create the backfill data migration**

`database/migrations/2026_06_25_000200_backfill_default_organization.php`:

```php
<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasData = DB::table('users')->exists()
            || DB::table('monitors')->exists()
            || DB::table('groups')->exists();

        if (! $hasData) {
            return; // fresh/test database — nothing to migrate
        }

        $organization = Organization::firstOrCreate(
            ['slug' => 'coloredcow'],
            ['name' => 'ColoredCow']
        );

        DB::table('groups')->whereNull('organization_id')->update(['organization_id' => $organization->id]);
        DB::table('monitors')->whereNull('organization_id')->update(['organization_id' => $organization->id]);

        foreach (User::all() as $user) {
            $organization->users()->syncWithoutDetaching([
                $user->id => ['role' => Organization::ROLE_ADMIN],
            ]);
        }

        $defaultEmail = config('constants.default.user.email');
        if ($defaultEmail) {
            User::where('email', $defaultEmail)->update(['is_super_admin' => true]);
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill.
    }
};
```

- [ ] **Step 4: Create the NOT NULL migration**

`database/migrations/2026_06_25_000210_make_organization_id_required.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });
    }
};
```

- [ ] **Step 5: Update the remaining (non-HTTP) monitor-creation sites**

The five **HTTP** MonitorHistory suites were already migrated in Task 5 (their monitors carry `organization_id`). What remains are the **non-HTTP** suites (service/command/unit tests) that create monitors directly and don't go through the org middleware — they only need an `organization_id` on each created monitor (no membership/session needed).

Add `'organization_id' => \App\Models\Organization::factory()->create()->id,` to **every** `Monitor::create([...])` call below. Some files have a shared `makeMonitor()` helper (one edit); others inline a fresh `Monitor::create()` per test (edit each site):

- `tests/Feature/MonitorHistory/MonitorCheckLogServiceTest.php` — shared `makeMonitor()` helper (≈ line 18), **single edit**.
- `tests/Feature/MonitorHistory/MonitorHistoryFeatureFlagTest.php` — shared `makeMonitor()` helper (≈ line 16), **single edit**.
- `tests/Feature/MonitorHistory/MonitorDailyCheckMetricsAggregatorTest.php` — **two** inline sites (≈ lines 19 and 62).
- `tests/Feature/MonitorHistory/BackfillMonitorCheckHistoryTest.php` — **three** inline sites (≈ lines 18, 43, 59).

(If a test creates several monitors that must share one org, create the org once in `setUp()` as `$this->organization = \App\Models\Organization::factory()->create();` and reuse `$this->organization->id`. Grep to confirm none are missed: `grep -rn "Monitor::create" tests/` should show zero results without an `organization_id` key after this step.)

- [ ] **Step 6: Update the MonitorHistorySeeder**

In `database/seeders/MonitorHistorySeeder.php`, the actual `Monitor::updateOrCreate(...)` lives in the private `createMonitor()` method (≈ line 133), **not** in `run()` — so a local variable in `run()` would be undefined inside `createMonitor()`. Store the org on the seeder instance.

1. Add a property to the class: `private ?\App\Models\Organization $organization = null;`
2. At the top of `run()`, set it:

```php
$this->organization = \App\Models\Organization::firstOrCreate(
    ['slug' => 'coloredcow'],
    ['name' => 'ColoredCow']
);
```

3. In `createMonitor()`, add `'organization_id' => $this->organization->id,` to the attributes array (second argument) of `Monitor::updateOrCreate(...)`.

- [ ] **Step 7: Run the new test, then the full suite**

Run: `php artisan test --filter=BackfillMigrationTest`
Expected: PASS (2 tests).

Run: `php artisan test`
Expected: PASS — entire suite green, including all monitor-history tests.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_06_25_0002*_*.php database/seeders/MonitorHistorySeeder.php tests/Feature/MonitorHistory/ tests/Feature/Organizations/BackfillMigrationTest.php
git commit -m "feat: backfill default organization and require organization_id"
```

---

## Final verification

- [ ] Run the full backend suite: `php artisan test` → all green.
- [ ] Run the JS suite: `npm run test` (vitest) → green; `npm run build` → succeeds.
- [ ] **Console-safety smoke check** (proves the no-global-scope decision holds end to end):
  `php artisan tinker --execute="echo \App\Models\Monitor::count();"` returns the total across all orgs (run after seeding two orgs' monitors).
- [ ] Manually: log in as a multi-org user → switcher appears and changes the dashboard; a member sees read-only (no create/edit); a super-admin can onboard an org at `/organizations`; `/register` returns 404.

## Self-review notes (for the author)

- **Spec coverage:** §4 data model → Tasks 1,2,4,12; §5 models/trait → Tasks 1–4; §6 active-org/switcher → Tasks 5,8; §7 authorization → Tasks 6,7; §8 onboarding/user-mgmt/registration → Tasks 9,10,11; §10 migration → Task 12; §11 testing → woven into every task + Final verification.
- **No-global-scope guarantee** is asserted in Task 4 (`test_no_global_scope_leaks_into_console_context`) and the Final-verification tinker check.
- **Middleware-ordering independence (verified on L12.48):** `SetActiveOrganization` runs after `SubstituteBindings` and Inertia `share()`. The design does **not** depend on reordering: (a) the route binding self-resolves via `CurrentOrganization::resolveFor()` and hard-404s when no org (Task 6); (b) the `auth` Inertia prop is a lazy closure evaluated at render time (Task 5); (c) policies re-check ownership in the controller, which always runs after all middleware (Task 7). Cross-org access is enforced at two independent layers (binding 404 + policy 403).
- **Green at every commit:** the active-org middleware (Task 5) and the MonitorHistory HTTP-test migration ship in the *same* task; non-HTTP test + seeder updates ship with the NOT NULL migration (Task 12).
- **Type consistency:** `forOrganization(int)`, `CurrentOrganization::{get,id,set,resolveFor}`, `User::{isSuperAdmin,isAdminOf,hasRoleInOrganization}`, `Organization::{ROLE_ADMIN,ROLE_MEMBER}`, session key `active_organization_id`, route name `organizations.switch` are used identically across all tasks.
