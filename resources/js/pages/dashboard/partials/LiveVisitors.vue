<script lang="ts" setup>
import Live from '@/components/icons/Live.vue';
import { csrfToken } from '@/lib/utils';
import { onMounted, Ref, ref } from 'vue';

const { site_id } = defineProps<{ site_id: number | null }>();
const POLLLING_INTERVAL_S = 30;

const live_visitors: Ref<number | null> = ref(null);

async function fetchLiveVisitors() {
    if (!site_id) return;
    try {
        const response = await fetch(`/api/dashboard/live-visitors?site_id=${site_id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
        const data = await response.json();
        live_visitors.value = data.count ?? 0;
    } catch (error) {
        console.error('Failed to fetch live visitors:', error);
    }
}

onMounted(() => {
    fetchLiveVisitors();
    setInterval(fetchLiveVisitors, POLLLING_INTERVAL_S * 1000);
});
</script>

<template>
    <div class="flex items-center justify-center gap-2 pt-5 text-sm text-neutral-500 font-medium" v-if="live_visitors">
        <Live />
        <span>
            {{ live_visitors }}
            current visitor{{ live_visitors !== 1 ? 's' : '' }}
        </span>
    </div>
</template>