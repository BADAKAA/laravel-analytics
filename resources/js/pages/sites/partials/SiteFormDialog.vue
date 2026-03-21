<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TimezoneInput from './TimezoneInput.vue';
import type { SiteFormPayload, SiteItem } from './types';

const props = defineProps<{
    open: boolean;
    site: SiteItem | null;
    submitting: boolean;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    submit: [payload: SiteFormPayload];
}>();

const form = ref<SiteFormPayload>({
    name: '',
    domain: '',
    timezone: 'UTC',
    is_public: false,
});

const title = computed(() => (props.site ? 'Edit Site' : 'Create Site'));
const description = computed(() =>
    props.site
        ? 'Update this site without leaving the current page.'
        : 'Add a new tracked domain to your analytics workspace.',
);

watch(
    () => [props.open, props.site],
    () => {
        if (!props.open) return;

        if (props.site) {
            form.value = {
                name: props.site.name,
                domain: props.site.domain,
                timezone: props.site.timezone,
                is_public: props.site.is_public,
            };

            return;
        }

        form.value = {
            name: '',
            domain: '',
            timezone: 'UTC',
            is_public: false,
        };
    },
    { immediate: true },
);

const onSubmit = () => {
    emit('submit', { ...form.value });
};

const onOpenChange = (value: boolean) => {
    emit('update:open', value);
};

const onVisibilityChange = (value: boolean | 'indeterminate') => {
    form.value.is_public = value === true;
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    {{ description }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="onSubmit">
                <div class="grid gap-2">
                    <Label for="site-name">Name</Label>
                    <Input id="site-name" v-model="form.name" placeholder="Project Site" />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="site-domain">Domain</Label>
                    <Input id="site-domain" v-model="form.domain" placeholder="example.com" />
                    <InputError :message="errors.domain" />
                </div>

                <div class="grid gap-2">
                    <Label for="site-timezone">Timezone</Label>
                    <TimezoneInput
                        id="site-timezone"
                        v-model="form.timezone"
                        placeholder="UTC or Europe/London"
                    />
                    <InputError :message="errors.timezone" />
                </div>

                <label class="flex items-center gap-3 rounded-md border p-3 cursor-pointer">
                    <Checkbox
                        id="site-public"
                        :model-value="form.is_public"
                        @update:model-value="onVisibilityChange"
                    />
                    <div>
                        <Label for="site-public" class="cursor-pointer">Public visibility</Label>
                        <p class="text-xs text-muted-foreground">
                            <!-- Public sites can be shared without requiring access. -->
                        </p>
                    </div>
                </label>
                <InputError :message="errors.is_public" />

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="submitting"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        {{ submitting ? 'Saving...' : site ? 'Save changes' : 'Create site' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
