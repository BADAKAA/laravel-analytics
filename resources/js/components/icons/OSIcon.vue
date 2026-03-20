<script lang="ts" setup>
import { Cpu} from 'lucide-vue-next';
import { computed } from 'vue';

const { name } = defineProps<{
    name: string;
}>();

const ICONS = [ 'windows', 'macos', 'gnu-linux', 'ubuntu', 'ios', 'android' ] as const;
const iconExists = computed(() => (ICONS as readonly string[]).includes(name.toLowerCase().replaceAll('/','-')));

</script>

<template>
    <img v-if="iconExists" :src="`/img/os/${name.toLowerCase()}.svg`" class="size-4" />
    <Cpu v-else class="size-4 text-neutral-500" />
</template>