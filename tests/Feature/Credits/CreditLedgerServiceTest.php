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
