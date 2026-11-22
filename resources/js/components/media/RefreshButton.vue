<script setup lang="ts">
import MenuRow from '@/components/MenuRow.vue';
import { rowTint } from '@/lib/ui';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { RefreshCw } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

// On-demand "refresh this entity", a row of the details dialog: POSTs to a
// refresh endpoint that re-fetches from the provider with the cache bypassed,
// then back() reloads the page with fresh data. Errors-only toast, matching
// the rest of the app.
const props = defineProps<{
    url: string;
    data?: Record<string, string>;
}>();

const emit = defineEmits<{ success: [] }>();

const loading = ref(false);

function refresh(): void {
    if (loading.value) {
        return;
    }

    loading.value = true;

    router.post(props.url, props.data ?? {}, {
        preserveScroll: true,
        onSuccess: () => emit('success'),
        onError: () => toast.error(trans('common.something_went_wrong_please_try_again')),
        onFinish: () => (loading.value = false),
    });
}
</script>

<template>
    <MenuRow
        inset="dialog"
        :icon="RefreshCw"
        :label="trans('common.refresh_from_provider')"
        :description="loading ? trans('common.refreshing') : trans('media.fetch_the_latest_data_from_the')"
        :style="rowTint('neutral')"
        :disabled="loading"
        :aria-busy="loading"
        class="disabled:cursor-default"
        @click="refresh"
    />
</template>
