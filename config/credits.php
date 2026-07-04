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
