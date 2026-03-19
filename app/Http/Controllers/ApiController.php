<?php

namespace App\Http\Controllers;

use App\Models\Pageview;
use App\Models\Session;
use App\Models\Site;
use App\Services\ChannelClassifier;
use App\Services\VisitorHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        // Validate incoming pageview data
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'pathname' => 'required|string|max:2048',
            'hostname' => 'nullable|string|max:255',
            'referrer' => 'nullable|string|max:2048',
            'utm_source' => 'nullable|string|max:255',
            'utm_medium' => 'nullable|string|max:255',
            'utm_campaign' => 'nullable|string|max:255',
            'utm_content' => 'nullable|string|max:255',
            'utm_term' => 'nullable|string|max:255',
            'screen_width' => 'nullable|integer|min:100|max:7680',
        ]);

        // Find site by domain
        $site = Site::where('domain', $validated['domain'])->first();

        if (!$site) {
            return response()->json(['error' => 'Site not found'], 404);
        }

        // Extract visitor hash, browser, OS, device type, and location
        $ip = $request->ip() ?? '';
        $userAgent = $request->header('User-Agent') ?? '';

        $visitorId = VisitorHash::make($ip, $userAgent, $site->domain);

        // Parse user agent for browser/OS/device info
        $browserInfo = $this->parseBrowserInfo($userAgent);
        $deviceInfo = $this->parseDeviceInfo($validated['screen_width'] ?? null);

        // Extract referrer domain
        $referrerDomain = null;
        if ($validated['referrer'] ?? null) {
            $referrerDomain = parse_url($validated['referrer'], PHP_URL_HOST);
        }

        // Classify traffic channel
        $channelClassifier = app(ChannelClassifier::class);
        $channel = $channelClassifier->classify(
            $validated['utm_source'] ?? null,
            $validated['utm_medium'] ?? null,
            $validated['utm_campaign'] ?? null,
            $referrerDomain,
        );

        // Get geolocation data (if available)
        $geoData = $this->getGeoData($ip);

        // Find or create session (created in same day)
        $session = Session::where('site_id', $site->id)
            ->where('visitor_id', $visitorId)
            ->whereDate('started_at', today($site->timezone))
            ->latest('started_at')
            ->first();

        if (!$session) {
            // Create new session
            $session = Session::create([
                'site_id' => $site->id,
                'visitor_id' => $visitorId,
                'started_at' => now($site->timezone),
                'duration' => null,
                'pageviews' => 1,
                'is_bounce' => true,
                'entry_page' => $validated['pathname'],
                'exit_page' => $validated['pathname'],
                'utm_source' => $validated['utm_source'] ?? null,
                'utm_medium' => $validated['utm_medium'] ?? null,
                'utm_campaign' => $validated['utm_campaign'] ?? null,
                'utm_content' => $validated['utm_content'] ?? null,
                'utm_term' => $validated['utm_term'] ?? null,
                'referrer' => $validated['referrer'] ?? null,
                'referrer_domain' => $referrerDomain,
                'channel' => $channel,
                'country_code' => $geoData['country_code'] ?? null,
                'subdivision_code' => $geoData['subdivision_code'] ?? null,
                'city' => $geoData['city'] ?? null,
                'browser' => $browserInfo['name'],
                'browser_version' => $browserInfo['version'],
                'os' => $browserInfo['os'],
                'os_version' => $browserInfo['os_version'],
                'device_type' => $deviceInfo['type'],
                'screen_width' => $validated['screen_width'] ?? null,
            ]);
        } else {
            // Update existing session: increment pageviews, update exit page, update duration
            $sessionStarted = $session->started_at->timestamp;
            $duration = max(0, now($site->timezone)->timestamp - $sessionStarted);

            $session->update([
                'pageviews' => $session->pageviews + 1,
                'exit_page' => $validated['pathname'],
                'is_bounce' => false, // Session has multiple pageviews
                'duration' => $duration,
            ]);
        }

        // Mark previous pageview (if exists) as non-exit
        Pageview::where('session_id', $session->id)
            ->where('is_exit', true)
            ->update(['is_exit' => false]);

        // Create pageview
        $pageview = Pageview::create([
            'site_id' => $site->id,
            'session_id' => $session->id,
            'hostname' => $validated['hostname'] ?? $validated['domain'],
            'pathname' => $validated['pathname'],
            'viewed_at' => now($site->timezone),
            'is_entry' => $session->pageviews === 1,
            'is_exit' => true,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'pageview_id' => $pageview->id,
        ]);
    }

    /**
     * Parse browser and OS info from user agent string.
     */
    private function parseBrowserInfo(string $userAgent): array
    {
        $browser = 'Unknown';
        $browserVersion = 'Unknown';
        $os = 'Unknown';
        $osVersion = 'Unknown';

        // Chrome
        if (preg_match('/Chrome\/(\d+)/', $userAgent, $m)) {
            $browser = 'Chrome';
            $browserVersion = $m[1];
        } // Safari (before Chrome check since Chrome also mentions Safari)
        elseif (preg_match('/Version\/(\d+).*Safari/', $userAgent, $m)) {
            $browser = 'Safari';
            $browserVersion = $m[1];
        } // Firefox
        elseif (preg_match('/Firefox\/(\d+)/', $userAgent, $m)) {
            $browser = 'Firefox';
            $browserVersion = $m[1];
        } // Edge
        elseif (preg_match('/Edg\/(\d+)/', $userAgent, $m)) {
            $browser = 'Edge';
            $browserVersion = $m[1];
        }

        // Operating Systems
        if (preg_match('/Windows NT 10\.0/', $userAgent)) {
            $os = 'Windows';
            $osVersion = '10';
        } elseif (preg_match('/Windows NT 11\.0/', $userAgent)) {
            $os = 'Windows';
            $osVersion = '11';
        } elseif (preg_match('/Macintosh.*Mac OS X (\d+_\d+)/', $userAgent, $m)) {
            $os = 'macOS';
            $osVersion = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/iPhone.*OS (\d+)/', $userAgent, $m)) {
            $os = 'iOS';
            $osVersion = $m[1];
        } elseif (preg_match('/Android (\d+)/', $userAgent, $m)) {
            $os = 'Android';
            $osVersion = $m[1];
        }

        return [
            'name' => $browser,
            'version' => $browserVersion,
            'os' => $os,
            'os_version' => $osVersion,
        ];
    }

    /**
     * Detect device type from user agent and screen width.
     */
    private function parseDeviceInfo(?int $screenWidth): array
    {
        // Use screen width as primary signal
        if ($screenWidth) {
            if ($screenWidth < 768) {
                return ['type' => 2]; // Mobile (DeviceType::Mobile->value)
            } elseif ($screenWidth < 1024) {
                return ['type' => 3]; // Tablet (DeviceType::Tablet->value)
            } else {
                return ['type' => 1]; // Desktop (DeviceType::Desktop->value)
            }
        }

        return ['type' => 0]; // Unknown (DeviceType::Unknown->value)
    }

    /**
     * Resolve geolocation from IP address.
     * Uses GeoIP2 if available, otherwise returns empty array.
     */
    private function getGeoData(string $ip): array
    {
        try {
            // Try to use GeoIP2 if configured
            $geoipPath = storage_path('app/GeoLite2-City.mmdb');

            if (file_exists($geoipPath) && class_exists('GeoIp2\\Database\\Reader')) {
                $reader = new \GeoIp2\Database\Reader($geoipPath);
                $record = $reader->city($ip);

                return [
                    'country_code' => $record->country->isoCode,
                    'subdivision_code' => $record->mostSpecificSubdivision->isoCode
                        ? $record->country->isoCode . '-' . $record->mostSpecificSubdivision->isoCode
                        : null,
                    'city' => $record->city->name,
                ];
            }
        } catch (\Exception $e) {
            // Silently fail if GeoIP not available
        }

        return [
            'country_code' => null,
            'subdivision_code' => null,
            'city' => null,
        ];
    }
}
