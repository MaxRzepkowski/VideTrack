<script setup lang="ts">
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { shownState, stateKey, useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import type { SharedData } from '@/types';
import type { UserList } from '@/types/media';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Check, Plus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const { modals, close } = useMediaModals();
const { toggleList, createListWithItem } = useMediaActions();

const page = usePage<SharedData>();

const media = computed(() => modals.lists);
const state = computed(() => (media.value ? shownState(stateKey(media.value)) : null));
const lists = computed(() => page.props.userLists ?? []);

const newTitle = ref('');

watch(media, () => (newTitle.value = ''));

function toggle(list: UserList): void {
    if (!media.value || !state.value) {
        return;
    }

    toggleList(media.value, list.id);
}

function create(): void {
    const title = newTitle.value.trim();

    if (!media.value || title === '') {
        return;
    }

    createListWithItem(media.value, title);
    newTitle.value = '';
}
</script>

<template>
    <Dialog :open="media !== null" @update:open="(open: boolean) => !open && close()">
        <DialogContent v-if="media && state" class="border-line-subtle bg-surface max-w-md overflow-x-hidden rounded-2xl p-5 sm:p-7">
            <DialogTitle class="min-w-0 truncate text-2xl font-semibold">{{ trans('media.add_to_lists') }}</DialogTitle>

            <div class="flex min-w-0 flex-col gap-5">
                <!-- Cap the list at ~5 rows; the rest scrolls so a big
                     collection of lists never blows the modal past the viewport. -->
                <div class="flex max-h-72 max-w-full min-w-0 flex-col gap-2.5 overflow-x-hidden overflow-y-auto pr-1">
                    <!-- No `overflow-hidden` on the row: it clips the line box
                         and the title loses its air above and below. A long
                         title is already held by the label's own `truncate`. -->
                    <button
                        v-for="list in lists"
                        :key="list.id"
                        type="button"
                        class="flex h-12 w-full max-w-full min-w-0 shrink-0 items-center gap-3 rounded-xl border px-4 text-left transition-colors duration-150"
                        :class="
                            state.listIds.includes(list.id)
                                ? 'border-action-watchlist bg-action-watchlist/10'
                                : 'border-line bg-surface-raised hover:border-line-strong'
                        "
                        @click="toggle(list)"
                    >
                        <span
                            class="flex size-5.5 shrink-0 items-center justify-center rounded-md border transition-colors duration-150"
                            :class="state.listIds.includes(list.id) ? 'border-action-watchlist bg-action-watchlist' : 'border-line-strong'"
                        >
                            <Check v-if="state.listIds.includes(list.id)" class="text-surface-base size-3.5" :stroke-width="3" />
                        </span>
                        <span class="text-ink w-0 min-w-0 flex-1 truncate text-base font-semibold">{{ list.title }}</span>
                    </button>
                </div>

                <div class="bg-surface-overlay h-px"></div>

                <form class="flex items-center gap-2" @submit.prevent="create">
                    <label for="media-new-list" class="sr-only">{{ trans('common.new_list_name') }}</label>
                    <input
                        id="media-new-list"
                        v-model="newTitle"
                        type="text"
                        maxlength="100"
                        :placeholder="trans('common.new_list_name')"
                        class="border-line bg-surface-raised text-ink placeholder:text-ink-faint focus:border-action-watchlist h-12 min-w-0 flex-1 rounded-xl border px-4 text-base font-semibold focus:outline-none"
                    />
                    <button
                        v-tooltip="trans('common.create_list')"
                        type="submit"
                        :disabled="newTitle.trim() === ''"
                        class="bg-action-watchlist text-surface-base flex size-12 shrink-0 items-center justify-center rounded-xl transition-opacity duration-150 hover:opacity-90 disabled:opacity-40"
                    >
                        <Plus class="size-5" :stroke-width="2.5" />
                    </button>
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
