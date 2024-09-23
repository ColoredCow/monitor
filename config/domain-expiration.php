<?php

return [

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail'
     * and 'slack'. Of course you can also specify your own notification classes.
     */
    'notifications' => [
        'notifications' => [
            \App\Notifications\DomainExpirationWarning::class => ['mail', \App\Channels\GoogleChatChannel::class],
        ],

        /*
         * The location from where you are running this Laravel application. This location will be
         * mentioned in all notifications that will be sent.
         */
        'location' => '',

        'mail' => [
            'to' => explode(',', trim(env('UPTIME_MONITOR_MAIL_TO', ''))),
        ],

        'slack' => [
            'webhook_url' => env('UPTIME_MONITOR_SLACK_WEBHOOK_URL'),
        ],

        'google_chat' => [
            'webhook_url' => env('UPTIME_MONITOR_GOOGLE_CHAT_WEBHOOK_URL'),
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent. The default
         * notifiable will use the variables specified in this config file.
         */
        'notifiable' => \App\Notifications\Notifiable::class,

        /*
         * The date format used in notifications.
         */
        'date_format' => 'd/m/Y',
    ],

    'domain_check_time_period' => [

        /*
        * The `App\Events\DomainExpiresSoon` will notify
        * when a domain is found whose expiration date is in
        * the next number of given days.
        */

        '30_days_warning' => [
            'days' => 30,
        ],  // The first warning, e.g., 30 days before expiration
        '7_days_warning' => [
            'days' => 7,
        ],  // Second warning, e.g., 7 days before expiration
        '1_day_warning' => [
            'days' => 1,
        ],  // Final warning, e.g., 1 day before expiration  
    ],
];
