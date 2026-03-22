<?php

namespace App\Http\Controllers;

use App\Concerns\PublicID;
use App\Enums\DeviceType;
use App\Models\Pageview;
use App\Models\Session;
use App\Models\Site;
use App\Policies\BotPolicy;
use App\Services\ChannelClassifier;
use App\Services\IpLocationService;
use App\Services\VisitorHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller {

    private array $timings = [];
    private bool $trackTiming = false;

    public function __invoke(Request $request): JsonResponse {
        $requestTime = microtime(true);
        $this->trackTiming = $request->header('X-Benchmark') === 'true';

        if (BotPolicy::isBot($request)) return response()->json([]);

        $t1 = microtime(true);
        $validated = $request->validate([
            'site_id' => 'required|string|size:' . PublicID::ID_LENGTH,
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
        $this->recordTiming('validation', $t1);

        $t2 = microtime(true);
        $siteId = Site::getID($validated['site_id']);
        $this->recordTiming('site_lookup', $t2);
        if (!$siteId) return response()->json(['error' => 'Site not found'], 404);

        $ip = $this->extractClientIp($request);
        $userAgent = $request->header('User-Agent') ?? '';

        $t3 = microtime(true);
        $visitorId = VisitorHash::make($ip, $userAgent, $siteId);
        $this->recordTiming('visitor_hash', $t3);

        $t4 = microtime(true);
        $browserInfo = $this->parseBrowserInfo($userAgent);
        $this->recordTiming('parse_browser', $t4);

        $t5 = microtime(true);
        $deviceType = DeviceType::fromScreenWidth($validated['screen_width'] ?? null);
        $this->recordTiming('parse_device', $t5);

        $t6 = microtime(true);
        $referrerDomain = null;
        if ($validated['referrer'] ?? null) {
            $referrerDomain = parse_url($validated['referrer'], PHP_URL_HOST);
        }
        $this->recordTiming('parse_referrer', $t6);

        $t7 = microtime(true);
        $channelClassifier = app(ChannelClassifier::class);
        $channel = $channelClassifier->classify(
            $validated['utm_source'] ?? null,
            $validated['utm_medium'] ?? null,
            $validated['utm_campaign'] ?? null,
            $referrerDomain,
        );
        $this->recordTiming('classify_channel', $t7);

        $t9 = microtime(true);
        $now = now();
        $trackPageViews = config('analytics.track_page_views', true);
        $maxSessionDuration = config('analytics.max_session_duration', 1800);

        $existingSession = Session::where('site_id', $siteId)
            ->where('visitor_id', $visitorId)
            ->orderBy('started_at', 'desc')
            ->first();
        
        $isNewSession = !$existingSession;
        if ($existingSession) {
            $startedAt = $existingSession->started_at;
            $sessionDuration = (int) $startedAt->diffInSeconds($now, true);
            if ($sessionDuration > $maxSessionDuration) {
                $isNewSession = true;
            }
        } 

        $geoData = [];
        if ($isNewSession) {
            $t8 = microtime(true);
            $geoData = IpLocationService::fromIp($ip);
            $this->recordTiming('geoip_lookup', $t8);
        }
        
        $countryCode = $geoData['country_code'] ?? null;
        $subdivisionCode = $geoData['subdivision_code'] ?? null;
        $city = $geoData['city'] ?? null;

        $t_session_upsert = microtime(true);
        if ($isNewSession) {
            $session = Session::create([
                'site_id' => $siteId,
                'visitor_id' => $visitorId,
                'started_at' => $now->toDateTimeString(),
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
                'country_code' => $countryCode,
                'subdivision_code' => $subdivisionCode,
                'city' => $city,
                'browser' => $browserInfo['name'],
                'browser_version' => $browserInfo['version'],
                'os' => $browserInfo['os'],
                'os_version' => $browserInfo['os_version'],
                'device_type' => $deviceType->value,
                'screen_width' => $validated['screen_width'] ?? null,
            ]);
        } else {
            // Update the existing session
            $durationSigned = (float) $existingSession->started_at->diffInSeconds($now, false);
            $durationSeconds = (int) max(0, $durationSigned);
            $existingSession->update([
                'pageviews' => $existingSession->pageviews + 1,
                'exit_page' => $validated['pathname'],
                'duration' => $durationSeconds,
                'is_bounce' => false,
            ]);
            $session = $existingSession;
        }
        $this->recordTiming('session_upsert', $t_session_upsert);

        if ($trackPageViews && $session) {
            $t_pageview = microtime(true);
            Pageview::create([
                'site_id' => $siteId,
                'session_id' => $session->id,
                'hostname' => $validated['hostname'] ?? '',
                'pathname' => $validated['pathname'],
                'viewed_at' => $now,
                'is_entry' => $isNewSession,
            ]);
            $this->recordTiming('pageview_operations', $t_pageview);
        }

        $this->recordTiming('total', $t9);

        $this->recordTiming('total', $requestTime);

        $response = response()->json([]);

        if ($this->trackTiming) {
            $response->header('X-Timing-Breakdown', json_encode($this->timings));
        }

        return $response;
    }

    private function recordTiming(string $operation, float $startTime): void {
        if ($this->trackTiming) {
            $duration = (microtime(true) - $startTime) * 1000; // ms
            $this->timings[$operation] = $duration;
        }
    }

    /**
     * Prefer forwarded client IP when requests arrive through same-origin forwarders.
     */
    private function extractClientIp(Request $request): string {
        $xForwardedFor = $request->header('X-Forwarded-For');
        if (is_string($xForwardedFor) && $xForwardedFor !== '') {
            $ips = array_map('trim', explode(',', $xForwardedFor));
            foreach ($ips as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        $fallback = $request->ip();
        return is_string($fallback) ? $fallback : '';
    }

    /**
     * Parse browser and OS info from user agent string.
     */
    private function parseBrowserInfo(string $userAgent): array {
        $browser = 'Unknown';
        $browserVersion = 'Unknown';
        $os = 'Unknown';
        $osVersion = 'Unknown';

        [$browser, $browserVersion] = match (true) {
            preg_match('/Chrome\/(\d+)/', $userAgent, $m) === 1 => ['Chrome', $m[1]],
            // Safari before Chrome because Chrome UAs also include Safari token.
            preg_match('/Version\/(\d+).*Safari/', $userAgent, $m) === 1 => ['Safari', $m[1]],
            preg_match('/Firefox\/(\d+)/', $userAgent, $m) === 1 => ['Firefox', $m[1]],
            preg_match('/Edg\/(\d+)/', $userAgent, $m) === 1 => ['Edge', $m[1]],
            default => ['Unknown', 'Unknown'],
        };

        [$os, $osVersion] = match (true) {
            preg_match('/Windows NT 10\.0/', $userAgent) === 1 => ['Windows', '10'],
            preg_match('/Windows NT 11\.0/', $userAgent) === 1 => ['Windows', '11'],
            preg_match('/Macintosh.*Mac OS X (\d+_\d+)/', $userAgent, $m) === 1 => ['macOS', str_replace('_', '.', $m[1])],
            preg_match('/iPhone.*OS (\d+)/', $userAgent, $m) === 1 => ['iOS', $m[1]],
            preg_match('/Android (\d+)/', $userAgent, $m) === 1 => ['Android', $m[1]],
            preg_match('/Ubuntu(?:[\/\s]+([0-9]+(?:\.[0-9]+)?))?/i', $userAgent, $m) === 1 => ['Ubuntu', $m[1] ?? 'Unknown'],
            preg_match('/(?:GNU\/Linux|Linux)/i', $userAgent) === 1 => ['GNU/Linux', 'Unknown'],
            default => ['Unknown', 'Unknown'],
        };

        return [
            'name' => $browser,
            'version' => $browserVersion,
            'os' => $os,
            'os_version' => $osVersion,
        ];
    }

}
