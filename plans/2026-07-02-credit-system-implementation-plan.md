# Credit System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **⚠️ EXECUTION IS DEFERRED.** Do not start this plan until the organization tenancy feature (`feat/issue-23-organization-dashboard`) is merged into `main`. Then branch `feat/credit-system` off `main` and execute there.

**Goal:** Organizations hold a credit balance; every executed monitor check (uptime, certificate, domain) atomically consumes 1 credit; at zero balance all checks pause until a super-admin grants more; org admins see balance, projected runway, usage breakdown, and warnings.

**Architecture:** Live `credit_balance` counter on `organizations` decremented per check via the Monitor model hooks that fire once per executed check; auditable `credit_transactions` ledger (grants/adjustments + nightly usage-debit rollups); `credit_usage_daily` upserts power the dashboard. Enforcement is query-level: an overridden `scopeEnabled` on `App\Models\Monitor` (used by Spatie's `MonitorRepository` for uptime + certificate selection) plus the domain scope exclude monitors of orgs with `credit_balance <= 0`. Runway is projected from live monitor config by `CreditRunwayService` and reused by warning emails, shared Inertia props, a header chip, and monitor-form previews.

**Tech Stack:** Laravel 12, spatie/laravel-uptime-monitor 4.x, MySQL, Inertia + React (JSX), Tailwind, PHPUnit 11, Vitest.

**Spec:** `plans/2026-07-02-credit-system-design.md` (read it first).

## Global Constraints

- Base branch: `main` AFTER tenancy merge; work on `feat/credit-system`.
- PHP tests: `php artisan test` (MySQL database `monitor_test` must exist; create with `mysql -u root -e "CREATE DATABASE IF NOT EXISTS monitor_test;"`). Filter single classes with `php artisan test --filter=ClassName`.
- JS: default shell node is v12 — ALWAYS `source ~/.nvm/nvm.sh && nvm use 22` before any npm command. JS tests: `npm run test:js` (Vitest, node env, pure-function tests only — no jsdom).
- PHP style: `./vendor/bin/pint <changed files>` before each commit.
- Check-type string constants live on `MonitorCheckLogService`: `CHECK_TYPE_UPTIME` = 'uptime', `CHECK_TYPE_CERTIFICATE` = 'certificate', `CHECK_TYPE_DOMAIN` = 'domain'. Reuse them everywhere; never hardcode.
- Every check costs exactly 1 credit regardless of outcome. Metering must NEVER throw out of `CreditMeteringService::recordCheck()`.
- `credit_balance` is intentionally signed (in-flight checks may push it slightly negative) and intentionally NOT in `$fillable`.
- All usage dates are UTC (`now('UTC')->toDateString()`).
- Feature tests use `RefreshDatabase` + `Tests\Concerns\InteractsWithOrganizations` (gives `createOrganization()`, `actingAsAdmin($org)`, `actingAsMember($org)`, `actingAsSuperAdmin()`).
- The monitor Create/Edit forms have NO certificate toggle (only `monitorUptime` + `monitorDomain`); certificate burn still counts from the DB column `certificate_check_enabled`.

## File Structure

New backend files:
- `config/credits.php` — default grant + warning-day thresholds
- `database/migrations/*_add_credit_columns_to_organizations_table.php`
- `database/migrations/*_create_credit_transactions_table.php`
- `database/migrations/*_create_credit_usage_daily_table.php`
- `app/Models/CreditTransaction.php`, `app/Models/CreditUsageDaily.php`
- `app/Services/CreditLedgerService.php` — grants/adjustments/usage-debits (the ONLY writer of `credit_transactions`)
- `app/Services/CreditMeteringService.php` — per-check decrement + usage upsert + zero-crossing (the ONLY per-check writer)
- `app/Services/CreditRunwayService.php` — config-derived daily burn + runway days
- `app/Notifications/CreditBalanceLow.php`, `CreditBalanceCritical.php`, `MonitoringPaused.php`, `MonitoringResumed.php`
- `app/Console/Commands/RollupCreditUsage.php`, `EvaluateCreditWarnings.php`
- `app/Http/Controllers/CreditsController.php` (org-facing), `OrganizationCreditsController.php` (super-admin)

New frontend files:
- `resources/js/Utils/creditRunway.js` (+ `creditRunway.test.js`)
- `resources/js/Components/CreditRunwayChip.jsx`, `MonitorCreditImpact.jsx`
- `resources/js/Pages/Credits/Index.jsx`, `resources/js/Pages/Organizations/Credits.jsx`

Modified:
- `app/Models/Organization.php` (constants, casts, relationships, `admins()`, `hasCredits()`)
- `app/Models/Monitor.php` (metering hooks, `scopeEnabled` override, `setCertificate`/`setCertificateException` overrides, domain scope filter)
- `app/Console/Commands/CheckDomainExpiration.php` (metering + `--force` balance filter)
- `app/Http/Controllers/OrganizationsController.php` (default grant on store)
- `app/Http/Middleware/HandleInertiaRequests.php` (shared `credits` props)
- `routes/console.php` (two schedules), `routes/web.php` (credits routes)
- `resources/js/Layouts/Authenticated.jsx` (header chip)
- `resources/js/Pages/Monitors/Create.jsx`, `Edit.jsx` (impact preview)
- `resources/js/Pages/Organizations/Index.jsx` (balance column + link)

---

### Task 1: Schema, models, config

**Files:**
- Create: `config/credits.php`
- Create: `database/migrations/2026_07_10_000100_add_credit_columns_to_organizations_table.php`
- Create: `database/migrations/2026_07_10_000110_create_credit_transactions_table.php`
- Create: `database/migrations/2026_07_10_000120_create_credit_usage_daily_table.php`
- Create: `app/Models/CreditTransaction.php`, `app/Models/CreditUsageDaily.php`
- Modify: `app/Models/Organization.php`
- Test: `tests/Feature/Credits/CreditSchemaTest.php`

**Interfaces:**
- Consumes: existing `Organization`, `Monitor`, `User` models.
- Produces: `Organization::CREDIT_LEVEL_NONE|LOW|CRITICAL|EXHAUSTED` constants; `Organization::creditTransactions(): HasMany`, `creditUsage(): HasMany`, `admins(): BelongsToMany`, `hasCredits(): bool`; `CreditTransaction::TYPE_GRANT|TYPE_ADJUSTMENT|TYPE_USAGE_DEBIT`; `CreditUsageDaily` model (table `credit_usage_daily`); config keys `credits.default_grant`, `credits.warning_days.low`, `credits.warning_days.critical`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditSchemaTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_organization_credit_defaults(): void
    {
        $organization = $this->createOrganization();

        $this->assertSame(0, $organization->fresh()->credit_balance);
        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $organization->fresh()->credit_warning_level);
        $this->assertFalse($organization->fresh()->hasCredits());
    }

    public function test_credit_balance_is_not_mass_assignable(): void
    {
        $organization = Organization::factory()->create(['credit_balance' => 999999]);

        $this->assertSame(0, $organization->fresh()->credit_balance);
    }

    public function test_transaction_and_usage_relationships(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $transaction = CreditTransaction::create([
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 100,
            'balance_after' => 100,
            'description' => 'Initial grant',
            'metadata' => ['source' => 'test'],
        ]);

        $usage = CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => '2026-07-01',
            'credits' => 5,
        ]);

        $this->assertTrue($organization->creditTransactions()->whereKey($transaction->id)->exists());
        $this->assertTrue($organization->creditUsage()->whereKey($usage->id)->exists());
        $this->assertSame(['source' => 'test'], $transaction->fresh()->metadata);
    }

    public function test_usage_survives_monitor_hard_delete_with_null_monitor_id(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => '2026-07-01',
            'credits' => 5,
        ]);

        $monitor->forceDelete();

        $this->assertDatabaseHas('credit_usage_daily', [
            'organization_id' => $organization->id,
            'monitor_id' => null,
            'credits' => 5,
        ]);
    }

    public function test_admins_relationship_only_returns_admins(): void
    {
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);

        $this->assertSame([$admin->id], $organization->admins()->pluck('users.id')->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditSchemaTest`
Expected: FAIL (missing columns/classes: `credit_balance` column not found, class `CreditTransaction` not found).

- [ ] **Step 3: Create config, migrations, models**

`config/credits.php`:

```php
<?php

return [
    // Credits granted automatically when a new organization is created,
    // recorded as a normal `grant` transaction. 0 disables the auto-grant.
    'default_grant' => (int) env('CREDITS_DEFAULT_GRANT', 0),

    // Projected-runway thresholds (in days) for warning-level escalation.
    'warning_days' => [
        'low' => (int) env('CREDITS_WARNING_LOW_DAYS', 7),
        'critical' => (int) env('CREDITS_WARNING_CRITICAL_DAYS', 2),
    ],
];
```

`database/migrations/2026_07_10_000100_add_credit_columns_to_organizations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Signed on purpose: checks in flight when the balance crosses zero
            // may push it a few credits negative (bounded by one scheduler tick).
            $table->bigInteger('credit_balance')->default(0)->after('slug');
            $table->string('credit_warning_level', 20)->default('none')->after('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['credit_balance', 'credit_warning_level']);
        });
    }
};
```

`database/migrations/2026_07_10_000110_create_credit_transactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // grant | adjustment | usage_debit
            $table->bigInteger('amount'); // positive = credit, negative = debit
            $table->bigInteger('balance_after');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
