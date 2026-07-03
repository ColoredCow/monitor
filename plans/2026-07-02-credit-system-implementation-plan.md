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
        // Plain create(), NOT a factory — factories run unguarded and would
        // bypass the $fillable protection this test asserts.
        $organization = Organization::create([
            'name' => 'Guarded Org',
            'slug' => 'guarded-org',
            'credit_balance' => 999999,
        ]);

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

---

### Task 2: Notification classes

**Files:**
- Create: `app/Notifications/CreditBalanceLow.php`, `app/Notifications/CreditBalanceCritical.php`, `app/Notifications/MonitoringPaused.php`, `app/Notifications/MonitoringResumed.php`
- Test: `tests/Feature/Credits/CreditNotificationsTest.php`

**Interfaces:**
- Consumes: `Organization` model (Task 1).
- Produces: `new CreditBalanceLow(Organization $organization, float $runwayDays)`, `new CreditBalanceCritical(Organization $organization, float $runwayDays)`, `new MonitoringPaused(Organization $organization)`, `new MonitoringResumed(Organization $organization)`. All are mail-only (`via()` returns `['mail']`), non-queued (matches every existing app notification; `QUEUE_CONNECTION=sync` anyway).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\User;
use App\Notifications\CreditBalanceCritical;
use App\Notifications\CreditBalanceLow;
use App\Notifications\MonitoringPaused;
use App\Notifications\MonitoringResumed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditNotificationsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_all_credit_notifications_are_mail_only(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $user = User::factory()->create();

        foreach ([
            new CreditBalanceLow($organization, 6.4),
            new CreditBalanceCritical($organization, 1.2),
            new MonitoringPaused($organization),
            new MonitoringResumed($organization),
        ] as $notification) {
            $this->assertSame(['mail'], $notification->via($user));
        }
    }

    public function test_mail_subjects_name_the_organization_and_severity(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $user = User::factory()->create();

        $this->assertStringContainsString('Acme', (new CreditBalanceLow($organization, 6.4))->toMail($user)->subject);
        $this->assertStringContainsString('low', (new CreditBalanceLow($organization, 6.4))->toMail($user)->subject);
        $this->assertStringContainsString('critical', (new CreditBalanceCritical($organization, 1.2))->toMail($user)->subject);
        $this->assertStringContainsString('paused', (new MonitoringPaused($organization))->toMail($user)->subject);
        $this->assertStringContainsString('resumed', (new MonitoringResumed($organization))->toMail($user)->subject);
    }

    public function test_low_warning_mentions_runway_days(): void
    {
        $organization = $this->createOrganization(['name' => 'Acme']);
        $user = User::factory()->create();

        $mail = (new CreditBalanceLow($organization, 6.4))->toMail($user);

        $this->assertStringContainsString('6 day', implode(' ', $mail->introLines));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditNotificationsTest`
Expected: FAIL — `Class "App\Notifications\CreditBalanceLow" not found`.

- [ ] **Step 3: Create the four notifications**

`app/Notifications/CreditBalanceLow.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CreditBalanceLow extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization, public float $runwayDays)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = max(1, (int) floor($this->runwayDays));

        return (new MailMessage)
            ->subject("{$this->organization->name}: credit balance low")
            ->line("{$this->organization->name} has {$this->organization->credit_balance} credits left.")
            ->line("At the current monitor configuration they will last about {$days} day(s).")
            ->line('Please contact your service administrator to top up before monitoring pauses.');
    }
}
```

`app/Notifications/CreditBalanceCritical.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CreditBalanceCritical extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization, public float $runwayDays)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = max(1, (int) floor($this->runwayDays));

        return (new MailMessage)
            ->subject("{$this->organization->name}: credit balance critical")
            ->line("{$this->organization->name} is nearly out of credits ({$this->organization->credit_balance} left).")
            ->line("At the current monitor configuration they will last about {$days} day(s).")
            ->line('Please contact your service administrator to top up before monitoring pauses.');
    }
}
```

`app/Notifications/MonitoringPaused.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonitoringPaused extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->organization->name}: monitoring paused — out of credits")
            ->line("{$this->organization->name} has run out of credits.")
            ->line('All uptime, certificate, and domain checks are paused until credits are added.')
            ->line('Please contact your service administrator to top up.');
    }
}
```

`app/Notifications/MonitoringResumed.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonitoringResumed extends Notification
{
    use Queueable;

    public function __construct(public Organization $organization)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->organization->name}: monitoring resumed")
            ->line("Credits were added to {$this->organization->name}.")
            ->line('All monitor checks are running again.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditNotificationsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Notifications tests/Feature/Credits/CreditNotificationsTest.php
git add app/Notifications tests/Feature/Credits/CreditNotificationsTest.php
git commit -m "feat(credits): warning/paused/resumed notification classes"
```

---

### Task 3: CreditLedgerService (grants, adjustments, usage debits)

**Files:**
- Create: `app/Services/CreditLedgerService.php`
- Test: `tests/Feature/Credits/CreditLedgerServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models/constants; Task 2 `MonitoringResumed`.
- Produces: `CreditLedgerService::grant(Organization $organization, int $amount, ?User $createdBy = null, ?string $description = null): CreditTransaction` (throws `InvalidArgumentException` for amount ≤ 0); `adjust(...)` same signature (signed amount, throws for 0); `recordUsageDebit(Organization $organization, int $credits, string $date): CreditTransaction` (ledger row only, NO balance change, `metadata = ['date' => $date]`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MonitoringResumed;
use App\Services\CreditLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditLedgerServiceTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function setCredits(Organization $organization, int $balance, string $level = Organization::CREDIT_LEVEL_NONE): void
    {
        DB::table('organizations')->where('id', $organization->id)
            ->update(['credit_balance' => $balance, 'credit_warning_level' => $level]);
    }

    public function test_grant_increments_balance_and_writes_ledger_row(): void
    {
        $organization = $this->createOrganization();
        $superAdmin = User::factory()->superAdmin()->create();
        $this->setCredits($organization, 100);

        $transaction = app(CreditLedgerService::class)->grant($organization, 250, $superAdmin, 'Top up');

        $this->assertSame(350, $organization->fresh()->credit_balance);
        $this->assertSame(CreditTransaction::TYPE_GRANT, $transaction->type);
        $this->assertSame(250, $transaction->amount);
        $this->assertSame(350, $transaction->balance_after);
        $this->assertSame($superAdmin->id, $transaction->created_by);
        $this->assertSame('Top up', $transaction->description);
    }

    public function test_grant_rejects_non_positive_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(CreditLedgerService::class)->grant($this->createOrganization(), 0);
    }

    public function test_adjust_accepts_negative_amounts(): void
    {
        $organization = $this->createOrganization();
        $this->setCredits($organization, 100);

        $transaction = app(CreditLedgerService::class)->adjust($organization, -30, null, 'Correction');

        $this->assertSame(70, $organization->fresh()->credit_balance);
        $this->assertSame(CreditTransaction::TYPE_ADJUSTMENT, $transaction->type);
        $this->assertSame(-30, $transaction->amount);
    }

    public function test_grant_resets_warning_level(): void
    {
        $organization = $this->createOrganization();
        $this->setCredits($organization, 10, Organization::CREDIT_LEVEL_CRITICAL);

        app(CreditLedgerService::class)->grant($organization, 1000);

        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $organization->fresh()->credit_warning_level);
    }

    public function test_grant_to_paused_org_sends_resumed_email_to_admins_only(): void
    {
        Notification::fake();
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $organization->users()->attach($member->id, ['role' => Organization::ROLE_MEMBER]);
        $this->setCredits($organization, -3, Organization::CREDIT_LEVEL_EXHAUSTED);

        app(CreditLedgerService::class)->grant($organization, 1000);

        Notification::assertSentTo($admin, MonitoringResumed::class);
        Notification::assertNotSentTo($member, MonitoringResumed::class);
        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $organization->fresh()->credit_warning_level);
    }

    public function test_no_resumed_email_when_org_was_not_paused_or_stays_at_zero(): void
    {
        Notification::fake();
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);

        // Fresh org (level none, balance 0): a first grant is NOT a "resume".
        app(CreditLedgerService::class)->grant($organization, 100);
        Notification::assertNothingSent();

        // Paused org that stays <= 0 after a partial grant: still paused.
        $this->setCredits($organization, -500, Organization::CREDIT_LEVEL_EXHAUSTED);
        app(CreditLedgerService::class)->grant($organization, 100);
        Notification::assertNothingSent();
        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $organization->fresh()->credit_warning_level);
    }

    public function test_usage_debit_writes_ledger_row_without_touching_balance(): void
    {
        $organization = $this->createOrganization();
        $this->setCredits($organization, 100);

        $transaction = app(CreditLedgerService::class)->recordUsageDebit($organization, 40, '2026-07-09');

        $this->assertSame(100, $organization->fresh()->credit_balance); // unchanged
        $this->assertSame(CreditTransaction::TYPE_USAGE_DEBIT, $transaction->type);
        $this->assertSame(-40, $transaction->amount);
        $this->assertSame(100, $transaction->balance_after);
        $this->assertSame(['date' => '2026-07-09'], $transaction->metadata);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditLedgerServiceTest`
Expected: FAIL — `Class "App\Services\CreditLedgerService" not found`.

- [ ] **Step 3: Implement the service**

`app/Services/CreditLedgerService.php`:

```php
<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MonitoringResumed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class CreditLedgerService
{
    public function grant(Organization $organization, int $amount, ?User $createdBy = null, ?string $description = null): CreditTransaction
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Grant amount must be positive.');
        }

        return $this->applyTransaction($organization, CreditTransaction::TYPE_GRANT, $amount, $createdBy, $description);
    }

    public function adjust(Organization $organization, int $amount, ?User $createdBy = null, ?string $description = null): CreditTransaction
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Adjustment amount must be non-zero.');
        }

        return $this->applyTransaction($organization, CreditTransaction::TYPE_ADJUSTMENT, $amount, $createdBy, $description);
    }

    /**
     * Audit record for a day of metered usage. The balance was already
     * decremented live by CreditMeteringService, so this only writes the
     * ledger row — it must NOT touch the balance.
     */
    public function recordUsageDebit(Organization $organization, int $credits, string $date): CreditTransaction
    {
        return CreditTransaction::create([
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_USAGE_DEBIT,
            'amount' => -$credits,
            'balance_after' => $organization->fresh()->credit_balance,
            'description' => "Metered usage for {$date}",
            'metadata' => ['date' => $date],
        ]);
    }

    protected function applyTransaction(Organization $organization, string $type, int $amount, ?User $createdBy, ?string $description): CreditTransaction
    {
        [$transaction, $resumed] = DB::transaction(function () use ($organization, $type, $amount, $createdBy, $description) {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            $wasPaused = $locked->credit_warning_level === Organization::CREDIT_LEVEL_EXHAUSTED;
            $locked->credit_balance += $amount;

            if ($amount > 0 && $locked->credit_balance > 0) {
                $locked->credit_warning_level = Organization::CREDIT_LEVEL_NONE;
            }

            $locked->save();

            $transaction = CreditTransaction::create([
                'organization_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $locked->credit_balance,
                'description' => $description,
                'created_by' => $createdBy?->id,
            ]);

            return [$transaction, $wasPaused && $locked->credit_balance > 0];
        });

        // Outside the DB transaction: never hold a row lock while sending
        // synchronous mail.
        if ($resumed) {
            $fresh = $organization->fresh();
            Notification::send($fresh->admins()->get(), new MonitoringResumed($fresh));
        }

        return $transaction;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditLedgerServiceTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Services/CreditLedgerService.php tests/Feature/Credits/CreditLedgerServiceTest.php
git add app/Services/CreditLedgerService.php tests/Feature/Credits/CreditLedgerServiceTest.php
git commit -m "feat(credits): ledger service for grants, adjustments, and usage debits"
```

---

### Task 4: CreditMeteringService (per-check decrement + usage upsert + zero-crossing)

**Files:**
- Create: `app/Services/CreditMeteringService.php`
- Test: `tests/Feature/Credits/CreditMeteringServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models/constants; Task 2 `MonitoringPaused`; `MonitorCheckLogService::CHECK_TYPE_*` constants.
- Produces: `CreditMeteringService::recordCheck(Monitor $monitor, string $checkType): void` — never throws; per call: one atomic balance decrement, one `credit_usage_daily` upsert (+1), and a race-free zero-crossing that sets `credit_warning_level = exhausted` and emails org admins exactly once.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MonitoringPaused;
use App\Services\CreditMeteringService;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditMeteringServiceTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function setBalance(Organization $organization, int $balance): void
    {
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);
    }

    public function test_record_check_decrements_balance_and_upserts_daily_usage(): void
    {
        $organization = $this->createOrganization();
        $this->setBalance($organization, 10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        $this->assertSame(8, $organization->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_usage_daily', 1); // same day+type upserts in place
        $this->assertDatabaseHas('credit_usage_daily', [
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->toDateString(),
            'credits' => 2,
        ]);
    }

    public function test_each_check_type_gets_its_own_usage_row(): void
    {
        $organization = $this->createOrganization();
        $this->setBalance($organization, 10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_CERTIFICATE);
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_DOMAIN);

        $this->assertSame(7, $organization->fresh()->credit_balance);
        $this->assertDatabaseCount('credit_usage_daily', 3);
    }

    public function test_zero_crossing_pauses_org_and_emails_admins_exactly_once(): void
    {
        Notification::fake();
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        $this->setBalance($organization, 1);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        $fresh = $organization->fresh();
        $this->assertSame(0, $fresh->credit_balance);
        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $fresh->credit_warning_level);
        Notification::assertSentToTimes($admin, MonitoringPaused::class, 1);

        // An in-flight check after exhaustion still meters but must not re-notify.
        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);
        $this->assertSame(-1, $organization->fresh()->credit_balance);
        Notification::assertSentToTimes($admin, MonitoringPaused::class, 1);
    }

    public function test_monitor_without_organization_is_a_noop(): void
    {
        $monitor = new Monitor;
        $monitor->id = 4242;
        $monitor->organization_id = null;

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        $this->assertDatabaseCount('credit_usage_daily', 0);
    }

    public function test_metering_failure_is_swallowed_and_logged(): void
    {
        Log::spy();

        // Unsaved monitor pointing at a nonexistent org: the usage upsert
        // violates the FK constraint and throws inside the service.
        $monitor = new Monitor;
        $monitor->id = 4242;
        $monitor->organization_id = 999999;

        app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        Log::shouldHaveReceived('error')->once();
        $this->assertDatabaseCount('credit_usage_daily', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditMeteringServiceTest`
Expected: FAIL — `Class "App\Services\CreditMeteringService" not found`.

- [ ] **Step 3: Implement the service**

`app/Services/CreditMeteringService.php`:

```php
<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\Organization;
use App\Notifications\MonitoringPaused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class CreditMeteringService
{
    /**
     * Charge one credit for an executed check. Called from the per-check
     * model hooks, so it must never throw: losing a credit's worth of
     * metering beats breaking the check pipeline.
     */
    public function recordCheck(Monitor $monitor, string $checkType): void
    {
        try {
            $organizationId = $monitor->organization_id;

            if (! $organizationId) {
                return;
            }

            DB::update(
                'update organizations set credit_balance = credit_balance - 1 where id = ?',
                [$organizationId]
            );

            $now = now();

            DB::table('credit_usage_daily')->upsert(
                [[
                    'organization_id' => $organizationId,
                    'monitor_id' => $monitor->id,
                    'check_type' => $checkType,
                    'date' => now('UTC')->toDateString(),
                    'credits' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['organization_id', 'monitor_id', 'check_type', 'date'],
                ['credits' => DB::raw('credits + 1'), 'updated_at' => $now]
            );

            $this->handleZeroCrossing($organizationId);
        } catch (Throwable $exception) {
            Log::error('Credit metering failed; check continues unmetered.', [
                'monitor_id' => $monitor->id,
                'check_type' => $checkType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function handleZeroCrossing(int $organizationId): void
    {
        // Atomic conditional update: the affected-rows count tells us whether
        // WE made the ->exhausted transition, so overlapping check runs can
        // never send the paused email twice.
        $becameExhausted = Organization::query()
            ->whereKey($organizationId)
            ->where('credit_balance', '<=', 0)
            ->where('credit_warning_level', '!=', Organization::CREDIT_LEVEL_EXHAUSTED)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_EXHAUSTED]);

        if ($becameExhausted > 0) {
            $organization = Organization::find($organizationId);

            if ($organization) {
                Notification::send($organization->admins()->get(), new MonitoringPaused($organization));
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditMeteringServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Services/CreditMeteringService.php tests/Feature/Credits/CreditMeteringServiceTest.php
git add app/Services/CreditMeteringService.php tests/Feature/Credits/CreditMeteringServiceTest.php
git commit -m "feat(credits): per-check metering service with zero-crossing pause"
```

---

### Task 5: Metering call sites (uptime, certificate, domain hooks)

**Files:**
- Modify: `app/Models/Monitor.php`
- Modify: `app/Console/Commands/CheckDomainExpiration.php`
- Test: `tests/Feature/Credits/CreditMeteringCallSitesTest.php`

**Interfaces:**
- Consumes: Task 4 `CreditMeteringService::recordCheck()`.
- Produces: every executed check meters exactly one credit. Hook points (chosen because each fires exactly once per executed check, and the certificate pair is testable without network I/O):
  - uptime → existing `uptimeRequestSucceeded(ResponseInterface $response)` / `uptimeRequestFailed(string $reason)` overrides — Spatie's `MonitorCollection::checkUptime()` calls exactly one of them per settled request.
  - certificate → NEW overrides of `setCertificate(SslCertificate $certificate)` / `setCertificateException(Exception $exception)` — vendor `checkCertificate()` branches into exactly one.
  - domain → the loop in `CheckDomainExpiration::handle()`.

**Placement rule:** metering is the FIRST line of each hook, before `parent::` calls and before the existing `uptime_check_enabled` early-returns — a check that executed is charged regardless of what happens while recording its result.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use App\Services\DomainService;
use Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\SslCertificate\SslCertificate;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditMeteringCallSitesTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function orgWithBalance(int $balance): Organization
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);

        return $organization->fresh();
    }

    public function test_failed_uptime_check_is_charged(): void
    {
        Notification::fake(); // vendor hooks fire uptime events; keep them quiet
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $monitor->uptimeRequestFailed('connection timed out');

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'uptime',
            'credits' => 1,
        ]);
    }

    public function test_successful_uptime_check_is_charged(): void
    {
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create();

        $monitor->uptimeRequestSucceeded(new Response(200, [], 'ok'));

        $this->assertSame(9, $organization->fresh()->credit_balance);
    }

    public function test_uptime_check_on_monitor_with_uptime_disabled_is_still_charged_when_hook_fires(): void
    {
        // The hook only fires when Spatie actually executed a request; the
        // existing early-return guards only skip HISTORY logging, not billing.
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['uptime_check_enabled' => false]);

        $monitor->uptimeRequestFailed('connection timed out');

        $this->assertSame(9, $organization->fresh()->credit_balance);
    }

    public function test_certificate_failure_path_is_charged(): void
    {
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['certificate_check_enabled' => true]);

        $monitor->setCertificateException(new Exception('could not download certificate'));

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'certificate',
            'credits' => 1,
        ]);
    }

    public function test_certificate_success_path_is_charged(): void
    {
        Notification::fake();
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['certificate_check_enabled' => true]);

        $monitor->setCertificate(new SslCertificate([
            'subject' => ['CN' => 'example.com'],
            'issuer' => ['CN' => 'Test CA'],
            'validFrom_time_t' => now()->subDay()->timestamp,
            'validTo_time_t' => now()->addYear()->timestamp,
        ]));

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'certificate',
            'credits' => 1,
        ]);
    }

    public function test_domain_expiration_command_charges_each_checked_monitor(): void
    {
        $organization = $this->orgWithBalance(10);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['domain_check_enabled' => true]);

        $this->mock(DomainService::class)
            ->shouldReceive('verifyDomainExpiration')
            ->once()
            ->andReturn(['notified' => false]);

        $this->artisan('monitor:check-domain-expiration')->assertSuccessful();

        $this->assertSame(9, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_usage_daily', [
            'monitor_id' => $monitor->id,
            'check_type' => 'domain',
            'credits' => 1,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditMeteringCallSitesTest`
Expected: FAIL — balances stay at 10 (`Failed asserting that 10 is identical to 9`) because no hook meters yet.

- [ ] **Step 3: Wire the hooks**

`app/Models/Monitor.php` — add imports:

```php
use App\Services\CreditMeteringService;
use Exception;
use Spatie\SslCertificate\SslCertificate;
```

Make metering the first line of both existing uptime overrides:

```php
    public function uptimeRequestSucceeded(ResponseInterface $response): void
    {
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        parent::uptimeRequestSucceeded($response);
        // ... existing body unchanged ...
```

```php
    public function uptimeRequestFailed(string $reason): void
    {
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_UPTIME);

        parent::uptimeRequestFailed($reason);
        // ... existing body unchanged ...
```

Add the two certificate overrides after `uptimeRequestFailed()`:

```php
    /**
     * Vendor checkCertificate() branches into exactly one of these two per
     * executed certificate check — that makes them the single metering
     * point for certificate billing.
     */
    public function setCertificate(SslCertificate $certificate): void
    {
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_CERTIFICATE);

        parent::setCertificate($certificate);
    }

    public function setCertificateException(Exception $exception): void
    {
        app(CreditMeteringService::class)->recordCheck($this, MonitorCheckLogService::CHECK_TYPE_CERTIFICATE);

        parent::setCertificateException($exception);
    }
```

`app/Console/Commands/CheckDomainExpiration.php` — add imports `use App\Services\CreditMeteringService;` and `use App\Services\MonitorCheckLogService;`, then meter inside the loop:

```php
        foreach ($monitors as $monitor) {
            app(CreditMeteringService::class)->recordCheck($monitor, MonitorCheckLogService::CHECK_TYPE_DOMAIN);

            $result = $domainService->verifyDomainExpiration($monitor);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditMeteringCallSitesTest`
Expected: PASS (6 tests). Also run `php artisan test --filter=MonitorHistory` — the history logging tests must still pass (metering is additive).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Models/Monitor.php app/Console/Commands/CheckDomainExpiration.php tests/Feature/Credits/CreditMeteringCallSitesTest.php
git add app/Models/Monitor.php app/Console/Commands/CheckDomainExpiration.php tests/Feature/Credits/CreditMeteringCallSitesTest.php
git commit -m "feat(credits): meter every uptime, certificate, and domain check"
```

---

### Task 6: Enforcement — zero-balance orgs are excluded from all checks

**Files:**
- Modify: `app/Models/Monitor.php`
- Modify: `app/Console/Commands/CheckDomainExpiration.php`
- Test: `tests/Feature/Credits/CreditEnforcementTest.php`

**Interfaces:**
- Consumes: Task 1 `credit_balance` column.
- Produces: `Monitor::scopeEnabled()` override (balance gate for vendor `MonitorRepository::getForUptimeCheck()` / `getForCertificateCheck()` / `getEnabled()`, which all funnel through `config('uptime-monitor.monitor_model')::enabled()`); `Monitor::scopeDomainCheckEnabled()` gains the same gate; the domain command's `--force` path is also gated (billing must not be bypassable).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\UptimeMonitor\MonitorRepository;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditEnforcementTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function orgWithBalance(int $balance): Organization
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);

        return $organization->fresh();
    }

    public function test_uptime_selection_excludes_zero_balance_orgs(): void
    {
        $funded = Monitor::factory()->forOrganization($this->orgWithBalance(100))->create();
        Monitor::factory()->forOrganization($this->orgWithBalance(0))->create();

        $selected = MonitorRepository::getForUptimeCheck();

        $this->assertSame([$funded->id], $selected->pluck('id')->all());
    }

    public function test_certificate_selection_excludes_zero_balance_orgs(): void
    {
        $funded = Monitor::factory()->forOrganization($this->orgWithBalance(100))
            ->create(['certificate_check_enabled' => true]);
        Monitor::factory()->forOrganization($this->orgWithBalance(0))
            ->create(['certificate_check_enabled' => true]);

        $selected = MonitorRepository::getForCertificateCheck();

        $this->assertSame([$funded->id], $selected->pluck('id')->all());
    }

    public function test_domain_selection_excludes_zero_balance_orgs(): void
    {
        $funded = Monitor::factory()->forOrganization($this->orgWithBalance(100))
            ->create(['domain_check_enabled' => true]);
        Monitor::factory()->forOrganization($this->orgWithBalance(0))
            ->create(['domain_check_enabled' => true]);

        $selected = Monitor::domainCheckEnabled();

        $this->assertSame([$funded->id], $selected->pluck('id')->all());
    }

    public function test_check_uptime_command_checks_zero_monitors_when_all_orgs_are_exhausted(): void
    {
        Monitor::factory()->forOrganization($this->orgWithBalance(0))->create();

        // Safe to run for real: with every org at zero, no HTTP request is made.
        $this->artisan('monitor:check-uptime')
            ->expectsOutputToContain('Start checking the uptime of 0 monitors')
            ->assertSuccessful();
    }

    public function test_soft_deleted_org_monitors_are_also_excluded(): void
    {
        $organization = $this->orgWithBalance(100);
        Monitor::factory()->forOrganization($organization)->create();
        $organization->delete();

        $this->assertCount(0, MonitorRepository::getForUptimeCheck());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditEnforcementTest`
Expected: FAIL — zero-balance monitors are still selected.

- [ ] **Step 3: Add the balance gate**

`app/Models/Monitor.php` — add the override (place it above `scopeDomainCheckEnabled`) and update the domain scope:

```php
    /**
     * Overrides the vendor scope. Spatie's MonitorRepository funnels every
     * check-selection query through Monitor::enabled() (resolved via config
     * uptime-monitor.monitor_model), so the balance gate here pauses uptime
     * AND certificate checks for organizations that are out of credits.
     * whereHas('organization') also drops monitors of soft-deleted orgs.
     */
    public function scopeEnabled($query)
    {
        return $query
            ->where(function ($q) {
                $q->where('uptime_check_enabled', true)
                    ->orWhere('certificate_check_enabled', true);
            })
            ->whereHas('organization', fn ($q) => $q->where('credit_balance', '>', 0));
    }

    public function scopeDomainCheckEnabled(Builder $query): Collection
    {
        return $query
            ->where('domain_check_enabled', true)
            ->whereHas('organization', fn ($q) => $q->where('credit_balance', '>', 0))
            ->get();
    }
```

`app/Console/Commands/CheckDomainExpiration.php` — gate the `--force` path too (checks cost credits, so `--force` must not bypass billing):

```php
        $monitors = $this->option('force')
            ? Monitor::whereHas('organization', fn ($query) => $query->where('credit_balance', '>', 0))->get()
            : Monitor::domainCheckEnabled();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditEnforcementTest`
Expected: PASS (5 tests). Also run `php artisan test --filter=ConsoleScopeRegressionTest` (existing console-scope tests must not regress).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Models/Monitor.php app/Console/Commands/CheckDomainExpiration.php tests/Feature/Credits/CreditEnforcementTest.php
git add app/Models/Monitor.php app/Console/Commands/CheckDomainExpiration.php tests/Feature/Credits/CreditEnforcementTest.php
git commit -m "feat(credits): pause all checks for organizations without credits"
```

---

### Task 7: CreditRunwayService (config-derived burn + runway)

**Files:**
- Create: `app/Services/CreditRunwayService.php`
- Test: `tests/Feature/Credits/CreditRunwayServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models.
- Produces: `CreditRunwayService::dailyBurnFor(Organization $organization): int`; `dailyBurnForMonitor(Monitor $monitor): int` (1440/interval for uptime + 1 for certificate + 1 for domain); `runwayDaysFor(Organization $organization): ?float` (null when burn is 0 — "credits aren't being consumed"). Computed on read, never stored.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Services\CreditRunwayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditRunwayServiceTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_daily_burn_across_configurations(): void
    {
        $organization = $this->createOrganization();
        $service = app(CreditRunwayService::class);

        // 5-min uptime only: 1440/5 = 288
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5,
        ]);
        $this->assertSame(288, $service->dailyBurnFor($organization));

        // + 1-min uptime with certificate and domain: 1440 + 1 + 1
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 1,
            'certificate_check_enabled' => true,
            'domain_check_enabled' => true,
        ]);
        $this->assertSame(288 + 1442, $service->dailyBurnFor($organization));
    }

    public function test_disabled_and_soft_deleted_monitors_burn_nothing(): void
    {
        $organization = $this->createOrganization();
        $service = app(CreditRunwayService::class);

        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_enabled' => false,
            'certificate_check_enabled' => false,
            'domain_check_enabled' => false,
        ]);
        $this->assertSame(0, $service->dailyBurnFor($organization));

        $deleted = Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5,
        ]);
        $deleted->delete();
        $this->assertSame(0, $service->dailyBurnFor($organization));
    }

    public function test_runway_days(): void
    {
        $organization = $this->createOrganization();
        $service = app(CreditRunwayService::class);

        // No burn -> no runway concept.
        $this->assertNull($service->runwayDaysFor($organization));

        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5, // 288/day
        ]);

        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => 2880]);
        $this->assertEqualsWithDelta(10.0, $service->runwayDaysFor($organization->fresh()), 0.001);

        // Negative balances clamp to zero runway.
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => -5]);
        $this->assertSame(0.0, $service->runwayDaysFor($organization->fresh()));
    }

    public function test_interval_is_floored_at_one_minute(): void
    {
        $organization = $this->createOrganization();
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 0,
        ]);

        $this->assertSame(1440, app(CreditRunwayService::class)->dailyBurnFor($organization));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditRunwayServiceTest`
Expected: FAIL — `Class "App\Services\CreditRunwayService" not found`.

- [ ] **Step 3: Implement the service**

`app/Services/CreditRunwayService.php`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditRunwayServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Services/CreditRunwayService.php tests/Feature/Credits/CreditRunwayServiceTest.php
git add app/Services/CreditRunwayService.php tests/Feature/Credits/CreditRunwayServiceTest.php
git commit -m "feat(credits): config-derived runway projection service"
```

