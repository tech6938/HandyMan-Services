<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reminder Intervals (in minutes)
    |--------------------------------------------------------------------------
    | First 3 reminders are frequent, then daily after that
    */
    'intervals' => [
        30   => '30_MINUTES',    // 1st reminder
        60   => '1_HOUR',        // 2nd reminder
        300  => '5_HOURS',       // 3rd reminder
        1440 => '24_HOURS',      // 4th reminder (1 day)
        2880 => '48_HOURS',      // 5th reminder (2 days)
        4320 => '72_HOURS',      // 6th reminder (3 days)
        5760 => '96_HOURS',      // 7th reminder (4 days)
        7200 => '120_HOURS',     // 8th reminder (5 days)
        8640 => '144_HOURS',     // 9th reminder (6 days)
        // Add more as needed
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Titles
    |--------------------------------------------------------------------------
    */
    'titles' => [
        '30_MINUTES' => '⏰ Reminder: Booking pending 30 minutes',
        '1_HOUR'     => '⏰ Reminder: Booking pending 1 hour',
        '5_HOURS'    => '📋 Reminder: Booking pending 5 hours',
        '24_HOURS'   => '⚠️ Reminder: Booking pending 24 hours',
        '48_HOURS'   => '🔔 Reminder: Booking pending 48 hours',
        '72_HOURS'   => '🔔 Reminder: Booking pending 72 hours',
        '96_HOURS'   => '🔔 Reminder: Booking pending 96 hours',
        '120_HOURS'  => '🔔 Reminder: Booking pending 120 hours',
        '144_HOURS'  => '🔔 Reminder: Booking pending 144 hours',
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Interval (in minutes)
    |--------------------------------------------------------------------------
    | After 5 hours, send reminders every 24 hours
    */
    'daily_interval_minutes' => 1440, // 24 hours

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    */
    'batch_size' => 100,
    'cache_ttl_days' => 30, // Keep track for 30 days

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limit' => [
        'max_notifications_per_minute' => 60,
        'max_retries' => 3,
    ],
];
