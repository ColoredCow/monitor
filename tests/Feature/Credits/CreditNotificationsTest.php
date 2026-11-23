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
