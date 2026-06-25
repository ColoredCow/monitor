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
     * Maximum recent checks surfaced on the monitor detail page's recent strip.
     * Single source of truth: the controller caps `latest_checks` to this value
     * AND ships it in the graph payload, and the frontend strip uses the same
     * number as its slot cap (MonitorRecentStrip maxSlots) — so the backend and
     * frontend caps cannot drift apart.
     */
    'recent_checks_limit' => 150,

    /*
     * How many days of raw logs to keep before pruning.
     */
    'raw_log_retention_days' => 180,

    /*
     * How many days of synthetic history MonitorHistorySeeder generates when
     * seeding demo data locally (php artisan db:seed --class=MonitorHistorySeeder).
     */
    'seed_days' => (int) env('MONITOR_HISTORY_SEED_DAYS', 90),
];
