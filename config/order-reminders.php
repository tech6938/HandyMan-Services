<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reminder Settings
    |--------------------------------------------------------------------------
    */
    'reminder_interval_minutes' => 30, // Check every 30 minutes

    'max_reminders' => 10, // Maximum reminders per order (safety limit)

    'order_status' => 'pending', // Status that triggers reminders

    'accepted_status' => ['accepted', 'ongoing', 'completed', 'cancelled'], // Statuses that stop reminders


    /*
    |--------------------------------------------------------------------------
    | Rate Limiting (IMPORTANT)
    |--------------------------------------------------------------------------
    */
    'rate_limit' => [
        'notifications_per_minute' => 30, // Max 30 notifications per minute
        'batch_size' => 10, // Process 10 orders at a time
        'delay_between_batches' => 60, // Wait 60 seconds between batches
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Titles
    |--------------------------------------------------------------------------
    */
    'titles' => [
        1 => '📋 New Order #: Please take action',
        2 => '⏰ Reminder: Order # is still pending (30 min)',
        3 => '⚠️ Reminder: Order # still pending (1 hour)',
        4 => '🔔 Reminder: Order # still pending (1.5 hours)',
        'default' => '📢 Reminder: Order # still pending',
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    */
    'batch_size' => 100,
    'cache_ttl_days' => 7,
];
