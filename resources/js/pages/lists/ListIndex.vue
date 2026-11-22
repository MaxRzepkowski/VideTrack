<script setup lang="ts">
import { groupOf, userPanel } from '@/lib/navigation';
import ListCardFooter from '@/components/lists/ListCardFooter.vue';
import ListCover from '@/components/lists/ListCover.vue';
import PageHeading from '@/components/PageHeading.vue';
import PageMasthead from '@/components/PageMasthead.vue';
import PageToolbar from '@/components/PageToolbar.vue';
import ReorderControls from '@/components/ReorderControls.vue';
import SortControl from '@/components/SortControl.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import UserHeader from '@/components/users/UserHeader.vue';
import { useListActions } from '@/composables/useListActions';
import { useManualOrder } from '@/composables/useManualOrder';
import AppLayout from '@/layouts/AppLayout.vue';
import { listUrl } from '@/lib/urls';
import type { ListOverview, ProfileData } from '@/types/media';
import { Head, Link } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { GripVertical, List as ListIcon, Pin, Plus } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        lists: ListOverview[];
        /** Username of whose lists these are: each one lives under them. */
        owner: string;
        /** Set on /user/{username}/lists — there this is a panel of a user
         *  view and wears its header; absent on the own /lists page. */
        profile?: ProfileData | null;
        isOwner?: boolean;
    }>(),
    { profile: null, isOwner: true },
);

const heading = computed(() => (props.profile && !props.isOwner ? trans('lists.name_lists', { name: props.profile.name }) : trans('common.lists')));

function listTitle(list: ListOverview): string {
    return list.displayTitle;
}

// A system list (the backlog) is not user content: it leads the index by
// definition, so it sits out of reordering — no drag, no move buttons, and the
// server pins it to position 0 whatever a payload claims.
function isPinned(list: ListOverview): boolean {
    return list.systemKey !== null;
}

const listActions = useListActions();

const pinnedIds = computed(() => new Set(props.lists.filter(isPinned).map((list) => list.id)));

// Reordering needs two lists that may actually swap — the backlog does not
// count, so a lone custom list next to it offers nothing to arrange.
const reorderable = computed(() => props.lists.length - pinnedIds.value.size > 1);

// ---- Sorting (client-side, mirrors the sort inside a list) ----

// 'order' = the manual order (position), the only mode you can reorder in;
// 'added' sorts by when the list was created — reordering never touches it.
// A list has no rating, so the third axis is how many positions it holds.
const sort = ref<'order' | 'added' | 'count' | 'az'>('order');
const sortDir = ref<'asc' | 'desc'>('asc');
const editing = ref(false);

// Each mode has a natural default direction (A–Z and manual order read
// ascending; newest-created and biggest-list read descending); switching mode
// resets to it, then the toggle flips from there — same as inside a list.
watch(sort, () => {
    sortDir.value = sort.value === 'az' || sort.value === 'order' ? 'asc' : 'desc';
});

// Reordering only makes sense against the manual order — entering edit mode
// switches back to it instead of leaving a mode where dragging is dead.
watch(editing, (on) => {
    if (on) {
        sort.value = 'order';
        sortDir.value = 'asc';
        // The grid itself is select-none, but a highlight made before the
        // toggle survives it — and the browser would drag that selection
        // instead of the card grabbed next.
        window.getSelection()?.removeAllRanges();
    }
});

// Reordering, by drag & drop or the step buttons, in List order only. The
// backlog keeps its slot at the top.
const { orderedIds, dragId, startDrag, endDrag, dropOn, move, moveToEdge } = useManualOrder(
    () => props.lists.map((list) => list.id),
    (ids, onError) =>
        // The move did not stick: put it back (the store has said so).
        void listActions.reorder(ids.filter((id) => !pinnedIds.value.has(id))).then((landed) => landed || onError()),
    (id) => pinnedIds.value.has(id),
);

