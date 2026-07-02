<?php

return [
    // Days a soft-deleted organization (and everything cascaded with it)
    // remains restorable before the scheduled purge hard-deletes it.
    'purge_after_days' => (int) env('ORGANIZATIONS_PURGE_AFTER_DAYS', 60),
];
