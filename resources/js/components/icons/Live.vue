<script lang="ts" setup>
import { onMounted, ref } from 'vue';

const ANIMATION_DURATION_MS = 2000;
const ANIMATION_INTERVAL_MS = 5000;

const animationActive = ref(false);

onMounted(() => {
    setInterval(() => {
        animationActive.value = true;
        setTimeout(() => animationActive.value = false, ANIMATION_DURATION_MS); 
    }, ANIMATION_INTERVAL_MS);
});
</script>

<template>
    <span class="relative flex size-[0.7em]">
        <span class="absolute inline-flex h-full w-full  rounded-full bg-blue-400 opacity-75" :class="{'animate-ping':animationActive}" :style="{ animationDuration: `${ANIMATION_DURATION_MS}ms` }"></span>
        <span class="relative inline-flex size-[inherit] rounded-full bg-blue-500"></span>
    </span>
</template>