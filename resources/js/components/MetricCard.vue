<script setup lang="ts">
defineProps<{
    title: string;
    value: string | number;
    isLoading?: boolean;
}>();
</script>

<template>
    <div class="rounded-lg border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-slate-950">
        <div class="flex flex-col gap-2">
            <p class="text-sm font-medium text-neutral-600 dark:text-neutral-400">{{ title }}</p>
            <p v-if="isLoading" class="h-8 w-16 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
            <p v-else class="text-2xl font-bold text-gray-900 dark:text-neutral-200">{{ value }}</p>
        </div>
    </div>
</template>
