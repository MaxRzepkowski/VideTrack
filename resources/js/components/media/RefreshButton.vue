<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

// On-demand "refresh this entity" — POSTs to a refresh endpoint that re-fetches
// from the provider with the cache bypassed, then back() reloads the page with
// fresh data. Errors-only toast, matching the rest of the app.
const props = withDefaults(
    defineProps<{
        url: string;
        data?: Record<string, string>;
        label?: string;
        // 'block' fills its container (modal footer); 'inline' matches the
        // compact header action buttons (collection header).
        variant?: 'block' | 'inline';
    }>(),
    { variant: 'block' },
);

const emit = defineEmits<{ success: [] }>();

const loading = ref(false);
const text = computed(() => props.label ?? trans('common.refresh_from_provider'));

const classes = computed(() =>
    props.variant === 'inline'
        ? 'border-line bg-surface text-ink hover:border-line-strong rounded-xl px-4 py-2.5'
        : 'border-line hover:border-brand hover:text-ink text-ink-secondary bg-surface-raised w-full rounded-lg px-4 py-2.5',
);

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
    <button
        type="button"
        class="inline-flex items-center justify-center gap-2 border text-sm font-semibold transition-colors duration-150 disabled:opacity-50"
        :class="classes"
        :disabled="loading"
        @click="refresh"
    >
        <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" />
        <span>{{ loading ? trans('common.refreshing') : text }}</span>
    </button>
</template>
