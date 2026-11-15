<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import { formatDateTime, relativeTime } from '@/lib/format';
import type { DeliveryNote } from '@/types/generated';
import type { DeliveryRow } from '@/types/integrations';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

/**
 * What the server has sent and what became of it — the whole diagnostic
 * surface of an integration. Plain rows on purpose: a coloured pill per
 * line would say in decoration what the sentence already says in words.
 *
 * A payload we could not read can be unfolded. Media servers rename fields
 * between versions, and the only way to fix a parser is to see what the
 * thing actually sent.
 */
defineProps<{ deliveries: DeliveryRow[] }>();

const opened = ref<Set<number>>(new Set());

function toggle(id: number): void {
    if (opened.value.has(id)) {
        opened.value.delete(id);
    } else {
        opened.value.add(id);
    }
}

const KINDS: Record<string, () => string> = {
    complete: () => trans('integrations.watched_to_the_end'),
    stop: () => trans('integrations.playback_stopped'),
    start: () => trans('integrations.playback_started'),
    pause: () => trans('integrations.playback_paused'),
    progress: () => trans('integrations.progress_reported'),
    rate: () => trans('integrations.rated'),
    added: () => trans('integrations.added_to_the_library'),
};

// The pipeline names its reasons in code; the page says them in words.
const MESSAGES: Record<DeliveryNote, () => string> = {
    already_played: () => trans('integrations.already_counted_as_watched'),
    below_threshold: () => trans('integrations.stopped_too_early_to_count_as'),
    duplicate_delivery: () => trans('integrations.the_same_delivery_arrived_twice'),
    invalid_payload: () => trans('integrations.the_payload_did_not_match_the'),
    kind_not_actionable: () => trans('integrations.nothing_to_do_for_an_event'),
    no_external_id_match: () => trans('integrations.no_id_in_the_payload_matched'),
    no_progress_unit: () => trans('integrations.this_kind_of_title_has_no'),
    not_an_event: () => trans('integrations.not_a_playback_event'),
    parse_failed: () => trans('integrations.the_payload_could_not_be_read'),
    payload_too_large: () => trans('integrations.the_payload_was_too_large'),
    processing_failed: () => trans('integrations.saving_it_failed'),
    rating_not_imported: () => trans('integrations.ratings_from_this_server_are_not'),
    rating_out_of_range: () => trans('integrations.the_rating_was_outside_the_scale'),
    test_parse_failed: () => trans('integrations.the_sample_payload_could_not_be'),
    test_passed: () => trans('integrations.the_sample_payload_was_read_and'),
    test_provider_unreachable: () => trans('integrations.the_metadata_provider_could_not_be'),
    user_mismatch: () => trans('integrations.played_by_a_different_account_on'),
};

function headline(delivery: DeliveryRow): string {
    if (delivery.label !== null) {
        return delivery.label;
    }

    return (delivery.kind ? KINDS[delivery.kind]?.() : undefined) ?? trans('integrations.unrecognized_delivery');
}

/** The second line: what kind of event it was, and why it ended as it did. */
function facts(delivery: DeliveryRow): (string | undefined)[] {
    const kind = delivery.kind && delivery.label !== null ? KINDS[delivery.kind]?.() : undefined;

    if (delivery.status === 'accepted') {
        return [kind, trans('integrations.saved_to_your_library')];
    }

    if (delivery.message === null) {
        return [kind];
    }

    return [kind, MESSAGES[delivery.message]()];
}
</script>

<template>
    <ul class="divide-line-subtle border-line-subtle divide-y border-t">
        <li v-for="delivery in deliveries" :key="delivery.id" class="py-2">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                <span class="text-ink min-w-0 flex-1 truncate text-sm">
                    <span v-if="delivery.status === 'test'" class="text-ink-muted">{{ trans('integrations.dry_run') }}: </span>
                    {{ headline(delivery) }}
                </span>
                <span v-if="delivery.at" v-tooltip="formatDateTime(delivery.at)" class="text-ink-muted shrink-0 text-sm">
                    {{ relativeTime(delivery.at) }}
                </span>
            </div>

            <MetaLine :parts="facts(delivery)" wrap class="mt-0.5 text-sm" :class="delivery.status === 'error' ? 'text-danger' : 'text-ink-muted'" />

            <template v-if="delivery.payload">
                <button type="button" class="text-brand-soft mt-1 text-sm font-medium hover:underline" @click="toggle(delivery.id)">
                    {{ opened.has(delivery.id) ? trans('integrations.hide_what_arrived') : trans('integrations.show_what_arrived') }}
                </button>
                <pre
                    v-if="opened.has(delivery.id)"
                    class="border-line-subtle bg-surface-base text-ink-secondary mt-1 max-h-80 overflow-auto rounded-md border px-3 py-2 font-mono text-sm"
                ><code>{{ delivery.payload }}</code></pre>
            </template>
        </li>
    </ul>
</template>
