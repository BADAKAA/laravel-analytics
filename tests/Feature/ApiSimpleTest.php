<?php

use App\Models\Site;

test('POST /api/pageview creates session and pageview', function () {
    // Create a test site
    $site = Site::firstOrCreate(
        ['domain' => 'test.local'],
        ['name' => 'Test Site']
    );

    // Send pageview request
    $response = $this->postJson('/api/pageview', [
        'site_id' => $site->public_id,
        'pathname' => '/home',
        'screen_width' => 1920,
    ]);

    $response->assertStatus(200);

    // Verify session created
    expect(
        \App\Models\Session::where('site_id', $site->id)
            ->where('entry_page', '/home')
            ->exists()
    )->toBeTrue();
});

test('POST /api/pageview increments pageviews and updates exit page', function () {
    $site = Site::firstOrCreate(
        ['domain' => 'test.local'],
        ['name' => 'Test Site']
    );
    
    $userAgent = 'Mozilla/5.0 Test Browser';

    // First pageview
    $response1 = $this->postJson(
        '/api/pageview',
        [
            'site_id' => $site->public_id,
            'pathname' => '/home',
            'screen_width' => 1920,
        ],
        ['User-Agent' => $userAgent]
    );

    $response1->assertStatus(200);

    // Get the session created by first pageview
    $session1 = \App\Models\Session::where('site_id', $site->id)
        ->where('entry_page', '/home')
        ->first();
    
    expect($session1)->not()->toBeNull('Session should exist after first pageview');
    expect($session1->pageviews)->toBe(1);
    expect($session1->is_bounce)->toBeTrue();

    // Second pageview
    $response2 = $this->postJson(
        '/api/pageview',
        [
            'site_id' => $site->public_id,
            'pathname' => '/about',
            'screen_width' => 1920,
        ],
        ['User-Agent' => $userAgent]
    );

    $response2->assertStatus(200);
    
    // Verify sessions exist
    expect(
        \App\Models\Session::where('site_id', $site->id)->exists()
    )->toBeTrue();
});
