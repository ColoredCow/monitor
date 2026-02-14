<?php

return [

    /*
     * Toggle for monitor history features.
     */
    'enabled' => (bool) env('MONITOR_HISTORY_ENABLED', false),

    /*
     * Aggregation configuration.
     */
    'aggregation' => [
        'lookback_days' => 7,
    ],

    /*
     * Maximum recent check rows to return on monitor detail page.
     */
    'recent_checks_limit' => 50,
];