---

### Task 8: `credits:rollup-usage` command + schedule

**Files:**
- Create: `app/Console/Commands/RollupCreditUsage.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Credits/RollupCreditUsageTest.php`

**Interfaces:**
- Consumes: Task 3 `CreditLedgerService::recordUsageDebit()`; Task 1 models.
- Produces: `credits:rollup-usage {--date=}` — one `usage_debit` transaction per org per day, idempotent via `metadata->date`; scheduled `dailyAt('00:15')`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Services\MonitorCheckLogService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class RollupCreditUsageTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_rollup_writes_one_debit_per_org_and_is_idempotent(): void
    {
        $orgA = $this->createOrganization();
        $orgB = $this->createOrganization();
        DB::table('organizations')->whereIn('id', [$orgA->id, $orgB->id])->update(['credit_balance' => 1000]);
        $monitorA = Monitor::factory()->forOrganization($orgA)->create();
        $monitorB = Monitor::factory()->forOrganization($orgB)->create();

        CreditUsageDaily::create(['organization_id' => $orgA->id, 'monitor_id' => $monitorA->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => '2026-07-09', 'credits' => 288]);
        CreditUsageDaily::create(['organization_id' => $orgA->id, 'monitor_id' => $monitorA->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_DOMAIN, 'date' => '2026-07-09', 'credits' => 1]);
        CreditUsageDaily::create(['organization_id' => $orgB->id, 'monitor_id' => $monitorB->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME, 'date' => '2026-07-09', 'credits' => 144]);
        // A different day must NOT be picked up.
        CreditUsageDaily::create(['organization_id' => $orgB->id, 'monitor_id' => $monitorB->id, 'check_type' => MonitorCheckLogService::CHECK_TYPE_CERTIFICATE, 'date' => '2026-07-08', 'credits' => 1]);

        $this->artisan('credits:rollup-usage', ['--date' => '2026-07-09'])->assertSuccessful();

        $debits = CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->get();
        $this->assertCount(2, $debits);
        $this->assertSame(-289, $debits->firstWhere('organization_id', $orgA->id)->amount);
        $this->assertSame(-144, $debits->firstWhere('organization_id', $orgB->id)->amount);
        // Balance untouched — it was decremented live.
        $this->assertSame(1000, $orgA->fresh()->credit_balance);

        // Second run: no duplicates.
        $this->artisan('credits:rollup-usage', ['--date' => '2026-07-09'])->assertSuccessful();
        $this->assertSame(2, CreditTransaction::where('type', CreditTransaction::TYPE_USAGE_DEBIT)->count());
    }

    public function test_rollup_defaults_to_yesterday_utc(): void
    {
        $organization = $this->createOrganization();
        $monitor = Monitor::factory()->forOrganization($organization)->create();
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->subDay()->toDateString(),
            'credits' => 10,
        ]);

        $this->artisan('credits:rollup-usage')->assertSuccessful();

        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_USAGE_DEBIT,
            'amount' => -10,
        ]);
    }

    public function test_rollup_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'credits:rollup-usage')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RollupCreditUsageTest`
Expected: FAIL — `There are no commands defined in the "credits" namespace.`

- [ ] **Step 3: Implement command + schedule**

`app/Console/Commands/RollupCreditUsage.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Organization;
use App\Services\CreditLedgerService;
use Illuminate\Console\Command;

