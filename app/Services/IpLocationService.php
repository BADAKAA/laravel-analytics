<?php

namespace App\Services;

class IpLocationService
{
    /**
     * Resolve geolocation from IP address.
     * Uses GeoIP2 if available, otherwise returns null values.
     */
    public static function fromIp(string $ip): array
    {
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
        } catch (\Exception $e) {
            // Silently fail if GeoIP lookup is unavailable.
        }

        return [
            'country_code' => null,
            'subdivision_code' => null,
            'city' => null,
        ];
    }
}
