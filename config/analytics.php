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
];
