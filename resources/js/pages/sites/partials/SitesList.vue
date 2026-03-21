<script setup lang="ts">
import { Check, Copy, Pencil } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { SiteItem } from './types';

defineProps<{
    sites: SiteItem[];
}>();

defineEmits<{
    edit: [site: SiteItem];
}>();

const copiedSiteId = ref<number | null>(null);
let copiedTimer: ReturnType<typeof setTimeout> | null = null;

const scriptTagForSite = (site: SiteItem) => {
    const origin =
        typeof window !== 'undefined'
            ? window.location.origin
            : 'https://your-analytics-domain.com';

    const scriptUrl = `${origin}/client.js?site_id=${site.id}`;

    return `<script defer src="${scriptUrl}"><\/script>`;
};

const copySiteScript = async (site: SiteItem) => {
    const value = scriptTagForSite(site);

    if (navigator?.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
    } else {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }

    copiedSiteId.value = site.id;

    if (copiedTimer) {
        clearTimeout(copiedTimer);
    }

    copiedTimer = setTimeout(() => {
        copiedSiteId.value = null;
    }, 1500);
};

onBeforeUnmount(() => {
    if (copiedTimer) {
        clearTimeout(copiedTimer);
    }
});

const formatDate = (value: string | null) => {
    if (!value) return 'Unknown';

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};
</script>

<template>
    <div v-if="sites.length === 0" class="rounded-lg border border-dashed p-10 text-center">
        <h2 class="text-lg font-medium">No sites yet</h2>
        <p class="mt-2 text-sm text-muted-foreground">
            Create your first site to start collecting analytics.
        </p>
    </div>

    <div v-else class="grid gap-4 md:grid-cols-2">
        <Card v-for="site in sites" :key="site.id" class="h-full">
            <CardHeader>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <CardTitle class="text-lg">{{ site.name }}</CardTitle>
                        <CardDescription class="mt-1">{{ site.domain }}</CardDescription>
                    </div>

                    <Badge :variant="site.is_public ? 'secondary' : 'outline'">
                        {{ site.is_public ? 'Public' : 'Private' }}
                    </Badge>
                </div>
            </CardHeader>

            <CardContent class="space-y-2 text-sm">
                <p>
                    <span class="font-medium">Timezone:</span>
                    {{ site.timezone }}
                </p>
                <p>
                    <span class="font-medium">Role:</span>
                    {{ site.role_label }}
                </p>
                <p class="text-muted-foreground">
                    Updated {{ formatDate(site.updated_at) }}
                </p>
            </CardContent>

            <CardFooter class="justify-between gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="copySiteScript(site)"
                >
                    <Check v-if="copiedSiteId === site.id" class="mr-2 size-4" />
                    <Copy v-else class="mr-2 size-4" />
                    {{ copiedSiteId === site.id ? 'Copied' : 'Copy Script' }}
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="!site.can_edit"
                    @click="$emit('edit', site)"
                >
                    <Pencil class="mr-2 size-4" />
                    Edit
                </Button>
            </CardFooter>
        </Card>
    </div>
</template>
