<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { useUrlSearchParams } from '@vueuse/core';
import { XIcon } from 'lucide-vue-next';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import DetailModal from './partials/DetailModal.vue';
import SummaryChart from './partials/SummaryChart.vue';
import TabbedDataPanel from './partials/TabbedDataPanel.vue';
import { Button } from '@/components/ui/button';
import Label from '@/components/ui/label/Label.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { ucfirst } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import MetricCards from './partials/MetricCards.vue';
import LiveVisitors from './partials/LiveVisitors.vue';

interface Site {
    id: number;
    name: string;
    domain: string;
    timezone: string;
}

interface Metric {
    visitors: number;
    visits: number;
    pageviews: number;
    bounce_rate: number;
    avg_duration: number;
    views_per_visit: number;
}

interface MetricsWithChart extends Metric {
    chart_data: Array<any>;
    chart_granularity?: 'hour' | 'day' | 'week' | 'month';
}

interface CategoryData {
    [key: string]: any[];
}

interface DashboardData {
    metrics?: MetricsWithChart;
    channels?: any[];
    sources?: any[];
    utm_campaigns?: any[];
    top_pages?: any[];
    entry_pages?: any[];
    exit_pages?: any[];
    countries?: any[];
    regions?: any[];
    cities?: any[];
    browsers?: any[];
    operating_systems?: any[];
    devices?: any[];
}

const props = defineProps<{
    sites: Site[];
    selectedSiteId: number | null;
    timeframe: string;
    granularity: string;
    timeframeGranularities: Record<string, string[]>;
    dateRange: { from: string; to: string };
    unfiltered_data: any;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
];

const selectedSiteId = ref(props.selectedSiteId);
const selectedTimeframe = ref(props.timeframe || '28_days');
const selectedGranularity = ref(props.granularity || 'day');
const urlSearchParams = useUrlSearchParams('history');

const activeFilters = computed(() => {
    const filters: Record<string, string> = {};

    for (const [key, value] of Object.entries(urlSearchParams)) {
        if (key.startsWith('filter_')) {
            const filterKey = key.replace('filter_', '');
            filters[filterKey] = Array.isArray(value) ? value[0] : String(value);
        }
    }

    return filters;
});

// Shared dashboard data
const dashboardData = ref<DashboardData>({});
const loadingCategories = ref<Set<string>>(new Set());
const metricsLoading = ref(false);
const detailModal = ref<{ open: () => void } | null>(null);
const detailModalCategory = ref('');
const pollingInterval = ref<number | null>(null);
const hasZoomedChart = ref(false);
const zoomedDateRange = ref<{ from: string; to: string } | null>(null);
const selectedChartMetric = ref<'visitors' | 'visits' | 'pageviews' | 'bounce_rate' | 'avg_duration' | 'views_per_visit'>('visitors');
const loadedCategories = ref<Set<string>>(new Set());
const pendingFetches = ref<Set<string>>(new Set());
const visibleCategories = ref<Set<string>>(new Set());
let fetchTimeout: ReturnType<typeof setTimeout> | null = null;

const timeframeOptions = [
    { label: 'Today', value: 'today' },
    { label: 'Yesterday', value: 'yesterday' },
    { label: 'Realtime', value: 'realtime' },
    { label: 'Last 24 hours', value: 'yesterday_24h' },
    { label: 'Last 7 days', value: '7_days' },
    { label: 'Last 28 days', value: '28_days' },
    { label: 'Last 90 days', value: '90_days' },
    { label: 'Month to date', value: 'month_to_date' },
    { label: 'Last month', value: 'last_month' },
    { label: 'Year to date', value: 'year_to_date' },
    { label: 'Last 12 months', value: 'last_12_months' },
    { label: 'All time', value: 'all_time' },
];

const granularityOptions = computed(() => {
    const allowed = props.timeframeGranularities[selectedTimeframe.value] ?? ['day'];
    return allowed.map(g => ({
        label: g.charAt(0).toUpperCase() + g.slice(1),
        value: g,
    }));
});

