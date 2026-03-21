<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class GeoJsonService
{
    public static function getCountriesGeoJson(array $countryCodes): array
    {
        // Normalize and filter country codes
        $requestedCodes = collect($countryCodes)
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter(fn ($code) => $code !== '' && $code !== '-99')
            ->unique()
            ->values();

        if ($requestedCodes->isEmpty()) {
            return [
                'type' => 'FeatureCollection',
                'features' => [],
            ];
        }

        $geoJsonPath = public_path('countries.geojson');
        if (!File::exists($geoJsonPath)) {
            return [
                'type' => 'FeatureCollection',
                'features' => [],
            ];
        }

        $geoJson = json_decode(File::get($geoJsonPath), true);
        if (!is_array($geoJson) || !isset($geoJson['features']) || !is_array($geoJson['features'])) {
            return [
                'error' => 'Invalid countries GeoJSON source',
                'status' => 500,
            ];
        }

        $requestedCodeLookup = $requestedCodes->flip();

        $filteredFeatures = array_values(array_filter(
            $geoJson['features'],
            fn ($feature) => self::featureMatchesCountries($feature, $requestedCodeLookup)
        ));

        return [
            'type' => 'FeatureCollection',
            'name' => $geoJson['name'] ?? 'countries',
            'features' => $filteredFeatures,
        ];
    }

    private static function featureMatchesCountries(mixed $feature, $requestedCodeLookup): bool
    {
        if (!is_array($feature)) {
            return false;
        }

        $code = self::extractCountryCode($feature);

        return $code !== '' && $requestedCodeLookup->has($code);
    }

    private static function extractCountryCode(array $feature): string
    {
        $properties = $feature['properties'] ?? [];
        if (!is_array($properties)) {
            return '';
        }

        $code = strtoupper(trim((string) ($properties['ISO3166-1-Alpha-2'] ?? $properties['ISO_A2'] ?? '')));

        return $code !== '-99' ? $code : '';
    }
}