const displayed = computed<ListOverview[]>(() => {
    const byId = new Map(props.lists.map((list) => [list.id, list]));
    const ordered = orderedIds.value.map((id) => byId.get(id)).filter((list): list is ListOverview => list !== undefined);

    // Direction multiplier: comparators below are written ascending, then
    // flipped for descending.
    const dir = sortDir.value === 'asc' ? 1 : -1;

    if (sort.value === 'order') {
        return dir === 1 ? ordered : [...ordered].reverse();
    }

    if (sort.value === 'added') {
        // createdAt is an ISO string so it sorts chronologically.
        return [...ordered].sort((a, b) => dir * (a.createdAt ?? '').localeCompare(b.createdAt ?? ''));
    }

    if (sort.value === 'count') {
        // Ties keep the manual order — the array is already in it.
        return [...ordered].sort((a, b) => dir * (a.itemCount - b.itemCount));
    }

    // A–Z follows what the card shows: the backlog sorts under its translated
    // title, not the stored one.
    return [...ordered].sort((a, b) => dir * listTitle(a).localeCompare(listTitle(b)));
});

// The ends are the ends of the movable block: with the backlog pinned above
// them, the topmost custom list is already as high as anything can go.
const movable = computed(() => displayed.value.filter((list) => !isPinned(list)));

function isFirst(list: ListOverview): boolean {
    return movable.value[0]?.id === list.id;
}

function isLast(list: ListOverview): boolean {
    return movable.value[movable.value.length - 1]?.id === list.id;
}

const sortOptions = computed(() => [
    { value: 'order', label: trans('lists.list_order') },
    { value: 'added', label: trans('common.date_added') },
    { value: 'count', label: trans('lists.item_count') },
    { value: 'az', label: trans('lists.a_z') },
]);

const createOpen = ref(false);

const createForm = reactive({ title: '', processing: false });

async function create(): Promise<void> {
    createForm.processing = true;

    try {
        // A new list opens at once, on its own page.
        if (await listActions.create(createForm.title.trim())) {
            createForm.title = '';
            createOpen.value = false;
        }
    } finally {
        createForm.processing = false;
    }
}
</script>

