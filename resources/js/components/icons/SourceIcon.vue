<script lang="ts" setup>
import { Link } from 'lucide-vue-next';
import { computed } from 'vue';

const { name } = defineProps<{
    name: string;
}>();

const ICONS = [ 'google', 'facebook', 'twitter', 'instagram', 'reddit', 'duckduckgo', 'x' ] as const;
const iconExists = computed(() => (ICONS as readonly string[]).includes(name.toLowerCase().replaceAll('/','-')));

</script>

<template>
    <img v-if="iconExists" :src="`/img/sources/${name.toLowerCase()}.svg`" class="size-4" />
    <Link v-else class="size-3 text-neutral-500" />
</template>