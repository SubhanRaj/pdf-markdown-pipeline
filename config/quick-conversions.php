<?php

return [
    // How long an unclaimed QuickConversion (and its files) sticks around before
    // PruneQuickConversion deletes it — see app/Jobs/PruneQuickConversion.php.
    'ttl_hours' => env('QUICK_CONVERSION_TTL_HOURS', 48),
];
