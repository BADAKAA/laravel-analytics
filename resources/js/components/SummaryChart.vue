<script setup lang="ts">
import { VisXYContainer, VisLine, VisAxis, VisArea, VisTooltip, VisCrosshair } from '@unovis/vue';
import { computed } from 'vue';
import { compactNumber } from '@/lib/utils';

interface ChartDataPoint {
    date: string;
    visitors: number;
    pageviews: number;
    bounce_rate: number;
    avg_duration: number;
    views_per_visit: number;
}


type MetricType = 'visitors' | 'pageviews' | 'bounce_rate' | 'avg_duration' | 'views_per_visit';

const props = defineProps<{
    data: ChartDataPoint[];
    isLoading?: boolean;
    metric?: MetricType;
}>();

const emit = defineEmits<{
    zoom: [range: { from: string; to: string }];
    filter: [type: string, value: string];
}>();

const chartData = computed(() => props.data || []);
const activeMetric = computed(() => props.metric || 'visitors');

const xAccessor = (d: ChartDataPoint) => new Date(d.date).getTime();

const yAccessor = (d: ChartDataPoint) => {
    const metric = activeMetric.value;

    return d[metric] ?? 0;
};

const metricColors = {
    visitors: '#3b82f6', // blue
    pageviews: '#6366f1', // indigo
    bounce_rate: '#ef4444', // red
    avg_duration: '#f59e0b', // amber
    views_per_visit: '#10b981', // emerald
};

const metricColor = computed(() => metricColors[activeMetric.value] || '#3b82f6');

const svgDefs = computed(
        () => `
    <linearGradient id="summaryMetricFill" x1="0" y1="0" x2="0" y2="1">
        <stop offset="5%" stop-color="${metricColor.value}" stop-opacity="0.35" />
        <stop offset="95%" stop-color="${metricColor.value}" stop-opacity="0.04" />
    </linearGradient>
`,
);

const handlePointClick = (index: number) => {
    const point = props.data[index];

    if (point) {
        emit('zoom', {
            from: point.date,
            to: point.date,
        });
    }
};

const getMetricLabel = () => {
    return activeMetric.value.charAt(0).toUpperCase() + activeMetric.value.slice(1).replace(/_/g, ' ');
};

const formatDateLabel = (value: number | Date | string) => {
    const date = value instanceof Date ? value : new Date(value);

    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const formatMetricValue = (value: number) => {
    switch (activeMetric.value) {
        case 'bounce_rate':
            return `${value.toFixed(1)}%`;
        case 'avg_duration':
            return `${Math.round(value)}s`;
        case 'visitors':
        case 'pageviews':
            return compactNumber(value);
        default:
            return value.toLocaleString();
    }
};

const crosshairTemplate = (datum: ChartDataPoint | undefined, x: number | Date) => {
    const label = getMetricLabel();
    const dateText = datum ? formatDateLabel(datum.date) : formatDateLabel(x);
    const valueText = datum ? formatMetricValue(yAccessor(datum)) : '-';

    return `
            <div class="text-xs font-medium">${dateText}</div>
            <div class="mt-1 text-xs flex items-center gap-1">
                <div class="size-3 rounded-sm bg-blue-100"></div>
                <span class="text-neutral-500 mr-2">${label}</span>
                <span class="font-semibold">${valueText}</span>
            </div>
    `;
};
</script>

<template>
    <div v-if="isLoading" class="flex h-80 items-center justify-center">
        <div class="text-gray-500 dark:text-gray-400">Loading chart...</div>
    </div>
    <div v-else-if="chartData.length === 0" class="flex h-80 items-center justify-center text-gray-500 dark:text-gray-400">
        No data available
    </div>
    <div v-else class="relative min-h-80 w-full">
        <!-- Chart Container -->
        <div class="h-80 w-full">
            <VisXYContainer class="chart-container"
                :data="chartData"
                :svg-defs="svgDefs"
                :margin="{ top: 20, bottom: 40, left: 10, right: 20 }"
            >
                <VisArea
                    :x="xAccessor"
                    :y="yAccessor"
                    color="url(#summaryMetricFill)"
                />

                <!-- Single Line for Selected Metric -->
                <VisLine
                    :x="xAccessor"
                    :y="yAccessor"
                    :color="metricColor"
                    :stroke-width="2"
                    @data-point-click="handlePointClick"
                />

                <!-- X Axis only -->
                <VisAxis
                    type="x"
                    :tick-line="false"
                    :domain-line="false"
                    :gridLine="false"
                    :tick-format="(d: number) => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })"
                />
                <!-- Y Axis without label -->
                <VisAxis type="y" :tick-line="false" :domain-line="false" />

                <VisTooltip />
                <VisCrosshair
                    :x="xAccessor"
                    :y="yAccessor"
                    :template="crosshairTemplate"
                    :color="metricColor"
                />
            </VisXYContainer>
        </div>

        <!-- Legend -->
        <!-- <div class="mt-4 flex justify-center gap-6">
            <div class="flex items-center gap-2">
                <div class="h-3 w-3 rounded-full" :style="{ backgroundColor: metricColor }"></div>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ getMetricLabel() }}</span>
            </div>
        </div> -->
    </div>
</template>
<style scoped>
    .dark .chart-container {
        --vis-axis-grid-color: #222;
        --vis-axis-grid-line-width: 1px;
    }
</style>
