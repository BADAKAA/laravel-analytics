<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { useUrlSearchParams } from '@vueuse/core';
import { XIcon } from 'lucide-vue-next';
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import DetailModal from '@/components/DetailModal.vue';
import SummaryChart from '@/components/SummaryChart.vue';
import TabbedDataPanel from '@/components/TabbedDataPanel.vue';
import { Button } from '@/components/ui/button';
import Label from '@/components/ui/label/Label.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { compactNumber, ucfirst } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

interface Site {
    id: number;
    name: string;
    domain: string;
    timezone: string;
}

interface Metric {
    visitors: number;
    pageviews: number;
    bounce_rate: number;
    avg_duration: number;
    views_per_visit: number;
}

const props = defineProps<{
    sites: Site[];
    selectedSiteId: number | null;
    timeframe: string;
    dateRange: { from: string; to: string };
    unfiltered_data: any;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard() },
];

const selectedSiteId = ref(props.selectedSiteId);
const selectedTimeframe = ref(props.timeframe || '28_days');

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
const metrics = ref<Metric | null>(null);
const chartData = ref<any[]>([]);
const isLoading = ref(false);
const detailModal = ref<{ open: () => void } | null>(null);
const detailModalCategory = ref('');
const pollingInterval = ref<number | null>(null);
const hasZoomedChart = ref(false);
const zoomedDateRange = ref<{ from: string; to: string } | null>(null);
const selectedChartMetric = ref<'visitors' | 'pageviews' | 'bounce_rate' | 'avg_duration' | 'views_per_visit'>('visitors');

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

const buildQueryParams = () => {
    const params = new URLSearchParams({
        site_id: selectedSiteId.value!.toString(),
        date_from: dateRange.value.from,
        date_to: dateRange.value.to,
        timeframe: selectedTimeframe.value,
    });

    Object.entries(activeFilters.value).forEach(([key, value]) => {
        params.append(`filter_${key}`, value);
    });

    return params;
};

