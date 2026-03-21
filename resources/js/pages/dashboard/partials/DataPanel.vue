<script setup lang="ts">
import { Maximize2 } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

interface DataItem {
    name: string;
    count?: number;
    visitors?: number;
}

const props = defineProps<{
    title: string;
    items: DataItem[];
    category: string;
    isLoading?: boolean;
}>();

const emit = defineEmits<{
    filter: [type: string, value: string];
    openDetails: [category: string];
}>();

const displayItems = computed(() => {
    return (props.items as DataItem[]).slice(0, 9);
});

const onItemClick = (item: DataItem) => {
    // Map category to filter type
    const filterTypeMap: Record<string, string> = {
        channels: 'channel',
        top_pages: 'page',
        pages: 'page',
        entry_pages: 'page',
        exit_pages: 'page',
        countries: 'country',
        browsers: 'browser',
        operating_systems: 'os',
        devices: 'device_type',
    };
    
    const filterType = filterTypeMap[props.category] || props.category;
    emit('filter', filterType, item.name);
};

const getItemValue = (item: DataItem): number => {
    return item.count ?? item.visitors ?? 0;
};

const maxValue = computed(() => {
    return Math.max(...displayItems.value.map(getItemValue), 1);
});
</script>

<template>
    <div class="flex flex-col rounded-lg border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-slate-950">
        <!-- Header -->
        <div class="flex items-center justify-between border-b border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <h3 class="text-lg font-semibold dark:text-gray-100">{{ title }}</h3>
            <Button
                variant="ghost"
                size="sm"
                @click="emit('openDetails', category)"
            >
                <Maximize2 class="h-4 w-4" />
            </Button>
        </div>

        <!-- Content -->
        <div class="flex-1 p-4">
            <div v-if="isLoading" class="flex h-48 items-center justify-center">
                <div class="text-gray-500">Loading...</div>
            </div>
            <div v-else-if="displayItems.length === 0" class="flex h-48 items-center justify-center text-gray-500">
                No data available
            </div>
            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="(item, index) in displayItems"
                    :key="index"
                    class="group flex cursor-pointer items-center gap-3 rounded-lg p-2 hover:bg-gray-50 dark:hover:bg-slate-800"
                    @click="onItemClick(item)"
                >
                    <!-- Rank Badge -->
                    <div class="flex h-6 w-6 items-center justify-center rounded bg-gray-200 text-xs font-semibold dark:bg-slate-700">
                        {{ index + 1 }}
                    </div>

                    <!-- Item Name -->
                    <div class="flex-grow truncate">
                        <p class="truncate text-sm font-medium text-gray-900 group-hover:text-blue-600 dark:text-gray-200 dark:group-hover:text-blue-400">
                            {{ item.name }}
                        </p>
                    </div>

                    <!-- Value and Bar -->
                    <div class="flex items-center gap-2">
                        <div class="w-12 text-right">
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-200">
                                {{ getItemValue(item) }}
                            </p>
                        </div>
                        <div class="h-6 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-slate-700">
                            <div
                                class="h-full bg-blue-500 transition-all"
                                :style="{ width: `${(getItemValue(item) / maxValue) * 100}%` }"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