class RollupCreditUsage extends Command
{
    protected $signature = 'credits:rollup-usage
                            {--date= : UTC date (Y-m-d) to roll up; defaults to yesterday}';

    protected $description = 'Write one usage_debit ledger transaction per organization for a day of metered usage';

    public function handle(CreditLedgerService $ledger): int
    {
        $date = $this->option('date') ?? now('UTC')->subDay()->toDateString();

        $totals = CreditUsageDaily::query()
            ->where('date', $date)
            ->groupBy('organization_id')
            ->selectRaw('organization_id, sum(credits) as total')
            ->pluck('total', 'organization_id');

        $written = 0;

        foreach ($totals as $organizationId => $total) {
            $organization = Organization::withTrashed()->find($organizationId);

            if (! $organization) {
                continue;
            }

            $alreadyRolledUp = CreditTransaction::query()
                ->where('organization_id', $organizationId)
                ->where('type', CreditTransaction::TYPE_USAGE_DEBIT)
                ->where('metadata->date', $date)
                ->exists();

            if ($alreadyRolledUp) {
                continue;
            }

            $ledger->recordUsageDebit($organization, (int) $total, $date);
            $written++;
        }

        $this->info("Rolled up credit usage for {$date}: {$written} organization(s).");

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add after the `organizations:purge-deleted` block:

```php
// Credit system: nightly ledger rollup of yesterday's metered usage, then
// warning-level evaluation (order matters only for tidy ledger timestamps —
// warnings read live config, not the rollup).
Schedule::command('credits:rollup-usage')
    ->dailyAt('00:15')
    ->withoutOverlapping();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RollupCreditUsageTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Console/Commands/RollupCreditUsage.php routes/console.php tests/Feature/Credits/RollupCreditUsageTest.php
git add app/Console/Commands/RollupCreditUsage.php routes/console.php tests/Feature/Credits/RollupCreditUsageTest.php
git commit -m "feat(credits): nightly usage-debit rollup command"
```

---

### Task 9: `credits:evaluate-warnings` command + schedule

**Files:**
- Create: `app/Console/Commands/EvaluateCreditWarnings.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Credits/EvaluateCreditWarningsTest.php`

**Interfaces:**
- Consumes: Task 7 `CreditRunwayService::runwayDaysFor()`; Task 2 `CreditBalanceLow` / `CreditBalanceCritical`; config `credits.warning_days.*`.
- Produces: `credits:evaluate-warnings` — escalates `credit_warning_level` (`none → low → critical`; `exhausted` is set live by metering, never here), emails admins once per escalation, clears the level when runway is healthy again. Escalation-only within the warning bands: it never de-escalates `critical → low` (conservative; a grant is what resets it). Scheduled `dailyAt('00:30')`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CreditBalanceCritical;
use App\Notifications\CreditBalanceLow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class EvaluateCreditWarningsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    /** @return array{0: Organization, 1: User} */
    private function orgBurning288PerDay(int $balance): array
    {
        $organization = $this->createOrganization();
        $admin = User::factory()->create();
        $organization->users()->attach($admin->id, ['role' => Organization::ROLE_ADMIN]);
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5, // 288 credits/day
        ]);
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => $balance]);

        return [$organization->fresh(), $admin];
    }

    public function test_low_runway_escalates_and_emails_once(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(288 * 5); // 5 days < low threshold (7)

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_LOW, $organization->fresh()->credit_warning_level);
        Notification::assertSentToTimes($admin, CreditBalanceLow::class, 1);

        // Re-running at the same level must not re-email.
        $this->artisan('credits:evaluate-warnings')->assertSuccessful();
        Notification::assertSentToTimes($admin, CreditBalanceLow::class, 1);
    }

    public function test_critical_runway_escalates_past_low(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(288); // 1 day < critical threshold (2)

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_CRITICAL, $organization->fresh()->credit_warning_level);
        Notification::assertSentToTimes($admin, CreditBalanceCritical::class, 1);
        Notification::assertNotSentTo($admin, CreditBalanceLow::class);
    }

    public function test_healthy_runway_clears_stale_warning_silently(): void
    {
        Notification::fake();
        [$organization, $admin] = $this->orgBurning288PerDay(288 * 100); // 100 days
        DB::table('organizations')->where('id', $organization->id)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_LOW]);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $organization->fresh()->credit_warning_level);
        Notification::assertNothingSent();
    }

    public function test_exhausted_and_zero_burn_orgs_are_skipped(): void
    {
        Notification::fake();

        // Exhausted org: pause/resume flow owns it, not the warning job.
        [$exhausted] = $this->orgBurning288PerDay(0);
        DB::table('organizations')->where('id', $exhausted->id)
            ->update(['credit_warning_level' => Organization::CREDIT_LEVEL_EXHAUSTED]);

        // Zero-burn org with a tiny balance: no runway concept, no warning.
        $idle = $this->createOrganization();
        DB::table('organizations')->where('id', $idle->id)->update(['credit_balance' => 3]);

        $this->artisan('credits:evaluate-warnings')->assertSuccessful();

        $this->assertSame(Organization::CREDIT_LEVEL_EXHAUSTED, $exhausted->fresh()->credit_warning_level);
        $this->assertSame(Organization::CREDIT_LEVEL_NONE, $idle->fresh()->credit_warning_level);
        Notification::assertNothingSent();
    }

    public function test_warnings_are_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command ?? '', 'credits:evaluate-warnings')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EvaluateCreditWarningsTest`
Expected: FAIL — `There are no commands defined in the "credits" namespace` (or only rollup-usage exists).

- [ ] **Step 3: Implement command + schedule**

`app/Console/Commands/EvaluateCreditWarnings.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Notifications\CreditBalanceCritical;
use App\Notifications\CreditBalanceLow;
use App\Services\CreditRunwayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class EvaluateCreditWarnings extends Command
{
    protected $signature = 'credits:evaluate-warnings';

    protected $description = 'Escalate per-organization credit warning levels from projected runway and email org admins';

    private const LEVEL_RANK = [
        Organization::CREDIT_LEVEL_NONE => 0,
        Organization::CREDIT_LEVEL_LOW => 1,
        Organization::CREDIT_LEVEL_CRITICAL => 2,
        Organization::CREDIT_LEVEL_EXHAUSTED => 3,
    ];

    public function handle(CreditRunwayService $runway): int
    {
        Organization::query()->each(function (Organization $organization) use ($runway) {
            // Exhaustion is owned by the live zero-crossing in metering;
            // grants own the reset. This job only handles low/critical.
            if ($organization->credit_balance <= 0) {
                return;
            }

            $days = $runway->runwayDaysFor($organization);

            if ($days === null) {
                return; // nothing is consuming credits
            }

            $target = Organization::CREDIT_LEVEL_NONE;

            if ($days <= (int) config('credits.warning_days.critical')) {
                $target = Organization::CREDIT_LEVEL_CRITICAL;
            } elseif ($days <= (int) config('credits.warning_days.low')) {
                $target = Organization::CREDIT_LEVEL_LOW;
            }

            if ($target === Organization::CREDIT_LEVEL_NONE) {
                // Healthy again (e.g. monitors were removed): clear silently.
                if ($organization->credit_warning_level !== Organization::CREDIT_LEVEL_NONE) {
                    $organization->forceFill(['credit_warning_level' => Organization::CREDIT_LEVEL_NONE])->save();
                }

                return;
            }

            // Escalation-only: same or lower severity never re-emails.
            if (self::LEVEL_RANK[$target] <= self::LEVEL_RANK[$organization->credit_warning_level]) {
                return;
            }

            $organization->forceFill(['credit_warning_level' => $target])->save();

            $notification = $target === Organization::CREDIT_LEVEL_CRITICAL
                ? new CreditBalanceCritical($organization, $days)
                : new CreditBalanceLow($organization, $days);

            Notification::send($organization->admins()->get(), $notification);
        });

        $this->info('Credit warning levels evaluated.');

        return self::SUCCESS;
    }
}
```

Note: `forceFill()` is required because `credit_warning_level` is intentionally not fillable.

`routes/console.php` — add directly below the rollup schedule:

```php
Schedule::command('credits:evaluate-warnings')
    ->dailyAt('00:30')
    ->withoutOverlapping();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EvaluateCreditWarningsTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Console/Commands/EvaluateCreditWarnings.php routes/console.php tests/Feature/Credits/EvaluateCreditWarningsTest.php
