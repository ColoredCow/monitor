<?php

return [

    /*
     * Toggle for monitor history features.
     */
    'enabled' => (bool) env('MONITOR_HISTORY_ENABLED', false),

    /*
     * Timezone used to bucket checks into daily metrics. Both the aggregation
     * command and the monitor detail page resolve to this same value, so the
     * heatmap always reads back the rows the scheduler wrote. Defaults to the
     * application timezone (resolved at runtime in code).
     */
    'timezone' => env('MONITOR_HISTORY_TIMEZONE'),

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

    /*
     * How many days of raw logs to keep before pruning.
     */
    'raw_log_retention_days' => 180,
];
