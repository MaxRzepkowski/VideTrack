<script setup lang="ts">
import ListPicker from '@/components/lists/ListPicker.vue';
import AppModal from '@/components/modal/AppModal.vue';
import { useCreatorActions, type Creator } from '@/composables/useCreatorActions';
import { useMediaModals } from '@/composables/useMediaModals';
import { creatorState } from '@/stores/library';
import type { SharedData } from '@/types';
import type { UserList } from '@/types/media';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

const { modals, close } = useMediaModals();

const page = usePage<SharedData>();

const target = computed(() => modals.creatorLists);

// Creators go on custom lists alone — the backlog means "intend to consume".
const customLists = computed(() => (page.props.userLists ?? []).filter((list: UserList) => list.systemKey === null));

const { loadLists, toggleList, createListWith } = useCreatorActions();

const creator = computed<Creator | null>(() => target.value?.creator ?? null);

// Membership is fetched on open, whatever page the card sits on, and read from
// the store. Until it lands nothing is sent: a page may not have told the store
// about this creator, and "on no list" would then be a guess. An answer that
// comes back after the modal moved on to another creator says nothing about
// this one, so it leaves the modal locked.
const loaded = ref(false);
const loading = ref(false);

const listIds = computed(() => (creator.value ? creatorState(creator.value).listIds : []));

watch(target, async (value, _, onCleanup) => {
    let current = true;

    onCleanup(() => (current = false));
    loaded.value = false;
    loading.value = false;

    if (!value) {
        return;
    }

    loading.value = true;

    try {
        const landed = await loadLists(value.creator);

        if (current) {
            loaded.value = landed;
        }
    } finally {
        if (current) {
            loading.value = false;
        }
    }
});

// Create a new list with this creator already on it; the answer brings the
// creator's lists with the fresh one checked.
function create(title: string): void {
    if (creator.value && loaded.value) {
        void createListWith(creator.value, title);
    }
}

function toggle(list: UserList): void {
    if (creator.value && loaded.value) {
        void toggleList(creator.value, list.id);
    }
}
</script>

<template>
    <AppModal :open="target !== null" :title="trans('media.add_to_lists')" @update:open="(open: boolean) => !open && close()">
        <template v-if="target" #meta>
            <span v-tooltip.truncated="target.name" class="text-ink-muted truncate">{{ target.name }}</span>
        </template>

        <!-- The membership is read when the dialog opens; until it lands nothing can be ticked. -->
        <ListPicker v-if="target" :lists="customLists" :on="listIds" :waiting="loading || !loaded" @toggle="toggle" @create="create">
            <template v-if="customLists.length === 0" #empty>
                <p class="text-ink-muted px-5 py-2 text-base">{{ trans('media.no_custom_lists_yet_create_your') }}</p>
            </template>
        </ListPicker>
    </AppModal>
</template>