git add app/Console/Commands/EvaluateCreditWarnings.php routes/console.php tests/Feature/Credits/EvaluateCreditWarningsTest.php
git commit -m "feat(credits): daily runway-based warning escalation"
```

---

### Task 10: Default grant on organization creation

**Files:**
- Modify: `app/Http/Controllers/OrganizationsController.php`
- Test: `tests/Feature/Credits/DefaultGrantTest.php`

**Interfaces:**
- Consumes: Task 3 `CreditLedgerService::grant()`; config `credits.default_grant`.
- Produces: new orgs start with `credits.default_grant` credits via a normal `grant` ledger row (description "Initial grant", `created_by` = acting super-admin); 0 disables it.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class DefaultGrantTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    private function onboardOrganization(): Organization
    {
        $this->post(route('organizations.store'), [
            'name' => 'Fresh Org',
            'admin_name' => 'Ada Admin',
            'admin_email' => 'ada@fresh.test',
            'admin_password' => 'secret-password',
        ])->assertRedirect(route('organizations.index'));

        return Organization::where('name', 'Fresh Org')->firstOrFail();
    }

    public function test_new_org_receives_the_default_grant(): void
    {
        config(['credits.default_grant' => 500000]);
        $superAdmin = $this->actingAsSuperAdmin();

        $organization = $this->onboardOrganization();

        $this->assertSame(500000, $organization->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 500000,
            'description' => 'Initial grant',
            'created_by' => $superAdmin->id,
        ]);
    }

    public function test_zero_default_grant_is_disabled(): void
    {
        config(['credits.default_grant' => 0]);
        $this->actingAsSuperAdmin();

        $organization = $this->onboardOrganization();

        $this->assertSame(0, $organization->credit_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DefaultGrantTest`
