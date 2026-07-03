<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Base for spatie/laravel-uptime-monitor CLI commands that are unsafe under
 * multi-tenancy: they operate on the base Spatie model, so they bypass
 * organization assignment and soft deletes (e.g. `monitor:sync-file
 * --delete-missing` hard-deletes across all organizations and destroys check
 * history). We rebind them to this refusing shim; monitors are managed through
 * the dashboard, and `monitor:delete` has a soft-delete-aware override.
 */
abstract class DisabledMonitorCommand extends Command
{
    public function __construct()
    {
        parent::__construct();

        // Tolerate the vendor command's arguments/options so we can refuse
        // cleanly instead of erroring on an unknown flag.
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $this->error($this->disabledMessage());

        return self::FAILURE;
    }

    abstract protected function disabledMessage(): string;
}
