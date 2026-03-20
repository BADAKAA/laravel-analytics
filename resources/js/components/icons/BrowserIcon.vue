<script lang="ts" setup>
import { Globe } from 'lucide-vue-next';
import { computed } from 'vue';

const { name } = defineProps<{
    name: string;
}>();

const ICONS = [ 'chrome', 'edge', 'firefox', 'safari', 'opera' ] as const;
const iconExists = computed(() => (ICONS as readonly string[]).includes(name.toLowerCase()));

</script>

<template>
    <img v-if="iconExists" :src="`/img/browsers/${name.toLowerCase()}.svg`" class="size-4" />
    <Globe v-else class="size-4 text-neutral-500" />
</template>