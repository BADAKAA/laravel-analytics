<script setup lang="ts">
import type { GeoJSON, Map } from 'leaflet';
import L from 'leaflet';
import { LoaderCircle } from 'lucide-vue-next';
import { ref, onMounted, computed, watch, nextTick } from 'vue';
import { codeToName, compactNumber } from '@/lib/utils';
import 'leaflet/dist/leaflet.css';

const SHOW_MAP_WHILE_LOADING = false;

interface CountryData {
    name?: string;
    country_code?: string;
    visitors: number;
}

const props = defineProps<{
    siteId: number | null;
    dateRange: { from: string; to: string };
    filters: Record<string, string>;
    isLoading?: boolean;
}>();

const emit = defineEmits<{
    filter: [type: string, value: string];
    openDetails: [category: string];
}>();

const mapContainer = ref<HTMLDivElement>();
const mapL = ref<Map | null>(null);
const countriesLayer = ref<GeoJSON | null>(null);
const items = ref<CountryData[]>([]);
const dataLoading = ref(false);
const initialisingMap = ref(false);

const DB_NAME = 'analytics-geojson';
const STORE_NAME = 'country-features';
const DB_VERSION = 1;

let dbInstance: IDBDatabase | null = null;

function initializeDB(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        if (dbInstance) {
            resolve(dbInstance);
            return;
        }

        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => {
            dbInstance = request.result;
            resolve(dbInstance);
        };

        request.onupgradeneeded = (event) => {
            const db = (event.target as IDBOpenDBRequest).result;

            if (!db.objectStoreNames.contains(STORE_NAME)) {
                db.createObjectStore(STORE_NAME, { keyPath: 'code' });
            }
        };
    });
}

async function loadCountryGeoJsonCache(): Promise<Record<string, any>> {
    try {
        const db = await initializeDB();
        const transaction = db.transaction(STORE_NAME, 'readonly');
        const store = transaction.objectStore(STORE_NAME);
        const request = store.getAll();

        return new Promise((resolve, reject) => {
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                const features = request.result;
                const cache: Record<string, any> = {};
                features.forEach((item: any) => {
                    cache[item.code] = item.feature;
                });
                resolve(cache);
            };
        });
    } catch (error) {
        console.error('Error loading from IndexedDB:', error);

        return {};
    }
}

async function saveCountryGeoJsonCache(cache: Record<string, any>): Promise<void> {
    try {
        const db = await initializeDB();
        const transaction = db.transaction(STORE_NAME, 'readwrite');
        const store = transaction.objectStore(STORE_NAME);

        // Clear existing data
        store.clear();

        // Add new data
        Object.entries(cache).forEach(([code, feature]) => {
            store.add({ code, feature });
        });

        return new Promise((resolve, reject) => {
            transaction.onerror = () => reject(transaction.error);
            transaction.oncomplete = () => resolve();
        });
    } catch (error) {
        console.error('Error saving to IndexedDB:', error);
    }
}

const buildQueryParams = () => {
    const params = new URLSearchParams({
        site_id: props.siteId!.toString(),
        date_from: props.dateRange.from,
        date_to: props.dateRange.to,
    });

    Object.entries(props.filters).forEach(([key, value]) => {
        params.append(`filter_${key}`, value);
    });

    return params;
};

const fetchCountryData = async () => {
    if (!props.siteId) return;

    dataLoading.value = true;

    try {
        const response = await fetch(`/api/dashboard/countries?${buildQueryParams()}`);
        const data = await response.json();
        items.value = data;
    } catch (error) {
        console.error('Error fetching country data:', error);
        items.value = [];
    } 
    dataLoading.value = false;
};

const countryDataMap = computed(() => {
    const map: Record<string, CountryData> = {};
    items.value.forEach((item) => {
        const code = String(item.country_code || item.name || '').trim().toUpperCase();
        map[code] = item;
    });

    return map;
});

const maxVisitors = computed(() => {
    return Math.max(...items.value.map((i) => i.visitors), 1);
});

function countryCodeFromFeature(feature: any): string {
    const properties = feature?.properties ?? {};
    const code = String(properties['ISO3166-1-Alpha-2'] ?? properties.ISO_A2 ?? '').trim().toUpperCase();

    return code && code !== '-99' ? code : '';
}

function requestedCountryCodes(): string[] {
    return Array.from(new Set(items.value
        .map((item) => String(item.country_code || item.name || '').trim().toUpperCase())
        .filter((code) => code && code !== '-99')));
}

function csrfToken(): string {
    // Try meta tag first (most common)
    const metaEl = document.querySelector('meta[name="csrf-token"]');

    if (metaEl?.getAttribute('content')) {
        return metaEl.getAttribute('content') || '';
    }

    // Fallback: try to get from cookie
    const cookieValue = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    if (cookieValue) {
        return decodeURIComponent(cookieValue);
    }

    console.warn('CSRF token not found in meta tag or cookies');

    return '';
}

