<?php

return [
    'enabled' => (bool) env('FTP_ENABLED', false),

    'host' => env('FTP_HOST'),
    'port' => (int) env('FTP_PORT', 21),
    'username' => env('FTP_USERNAME'),
    'password' => env('FTP_PASSWORD'),

    // Remote base directory where files will be uploaded.
    'root' => env('FTP_ROOT', ''),

    'ssl' => (bool) env('FTP_SSL', false),
    'passive' => (bool) env('FTP_PASSIVE', true),
    'timeout' => (int) env('FTP_TIMEOUT', 90),

    'include' => [
        '\\app',
        '\\bootstrap',
        '\\config',
        '\\database',
        // '\\lang',
        '\\public\\',
        '\\resources\\views',
        '\\routes',
        
        '\\artisan',
        '\\composer.json',
    ],

    'exclude' => [
        '\\System Volume Information\\',
        '\\$Recycle.Bin\\',
        '\\RECYCLE?\\',
        '\\Recovery\\',
        '*\\thumbs.db',
        '\\database\\database.sqlite',
        '\\bootstrap\\cache\\',
        '',
    ],

    'environment_file' => [
        'local' => env('FTP_ENV_LOCAL', '.env.production'),
        'remote' => env('FTP_ENV_REMOTE', '.env'),
    ],
];