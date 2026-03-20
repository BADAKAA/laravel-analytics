<?php

use App\Models\Site;

test('debug api pageview endpoint', function () {
    // Create a test site
    $site = Site::firstOrCreate(
        ['domain' => 'test.local'],
        ['name' => 'Test Site']
    );

    // Send pageview request
    $response = $this->postJson('/api/pageview', [
        'site_id' => $site->id,
        'pathname' => '/home',
        'screen_width' => 1920,
    ]);

    if ($response->status() !== 200) {
        dump($response->status());
        dump($response->json() ?: $response->content());
    }

    $response->assertStatus(200);
});
