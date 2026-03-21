<script setup lang="ts">
import iso3166 from 'iso-3166-2';
import { LoaderCircle, Search } from 'lucide-vue-next';
import { ref, computed, onMounted, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { codeToName } from '@/lib/utils';
import CountryMap from './CountryMap.vue';
import BrowserIcon from '@/components/icons/BrowserIcon.vue';
import ChannelIcon from '@/components/icons/ChannelIcon.vue';
import DeviceIcon from '@/components/icons/DeviceIcon.vue';
import OSIcon from '@/components/icons/OSIcon.vue';
import SourceIcon from '@/components/icons/SourceIcon.vue';

interface DataItem {
    [name: string]: string | number | undefined;
    count?: number;
    visitors?: number;
}

interface CategoryResponse {
    data?: DataItem[];
    total?: number;
}

interface Tab {
    id: string;
    label: string;
    category: string;
}

const props = defineProps<{
    title: string;
    tabs: Tab[];
    data: Record<string, any>;
    siteId: number | null;
    dateRange: { from: string; to: string };
    filters: Record<string, string>;
    loadingCategories?: Set<string>;
    bgClass?: string;
}>();

const bg = computed(() => props.bgClass || 'bg-foreground/5');

const emit = defineEmits<{
    filter: [type: string, value: string];
    openDetails: [category: string];
    tabChange: [category: string];
}>();

const activeTabId = ref<string>('');
const dataLoading = ref(false);
const preservedHeight = ref<number | null>(null);

const tabBody = ref<HTMLElement | null>(null);

onMounted(() => {
    if (props.tabs.length > 0 && !activeTabId.value) {
        activeTabId.value = props.tabs[0].id;
    }
});

watch([() => activeTabId.value], ([newTabId]) => {
    if (!newTabId) return;
    const currentTab = props.tabs.find((t) => t.id === newTabId);
    if (currentTab) {
        emit('tabChange', currentTab.category);
    }
});

// Reset data loading on filter/date/site change
watch(() => [props.filters, props.dateRange, props.siteId], () => {
    dataLoading.value = false;
}, { deep: true });

const currentTab = computed(() => {
    return props.tabs.find((t) => t.id === activeTabId.value) || props.tabs[0];
});

const displayItems = computed(() => {
    if (!currentTab.value) return [];

    const category = currentTab.value.category;
    const items = props.data[category] || [];

    return items.slice(0, 9);
});

const totalValue = computed(() => {
    const items = displayItems.value;
    return items.reduce((sum: number, item: DataItem) => sum + getItemValue(item), 0);
});

const currentCategory = computed(() => {
    return currentTab.value?.category || '';
});

const isCategoryLoading = computed(() => {
    return props.loadingCategories?.has(currentCategory.value) || false;
});

// Preserve height when loading starts, release when done
watch(() => isCategoryLoading.value, (isLoading) => {
    if (isLoading && tabBody.value) {
        // Capture current height before data changes
        preservedHeight.value = tabBody.value.offsetHeight;
    } else {
        // Release preserved height when loading completes
        preservedHeight.value = null;
    }
});

const onItemClick = (item: DataItem) => {
    const category = currentCategory.value;
    const filterTypeMap: Record<string, string> = {
        channels: 'channel',
        sources: 'referrer_domain',
        utm_campaigns: 'utm_campaign',
        top_pages: 'page',
        pages: 'page',
        entry_pages: 'entry_page',
        exit_pages: 'exit_page',
        countries: 'country',
        regions: 'region',
        cities: 'city',
        browsers: 'browser',
        operating_systems: 'os',
        os: 'os',
        devices: 'device_type',
    };

    const filterType = filterTypeMap[category] || category;
    emit('filter', filterType, getItemLabel(item));
};

const getItemValue = (item: DataItem): number => {
    return item.count ?? item.visitors ?? 0;
};

const getItemLabel = (item: DataItem): string => {
    const labelKey = Object.keys(item).find((key) => key !== 'count' && key !== 'visitors');
    const value = labelKey ? item[labelKey] : item.name;

    return typeof value === 'string' ? value : '';
};

const itemPercentage = (item: DataItem): number => {
    const value = getItemValue(item);

    if (totalValue.value <= 0) return 0;

    return (value / totalValue.value) * 100;
};

const regionCodeToName = (code: string): string => {
    return iso3166.subdivision(code)?.name || code;
};
</script>

<template>
    <div>
        <!-- Header -->
        <div class="flex items-center justify-between">
            <Tabs v-model="activeTabId">
                <TabsList :aria-label="title">
                    <TabsTrigger v-for="tab in tabs" :key="tab.id" :value="tab.id">
                        {{ tab.label }}
                    </TabsTrigger>
                </TabsList>
            </Tabs>
            <Button variant="ghost" size="sm" @click="emit('openDetails', currentCategory)">
                <Search class="h-4 w-4" />
            </Button>
        </div>

        <!-- Tab Content -->
        <div class="flex-1 p-4 group border rounded-2xl shadow-sm mt-3" ref="tabBody" 
            :style="{ height: preservedHeight ? `${preservedHeight}px` : 'auto' }">
            <!-- Map View -->
            <CountryMap v-if="currentCategory === 'map'" :siteId="props.siteId" :dateRange="props.dateRange"
                :filters="props.filters" :isLoading="isCategoryLoading" :countriesData="props.data.countries"
                @filter="(type, value) => emit('filter', type, value)"
                @openDetails="(category) => emit('openDetails', category)" />
            <!-- List View -->
            <div v-else>
                <div v-if="isCategoryLoading" class="flex h-48 items-center justify-center">
                    <LoaderCircle class="h-8 w-8 animate-spin opacity-30" />
                </div>
                <div v-else-if="displayItems.length === 0"
                    class="flex h-48 items-center justify-center text-gray-500 dark:text-gray-400">
                    No data available
                </div>
                <div v-else class="flex flex-col gap-1">
                    <div v-for="(item, index) in displayItems" :key="index"
                        class="group flex cursor-pointer items-center rounded-md  hover:bg-foreground/5 transition-colors pr-2"
                        @click="onItemClick(item)">

                        <!-- Item Name -->
                        <div class="grow truncate relative px-2 py-1.5">
                            <div class="w-full absolute inset-0">
                                <div class="h-full transition-all rounded-md opacity-60 group-hover:opacity-100"
                                    :class="bg" :style="{ width: `${itemPercentage(item)}%` }" />
                            </div>

                            <p
                                class="truncate text-sm font-medium text-gray-900 dark:text-gray-200  relative z-10 flex items-center gap-2">
                                <BrowserIcon :name="getItemLabel(item)" v-if="currentTab.label === 'Browsers'" />
                                <OSIcon v-if="currentTab.label === 'Operating Systems'" :name="getItemLabel(item)" />
                                <DeviceIcon v-if="currentTab.label === 'Devices'" :name="getItemLabel(item)" />
                                <ChannelIcon v-if="currentTab.label === 'Channels'" :name="getItemLabel(item)" />
                                <SourceIcon v-if="currentTab.label === 'Sources'" :name="getItemLabel(item)" />

                                <span v-if="currentTab.label === 'Countries'" class="flex items-center justify-center">
                                    <p class="uppercase opacity-50 text-xs mr-1 font-semibold font-mono mt-[0.2em]">
                                        {{ getItemLabel(item) }}
                                    </p>
                                    <span class="hover:underline">
                                        {{ codeToName(getItemLabel(item)) }}
                                    </span>
                                </span>
                                <span v-else-if="currentTab.label === 'Regions'" class="hover:underline">
                                    {{ regionCodeToName(getItemLabel(item)) }}
                                </span>
                                <span v-else class="hover:underline">
                                    {{ getItemLabel(item) }}
                                </span>

                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="w-12 text-right">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-200">
                                    {{ getItemValue(item) }}
                                </p>
                            </div>
                            <div class="w-0 group-hover:w-12 text-center transition-[width] overflow-hidden">
                                <p class="text-neutral-500 dark:text-neutral-400 text-sm">
                                    {{ itemPercentage(item).toFixed(1) }}%
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
