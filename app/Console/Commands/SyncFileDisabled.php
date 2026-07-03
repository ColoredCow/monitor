<?php

namespace App\Console\Commands;

class SyncFileDisabled extends DisabledMonitorCommand
{
    protected $signature = 'monitor:sync-file {file?} {--delete-missing}';

    protected $description = 'Disabled in multi-tenant mode — manage monitors from the dashboard';

    protected function disabledMessage(): string
    {
        return 'monitor:sync-file is disabled: it operates on the base model, so --delete-missing hard-deletes monitors across all organizations (destroying check history) and it cannot assign organizations. Manage monitors from the dashboard.';
    }
}
