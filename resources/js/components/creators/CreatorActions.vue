<script setup lang="ts">
import { useCreatorState } from '@/composables/useCreatorState';
import { useEntityFollow } from '@/composables/useEntityFollow';
import { useMediaModals } from '@/composables/useMediaModals';
import type { Creator } from '@/lib/creators';
import { onListsText } from '@/lib/lists';
import { trans } from 'laravel-vue-i18n';
import { Bell, BellRing, List } from '@lucide/vue';
import { computed } from 'vue';

// A creator's two actions — its lists, then the bell — as the creator page
// shows them, under the portrait on small screens and in the right column on
// large ones. No implicit tracking sources stand behind a creator: what the
// user did is all there is.
const props = defineProps<{ creator: Creator; name: string }>();

const state = useCreatorState(() => props.creator);
const onLists = computed(() => state.value.listIds.length);

const listsLabel = computed(() => onListsText(onLists.value) ?? trans('media.add_to_list'));

const { openCreatorLists } = useMediaModals();
const { followed, busy, toggle } = useEntityFollow(() => props.creator);
</script>

<template>
    <div class="border-line-subtle bg-surface flex flex-col gap-2 rounded-2xl border p-2">
        <button
            type="button"
            aria-haspopup="dialog"
            class="flex items-center gap-3 rounded-xl px-4 py-3.5 transition-colors duration-150"
            :class="onLists > 0 ? 'bg-action-watchlist' : 'hover:bg-highlight/5'"
            @click="openCreatorLists(creator, name)"
        >
            <List
                class="size-6 shrink-0 transition-colors duration-150"
                :class="onLists > 0 ? 'text-surface-base' : 'text-action-watchlist'"
                :stroke-width="2.2"
            />
            <span
                class="flex-1 text-left text-base font-semibold transition-colors duration-150"
                :class="onLists > 0 ? 'text-surface-base' : 'text-ink-muted'"
            >
                {{ listsLabel }}
            </span>
        </button>
        <button
            type="button"
            :disabled="busy"
            :aria-pressed="followed"
            class="flex items-center gap-3 rounded-xl px-4 py-3.5 transition-colors duration-150"
            :class="followed ? 'bg-brand' : 'hover:bg-highlight/5'"
            @click="toggle"
        >
            <component
                :is="followed ? BellRing : Bell"
                class="size-6 shrink-0 transition-colors duration-150"
                :class="followed ? 'text-on-accent' : 'text-brand'"
                :stroke-width="2.2"
            />
            <span
                class="flex-1 text-left text-base font-semibold transition-colors duration-150"
                :class="followed ? 'text-on-accent' : 'text-ink-muted'"
            >
                {{ followed ? trans('common.following') : trans('common.follow_releases') }}
            </span>
        </button>
    </div>
</template>
