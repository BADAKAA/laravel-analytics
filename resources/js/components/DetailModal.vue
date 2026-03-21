<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

interface DetailItem {
    name: string;
    visitors?: number;
    count?: number;
}

const props = defineProps<{
    category: string;
    isOpen: boolean;
    siteId: number | null;
    dateRange: { from: string; to: string };
    filters: Record<string, string>;
}>();

const emit = defineEmits<{
    close: [];
}>();

const items = ref<DetailItem[]>([]);
const isLoading = ref(false);
const hasMore = ref(false);
const searchQuery = ref('');
const cursor = ref<string | null>(null);
const totalCount = ref(0);

const categoryTitle = computed(() => {
    const titles: Record<string, string> = {
        channels: 'Channels',
        top_pages: 'Top Pages',
        pages: 'Pages',
        entry_pages: 'Entry Pages',
        exit_pages: 'Exit Pages',
        countries: 'Countries',
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
    if (!props.siteId) {
return;
}

    isLoading.value = true;

    try {
        const endpoint = props.category === 'top_pages' ? 'pages' : props.category;
        const response = await fetch(`/api/dashboard/details/${endpoint}?${buildQueryParams()}`);
        const data = await response.json();

        if (loadMore) {
            items.value = [...items.value, ...data.data];
        } else {
            items.value = data.data;
        }

        totalCount.value = data.total;
        hasMore.value = data.has_more;
        cursor.value = data.next_cursor || null;
    } catch (error) {
        console.error('Error fetching details:', error);
    } finally {
        isLoading.value = false;
    }
};

const onLoadMore = () => {
    fetchDetails(true);
};

const onSearchChange = () => {
    cursor.value = null;
    items.value = [];
    fetchDetails();
};

watch(() => props.isOpen, (newValue) => {
    if (newValue) {
        cursor.value = null;
        items.value = [];
        fetchDetails();
    }
});

const getItemValue = (item: DetailItem): number => {
    return item.count ?? item.visitors ?? 0;
};

const maxValue = computed(() => {
    return Math.max(...items.value.map(getItemValue), 1);
});
</script>

<template>
    <Dialog :open="isOpen" @openChange="(state) => !state && emit('close')">
        <DialogContent class="max-h-screen max-w-2xl">
            <!-- Header -->
            <DialogHeader>
                <DialogTitle>{{ categoryTitle }}</DialogTitle>
            </DialogHeader>

            <!-- Search Bar -->
            <div class="mb-4">
                <Input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search..."
                    class="w-full"
                    @input="onSearchChange"
                />
            </div>

            <!-- Items List -->
            <div class="max-h-96 overflow-y-auto">
                <div v-if="isLoading && items.length === 0" class="flex py-8 justify-center">
                    <div class="text-gray-500">Loading...</div>
                </div>
                <div v-else-if="items.length === 0" class="flex py-8 justify-center">
                    <div class="text-gray-500">No items found</div>
                </div>
                <div v-else class="flex flex-col gap-3">
                    <div
                        v-for="(item, index) in items"
                        :key="index"
                        class="flex items-center gap-3 rounded-lg border border-sidebar-border/50 p-3 dark:border-sidebar-border/30"
                    >
                        <!-- Item Name -->
                        <div class="flex-grow truncate">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-200">
                                {{ item.name }}
                            </p>
                        </div>

                        <!-- Value and Bar -->
                        <div class="flex items-center gap-2">
                            <div class="w-16 text-right">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-200">
                                    {{ getItemValue(item) }}
                                </p>
                            </div>
                            <div class="h-6 w-32 overflow-hidden rounded-full bg-gray-200 dark:bg-slate-700">
                                <div
                                    class="h-full bg-blue-500 transition-all"
                                    :style="{ width: `${(getItemValue(item) / maxValue) * 100}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Load More Button -->
            <div v-if="hasMore" class="mt-6 flex justify-center">
                <Button
                    :disabled="isLoading"
                    @click="onLoadMore"
                >
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
