<script lang="ts" setup>
import { LaptopMinimal, MonitorX, Smartphone, Tablet, TvMinimal } from 'lucide-vue-next';

const { name } = defineProps<{
    name: string;
}>();


</script>

<template>
    <div class="size-4">
        <LaptopMinimal class="size-[inherit]" v-if="name === 'Desktop'"/>
        <Smartphone class="size-[inherit]" v-else-if="name === 'Mobile'"/>
        <Tablet class="size-[inherit]" v-else-if="name === 'Tablet'"/>
        <TvMinimal class="size-[inherit]" v-else-if="name === 'TV'"/>
        <MonitorX class="size-[inherit]" v-else/>
    </div>
</template>