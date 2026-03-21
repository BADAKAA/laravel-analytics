<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogClose,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { LoaderCircle } from 'lucide-vue-next';

const DEBOUNCE_TIMEOUT = 300;

interface DetailItem {
    name: string;
    visitors?: number;
    count?: number;
}

const props = defineProps<{
    category: string;
    siteId: number | null;
    dateRange: { from: string; to: string };
    filters: Record<string, string>;
}>();

const items = ref<DetailItem[]>([]);
const isLoading = ref(false);
const hasMore = ref(false);
const searchQuery = ref('');
const cursor = ref<string | null>(null);
const totalCount = ref(0);
const totalVisitors = ref(0);
const dialogOpen = ref(false);
const itemsContainer = ref<HTMLElement | null>(null);
const searchDebounceTimeout = ref<number | null>(null);

const openModal = () => {
    dialogOpen.value = true;
};

defineExpose({
    open: openModal,
});

const categoryTitle = computed(() => {
    const titles: Record<string, string> = {
        channels: 'Channels',
        sources: 'Sources',
        utm_campaigns: 'UTM Campaigns',
        utm_campaign: 'UTM Campaigns',
        top_pages: 'Top Pages',
        pages: 'Pages',
        entry_pages: 'Entry Pages',
        exit_pages: 'Exit Pages',
        countries: 'Countries',
        regions: 'Regions',
        cities: 'Cities',
        browsers: 'Browsers',
        operating_systems: 'Operating Systems',
        os: 'Operating Systems',
        devices: 'Devices',
    };

    return titles[props.category] || props.category;
});

const buildQueryParams = () => {
    const params = new URLSearchParams({
        site_id: props.siteId!.toString(),
        date_from: props.dateRange.from,
        date_to: props.dateRange.to,
        search: searchQuery.value,
    });

    Object.entries(props.filters).forEach(([key, value]) => {
        params.append(`filter_${key}`, value);
    });

    if (cursor.value) {
        params.append('cursor', cursor.value);
    }

    return params;
};

const fetchDetails = async (loadMore = false) => {
    if (!props.siteId) return;

    isLoading.value = true;

    try {
        let category = props.category;
        const response = await fetch(`/api/dashboard/details/${category}?${buildQueryParams()}`);
        const data = await response.json();

        if (loadMore) {
            items.value = [...items.value, ...data.data];
        } else {
            items.value = data.data;
        }

        totalCount.value = data.total;
        totalVisitors.value = data.total_visitors ?? 0;
        hasMore.value = data.has_more;
        cursor.value = data.next_cursor || null;
    } catch (error) {
        console.error('Error fetching details:', error);
    } finally {
        isLoading.value = false;
        if (!loadMore && itemsContainer.value) {
            itemsContainer.value?.style.removeProperty('height');
        }
    }
};

const onLoadMore = () => {
    fetchDetails(true);
};

const onSearchChange = () => {
    if (searchDebounceTimeout.value) {
        clearTimeout(searchDebounceTimeout.value);
    }
    searchDebounceTimeout.value = window.setTimeout(() => {
        cursor.value = null;
        items.value = [];
        const currentHeight = itemsContainer.value?.clientHeight || 0;
        itemsContainer.value?.style.setProperty('height', `${Math.max(currentHeight, 100)}px`);

        fetchDetails();
    }, DEBOUNCE_TIMEOUT);
};

watch(() => dialogOpen.value, (newValue) => {
    if (newValue) {
        cursor.value = null;
        items.value = [];
        fetchDetails();
    }
});

const getItemValue = (item: DetailItem): number => {
    return item.count ?? item.visitors ?? 0;
};

const itemPercentage = (item: DetailItem): number => {
    const value = getItemValue(item);
    if (totalVisitors.value <= 0) return 0;
    return (value / totalVisitors.value) * 100;
};

</script>

<template>
    <Dialog :open="dialogOpen" @update:open="dialogOpen = $event">
        <DialogContent class="max-h-screen max-w-2xl">
            <!-- Header -->
            <DialogHeader>
                <DialogTitle>{{ categoryTitle }}</DialogTitle>
                <DialogClose />
            </DialogHeader>

            <!-- Search Bar -->
            <div class="mb-4">
                <Input v-model="searchQuery" type="text" placeholder="Search..." class="w-full"
                    @input="onSearchChange" />
            </div>

            <!-- Items List -->
            <div class="max-h-96 overflow-y-auto" ref="itemsContainer">
                <div v-if="isLoading" class="flex py-8 justify-center">
                    <LoaderCircle class="h-8 w-8 animate-spin opacity-30" />
                </div>
                <div v-else-if="items.length === 0" class="flex py-8 justify-center">
                    <div class="text-gray-500">No items found</div>
                </div>
                <div v-else class="flex flex-col gap-1">
                    <div v-for="(item, index) in items" :key="index"
                        class="group flex cursor-pointer items-center rounded-md hover:bg-foreground/5 transition-colors pr-2">
                        <!-- Item Name -->
                        <div class="grow truncate relative px-2 py-1.5">
                            <div class="w-full absolute inset-0">
                                <div class="h-full transition-all rounded-md opacity-60 group-hover:opacity-100 bg-foreground/5"
                                    :style="{ width: `${itemPercentage(item)}%` }" />
                            </div>

                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-200 relative z-10">
                                {{ item.name }}
                            </p>
                        </div>

                        <!-- Value and Percentage -->
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

            <!-- Load More Button -->
            <div v-if="hasMore" class="mt-6 flex justify-center">
                <Button :disabled="isLoading" @click="onLoadMore">
                    {{ isLoading ? 'Loading...' : 'Load More' }}
                </Button>
            </div>

            <!-- Total Count -->
            <div class="mt-4 text-center text-sm text-gray-500">
                Showing {{ items.length }} of {{ totalCount }} results
            </div>
        </DialogContent>
    </Dialog>
</template>