Expected: FAIL — balance stays 0 with `credits.default_grant = 500000`.

- [ ] **Step 3: Hook the grant into store()**

`app/Http/Controllers/OrganizationsController.php` — add import `use App\Services\CreditLedgerService;`, then in `store()`, after the `$organization->users()->syncWithoutDetaching([...])` call and before the redirect:

```php
        $defaultGrant = (int) config('credits.default_grant');

        if ($defaultGrant > 0) {
            app(CreditLedgerService::class)->grant($organization, $defaultGrant, $request->user(), 'Initial grant');
        }

        return redirect()->route('organizations.index');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DefaultGrantTest`
Expected: PASS (2 tests). Also run `php artisan test --filter=OrganizationOnboardingTest` (existing onboarding tests must still pass).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Http/Controllers/OrganizationsController.php tests/Feature/Credits/DefaultGrantTest.php
git add app/Http/Controllers/OrganizationsController.php tests/Feature/Credits/DefaultGrantTest.php
git commit -m "feat(credits): default credit grant on organization onboarding"
```

---

### Task 11: Shared Inertia `credits` props + JS runway utility

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/Utils/creditRunway.js`
- Test: `tests/Feature/Credits/SharedCreditPropsTest.php`, `resources/js/Utils/creditRunway.test.js`

**Interfaces:**
- Consumes: Task 7 `CreditRunwayService::dailyBurnFor()`.
- Produces: shared prop `auth.credits = { balance: int, dailyBurn: int, warningLevel: string } | null` (null when no active org — e.g. the super-admin organizations screens). JS: `dailyBurnForConfig({ intervalMinutes, uptimeEnabled, certificateEnabled, domainEnabled }): number` and `runwayLabel(balance, dailyBurn): string` — bands: "credits aren't being consumed" (burn ≤ 0), "out of credits" (balance ≤ 0), "over a year" (≥ 365d), "~N months" (≥ 60d), "~N weeks" (≥ 14d), "~N days" (≥ 1.5d), "~1 day", "~N hours" (≥ 1h), "less than an hour".

- [ ] **Step 1: Write the failing PHP test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class SharedCreditPropsTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_active_org_pages_share_credit_props(): void
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => 2880]);
        Monitor::factory()->forOrganization($organization)->create([
            'uptime_check_interval_in_minutes' => 5, // 288/day
        ]);

        $this->actingAsMember($organization);

        $this->get('/monitors')->assertInertia(fn ($page) => $page
            ->where('auth.credits.balance', 2880)
            ->where('auth.credits.dailyBurn', 288)
            ->where('auth.credits.warningLevel', 'none'));
    }

    public function test_credits_prop_is_null_without_an_active_org(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/organizations')->assertInertia(fn ($page) => $page
            ->where('auth.credits', null));
    }
}
```

Note: super-admins CAN have a resolvable active org (the `auth` closure falls back to the session org). The second test relies on a fresh super-admin session with no orgs existing, so `$active` is null.

- [ ] **Step 2: Write the failing JS test**

`resources/js/Utils/creditRunway.test.js`:

```js
import { describe, it, expect } from "vitest";
import { dailyBurnForConfig, runwayLabel } from "@/Utils/creditRunway";

describe("dailyBurnForConfig", () => {
    it("charges 1440/interval for uptime", () => {
        expect(
            dailyBurnForConfig({ intervalMinutes: "5", uptimeEnabled: true, domainEnabled: false })
        ).toBe(288);
    });

    it("adds 1/day each for certificate and domain", () => {
        expect(
            dailyBurnForConfig({
                intervalMinutes: "1",
                uptimeEnabled: true,
                certificateEnabled: true,
                domainEnabled: true,
            })
        ).toBe(1442);
    });

    it("burns nothing when all checks are off", () => {
        expect(
            dailyBurnForConfig({ intervalMinutes: "5", uptimeEnabled: false, domainEnabled: false })
        ).toBe(0);
    });

    it("floors bad intervals at one minute", () => {
        expect(
            dailyBurnForConfig({ intervalMinutes: "0", uptimeEnabled: true, domainEnabled: false })
        ).toBe(1440);
        expect(
            dailyBurnForConfig({ intervalMinutes: "garbage", uptimeEnabled: true, domainEnabled: false })
        ).toBe(1440);
    });
});

describe("runwayLabel", () => {
    it("handles zero burn before zero balance", () => {
        expect(runwayLabel(0, 0)).toBe("credits aren't being consumed");
        expect(runwayLabel(500, 0)).toBe("credits aren't being consumed");
    });

    it("reports out of credits", () => {
        expect(runwayLabel(0, 288)).toBe("out of credits");
        expect(runwayLabel(-3, 288)).toBe("out of credits");
    });

    it("picks human units by magnitude", () => {
        expect(runwayLabel(288 * 400, 288)).toBe("over a year");
        expect(runwayLabel(288 * 90, 288)).toBe("~3 months");
        expect(runwayLabel(288 * 21, 288)).toBe("~3 weeks");
        expect(runwayLabel(288 * 7, 288)).toBe("~7 days");
        expect(runwayLabel(288, 288)).toBe("~1 day");
        expect(runwayLabel(216, 288)).toBe("~18 hours");
        expect(runwayLabel(5, 288)).toBe("less than an hour");
    });
});
```

- [ ] **Step 3: Run both to verify they fail**

Run: `php artisan test --filter=SharedCreditPropsTest` — Expected: FAIL (`auth.credits` prop missing).
Run: `source ~/.nvm/nvm.sh && nvm use 22 && npm run test:js` — Expected: FAIL (`creditRunway` module not found).

- [ ] **Step 4: Implement**

`app/Http/Middleware/HandleInertiaRequests.php` — add import `use App\Services\CreditRunwayService;`, then inside the `auth` closure's return array, directly after the `'activeOrganization' => ...` entry:

```php
                    // One indexed 4-column query per request; the runway is
                    // config-derived and computed on read, so monitor edits
                    // are reflected on the very next Inertia response.
                    'credits' => $active ? [
                        'balance' => $active->credit_balance,
                        'dailyBurn' => app(CreditRunwayService::class)->dailyBurnFor($active),
                        'warningLevel' => $active->credit_warning_level,
                    ] : null,
```

`resources/js/Utils/creditRunway.js`:

```js
// Mirrors App\Services\CreditRunwayService — keep the burn math in sync.
export function dailyBurnForConfig({
    intervalMinutes,
    uptimeEnabled,
    certificateEnabled = false,
    domainEnabled,
}) {
    let burn = 0;

    if (uptimeEnabled) {
        const interval = Math.max(1, parseInt(intervalMinutes, 10) || 1);
        burn += Math.floor(1440 / interval);
    }

    if (certificateEnabled) burn += 1;
    if (domainEnabled) burn += 1;

    return burn;
}

