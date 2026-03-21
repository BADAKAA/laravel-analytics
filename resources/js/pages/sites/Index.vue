<script lang="ts" setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import SiteFormDialog from './partials/SiteFormDialog.vue';
import SitesList from './partials/SitesList.vue';
import type { SiteFormPayload, SiteItem } from './partials/types';

const props = defineProps<{
	sites: SiteItem[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
	{ title: 'Dashboard', href: dashboard() },
	{ title: 'Sites', href: '/sites' },
];

const sites = ref<SiteItem[]>([...props.sites]);
const isDialogOpen = ref(false);
const editingSite = ref<SiteItem | null>(null);
const isSubmitting = ref(false);
const requestError = ref('');
const formErrors = ref<Record<string, string>>({});

const openCreateDialog = () => {
	editingSite.value = null;
	formErrors.value = {};
	requestError.value = '';
	isDialogOpen.value = true;
};

const openEditDialog = (site: SiteItem) => {
	editingSite.value = site;
	formErrors.value = {};
	requestError.value = '';
	isDialogOpen.value = true;
};

const upsertSite = (site: SiteItem) => {
	const index = sites.value.findIndex((item) => item.id === site.id);

	if (index === -1) {
		sites.value.push(site);
	} else {
		sites.value[index] = site;
	}

	sites.value.sort((a, b) => a.name.localeCompare(b.name));
};

const submitSite = async (payload: SiteFormPayload) => {
	isSubmitting.value = true;
	formErrors.value = {};
	requestError.value = '';

	const isEditing = Boolean(editingSite.value);
	const endpoint = isEditing
		? `/api/sites/${editingSite.value?.id}`
		: '/api/sites';
	const method = isEditing ? 'PUT' : 'POST';

	try {
		const response = await fetch(endpoint, {
			method,
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-CSRF-TOKEN':
					(document
						.querySelector('meta[name="csrf-token"]')
						?.getAttribute('content') ?? ''),
			},
			body: JSON.stringify(payload),
		});

		const data = await response.json();

		if (response.status === 422) {
			formErrors.value = data.errors ?? {};
			return;
		}

		if (!response.ok) {
			requestError.value =
				data?.message ??
				'Could not save the site. Please try again.';
			return;
		}

		upsertSite(data.site as SiteItem);
		isDialogOpen.value = false;
	} catch {
		requestError.value = 'Could not save the site. Please try again.';
	} finally {
		isSubmitting.value = false;
	}
};

</script>

<template>
	<AppLayout :breadcrumbs="breadcrumbs">
		<Head title="Sites" />

		<div class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6">
			<div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
				<div>
					<h1 class="text-2xl font-semibold tracking-tight">Sites</h1>
					<p class="mt-1 text-sm text-muted-foreground">
						Manage your tracked domains and update their visibility.
					</p>
				</div>

				<Button type="button" @click="openCreateDialog">
					Add Site
				</Button>
			</div>

			<p
				v-if="requestError"
				class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
			>
				{{ requestError }}
			</p>

			<SitesList :sites="sites" @edit="openEditDialog" />
		</div>

		<SiteFormDialog
			v-model:open="isDialogOpen"
			:site="editingSite"
			:errors="formErrors"
			:submitting="isSubmitting"
			@submit="submitSite"
		/>
	</AppLayout>
</template>