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
        'domain' => 'test.local',
        'pathname' => '/home',
        'screen_width' => 1920,
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['session_id', 'pageview_id']);

    // Verify session created
    expect(
        \App\Models\Session::where('site_id', $site->id)
            ->where('entry_page', '/home')
            ->exists()
    )->toBeTrue();

    // Verify pageview created
    expect(
        \App\Models\Pageview::where('site_id', $site->id)
            ->where('pathname', '/home')
            ->exists()
    )->toBeTrue();
});

test('POST /api/pageview increments pageviews and updates exit page', function () {
    $site = Site::firstOrCreate(
        ['domain' => 'test.local'],
        ['name' => 'Test Site']
    );

    // First pageview
    $response1 = $this->postJson('/api/pageview', [
        'domain' => 'test.local',
        'pathname' => '/home',
        'screen_width' => 1920,
    ]);
    $sessionId = $response1->json('session_id');

    // Second pageview
    $response2 = $this->postJson('/api/pageview', [
        'domain' => 'test.local',
        'pathname' => '/about',
        'screen_width' => 1920,
    ]);

    $response2->assertStatus(200);

    $session = \App\Models\Session::find($sessionId);
    expect($session->pageviews)->toBe(2);
    expect($session->exit_page)->toBe('/about');
    expect($session->is_bounce)->toBeFalse();
});
