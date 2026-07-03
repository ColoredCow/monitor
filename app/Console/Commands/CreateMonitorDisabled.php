<?php

namespace App\Console\Commands;

class CreateMonitorDisabled extends DisabledMonitorCommand
{
    protected $signature = 'monitor:create {url?}';

    protected $description = 'Disabled in multi-tenant mode — create monitors from the dashboard';

    protected function disabledMessage(): string
    {
        return 'monitor:create is disabled: it cannot assign an organization (organization_id is required). Create monitors from the dashboard.';
    }
}