export function runwayLabel(balance, dailyBurn) {
    if (dailyBurn <= 0) return "credits aren't being consumed";
    if (balance <= 0) return "out of credits";

    const days = balance / dailyBurn;

    if (days >= 365) return "over a year";
    if (days >= 60) return `~${Math.round(days / 30)} months`;
    if (days >= 14) return `~${Math.round(days / 7)} weeks`;
    if (days >= 1.5) return `~${Math.round(days)} days`;
    if (days >= 1) return "~1 day";

    const hours = days * 24;
    if (hours >= 1) return `~${Math.round(hours)} hours`;

    return "less than an hour";
}
```

- [ ] **Step 5: Run both to verify they pass**

Run: `php artisan test --filter=SharedCreditPropsTest` — Expected: PASS (2 tests).
Run: `source ~/.nvm/nvm.sh && nvm use 22 && npm run test:js` — Expected: PASS (all suites, including the 7 new creditRunway tests).

- [ ] **Step 6: Style + commit**

```bash
./vendor/bin/pint app/Http/Middleware/HandleInertiaRequests.php tests/Feature/Credits/SharedCreditPropsTest.php
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/Utils/creditRunway.js resources/js/Utils/creditRunway.test.js tests/Feature/Credits/SharedCreditPropsTest.php
git commit -m "feat(credits): shared credit props and JS runway utility"
```

---

### Task 12: Org-facing credits page (`/credits`)

**Files:**
- Create: `app/Http/Controllers/CreditsController.php`
- Create: `resources/js/Pages/Credits/Index.jsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/Credits/CreditsPageTest.php`

**Interfaces:**
- Consumes: Tasks 1/7 models + runway service; shared `auth.credits` (Task 11).
- Produces: route `credits.index` (GET `/credits`, inside the `active.organization` group, viewable by admins AND members); Inertia component `Credits/Index` with props `balance`, `warningLevel`, `dailyBurn`, `usageByDay` (last 30 days: `[{date, total, byType}]`), `topMonitors` (`[{monitor_id, name, credits}]`, top 5, deleted monitors labeled), `transactions` (latest 25: `[{id, type, amount, balance_after, description, created_by, created_at}]`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Models\Organization;
use App\Services\CreditLedgerService;
use App\Services\MonitorCheckLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CreditsPageTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_member_sees_balance_usage_and_transactions(): void
    {
        $organization = $this->createOrganization();
        DB::table('organizations')->where('id', $organization->id)->update(['credit_balance' => 1000]);
        $monitor = Monitor::factory()->forOrganization($organization)->create(['name' => 'Main site']);
        CreditUsageDaily::create([
            'organization_id' => $organization->id,
            'monitor_id' => $monitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->toDateString(),
            'credits' => 288,
        ]);
        app(CreditLedgerService::class)->grant($organization->fresh(), 500, null, 'Top up');

        $this->actingAsMember($organization);

        $this->get('/credits')->assertInertia(fn ($page) => $page
            ->component('Credits/Index')
            ->where('balance', 1500)
            ->where('usageByDay.0.total', 288)
            ->where('topMonitors.0.name', 'Main site')
            ->where('topMonitors.0.credits', 288)
            ->where('transactions.0.type', 'grant')
            ->where('transactions.0.amount', 500));
    }

    public function test_usage_is_scoped_to_the_active_org(): void
    {
        $mine = $this->createOrganization();
        $theirs = $this->createOrganization();
        $theirMonitor = Monitor::factory()->forOrganization($theirs)->create();
        CreditUsageDaily::create([
            'organization_id' => $theirs->id,
            'monitor_id' => $theirMonitor->id,
            'check_type' => MonitorCheckLogService::CHECK_TYPE_UPTIME,
            'date' => now('UTC')->toDateString(),
            'credits' => 99,
        ]);

        $this->actingAsMember($mine);

        $this->get('/credits')->assertInertia(fn ($page) => $page
            ->component('Credits/Index')
            ->has('usageByDay', 0)
            ->has('transactions', 0));
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/credits')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CreditsPageTest`
Expected: FAIL — 404 (route not defined).

- [ ] **Step 3: Implement route, controller, page**

`routes/web.php` — inside the `Route::middleware('active.organization')->group(...)`, after the `users` resource line, add (import `use App\Http\Controllers\CreditsController;` at the top):

```php
        Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
```

`app/Http/Controllers/CreditsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\CreditUsageDaily;
use App\Models\Monitor;
use App\Services\CreditRunwayService;
use App\Support\CurrentOrganization;
use Inertia\Inertia;

class CreditsController extends Controller
{
    public function index(CreditRunwayService $runway)
    {
        $organization = app(CurrentOrganization::class)->get();

        $usageRows = CreditUsageDaily::query()
            ->where('organization_id', $organization->id)
            ->where('date', '>=', now('UTC')->subDays(29)->toDateString())
            ->get();

        $usageByDay = $usageRows
            ->groupBy(fn (CreditUsageDaily $row) => $row->date->toDateString())
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'total' => (int) $rows->sum('credits'),
                'byType' => $rows->groupBy('check_type')
                    ->map(fn ($typeRows) => (int) $typeRows->sum('credits')),
            ])
            ->values()
            ->sortBy('date')
            ->values();

        $topMonitors = $usageRows
            ->whereNotNull('monitor_id')
            ->groupBy('monitor_id')
            ->map(fn ($rows, $monitorId) => [
                'monitor_id' => (int) $monitorId,
                'credits' => (int) $rows->sum('credits'),
            ])
            ->sortByDesc('credits')
            ->take(5)
            ->values();

        $monitorNames = Monitor::withTrashed()
            ->whereIn('id', $topMonitors->pluck('monitor_id'))
            ->pluck('name', 'id');

        $topMonitors = $topMonitors->map(fn (array $row) => $row + [
            'name' => $monitorNames[$row['monitor_id']] ?? 'Deleted monitor',
        ]);

        return Inertia::render('Credits/Index', [
            'balance' => $organization->credit_balance,
            'warningLevel' => $organization->credit_warning_level,
            'dailyBurn' => $runway->dailyBurnFor($organization),
            'usageByDay' => $usageByDay,
            'topMonitors' => $topMonitors,
            'transactions' => CreditTransaction::with('createdBy:id,name')
                ->where('organization_id', $organization->id)
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn (CreditTransaction $transaction) => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                    'description' => $transaction->description,
                    'created_by' => $transaction->createdBy?->name,
                    'created_at' => $transaction->created_at->toDateTimeString(),
                ]),
        ]);
    }
}
```

`resources/js/Pages/Credits/Index.jsx`:

```jsx
import React from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import { runwayLabel } from "@/Utils/creditRunway";

const TYPE_LABELS = { grant: "Grant", adjustment: "Adjustment", usage_debit: "Usage" };

export default function Index() {
    const {
        auth,
        balance,
        warningLevel,
        dailyBurn,
        usageByDay = [],
        topMonitors = [],
        transactions = [],
    } = usePage().props;

    const maxDay = Math.max(1, ...usageByDay.map((d) => d.total));

    return (
        <Authenticated auth={auth}>
            <Head title="Credits" />
            <PageHeader>
                <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Credits</h1>
            </PageHeader>
            <div className="max-w-5xl mx-auto py-8 px-6 lg:px-8 space-y-6">
                {balance <= 0 && (
                    <div className="rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 font-medium">
                        Monitoring paused — out of credits. Contact your service administrator to top up.
                    </div>
                )}

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <div className="text-sm text-gray-500">Current balance</div>
                    <div className="text-3xl font-bold text-gray-900 mt-1">
                        {balance.toLocaleString()} credits
                    </div>
                    <div className="text-sm text-gray-500 mt-2">
                        Burning {dailyBurn.toLocaleString()} credits/day at the current configuration —
                        credits last <span className="font-medium text-gray-700">{runwayLabel(balance, dailyBurn)}</span>.
                    </div>
                    {warningLevel !== "none" && balance > 0 && (
                        <div className="text-xs font-medium text-amber-600 mt-2 uppercase tracking-wide">
                            Warning level: {warningLevel}
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4">Usage — last 30 days</h2>
                    {usageByDay.length === 0 ? (
                        <div className="text-sm text-gray-400">No usage recorded yet.</div>
                    ) : (
                        <div className="flex items-end gap-1 h-32">
                            {usageByDay.map((day) => (
                                <div
                                    key={day.date}
                                    title={`${day.date}: ${day.total.toLocaleString()} credits`}
                                    className="flex-1 bg-purple-200 hover:bg-purple-300 rounded-t transition-colors"
                                    style={{ height: `${Math.max(4, (day.total / maxDay) * 100)}%` }}
                                />
                            ))}
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4">Top monitors — last 30 days</h2>
                    {topMonitors.length === 0 ? (
                        <div className="text-sm text-gray-400">No usage recorded yet.</div>
                    ) : (
                        <ul className="space-y-2">
                            {topMonitors.map((monitor) => (
                                <li key={monitor.monitor_id} className="flex justify-between text-sm">
                                    <span className="text-gray-700">{monitor.name}</span>
                                    <span className="text-gray-500">{monitor.credits.toLocaleString()} credits</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4">Transactions</h2>
                    {transactions.length === 0 ? (
                        <div className="text-sm text-gray-400">No transactions yet.</div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-gray-400 uppercase tracking-wide">
                                    <th className="pb-2">When</th>
                                    <th className="pb-2">Type</th>
                                    <th className="pb-2">Description</th>
                                    <th className="pb-2 text-right">Amount</th>
                                    <th className="pb-2 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {transactions.map((transaction) => (
                                    <tr key={transaction.id}>
                                        <td className="py-2 text-gray-500">{transaction.created_at}</td>
                                        <td className="py-2 text-gray-700">
                                            {TYPE_LABELS[transaction.type] ?? transaction.type}
                                        </td>
                                        <td className="py-2 text-gray-500">
                                            {transaction.description}
                                            {transaction.created_by && ` — ${transaction.created_by}`}
                                        </td>
                                        <td
                                            className={`py-2 text-right font-medium ${
                                                transaction.amount >= 0 ? "text-green-600" : "text-gray-700"
                                            }`}
                                        >
                                            {transaction.amount >= 0 ? "+" : ""}
                                            {transaction.amount.toLocaleString()}
                                        </td>
                                        <td className="py-2 text-right text-gray-500">
                                            {transaction.balance_after.toLocaleString()}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </Authenticated>
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CreditsPageTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Http/Controllers/CreditsController.php routes/web.php tests/Feature/Credits/CreditsPageTest.php
git add app/Http/Controllers/CreditsController.php resources/js/Pages/Credits routes/web.php tests/Feature/Credits/CreditsPageTest.php
git commit -m "feat(credits): org-facing credits page with usage and transactions"
```

