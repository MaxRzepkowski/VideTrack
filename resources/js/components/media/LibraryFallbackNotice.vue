<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { CloudOff } from 'lucide-vue-next';

// The page is being served from the local database because the metadata
// provider did not answer. It is honestly poorer than the live one — no
// recommendations, no gallery, no streaming availability — and saying so is
// the whole point of this bar: an incomplete page that stays silent reads as a
// broken app. `removed` is the other reason for the same page: the source
// answered, but no longer has this title (removed, or merged into another).
defineProps<{ show?: boolean; removed?: boolean }>();
</script>

<template>
    <div
        v-if="show"
        class="border-line-subtle bg-surface-raised text-ink-secondary flex items-start gap-3 rounded-xl border p-4 text-sm"
        role="status"
    >
        <CloudOff class="text-status-onhold mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <p v-if="removed">
            {{ trans('media.this_title_is_no_longer_available') }}
        </p>
        <p v-else>{{ trans('media.the_data_provider_is_unavailable_so') }}</p>
    </div>
</template>
