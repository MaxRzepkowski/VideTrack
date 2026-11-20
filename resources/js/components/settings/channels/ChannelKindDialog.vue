<script setup lang="ts">
import ServiceTile from '@/components/settings/ServiceTile.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { kindsMatching } from '@/lib/channels';
import { SETTINGS_FIELD_CLASS } from '@/lib/ui';
import type { ChannelKindData } from '@/types/generated';
import { Bell } from '@lucide/vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

/**
 * The services a channel can go to, as square tiles: the popular ones on
 * opening, every one of them on request or as soon as the search has words.
 */
const props = defineProps<{ kinds: ChannelKindData[] }>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ pick: [kind: ChannelKindData] }>();

const query = ref('');
const showingAll = ref(false);

watch(open, (opened) => {
    if (opened) {
        query.value = '';
        showingAll.value = false;
    }
});

const searching = computed(() => query.value.trim() !== '');
const tiles = computed(() => {
    if (searching.value) {
        return kindsMatching(props.kinds, query.value);
    }

    return showingAll.value ? props.kinds : props.kinds.filter((kind) => kind.popular);
});

function pick(kind: ChannelKindData): void {
    emit('pick', kind);
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="border-line-subtle bg-surface flex max-h-[85vh] max-w-3xl flex-col gap-4 rounded-2xl p-5 sm:p-7">
            <DialogTitle class="text-ink text-xl font-semibold">{{ trans('settings.choose_a_service') }}</DialogTitle>

            <input
                v-model="query"
                type="search"
                autocomplete="off"
                :aria-label="trans('settings.search_services')"
                :placeholder="trans('settings.search_services')"
                :class="[SETTINGS_FIELD_CLASS, 'block w-full']"
            />

            <div class="-mx-1 min-h-0 flex-1 overflow-y-auto px-1 pb-1">
                <h3 v-if="!searching" class="text-ink-secondary mb-3 text-sm font-medium">
                    {{ showingAll ? trans('settings.all_services') : trans('settings.popular_services') }}
                </h3>

                <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
                    <li v-for="kind in tiles" :key="`${kind.driver}:${kind.code}`">
                        <ServiceTile :code="kind.code" :label="kind.label" :fallback="Bell" @click="pick(kind)" />
                    </li>
                </ul>

                <p v-if="tiles.length === 0" class="text-ink-muted py-6 text-center text-sm">{{ trans('settings.no_service_matches') }}</p>

                <button
                    v-if="!searching && !showingAll"
                    type="button"
                    class="text-brand-soft mt-4 text-sm font-medium hover:underline"
                    @click="showingAll = true"
                >
                    {{ transChoice('settings.show_all_count_services', props.kinds.length, { count: String(props.kinds.length) }) }}
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>
