<script setup lang="ts">
import ListPicker from '@/components/lists/ListPicker.vue';
import AppModal from '@/components/modal/AppModal.vue';
import { shownState, stateKey, useMediaActions } from '@/composables/useMediaActions';
import { useMediaModals } from '@/composables/useMediaModals';
import type { SharedData } from '@/types';
import type { UserList } from '@/types/media';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

const { modals, close } = useMediaModals();
const { toggleList, createListWithItem } = useMediaActions();

const page = usePage<SharedData>();

const media = computed(() => modals.lists);
const state = computed(() => (media.value ? shownState(stateKey(media.value)) : null));
const lists = computed(() => page.props.userLists ?? []);

function toggle(list: UserList): void {
    if (media.value) {
        toggleList(media.value, list.id);
    }
}

function create(title: string): void {
    if (media.value) {
        createListWithItem(media.value, title);
    }
}
</script>

<template>
    <AppModal :open="media !== null" :title="trans('media.add_to_lists')" @update:open="(open: boolean) => !open && close()">
        <template v-if="media" #meta>
            <span class="text-ink-muted truncate">{{ media.title }}</span>
        </template>

        <ListPicker v-if="media && state" :lists="lists" :on="state.listIds" @toggle="toggle" @create="create" />
    </AppModal>
</template>