const dateRange = computed(() => {
    if (hasZoomedChart.value && zoomedDateRange.value) {
        return zoomedDateRange.value;
    }

    return {
        from: props.dateRange?.from || new Date(Date.now() - 28 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
        to: props.dateRange?.to || new Date().toISOString().split('T')[0],
    };
});

const hasFilters = computed(() => Object.keys(activeFilters.value).length > 0);

const metrics = computed(() => {
    if (!dashboardData.value.metrics) return null;
    const { chart_data, chart_granularity, ...metricsData } = dashboardData.value.metrics;
    return metricsData as Record<string, number>;
});
const chartData = computed(() => dashboardData.value.metrics?.chart_data || []);
const chartGranularity = computed(() => dashboardData.value.metrics?.chart_granularity || 'day');
const unfilteredMetrics = computed(() => {
    if (!props.unfiltered_data) return null;

    return {
        visits: props.unfiltered_data.visits,
        visitors: props.unfiltered_data.visitors,
        pageviews: props.unfiltered_data.pageviews,
        bounce_rate: props.unfiltered_data.bounce_rate,
        avg_duration: props.unfiltered_data.avg_duration,
        views_per_visit: props.unfiltered_data.views_per_visit,
    };
});

const fetchCategories = async (categories: string[]) => {
    if (!selectedSiteId.value) return;
    
    // If no categories specified, fetch all defaults from backend
    const toFetch = categories.length === 0 
        ? [] 
        : (hasFilters.value 
            ? categories 
            : categories.filter(cat => !loadedCategories.value.has(cat)));

    // If no categories to fetch and none specified, request defaults from backend
    if (toFetch.length === 0 && categories.length === 0) {
        // Send empty array to backend, it will return defaults
    } else if (toFetch.length === 0) {
        return; // Categories already loaded
    }
    
    const categoriesToSend = categories.length === 0 ? [] : toFetch;
    categoriesToSend.forEach(cat => loadingCategories.value.add(cat));

    try {
        // Include metrics when filters are applied or when starting fresh
        const shouldIncludeMetrics = hasFilters.value || !props.unfiltered_data;
        
        // Only set metrics loading if we're fetching metrics
        if (shouldIncludeMetrics) {
            metricsLoading.value = true;
        }

        const payload: any = {
            site_id: selectedSiteId.value,
            date_from: dateRange.value.from,
            date_to: dateRange.value.to,
            timeframe: selectedTimeframe.value,
            granularity: selectedGranularity.value,
            categories: categoriesToSend,
            include_metrics: shouldIncludeMetrics,
            filters: activeFilters.value,
        };

        const response = await fetch('/api/dashboard/aggregate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json();
        Object.assign(dashboardData.value, data);
        
        // Mark categories as loaded
        if (categories.length === 0) {
            // When fetching defaults, mark all default categories as loaded
            const defaultCategories = Object.keys(data).filter(key => key !== 'metrics');
            defaultCategories.forEach(cat => loadedCategories.value.add(cat));
        } else {
            categoriesToSend.forEach(cat => loadedCategories.value.add(cat));
        }
    } catch (error) {
        console.error('Error fetching dashboard data:', error);
    } finally {
        // Remove categories from loading set
        categoriesToSend.forEach(cat => loadingCategories.value.delete(cat));
        // Clear metrics loading flag
        metricsLoading.value = false;
    }
};

const refreshData = (categories?: string[]) => {
    fetchCategories(categories || []);
};

const startPolling = () => {
    stopPolling();

    if (selectedTimeframe.value === 'realtime') {
        pollingInterval.value = window.setInterval(() => {
            fetchCategories(['channels', 'sources', 'utm_campaigns', 'top_pages', 'entry_pages', 'exit_pages']);
        }, 10000);
    }
};

const stopPolling = () => {
    if (pollingInterval.value) {
        clearInterval(pollingInterval.value);
        pollingInterval.value = null;
    }
};

const onSiteChange = (id: string) => {
    selectedSiteId.value = parseInt(id);
    loadedCategories.value.clear();
    dashboardData.value = {};
    router.visit(
        dashboard(),
        { data: { site_id: id, timeframe: selectedTimeframe.value, granularity: selectedGranularity.value } }
    );
};

const onTimeframeChange = (timeframe: string) => {
    selectedTimeframe.value = timeframe;
    // Reset to default granularity for new timeframe
    selectedGranularity.value = props.timeframeGranularities[timeframe]?.[0] || 'day';
    hasZoomedChart.value = false;
    zoomedDateRange.value = null;
    loadedCategories.value.clear();
    dashboardData.value = {};
    const data = { timeframe, granularity: selectedGranularity.value } as any;
    if (selectedSiteId.value !== props.sites[0].id) {
        data.site_id = selectedSiteId.value?.toString();
    }
    router.visit(
        dashboard(),
        { data: data }
    );
};

const onGranularityChange = (granularity: string) => {
    selectedGranularity.value = granularity;
    hasZoomedChart.value = false;
    zoomedDateRange.value = null;
    loadedCategories.value.clear();
    dashboardData.value = {};
    const data = { granularity } as any;
    if (selectedSiteId.value !== props.sites[0].id) {
        data.site_id = selectedSiteId.value?.toString();
    }
    data.timeframe = selectedTimeframe.value;
    router.visit(
        dashboard(),
        { data: data }
    );
};

const onChartZoom = (newDateRange: { from: string; to: string }) => {
    hasZoomedChart.value = true;
    zoomedDateRange.value = newDateRange;
    refreshData();
};

const onChartReset = () => {
    hasZoomedChart.value = false;
    zoomedDateRange.value = null;
    refreshData();
};

const onFilterApply = (filterType: string, value: string) => {
    if (value) {
        urlSearchParams[`filter_${filterType}`] = value;
    } else {
        delete urlSearchParams[`filter_${filterType}`];
    }

    // Preserve old metrics before clearing to avoid "no data available" message
    const oldMetrics = dashboardData.value.metrics;
    
    // Clear cache on filter change
    loadedCategories.value.clear();
    dashboardData.value = {};
    
    // Restore old metrics to keep chart visible while fetching new data
    if (oldMetrics) dashboardData.value.metrics = oldMetrics;
    
    // If all filters are now cleared and we have unfiltered data, restore it directly
    if (Object.keys(activeFilters.value).length === 0 && props.unfiltered_data) {
        dashboardData.value = props.unfiltered_data;
        const defaultCategories = Object.keys(props.unfiltered_data).filter(key => key !== 'metrics');
        defaultCategories.forEach(cat => loadedCategories.value.add(cat));
        
        const visibleNonDefaults = Array.from(visibleCategories.value).filter(
            cat => !defaultCategories.includes(cat)
        );
        if (visibleNonDefaults.length > 0) {
            fetchCategories(visibleNonDefaults);
        }
    } else {
        // Fetch default categories plus any visible non-default categories
        const categoriesToFetch = Array.from(visibleCategories.value);
        fetchCategories(categoriesToFetch.length > 0 ? categoriesToFetch : []);
    }
};

const onCategoryTabChange = (category: string) => {
    if (category === 'map') return;
    
    visibleCategories.value.add(category);
    
    // Queue category for fetching instead of fetching immediately
    if (!loadedCategories.value.has(category)) {
        pendingFetches.value.add(category);
        
        // Debounce the actual fetch to batch multiple tabs opening at once
        if (fetchTimeout) clearTimeout(fetchTimeout);
        fetchTimeout = setTimeout(() => {
            const toFetch = Array.from(pendingFetches.value);
            pendingFetches.value.clear();
            fetchCategories(toFetch);
            fetchTimeout = null;
        }, 50);
    }
};

const onOpenDetailModal = (category: string) => {
    detailModalCategory.value = category;
    detailModal.value?.open();
};

watch(() => selectedTimeframe.value, (newValue) => {
    startPolling();
});



onMounted(() => {
    // Initialize with unfiltered data from server if available
    if (props.unfiltered_data) {
        // Directly assign the data from server (all categories are now included)
        dashboardData.value = props.unfiltered_data;
        
        // Mark all default categories as loaded
        const defaultCategories = Object.keys(props.unfiltered_data).filter(key => key !== 'metrics');
        defaultCategories.forEach(cat => loadedCategories.value.add(cat));
    } else {
        // Fetch default categories from backend
        fetchCategories([]);
    }
    
    startPolling();
});

onBeforeUnmount(() => {
    stopPolling();
});

let pageTabs = usePage().props.pageviewsEnabled
    ? [{
        id: 'top_pages',
        label: 'Top Pages',
        category: 'top_pages',
    }]
    : [];
pageTabs = [...pageTabs,
{
    id: 'entry_pages',
    label: 'Entry Pages',
    category: 'entry_pages',
},
{
    id: 'exit_pages',
    label: 'Exit Pages',
    category: 'exit_pages',
},
];
</script>

<template>

    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col overflow-x-auto">

            <div class="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <div class="flex flex-col gap-4 md:flex-row items-start md:justify-between">
                    <div class="flex flex-wrap gap-4">
                        <div class="flex items-center gap-2">
                            <div class="grid gap-2">
                                <Label for="site">Site</Label>
                                <Select :defaultValue="selectedSiteId?.toString()">
                                    <SelectTrigger class="w-64">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="site in sites" :key="site.id" :value="site.id.toString()"
                                            @click="onSiteChange(site.id.toString())">
                                            {{ site.name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div class="flex items-center">
                            <div class="grid gap-2"v-if="hasFilters">
                                <Label>Filters</Label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Button v-for="(value, key) in activeFilters" :key="key" variant="outline"
                                        @click="onFilterApply(key, '')">
                                        {{ ucfirst(key) }}: {{ value }}
                                        <XIcon class="ml-1 h-3 w-3" />
                                    </Button>
                                </div>
                            </div>
                            <LiveVisitors v-else :site_id="selectedSiteId" />
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 lg:gap-2">
                        <div class="grid gap-2 md:opacity-50 hover:opacity-100 transition-opacity duration-500" v-if="granularityOptions.length > 1">
                            <Label for="granularity">Granularity</Label>
                            <Select :defaultValue="selectedGranularity">
                                <SelectTrigger class="w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="option in granularityOptions" :key="option.value"
                                        :value="option.value" @click="onGranularityChange(option.value)">
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-2">
                            <Label for="timeframe">Timeframe</Label>
                            <Select :defaultValue="selectedTimeframe">
                                <SelectTrigger class="w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="option in timeframeOptions" :key="option.value"
                                        :value="option.value" @click="onTimeframeChange(option.value)">
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <Button v-if="hasZoomedChart" variant="outline" @click="onChartReset">
                        Reset Zoom
                    </Button>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 gap-4 overflow-auto p-4">
                <div>
                    <MetricCards :metrics="metrics" :unfilteredMetrics="unfilteredMetrics"
                        v-model="selectedChartMetric" />
                    <SummaryChart :data="chartData" :isLoading="metricsLoading" :metric="selectedChartMetric" :granularity="chartGranularity"
                        @zoom="onChartZoom" @filter="onFilterApply" />
                </div>

                <div class="grid gap-6 lg:grid-cols-2 ">
                    <TabbedDataPanel title="Traffic Sources" bg-class="bg-blue-100 dark:bg-green-900" :tabs="[
                        {
                            id: 'channels',
                            label: 'Channels',
                            category: 'channels',
                        },
                        {
                            id: 'sources',
                            label: 'Sources',
                            category: 'sources',
                        },
                        {
                            id: 'utm_campaigns',
                            label: 'Campaigns',
                            category: 'utm_campaigns',
                        },
                    ]" :data="dashboardData" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters" 
                        :loadingCategories="loadingCategories" @filter="onFilterApply" @open-details="onOpenDetailModal" 
                        @tab-change="onCategoryTabChange" />

                    <TabbedDataPanel title="Pages" bg-class="bg-rose-100 dark:bg-rose-900" :tabs="pageTabs"
                        :data="dashboardData" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters" 
                        :loadingCategories="loadingCategories" @filter="onFilterApply" @open-details="onOpenDetailModal"
                        @tab-change="onCategoryTabChange" />

                    <TabbedDataPanel title="Locations" bg-class="bg-emerald-100 dark:bg-emerald-900" :tabs="[
                        {
                            id: 'map',
                            label: 'Map',
                            category: 'map',
                        },
                        {
                            id: 'countries',
                            label: 'Countries',
                            category: 'countries',
                        },
                        {
                            id: 'regions',
                            label: 'Regions',
                            category: 'regions',
                        },
                        {
                            id: 'cities',
                            label: 'Cities',
                            category: 'cities',
                        },
                    ]" :data="dashboardData" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters" 
                        :loadingCategories="loadingCategories" @filter="onFilterApply" @open-details="onOpenDetailModal"
                        @tab-change="onCategoryTabChange" />

                    <TabbedDataPanel title="Technical" :tabs="[
                        {
                            id: 'browsers',
                            label: 'Browsers',
                            category: 'browsers',
                        },
                        {
                            id: 'os',
                            label: 'Operating Systems',
                            category: 'operating_systems',
                        },
                        {
                            id: 'devices',
                            label: 'Devices',
                            category: 'devices',
                        },
                    ]" :data="dashboardData" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters" 
                        :loadingCategories="loadingCategories" @filter="onFilterApply" @open-details="onOpenDetailModal"
                        @tab-change="onCategoryTabChange" />
                </div>

                <DetailModal ref="detailModal" :category="detailModalCategory" :siteId="selectedSiteId"
                    :dateRange="dateRange" :filters="activeFilters" />
            </div>
        </div>
    </AppLayout>
</template>
