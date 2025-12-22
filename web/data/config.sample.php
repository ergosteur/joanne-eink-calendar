<?php
// web/data/config.sample.php - Template for LibreJoanne

return [
    'rooms' => [
        'default' => [
            'name' => 'The Boardroom',
            'calendar_url' => 'https://calendar.google.com/calendar/ical/your-calendar-id/public/basic.ics',
            'view' => 'room',
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
        ],
        'fr' => [
            'https://www.ledevoir.com/rss/manchettes.xml',
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
        'encryption_key' => 'REPLACE_WITH_RANDOM_SECRET_KEY', // Used to protect stored URLs
        'setup_password' => 'admin123' // Only used for initial setup
    ]
];