---

### Task 13: Header runway chip

**Files:**
- Create: `resources/js/Components/CreditRunwayChip.jsx`
- Modify: `resources/js/Layouts/Authenticated.jsx`
- Verify: `source ~/.nvm/nvm.sh && nvm use 22 && npm run build` (no PHP test — pure presentational; the util it renders is covered by Task 11's Vitest suite)

**Interfaces:**
- Consumes: shared `auth.credits` (Task 11), `runwayLabel` (Task 11), route `credits.index` (Task 12).
- Produces: `<CreditRunwayChip credits={auth.credits} />` — renders nothing when `credits` is null; otherwise a pill linking to `/credits`, gray when healthy, amber at `low`, red at `critical`/`exhausted`/balance ≤ 0.

- [ ] **Step 1: Create the component**

`resources/js/Components/CreditRunwayChip.jsx`:

```jsx
import React from "react";
import { Link } from "@inertiajs/react";
import { runwayLabel } from "@/Utils/creditRunway";

const TONES = {
    danger: "bg-red-50 text-red-700 hover:bg-red-100",
    warning: "bg-amber-50 text-amber-700 hover:bg-amber-100",
    neutral: "bg-gray-100 text-gray-600 hover:bg-gray-200",
};

export default function CreditRunwayChip({ credits }) {
    if (!credits) return null;

    const tone =
        credits.balance <= 0 ||
        credits.warningLevel === "exhausted" ||
        credits.warningLevel === "critical"
            ? "danger"
            : credits.warningLevel === "low"
              ? "warning"
              : "neutral";

    return (
        <Link
            href={route("credits.index")}
            title={`${credits.balance.toLocaleString()} credits · ${credits.dailyBurn.toLocaleString()} credits/day`}
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors ${TONES[tone]}`}
        >
            <span
                className={`h-1.5 w-1.5 rounded-full ${
                    tone === "danger" ? "bg-red-500" : tone === "warning" ? "bg-amber-500" : "bg-green-500"
                }`}
            />
            {credits.balance <= 0 ? "Out of credits" : runwayLabel(credits.balance, credits.dailyBurn)}
        </Link>
    );
}
```

- [ ] **Step 2: Slot it into the layout**

`resources/js/Layouts/Authenticated.jsx` — add the import:

```jsx
import CreditRunwayChip from "@/Components/CreditRunwayChip";
```

The layout destructures `organizations`/`activeOrganization`/`isSuperAdmin`/`isOrgAdmin` from `auth`; the chip reads `auth.credits` directly. In the desktop header — inside `<div className="flex justify-between items-center h-16">`, immediately BEFORE the `{showSwitcher && (` block — add:

```jsx
                        <div className="hidden sm:flex sm:items-center ml-auto mr-3">
                            <CreditRunwayChip credits={auth.credits} />
                        </div>
```

(`ml-auto` pushes chip + switcher + avatar right as one cluster; the chip refreshes with every Inertia navigation, so a monitor edit updates it on the redirect back.)

- [ ] **Step 3: Verify the bundle builds**

Run: `source ~/.nvm/nvm.sh && nvm use 22 && npm run build`
Expected: vite build completes with no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/CreditRunwayChip.jsx resources/js/Layouts/Authenticated.jsx
git commit -m "feat(credits): always-visible runway chip in the app header"
```

---

### Task 14: Super-admin credit management

**Files:**
- Create: `app/Http/Controllers/OrganizationCreditsController.php`
- Create: `resources/js/Pages/Organizations/Credits.jsx`
- Modify: `routes/web.php`, `resources/js/Pages/Organizations/Index.jsx`
- Test: `tests/Feature/Credits/OrganizationCreditsManagementTest.php`

**Interfaces:**
- Consumes: Task 3 `CreditLedgerService`; Task 7 `CreditRunwayService`; gate `manage-organizations` (false for everyone; only the super-admin `Gate::before` passes it).
- Produces: routes `organizations.credits.show` (GET `/organizations/{organization}/credits`) and `organizations.credits.store` (POST same path; body `{amount: int != 0, description?: string}`; positive → `grant`, negative → `adjust`); balance shown on the Organizations index cards.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Credits;

use App\Models\CreditTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationCreditsManagementTest extends TestCase
{
    use InteractsWithOrganizations;
    use RefreshDatabase;

    public function test_super_admin_can_view_the_credits_panel(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();

        $this->get("/organizations/{$organization->id}/credits")
            ->assertInertia(fn ($page) => $page
                ->component('Organizations/Credits')
                ->where('organization.id', $organization->id)
                ->where('organization.credit_balance', 0)
                ->has('transactions'));
    }

    public function test_super_admin_can_grant_credits(): void
    {
        $organization = $this->createOrganization();
        $superAdmin = $this->actingAsSuperAdmin();

        $this->post("/organizations/{$organization->id}/credits", [
            'amount' => 500000,
            'description' => 'Annual top-up',
        ])->assertRedirect("/organizations/{$organization->id}/credits");

        $this->assertSame(500000, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'organization_id' => $organization->id,
            'type' => CreditTransaction::TYPE_GRANT,
            'amount' => 500000,
            'created_by' => $superAdmin->id,
        ]);
    }

    public function test_negative_amount_records_an_adjustment(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();
        $this->post("/organizations/{$organization->id}/credits", ['amount' => 1000]);

        $this->post("/organizations/{$organization->id}/credits", [
            'amount' => -200,
            'description' => 'Billing correction',
        ]);

        $this->assertSame(800, $organization->fresh()->credit_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'type' => CreditTransaction::TYPE_ADJUSTMENT,
            'amount' => -200,
        ]);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsSuperAdmin();

        $this->post("/organizations/{$organization->id}/credits", ['amount' => 0])
            ->assertSessionHasErrors('amount');
    }

    public function test_org_admins_cannot_view_or_grant(): void
    {
        $organization = $this->createOrganization();
        $this->actingAsAdmin($organization);

        $this->get("/organizations/{$organization->id}/credits")->assertForbidden();
        $this->post("/organizations/{$organization->id}/credits", ['amount' => 1000])->assertForbidden();
        $this->assertSame(0, $organization->fresh()->credit_balance);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OrganizationCreditsManagementTest`
Expected: FAIL — 404 (routes not defined).

- [ ] **Step 3: Implement routes, controller, pages**

`routes/web.php` — import `use App\Http\Controllers\OrganizationCreditsController;`, then directly after the `organizations.restore` route:

```php
    Route::get('/organizations/{organization}/credits', [OrganizationCreditsController::class, 'show'])
        ->name('organizations.credits.show');
    Route::post('/organizations/{organization}/credits', [OrganizationCreditsController::class, 'store'])
        ->name('organizations.credits.store');
```

`app/Http/Controllers/OrganizationCreditsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Services\CreditLedgerService;
use App\Services\CreditRunwayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationCreditsController extends Controller
{
    public function show(Organization $organization, CreditRunwayService $runway)
    {
        $this->authorize('manage-organizations');

        return Inertia::render('Organizations/Credits', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'credit_balance' => $organization->credit_balance,
                'credit_warning_level' => $organization->credit_warning_level,
            ],
            'dailyBurn' => $runway->dailyBurnFor($organization),
            'transactions' => CreditTransaction::with('createdBy:id,name')
                ->where('organization_id', $organization->id)
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (CreditTransaction $transaction) => [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                    'description' => $transaction->description,
                    'created_by' => $transaction->createdBy?->name,
                    'created_at' => $transaction->created_at->toDateTimeString(),
                ]),
        ]);
    }

    public function store(Request $request, Organization $organization, CreditLedgerService $ledger): RedirectResponse
    {
        $this->authorize('manage-organizations');

        $validated = $request->validate([
            'amount' => 'required|integer|not_in:0',
            'description' => 'nullable|string|max:255',
        ]);

        $amount = (int) $validated['amount'];
        $description = $validated['description'] ?? null;

        $amount > 0
            ? $ledger->grant($organization, $amount, $request->user(), $description)
            : $ledger->adjust($organization, $amount, $request->user(), $description);

        return redirect()->route('organizations.credits.show', $organization);
    }
}
```

`resources/js/Pages/Organizations/Credits.jsx`:

```jsx
import React, { useState } from "react";
import Authenticated from "@/Layouts/Authenticated";
import { Head, Link, router, usePage } from "@inertiajs/react";
import PageHeader from "@/Components/PageHeader";
import Button from "@/Components/Button";
import Input from "@/Components/Input";
import Label from "@/Components/Label";
import { runwayLabel } from "@/Utils/creditRunway";

const TYPE_LABELS = { grant: "Grant", adjustment: "Adjustment", usage_debit: "Usage" };