<template>
    <AppLayout>
        <Head :title="heading" />

        <!-- As a profile panel the lit tab is the title. Reached from the app
             nav there is no tab bar to carry it, so the heading stands. -->
        <h1 v-if="profile" class="sr-only">{{ trans('common.lists') }}</h1>

        <PageMasthead :class="profile && 'pb-4'">
            <UserHeader v-if="profile" :profile="profile" active="lists" identity="link" />

            <div v-else class="max-w-content mx-auto w-full px-4 pt-8 pb-6 md:px-10">
                <PageHeading :group="groupOf(userPanel('lists'))" :title="heading" :subtitle="trans('lists.create_and_organize_lists_for_the')">
                    <template #icon><ListIcon class="size-6" /></template>
                </PageHeading>
            </div>
        </PageMasthead>

        <PageToolbar variant="band" :count="transChoice('lists.count_list', lists.length)">
            <template #sort>
                <SortControl v-if="!editing && lists.length > 1" v-model:sort="sort" v-model:dir="sortDir" :options="sortOptions" />
            </template>

            <template #actions>
                <!-- The page's own action stands with the controls; the panel
                     has no heading. -->
                <button
                    v-if="isOwner"
                    type="button"
                    class="bg-brand text-on-accent flex h-10 items-center gap-2 rounded-lg px-4 text-base font-bold transition-opacity duration-150 hover:opacity-90"
                    @click="createOpen = true"
                >
                    <Plus class="size-4" :stroke-width="2.5" />
                    {{ trans('lists.new_list') }}
                </button>

                <button
                    v-if="isOwner && reorderable"
                    type="button"
                    :data-toolbar-control="editing ? null : ''"
                    class="flex h-10 items-center gap-2 rounded-lg border px-4 text-base font-semibold transition-colors duration-150"
                    :class="editing ? 'border-brand bg-brand text-on-accent' : 'border-line bg-surface text-ink hover:border-line-strong'"
                    @click="editing = !editing"
                >
                    <GripVertical class="size-4" />
                    {{ editing ? trans('lists.done_editing') : trans('lists.reorder') }}
                </button>
            </template>
        </PageToolbar>

        <div class="max-w-content mx-auto w-full flex-1 px-4 pb-20 md:px-10">
            <!-- Edit mode: reorder only, no navigation (a list is deleted on its own page) -->
            <!-- Reorder animates: FLIP slides the cards into their new spot. -->
            <!-- select-none: a card that refuses to be dragged (the backlog) would
                 otherwise be text-selected, and the browser drags that selection
                 across the whole grid on the next grab. -->
            <TransitionGroup
                v-if="editing"
                tag="div"
                name="reorder"
                class="grid grid-cols-1 gap-5 select-none sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <div
                    v-for="list in displayed"
                    :key="list.id"
                    :draggable="!isPinned(list)"
                    class="border-line-subtle bg-surface overflow-hidden rounded-2xl border ring-2"
                    :class="[isPinned(list) ? 'ring-line-subtle cursor-default' : 'ring-brand/40 cursor-grab', { 'opacity-40': dragId === list.id }]"
                    @dragstart="startDrag($event, list.id)"
                    @dragend="endDrag"
                    @dragover.prevent
                    @drop.prevent="dropOn(list.id)"
                >
                    <ListCover :list="list">
                        <div class="bg-scrim/25 absolute inset-0"></div>
                        <!-- The backlog wears a pin instead of a grip: it is a system
                             list, so it always leads the index. -->
                        <div
                            v-tooltip="isPinned(list) ? trans('lists.system_list_always_first') : undefined"
                            class="text-ink-secondary bg-scrim/70 absolute top-2 left-2 flex size-7 items-center justify-center rounded-md"
                        >
                            <Pin v-if="isPinned(list)" class="size-3.5" />
                            <GripVertical v-else class="size-3.5" />
                        </div>
                        <ReorderControls
                            v-if="!isPinned(list)"
                            :first="isFirst(list)"
                            :last="isLast(list)"
                            @move="(delta) => move(list.id, delta)"
                            @edge="(edge) => moveToEdge(list.id, edge)"
                        />
                    </ListCover>
                    <ListCardFooter :title="listTitle(list)" :item-count="list.itemCount" :is-public="list.isPublic" />
                </div>
            </TransitionGroup>

            <!-- View mode: sorting re-renders without animation, like inside a list -->
            <div v-else class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <Link
                    v-for="list in displayed"
                    :key="list.id"
                    :href="listUrl(owner, list.id)"
                    class="group border-line-subtle bg-surface hover:border-line-strong overflow-hidden rounded-2xl border transition-colors duration-150"
                >
                    <ListCover :list="list">
                        <div class="from-surface-base/85 to-surface-base/15 absolute inset-0 bg-linear-to-t to-60%"></div>
                    </ListCover>
                    <ListCardFooter :title="listTitle(list)" :item-count="list.itemCount" :is-public="list.isPublic" linked />
                </Link>
            </div>
        </div>

        <Dialog :open="createOpen" @update:open="(open: boolean) => (createOpen = open)">
            <DialogContent class="border-line-subtle bg-surface max-w-md rounded-2xl p-5 sm:p-7">
                <DialogTitle class="text-2xl font-semibold">{{ trans('lists.new_list') }}</DialogTitle>
                <form class="flex flex-col gap-4" @submit.prevent="create">
                    <label for="new-list-title" class="sr-only">{{ trans('common.name') }}</label>
                    <Input
                        id="new-list-title"
                        v-model="createForm.title"
                        type="text"
                        maxlength="100"
                        required
                        :placeholder="trans('common.new_list_name')"
                    />
                    <Button type="submit" :disabled="createForm.processing || createForm.title.trim() === ''">
                        {{ trans('common.create_list') }}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
