<script lang="ts" setup>
import { BadgeDollarSign, Film, Globe, Link, Mail, MessageCircle, Music, Search, Send, ShoppingCart, Smartphone, Users } from 'lucide-vue-next';

const { name } = defineProps<{ name: string; }>();

</script>

<template>
    <div class="size-3">
        <Link class="size-[inherit]" v-if="name === 'Direct'" />
        <Search class="size-[inherit]" v-else-if="name === 'Organic Search'" />
        <Send class="size-[inherit]" v-else-if="name === 'Organic Social'" />
        <Film class="size-[inherit]" v-else-if="name === 'Organic Video'" />
        <ShoppingCart class="size-[inherit]" v-else-if="name === 'Organic Shopping'" />
        <Mail class="size-[inherit]" v-else-if="name === 'Email'" />
        <MessageCircle class="size-[inherit]" v-else-if="name === 'SMS'" />
        <Music class="size-[inherit]" v-else-if="name === 'Audio'" />
        <Smartphone class="size-[inherit]" v-else-if="name === 'Mobile Push'" />
        <Link class="size-[inherit]" v-else-if="name === 'Cross Network'" />

        <Users class="size-[inherit]" v-else-if="['Affiliates', 'Referral'].includes(name)" />
        <BadgeDollarSign class="size-[inherit]"
            v-else-if="['Paid Search', 'Paid Social', 'Paid Video', 'Paid Shopping', 'Paid Other'].includes(name)" />

        <Globe class="size-[inherit]" v-else />
    </div>
</template>