async function fetchMissingCountryFeatures(countries: string[]): Promise<any[]> {
    if (countries.length === 0) return [];

    const token = csrfToken();

    if (!token) {
        console.error('No CSRF token available. Check that meta[name="csrf-token"] exists in HTML.');

        throw new Error('CSRF token unavailable');
    }

    const response = await fetch('/api/dashboard/countries-geojson', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify({ countries }),
    });

    if (!response.ok) {
        const errorText = await response.text();
        console.error(`GeoJSON fetch failed (${response.status}):`, errorText);

        throw new Error(`Failed to fetch GeoJSON features: ${response.status}`);
    }

    const payload = await response.json();

    return Array.isArray(payload?.features) ? payload.features : [];
}

async function syncCountryGeoJson() {
    const requested = requestedCountryCodes();
    const cache = await loadCountryGeoJsonCache();
    const missing = requested.filter((code) => !cache[code]);

    if (missing.length > 0) {
    try {
            const fetchedFeatures = await fetchMissingCountryFeatures(missing);
            fetchedFeatures.forEach((feature) => {
                const code = countryCodeFromFeature(feature);
                if (code) cache[code] = feature;
            });
            await saveCountryGeoJsonCache(cache);
        } catch (error) {
            console.error('Error fetching GeoJSON features:', error);
        }
    }

    const features = requested
        .map((code) => cache[code])
        .filter(Boolean);

    if (countriesLayer.value) countriesLayer.value.clearLayers();
    
    countriesLayer.value = L.geoJSON({ type: 'FeatureCollection', features: [] } as any, {
        style: createStyle,
        onEachFeature: onEachCountry,
    }).addTo(mapL.value as any);

    countriesLayer.value.addData({
        type: 'FeatureCollection',
        features,
    } as any);
}

function countryNameFromFeature(feature: any, code: string): string {
    const properties = feature?.properties ?? {};
    const geoName = String(properties.name ?? properties.ADMIN ?? '').trim();

    if (geoName) return geoName;

    return code ? codeToName(code) : 'Unknown';
}

function fillColorByViews(views: number, maxViews: number): string {
    if (!views || !maxViews) return '#e5e7eb';

    const ratio = Math.min(views / maxViews, 1);
    const lightness = 88 - Math.round(ratio * 48);

    return `hsl(210 85% ${lightness}%)`;
}

function createStyle(feature: any) {
    const code = countryCodeFromFeature(feature);
    const views = countryDataMap.value[code]?.visitors ?? 0;

    return {
        fillColor: fillColorByViews(views, maxVisitors.value),
        weight: 0.7,
        opacity: 1,
        color: '#94a3b8',
        fillOpacity: 0.85,
    };
}

const onCountryClick = (countryCode: string) => {
    emit('filter', 'country', countryCode);
};

function onEachCountry(feature: any, layer: L.Layer) {
    const code = countryCodeFromFeature(feature);
    const views = countryDataMap.value[code]?.visitors ?? 0;
    const countryName = countryNameFromFeature(feature, code);
    const tooltip = `<div class="px-2">
            <b>${countryName}</b>
            <br>
            <span style="min-width: 1rem; display:inline-block;" class="font-semibold">
                ${compactNumber(views)}
            </span>
            Visitor${views !== 1 ? 's' : ''}
        </div>`;

    layer.bindTooltip(tooltip, {
        sticky: true,
        direction: 'top',
        opacity: 0.95,
    });

    layer.on({
        mouseover: (event: any) => {
            const current = event.target;
            current.setStyle({ weight: 1.6, color: '#0f172a' });
            current.openTooltip(event.latlng);
        },
        mouseout: (event: any) => {
            countriesLayer.value?.resetStyle(event.target);
        },
        click: (event: any) => {
            if (code) {
                onCountryClick(code);
            }
        },
    });
}

const initializeMap = async () => {
    console.log('INIT');
    
    if (!mapContainer.value?.offsetHeight || initialisingMap.value) {
        return setTimeout(() => initializeMap(), 300);
    }
    initialisingMap.value = true;

    if (!mapL.value) {
        mapL.value = L.map(mapContainer.value, {
            scrollWheelZoom: false,
            zoomControl: true,
            attributionControl: true,
        }).setView([20, 8], 2);
        
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 6,
            minZoom: 2,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(mapL.value as any);

        await fetchCountryData();
        await syncCountryGeoJson();  
    }

    initialisingMap.value = false;
};

onMounted(() => initializeMap());

watch(() => [props.siteId, props.dateRange, props.filters],
    () => initializeMap(),
    { deep: true }
);

</script>
<template>
    <div v-if="isLoading || dataLoading || (!SHOW_MAP_WHILE_LOADING && initialisingMap)" class="flex h-96 items-center justify-center">
        <LoaderCircle class="h-8 w-8 animate-spin opacity-30" />
    </div>
    <div ref="mapContainer" v-show="SHOW_MAP_WHILE_LOADING || !initialisingMap"  class="h-96 rounded-2xl -m-4 bg-gray-100 dark:bg-slate-800" />
</template>

<style scoped>
:deep(.leaflet-container) {
    height: 100%;
}

:deep(.leaflet-popup-content-wrapper) {
    background-color: rgba(15, 23, 42, 0.9);
    border-radius: 8px;
    color: white;
}

:deep(.leaflet-popup-content) {
    padding: 8px;
}
</style>