const dateRange = computed(() => {
    if (hasZoomedChart.value && zoomedDateRange.value) {
        return zoomedDateRange.value;
    }

    return {
        from: props.dateRange?.from || new Date(Date.now() - 28 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
        to: props.dateRange?.to || new Date().toISOString().split('T')[0],
    };
});

const unfilteredMetrics = computed(() => {
    if (!props.unfiltered_data) {
return null;
}

    return {
        visitors: props.unfiltered_data.visitors,
        pageviews: props.unfiltered_data.pageviews,
        bounce_rate: props.unfiltered_data.bounce_rate,
        avg_duration: props.unfiltered_data.avg_duration,
        views_per_visit: props.unfiltered_data.views_per_visit,
    };
});

const hasFilters = computed(() => Object.keys(activeFilters.value).length > 0);

const fetchMetrics = async () => {
    if (!selectedSiteId.value) {
return;
}

    isLoading.value = true;

    try {
        if (!hasFilters.value && props.unfiltered_data) {
            // Use unfiltered data from server
            metrics.value = unfilteredMetrics.value;
        } else {
            // Fetch filtered data
            const response = await fetch(`/api/dashboard/metrics?${buildQueryParams()}`);
            metrics.value = await response.json();
        }
    } catch (error) {
        console.error('Error fetching metrics:', error);
    } finally {
        isLoading.value = false;
    }
};

const fetchChartData = async () => {
    if (!selectedSiteId.value) return;

    try {
        if (!hasFilters.value && props.unfiltered_data) {
            chartData.value = props.unfiltered_data.chart_data;
        } else {
            const response = await fetch(`/api/dashboard/visitors-chart?${buildQueryParams()}`);
            chartData.value = await response.json();
        }
    } catch (error) {
        console.error('Error fetching chart data:', error);
    }
};

const refreshData = () => {
    fetchMetrics();
    fetchChartData();
};

const startPolling = () => {
    stopPolling();

    if (selectedTimeframe.value === 'realtime') {
        // Poll every 10 seconds for realtime
        pollingInterval.value = window.setInterval(refreshData, 10000);
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
    router.visit(
        dashboard(),
        { data: { site_id: id, timeframe: selectedTimeframe.value } }
    );
};

const onTimeframeChange = (timeframe: string) => {
    selectedTimeframe.value = timeframe;
    hasZoomedChart.value = false;
    zoomedDateRange.value = null;
    const data = { timeframe } as any;
    if (selectedSiteId.value !== props.sites[0].id) {
        data.site_id = selectedSiteId.value?.toString();
    }
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

const onMetricCardClick = (metric: 'visitors' | 'pageviews' | 'bounce_rate' | 'avg_duration' | 'views_per_visit') => {
    selectedChartMetric.value = metric;
};

const onFilterApply = (filterType: string, value: string) => {
    if (value) {
        urlSearchParams[`filter_${filterType}`] = value;
    } else {
        delete urlSearchParams[`filter_${filterType}`];
    }

    refreshData();
};

const onOpenDetailModal = (category: string) => {
    detailModalCategory.value = category;
    detailModal.value?.open();
};

watch(() => selectedTimeframe.value, (newValue) => {
    startPolling();
});

watch(() => props.unfiltered_data, () => {
    if (!hasFilters.value) {
        refreshData();
    }
});

onMounted(() => {
    refreshData();
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
pageTabs = [ ...pageTabs,
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
            <!-- Header: Site Selector and Time Range -->
            <div class="border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex flex-wrap gap-4">
                    <!-- Site Dropdown -->
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
                    <!-- Filters -->
                     <div class="flex gap-4" v-if="hasFilters">
                        <div class="grid gap-2">
                            <Label>Filters</Label>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button v-for="(value, key) in activeFilters" :key="key" variant="outline" 
                                    @click="onFilterApply(key, '')">
                                    {{ ucfirst(key) }}: {{ value }} <XIcon class="ml-1 h-3 w-3" />
                                </Button>

                     </div>
                     </div>
                     </div>
                    </div>

                    <!-- Timeframe Selector -->
                    <div class="flex items-center gap-2">
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

                    <!-- Reset Zoom Button (shown if zoomed) -->
                    <Button v-if="hasZoomedChart" variant="outline" @click="onChartReset">
                        Reset Zoom
                    </Button>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 gap-4 overflow-auto p-4">
                <!-- Summary Chart and Metrics -->
                <div>
                    <!-- Metrics Cards -->
                    <div class="mb-6 grid md:grid-cols-2 lg:grid-cols-5 divide-x border rounded-lg">
                        <div class="cursor-pointer p-4 transition-all"
                            :class="selectedChartMetric === 'visitors' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
                            @click="onMetricCardClick('visitors')">
                            <div class="text-xs font-medium text-gray-600 dark:text-gray-400"
                                :class="selectedChartMetric === 'visitors' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                                Visitors</div>
                            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                                {{ compactNumber(metrics?.visitors ?? unfilteredMetrics?.visitors ?? 0) }}
                            </div>
                        </div>

                        <div class="cursor-pointer p-4 transition-all"
                            :class="selectedChartMetric === 'pageviews' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
                            @click="onMetricCardClick('pageviews')">
                            <div class="text-xs font-medium text-gray-600 dark:text-gray-400"
                                :class="selectedChartMetric === 'pageviews' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                                Pageviews</div>
                            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">
                                {{ compactNumber(metrics?.pageviews ?? unfilteredMetrics?.pageviews ?? 0) }}
                            </div>
                        </div>

                        <div class="cursor-pointer p-4 transition-all"
                            :class="selectedChartMetric === 'bounce_rate' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
                            @click="onMetricCardClick('bounce_rate')">
                            <div class="text-xs font-medium text-gray-600 dark:text-gray-400"
                                :class="selectedChartMetric === 'bounce_rate' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                                Bounce Rate</div>
                            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{
                                metrics?.bounce_rate ?? unfilteredMetrics?.bounce_rate ?? 0 }}%</div>
                        </div>

                        <div class="cursor-pointer p-4 transition-all"
                            :class="selectedChartMetric === 'avg_duration' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
                            @click="onMetricCardClick('avg_duration')">
                            <div class="text-xs font-medium text-gray-600 dark:text-gray-400"
                                :class="selectedChartMetric === 'avg_duration' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                                Avg. Duration</div>
                            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{
                                metrics?.avg_duration ?? unfilteredMetrics?.avg_duration ?? 0 }}s</div>
                        </div>

                        <div class="cursor-pointer p-4 transition-all"
                            :class="selectedChartMetric === 'views_per_visit' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
                            @click="onMetricCardClick('views_per_visit')">
                            <div class="text-xs font-medium text-gray-600 dark:text-gray-400"
                                :class="selectedChartMetric === 'views_per_visit' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                                Views per Visit</div>
                            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{
                                metrics?.views_per_visit ?? unfilteredMetrics?.views_per_visit ?? 0 }}</div>
                        </div>
                    </div>
                    <SummaryChart :data="chartData" :isLoading="isLoading" :metric="selectedChartMetric"
                        @zoom="onChartZoom" @filter="onFilterApply" />
                </div>

                <!-- Data Panels Grid (4 panels with tabs) -->
                <div class="grid gap-6 lg:grid-cols-2 ">
                    <!-- Panel 1: Channels/Sources/UTM Campaigns -->
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
                    ]" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters"
                        :isLoading="isLoading" @filter="onFilterApply" @open-details="onOpenDetailModal" />

                    <!-- Panel 2: Top Pages/Entry/Exit Pages -->
                    <TabbedDataPanel title="Pages" bg-class="bg-rose-100 dark:bg-rose-900" :tabs="pageTabs" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters"
                        :isLoading="isLoading" @filter="onFilterApply" @open-details="onOpenDetailModal" />

                    <!-- Panel 3: Countries/Map -->
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
                    ]" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters"
                        :isLoading="isLoading" @filter="onFilterApply" @open-details="onOpenDetailModal" />

                    <!-- Panel 4: Browsers/OS/Devices -->
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
                    ]" :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters"
                        :isLoading="isLoading" @filter="onFilterApply" @open-details="onOpenDetailModal" />
                </div>

                <!-- Detail Modal -->
                <DetailModal ref="detailModal" :category="detailModalCategory"
                    :siteId="selectedSiteId" :dateRange="dateRange" :filters="activeFilters" />
            </div>
        </div>
    </AppLayout>
</template>
