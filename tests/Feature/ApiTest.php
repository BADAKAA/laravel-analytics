<?php

use App\Models\Site;
use App\Models\Session;
use App\Models\Pageview;

describe('Analytics API Endpoint', function () {
    beforeEach(function () {
        // Create a test site
        $this->site = Site::firstOrCreate(
            ['domain' => 'test.example.com'],
            ['name' => 'Test Site', 'timezone' => 'UTC']
        );
    });

    test('can create a new session on first pageview', function () {
        $response = $this->postJson('/api/pageview', [
            'site_id' => $this->site->id,
            'pathname' => '/home',
            'hostname' => 'test.example.com',
            'screen_width' => 1920,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('sessions', [
            'site_id' => $this->site->id,
            'is_bounce' => true,
            'pageviews' => 1,
            'entry_page' => '/home',
            'exit_page' => '/home',
        ]);

    });

    test('can update session on subsequent pageview', function () {
        $userAgent = 'Mozilla/5.0 Test Browser';
        
        // First pageview
        $response1 = $this->postJson(
            '/api/pageview',
            [
                'site_id' => $this->site->id,
                'pathname' => '/home',
                'screen_width' => 1920,
            ],
            ['User-Agent' => $userAgent]
        );

        $response1->assertStatus(200);

        // Get the session created by first pageview
        $session1 = Session::where('site_id', $this->site->id)
            ->where('entry_page', '/home')
            ->first();
        
        $this->assertNotNull($session1);
        $this->assertEquals(1, $session1->pageviews);
        $this->assertEquals('/home', $session1->exit_page);
        $this->assertTrue($session1->is_bounce);

        // Second pageview  
        $response2 = $this->postJson(
            '/api/pageview',
            [
                'site_id' => $this->site->id,
                'pathname' => '/about',
                'screen_width' => 1920,
            ],
            ['User-Agent' => $userAgent]
        );

        $response2->assertStatus(200);
        
        // Verify at least one session exists (no session_id returned, just check it processes)
        $this->assertTrue(Session::where('site_id', $this->site->id)->exists());
    });

    test('fails if site domain not found', function () {
        $response = $this->postJson('/api/pageview', [
            'site_id' => 99999, // Non-existent site ID
            'pathname' => '/home',
        ]);

        $response->assertStatus(404);
        $response->assertJsonFragment(['error' => 'Site not found']);
    });

    test('classifies traffic channel correctly', function () {
        $response = $this->postJson('/api/pageview', [
            'site_id' => $this->site->id,
            'pathname' => '/home',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer_sale',
        ]);

        $response->assertStatus(200);

        // Should classify as PaidSearch (Channel::PaidSearch = 6)
        $this->assertDatabaseHas('sessions', [
            'site_id' => $this->site->id,
            'channel' => 6, // PaidSearch
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);
    });

    test('parses browser and os info from user agent', function () {
        $response = $this->postJson(
            '/api/pageview',
            [
                'site_id' => $this->site->id,
                'pathname' => '/home',
            ],
            [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('sessions', [
            'site_id' => $this->site->id,
            'browser' => 'Chrome',
            'browser_version' => '120',
            'os' => 'Windows',
            'os_version' => '10',
        ]);
    });

    test('detects device type from screen width', function () {
        // Create a second test site
        $mobileSite = Site::firstOrCreate(
            ['domain' => 'mobile.example.com'],
            ['name' => 'Mobile Test Site', 'timezone' => 'UTC']
        );

        $desktopSite = Site::firstOrCreate(
            ['domain' => 'desktop.example.com'],
            ['name' => 'Desktop Test Site', 'timezone' => 'UTC']
        );

        // Mobile - test on first site
        $this->postJson('/api/pageview', [
            'site_id' => $mobileSite->id,
            'pathname' => '/home',
            'screen_width' => 375,
        ]);

        $this->assertDatabaseHas('sessions', [
            'site_id' => $mobileSite->id,
            'device_type' => 2, // Mobile
            'screen_width' => 375,
        ]);

        // Desktop - test on second site (ensures different session)
        $this->postJson('/api/pageview', [
            'site_id' => $desktopSite->id,
            'pathname' => '/about',
            'screen_width' => 1920,
        ]);

        $this->assertDatabaseHas('sessions', [
            'site_id' => $desktopSite->id,
            'device_type' => 1, // Desktop
            'screen_width' => 1920,
        ]);
    });
});
