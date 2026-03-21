# Laravel Analytics

## Documentation

### Dashboard GeoJSON Country Map Caching

The country map displays visitor data overlaid on a world map using Leaflet.js and GeoJSON features. To optimize performance and reduce bandwidth, the application implements a two-tier caching strategy:
- **Server-side filtering** ensures only requested country features from the GeoJSON file are sent
- **Client-side incremental caching** stores fetched features in IndexedDB and only requests missing countries

#### Server-side: POST `/api/dashboard/countries-geojson` | [DashboardController.php](app/Http/Controllers/DashboardController.php)

The `postCountriesGeoJson()` method:
1. Accepts a POST request with an array of ISO 3166-1 alpha-2 country codes
2. Validates and normalizes the country codes (uppercase, filters invalid codes like `-99`)
3. Loads the source GeoJSON file from `public/countries.geojson`
4. Filters the GeoJSON features to include only the requested countries
5. Returns a FeatureCollection with only those features

```php
// Request payload
POST /api/dashboard/countries-geojson
{
  "countries": ["US", "GB", "FR", "DE"]
}

// Response
{
  "type": "FeatureCollection",
  "features": [
    { /* US feature */ },
    { /* GB feature */ },
    { /* FR feature */ },
    { /* DE feature */ }
  ]
}
```

**Benefits**:
- Reduces response payload (only ~50KB per 4 countries vs 2MB+ for full world)
- Server controls which features are sent
- Prevents clients from accessing unneeded geographic data

#### Client-side: IndexedDB Caching | [CountryMap.vue](resources/js/components/CountryMap.vue)

The component maintains a persistent cache of country GeoJSON features using [IndexedDB](https://developer.mozilla.org/de/docs/Web/API/IndexedDB_API) with the following key functions:

**`initializeDB()`**
- Lazily initializes the `analytics-geojson` IndexedDB database
- Creates a `country-features` object store with `code` as the key
- Database is created once and reused across page loads

**`loadCountryGeoJsonCache()`**
- Retrieves all cached country features from IndexedDB
- Returns a `Record<string, GeoFeature>` where keys are country codes (e.g., "US")
- Gracefully returns empty object on errors (IndexedDB unavailable, etc.)

**`saveCountryGeoJsonCache(cache)`**
- Atomically saves the entire cache to IndexedDB
- Clears old entries and writes new ones in a single transaction
- Handles errors silently to prevent UI blocking

**`syncCountryGeoJson()`**
  1. Gets list of requested country codes from current visitor data
  2. loads missing countries (requested but not cached)
  3. Renders all requested features on the Leaflet map


**Network:**
- **First time seeing country**: ~1-2KB per country feature fetched
- **Subsequent views**: Zero network cost (cached locally)
- **Multiple countries**: Single request bundles all missing countries
- **Example**: 4 countries = ~8KB request vs 2MB+ full world GeoJSON

**Example Scenarios:**

| Scenario | Cached? | Network Cost | Map Render |
|----------|---------|--------------|-----------|
| View dashboard (US, GB, FR) first time | No | ~6-15KB | ~100ms |
| Same data, refresh page | Yes (IndexedDB) | 0KB | ~50ms |
| Add Germany (DE) to data | Partial | ~3-5KB | ~80ms |
| Switch to all-time view (50+ countries) | Partial | Only missing countries | ~200ms |

#### Adding New Countries or Refreshing Cache

**Automatic**:
- Whenever dashboard data changes, `syncCountryGeoJson()` is called via watchers
- New countries in visitor data are automatically detected and fetched
- No manual cache clearing needed

**Manual Cache Clear** (if needed):
```javascript
// Clear IndexedDB cache from browser console
const req = indexedDB.deleteDatabase('analytics-geojson');
// Reload page to rebuild cache
```