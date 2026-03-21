<script lang="ts" setup>
import { compactNumber } from '@/lib/utils';

const { modelValue: selectedChartMetric, metrics, unfilteredMetrics } = defineProps<{
    modelValue: string;
    metrics: Record<string, number> | null;
    unfilteredMetrics: Record<string, number> | null;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', metric: string): void;
}>();

const onMetricCardClick = (metric: 'visitors' | 'visits' | 'pageviews' | 'bounce_rate' | 'avg_duration' | 'views_per_visit') => {
    emit('update:modelValue', metric);
};
</script>

<template>
    <div class="mb-6 grid md:grid-cols-2 lg:grid-cols-6 divide-x border rounded-lg">
        <div class="cursor-pointer p-4 transition-all"
            :class="selectedChartMetric === 'visitors' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
            @click="onMetricCardClick('visitors')">
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-400"
                :class="selectedChartMetric === 'visitors' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                Unique Visitors</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-neutral-200">
                {{ compactNumber(metrics?.visitors ?? unfilteredMetrics?.visitors ?? 0) }}
            </div>
        </div>

        <div class="cursor-pointer p-4 transition-all"
            :class="selectedChartMetric === 'visits' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
            @click="onMetricCardClick('visits')">
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-400"
                :class="selectedChartMetric === 'visits' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                Sessions</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-neutral-200">
                {{ compactNumber(metrics?.visits ?? unfilteredMetrics?.visits ?? 0) }}
            </div>
        </div>

        <div class="cursor-pointer p-4 transition-all"
            :class="selectedChartMetric === 'pageviews' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
            @click="onMetricCardClick('pageviews')">
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-400"
                :class="selectedChartMetric === 'pageviews' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                Pageviews</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-neutral-200">
                {{ compactNumber(metrics?.pageviews ?? unfilteredMetrics?.pageviews ?? 0) }}
            </div>
        </div>

        <div class="cursor-pointer p-4 transition-all"
            :class="selectedChartMetric === 'bounce_rate' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
            @click="onMetricCardClick('bounce_rate')">
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-400"
                :class="selectedChartMetric === 'bounce_rate' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                Bounce Rate</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-neutral-200">{{
                metrics?.bounce_rate ?? unfilteredMetrics?.bounce_rate ?? 0 }}%</div>
        </div>

        <div class="cursor-pointer p-4 transition-all"
            :class="selectedChartMetric === 'avg_duration' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
            @click="onMetricCardClick('avg_duration')">
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-400"
                :class="selectedChartMetric === 'avg_duration' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                Avg. Duration</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-neutral-200">{{
                metrics?.avg_duration ?? unfilteredMetrics?.avg_duration ?? 0 }}s</div>
        </div>

        <div class="cursor-pointer p-4 transition-all"
            :class="selectedChartMetric === 'views_per_visit' ? 'bg-neutral-100 dark:bg-neutral-900/30' : ''"
            @click="onMetricCardClick('views_per_visit')">
            <div class="text-xs font-medium text-neutral-600 dark:text-neutral-400"
                :class="selectedChartMetric === 'views_per_visit' ? 'text-blue-600 dark:text-blue-400 underline' : ''">
                Views per Session</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 dark:text-neutral-200">{{
                metrics?.views_per_visit ?? unfilteredMetrics?.views_per_visit ?? 0 }}</div>
        </div>
    </div>
</template>