export default function Credits() {
    const { auth, organization, dailyBurn, transactions = [], errors = {} } = usePage().props;
    const [form, setForm] = useState({ amount: "", description: "" });

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post(route("organizations.credits.store", organization.id), form, {
            onSuccess: () => setForm({ amount: "", description: "" }),
        });
    };

    return (
        <Authenticated auth={auth}>
            <Head title={`Credits — ${organization.name}`} />
            <PageHeader>
                <div className="flex justify-between items-center">
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">
                        {organization.name} — Credits
                    </h1>
                    <Link
                        href={route("organizations.index")}
                        className="text-sm text-purple-600 hover:text-purple-800"
                    >
                        Back to organizations
                    </Link>
                </div>
            </PageHeader>
            <div className="max-w-3xl mx-auto py-8 px-6 lg:px-8 space-y-6">
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <div className="text-sm text-gray-500">Current balance</div>
                    <div className="text-3xl font-bold text-gray-900 mt-1">
                        {organization.credit_balance.toLocaleString()} credits
                    </div>
                    <div className="text-sm text-gray-500 mt-2">
                        {dailyBurn.toLocaleString()} credits/day — lasts{" "}
                        {runwayLabel(organization.credit_balance, dailyBurn)}. Warning level:{" "}
                        {organization.credit_warning_level}.
                    </div>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="bg-white rounded-xl border border-gray-200 shadow-sm p-6 space-y-4"
                >
                    <h2 className="text-sm font-semibold text-gray-700">Grant or adjust credits</h2>
                    <div>
                        <Label forInput="amount" value="Amount (negative for a correction)" />
                        <Input
                            type="number"
                            name="amount"
                            value={form.amount}
                            className="mt-1 block w-full"
                            handleChange={(e) => setForm({ ...form, amount: e.target.value })}
                        />
                        {errors.amount && (
                            <div className="text-sm text-red-600 mt-1">{errors.amount}</div>
                        )}
                    </div>
                    <div>
                        <Label forInput="description" value="Description (optional)" />
                        <Input
                            type="text"
                            name="description"
                            value={form.description}
                            className="mt-1 block w-full"
                            handleChange={(e) => setForm({ ...form, description: e.target.value })}
                        />
                    </div>
                    <Button>Apply</Button>
                </form>

                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4">Transactions</h2>
                    {transactions.length === 0 ? (
                        <div className="text-sm text-gray-400">No transactions yet.</div>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-gray-400 uppercase tracking-wide">
                                    <th className="pb-2">When</th>
                                    <th className="pb-2">Type</th>
                                    <th className="pb-2">Description</th>
                                    <th className="pb-2 text-right">Amount</th>
                                    <th className="pb-2 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {transactions.map((transaction) => (
                                    <tr key={transaction.id}>
                                        <td className="py-2 text-gray-500">{transaction.created_at}</td>
                                        <td className="py-2 text-gray-700">
                                            {TYPE_LABELS[transaction.type] ?? transaction.type}
                                        </td>
                                        <td className="py-2 text-gray-500">
                                            {transaction.description}
                                            {transaction.created_by && ` — ${transaction.created_by}`}
                                        </td>
                                        <td
                                            className={`py-2 text-right font-medium ${
                                                transaction.amount >= 0 ? "text-green-600" : "text-gray-700"
                                            }`}
                                        >
                                            {transaction.amount >= 0 ? "+" : ""}
                                            {transaction.amount.toLocaleString()}
                                        </td>
                                        <td className="py-2 text-right text-gray-500">
                                            {transaction.balance_after.toLocaleString()}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </Authenticated>
    );
}
```

`resources/js/Pages/Organizations/Index.jsx` — the active-org card meta line currently reads:

```jsx
                            <div className="text-xs text-gray-500 mt-0.5">
                                {org.users_count} users · {org.monitors_count} monitors
                            </div>
```

Replace with:

```jsx
                            <div className="text-xs text-gray-500 mt-0.5">
                                {org.users_count} users · {org.monitors_count} monitors ·{" "}
                                {(org.credit_balance ?? 0).toLocaleString()} credits
                            </div>
```

And add a "Credits" link before the "Rename" link in the card's action cluster:

```jsx
                            <Link
                                href={route("organizations.credits.show", org.id)}
                                className="text-sm text-purple-600 hover:text-purple-800"
                            >
                                Credits
                            </Link>
```

(No controller change needed: `OrganizationsController@index` returns full `Organization` models, so `credit_balance` is already serialized.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=OrganizationCreditsManagementTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Style + commit**

```bash
./vendor/bin/pint app/Http/Controllers/OrganizationCreditsController.php routes/web.php tests/Feature/Credits/OrganizationCreditsManagementTest.php
git add app/Http/Controllers/OrganizationCreditsController.php resources/js/Pages/Organizations routes/web.php tests/Feature/Credits/OrganizationCreditsManagementTest.php
git commit -m "feat(credits): super-admin credit granting and balance visibility"
```

---

### Task 15: Monitor-form credit impact preview

**Files:**
- Create: `resources/js/Components/MonitorCreditImpact.jsx`
- Modify: `resources/js/Pages/Monitors/Create.jsx`, `resources/js/Pages/Monitors/Edit.jsx`
- Verify: `source ~/.nvm/nvm.sh && nvm use 22 && npm run build` (util math covered by Task 11's Vitest suite)

**Interfaces:**
- Consumes: `dailyBurnForConfig` / `runwayLabel` (Task 11); shared `auth.credits` (Task 11).
- Produces: `<MonitorCreditImpact credits={auth.credits} burnBefore={n} form={form} certificateEnabled={bool} />` — live projected burn + runway as the user edits, before saving. NOTE: the forms have no certificate toggle, so `certificateEnabled` is held constant (false on Create; the monitor's stored value on Edit).

- [ ] **Step 1: Create the component**

`resources/js/Components/MonitorCreditImpact.jsx`:

```jsx
import React from "react";
import { dailyBurnForConfig, runwayLabel } from "@/Utils/creditRunway";

export default function MonitorCreditImpact({ credits, burnBefore = 0, form, certificateEnabled = false }) {
    if (!credits) return null;

    const burnAfter = dailyBurnForConfig({
        intervalMinutes: form.uptimeCheckInterval,
        uptimeEnabled: form.monitorUptime,
        certificateEnabled,
        domainEnabled: form.monitorDomain,
    });

    const orgBurnAfter = Math.max(0, credits.dailyBurn - burnBefore + burnAfter);

    return (
        <div className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
            This monitor will use{" "}
            <span className="font-medium text-gray-800">{burnAfter.toLocaleString()} credits/day</span>
            {burnBefore !== burnAfter && ` (currently ${burnBefore.toLocaleString()})`}. Organization total:{" "}
            {orgBurnAfter.toLocaleString()} credits/day — credits last{" "}
            <span className="font-medium text-gray-800">{runwayLabel(credits.balance, orgBurnAfter)}</span>.
        </div>
    );
}
```

- [ ] **Step 2: Wire into Create.jsx**

`resources/js/Pages/Monitors/Create.jsx` — add imports:

```jsx
import { usePage } from "@inertiajs/react";
import MonitorCreditImpact from "@/Components/MonitorCreditImpact";
```

Inside the component, read the shared props:

```jsx
    const { auth } = usePage().props;
```

Render directly below the `monitorUptime`/`monitorDomain` checkbox block (inside the same `pt-6 border-t` section):

```jsx
                                <MonitorCreditImpact credits={auth.credits} burnBefore={0} form={form} />
```

- [ ] **Step 3: Wire into Edit.jsx**

`resources/js/Pages/Monitors/Edit.jsx` — same two imports plus the util:

```jsx
import { usePage } from "@inertiajs/react";
import MonitorCreditImpact from "@/Components/MonitorCreditImpact";
import { dailyBurnForConfig } from "@/Utils/creditRunway";
```

Inside the component (which already receives the `monitor` prop):

```jsx
    const { auth } = usePage().props;

    // What this monitor costs TODAY, from its persisted settings — subtracted
    // from the org total so the preview shows the delta of the pending edit.
    const burnBefore = dailyBurnForConfig({
        intervalMinutes: monitor.uptime_check_interval_in_minutes,
        uptimeEnabled: monitor.uptime_check_enabled,
        certificateEnabled: monitor.certificate_check_enabled,
        domainEnabled: monitor.domain_check_enabled,
    });
```

Render below the checkbox block, mirroring Create:

```jsx
                                <MonitorCreditImpact
                                    credits={auth.credits}
                                    burnBefore={burnBefore}
                                    form={form}
                                    certificateEnabled={monitor.certificate_check_enabled}
                                />
```

- [ ] **Step 4: Verify the bundle builds**

Run: `source ~/.nvm/nvm.sh && nvm use 22 && npm run build`
Expected: vite build completes with no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/MonitorCreditImpact.jsx resources/js/Pages/Monitors/Create.jsx resources/js/Pages/Monitors/Edit.jsx
git commit -m "feat(credits): live credit-impact preview on monitor forms"
```

---

### Task 16: Full-suite verification

**Files:** none new — verification only.

- [ ] **Step 1: PHP style + full test suite**

```bash
./vendor/bin/pint
php artisan test
```
Expected: pint reports no changes (or fix + re-run); ALL tests pass — the new `tests/Feature/Credits/*` suites plus every pre-existing suite (Organizations, MonitorHistory, Auth).

- [ ] **Step 2: JS tests + production build**

```bash
source ~/.nvm/nvm.sh && nvm use 22
npm run test:js
npm run build
```
Expected: all Vitest suites pass; vite build succeeds.

- [ ] **Step 3: Manual smoke (optional but recommended)**

```bash
php artisan migrate:fresh --seed   # dev DB only — NEVER against production
CREDITS_DEFAULT_GRANT=1000000 php artisan serve
```
Walk through: onboard an org as super-admin → balance card shows 1,000,000 and the header chip appears → add a 5-min monitor and watch the form preview show 288/day → grant credits from the super-admin panel → set an org's balance to 1 via tinker, run `php artisan monitor:check-uptime`, confirm the org pauses and the mail log gets the paused notification.

- [ ] **Step 4: Final commit + handoff**

```bash
git add -A && git status   # expect: nothing unstaged
git log --oneline main..HEAD   # review the ~15 feat(credits) commits
```
Then follow the superpowers:finishing-a-development-branch skill (merge vs PR decision belongs to the user).

---

## Coverage map (spec section → task)

| Spec section | Task(s) |
|---|---|
| Data model (balance, ledger, usage table) | 1 |
| Metering (2 writes/check, failure isolation) | 4, 5 |
| Enforcement (query-level pause, no free checks) | 6 |
| Performance notes (atomic single-row writes) | 4 (implementation), 6 |
| Runway projection (config-derived, on-read) | 7, 11 |
| Scheduled jobs (rollup 00:15, warnings 00:30) | 8, 9 |
| Notifications (low/critical/paused/resumed, admins only) | 2, 3 (resumed), 4 (paused), 9 (low/critical) |
| Default grant on org creation | 10 |
| Org dashboard (balance card, usage, transactions) | 12 |
| Header chip | 13 |
| Monitor form preview | 15 |
| Super-admin panel (grant/adjust, balance column) | 14 |
| Testing strategy | every task (TDD) + 16 |
