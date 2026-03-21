<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Track Page Views
    |--------------------------------------------------------------------------
    |
    | When enabled, create a pageview record for each API hit in addition to
    | updating the session. This grants more granular page-level analytics
    | in exchange for higher storage and performance requirements.
    |
    */
    'track_page_views' => env('ANALYTICS_TRACK_PAGE_VIEWS', true),

    /*
    |--------------------------------------------------------------------------
    | Maximum Session Duration
    |--------------------------------------------------------------------------
    |
    | Maximum duration (in seconds) for a session before a new session is created.
    | If a pageview is recorded after this duration from session start, a new
    | session will be created. Default: 30 minutes (1800 seconds).
    |
    */
    'max_session_duration' => env('ANALYTICS_MAX_SESSION_DURATION', 1800),
];
