<?php
// web/config.php

return [
    'rooms' => [
        /**
         * 'default' is the fallback configuration. 
         * If a requested ?room= is not found in the database or config, this is used.
         */
        'default' => [
            'name' => 'The Boardroom',
            'calendar_url' => [
                'https://calendar.google.com/calendar/ical/en-gb.canadian%23holiday%40group.v.calendar.google.com/public/basic.ics',
                'demo.ics.php'
            ],
            'view' => 'room',
            'time_format' => 'auto', // '12h', '24h', or 'auto' (language-based)
            'show_rss' => true,
            'show_weather' => true,
        ],

        /**
         * 'personal' is the template for User views.
         * When ?userid= is used, the system forces the room context to 'personal'.
         * User-specific preferences (view, label, coords) from the database will 
         * then override these base settings.
         */
        'personal' => [
            'name' => 'My Schedule',
            'calendar_url' => [
                'https://calendar.google.com/calendar/ical/en-gb.canadian%23holiday%40group.v.calendar.google.com/public/basic.ics',
                'demo.ics.php'
            ],
            'view' => 'dashboard',
            'time_format' => 'auto',
            'show_rss' => true,
            'show_weather' => true,
        ],
        'personal-grid' => [
            'name' => 'Weekly Overview',
            'calendar_url' => [
                'https://calendar.google.com/calendar/ical/en-gb.canadian%23holiday%40group.v.calendar.google.com/public/basic.ics',
                'demo.ics.php'
            ],
            'view' => 'grid',
            'time_format' => 'auto',
            'show_rss' => true,
            'show_weather' => true,
        ],
    ],
    'calendar' => [
        'cache_ttl' => 30, // seconds
        'timezone' => 'America/Toronto',
    ],
    'rss' => [
        'en' => [
            'https://www.cbc.ca/cmlink/rss-topstories',
            'http://feeds.bbci.co.uk/news/world/rss.xml',
            'https://rss.dw.com/xml/rss-en-all',
        ],
        'fr' => [
            'https://www.ledevoir.com/rss/manchettes.xml',
            'https://www.lemonde.fr/rss/une.xml',
            'https://onfr.tfo.org/feed/',
        ],
        'all' => [],
        'cache_ttl' => 300, // 5 minutes
    ],
    'ui' => [
        'lang' => 'fr',
        'rotation_interval' => 0, // 0 to disable auto-switching
    ],
    'security' => [
        'db_path' => __DIR__ . '/librejoanne.db',
        'encryption_key' => 'ChangeThisToSomethingRandomAndSecret', // Used to protect stored URLs
        'setup_password' => 'admin123', // Only used for initial setup

        // Failed-login throttling for manage.php. Counted in a sliding window, per
        // source address and per account name; a correct login clears both counters.
        'login_window'       => 900, // seconds
        'login_max_per_ip'   => 10,
        'login_max_per_user' => 5,

        // Optional allowlist for manage.php. Addresses or CIDR ranges, v4 or v6.
        // Empty means no restriction. The strongest control available if the dashboard
        // is reachable from the internet, e.g. ['203.0.113.4', '10.20.28.0/22'].
        'manage_allow_ips' => [],

        // Proxies whose X-Forwarded-For may be believed. Empty means REMOTE_ADDR is
        // used as-is. Only set this if a reverse proxy really does front the app,
        // otherwise a client can forge its own address.
        'trusted_proxies' => [],
    ]
];