```

`database/migrations/2026_07_10_000120_create_credit_usage_daily_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // nullOnDelete preserves org-level usage history when the purge
            // hard-deletes a monitor.
            $table->foreignId('monitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('check_type', 20); // uptime | certificate | domain
            $table->date('date'); // UTC
            $table->unsignedBigInteger('credits')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'monitor_id', 'check_type', 'date'], 'credit_usage_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_usage_daily');
    }
};
```

`app/Models/CreditTransaction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const TYPE_GRANT = 'grant';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_USAGE_DEBIT = 'usage_debit';

    protected $fillable = [
        'organization_id',
        'type',
        'amount',
        'balance_after',
        'description',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

`app/Models/CreditUsageDaily.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditUsageDaily extends Model
{
    protected $table = 'credit_usage_daily';

    protected $fillable = [
        'organization_id',
        'monitor_id',
        'check_type',
        'date',
        'credits',
    ];

    protected $casts = [
        'date' => 'date',
        'credits' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
```

`app/Models/Organization.php` — add constants after the existing role constants, add casts, and add methods after `users()`:

```php
    public const CREDIT_LEVEL_NONE = 'none';

    public const CREDIT_LEVEL_LOW = 'low';

    public const CREDIT_LEVEL_CRITICAL = 'critical';

    public const CREDIT_LEVEL_EXHAUSTED = 'exhausted';

    // NOTE: credit_balance and credit_warning_level are deliberately NOT
    // fillable — they change only through CreditLedgerService / CreditMeteringService.

    protected $casts = [
        'credit_balance' => 'integer',
    ];
```

```php
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function creditUsage(): HasMany
    {
        return $this->hasMany(CreditUsageDaily::class);
    }

    public function admins(): BelongsToMany
    {
        return $this->users()->wherePivot('role', self::ROLE_ADMIN);
    }

    public function hasCredits(): bool
    {
        return $this->credit_balance > 0;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditSchemaTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint config/credits.php database/migrations app/Models tests/Feature/Credits
git add config/credits.php database/migrations app/Models/CreditTransaction.php app/Models/CreditUsageDaily.php app/Models/Organization.php tests/Feature/Credits/CreditSchemaTest.php
git commit -m "feat(credits): schema, models, and config for the credit system"
```
