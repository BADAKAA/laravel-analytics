<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class IpLocationService {

    public static function fromIp(?string $ip): array {
        if (!$ip || !config('analytics.geoip.enabled', false)) return [];

        if (!config('analytics.geoip.use_local_db')) return self::resolveFromApi($ip);
        $local = self::resolveFromLocalDatabase($ip);
        return $local;
    }

    private static function resolveFromLocalDatabase(string $ip): array {
        try {
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
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    private static function resolveFromApi(string $ip): array {
        $endpoint = trim((string) config('analytics.geoip.endpoint', ''));
        if ($endpoint === '') return [];

        $rateLimit = (int) config('analytics.geoip.rate_limit', 45);

        $url = str_contains($endpoint, '{ip}')
            ? str_replace('{ip}', rawurlencode($ip), $endpoint)
            : rtrim($endpoint, '/') . '/' . rawurlencode($ip);

        try {
            $result = RateLimiter::attempt(
                'get-ip-location',
                $rateLimit,
                function () use ($url): array {
                    $response = Http::timeout(2)->acceptJson()->get($url);
                    if (!$response->ok()) return [];

                    $payload = $response->json();
                    if (!is_array($payload)) return [];

                    if (isset($payload['status']) && strtolower((string) $payload['status']) !== 'success') return [];

                    $countryCode = $payload['countryCode'] ?? $payload['country_code'] ?? null;
                    if ($countryCode === null || trim((string) $countryCode) === '') return [];

                    $country = strtoupper(trim((string) $countryCode));

                    $regionCode = $payload['region'] ?? $payload['regionCode'] ?? $payload['subdivision_code'] ?? null;
                    $regionCode = $regionCode !== null ? strtoupper(trim((string) $regionCode)) : null;
                    if ($regionCode === '') $regionCode = null;

                    $city = $payload['city'] ?? null;
                    $city = $city !== null ? trim((string) $city) : null;
                    if ($city === '') $city = null;

                    return [
                        'country_code' => $country,
                        'subdivision_code' => $regionCode ? ($country . '-' . $regionCode) : null,
                        'city' => $city,
                    ];
                },
                60
            );

            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
