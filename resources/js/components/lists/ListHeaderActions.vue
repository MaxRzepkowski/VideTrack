<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleSwitch } from '@/components/ui/toggle-switch';
import { confirm } from '@/composables/useConfirm';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import { useCreatorActions } from '@/composables/useCreatorActions';
import { useListActions } from '@/composables/useListActions';
import { listCsv, listFileName, listText } from '@/lib/listText';
import { nextFollowMode } from '@/lib/tracking';
import type { ListDetailsData, ListItemEntry } from '@/types/media';
import { trans } from 'laravel-vue-i18n';
import { Bell, BellOff, BellRing, Check, FileDown, FileSpreadsheet, MoreVertical, Pencil, Share2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

// The list page's header actions: edit mode, a collection's bell, sharing,
// and the menu (details, downloads, duplicate, visibility, delete) with the
// details dialog it opens. A collection's refresh lives in its "i" window.
const props = defineProps<{
    list: ListDetailsData;
    /** How many positions the list holds; edit mode needs some, deleting names them. */
    itemCount: number;
    /** What the list shows now, filtered and sorted, every page of it: what the downloads hold. */
    positions: ListItemEntry[];
}>();

const editing = defineModel<boolean>('editing', { required: true });

const lists = useListActions();

const title = computed(() => props.list.displayTitle);

// Only a provider collection carries a bell: a plain user list has no
// upstream releases to be told about.
const collectionSource = computed(() => props.list.collection);

// The bell: the only explicit "this series interests me". A click walks it
// by the title bell's own rule (`nextFollowMode`); nothing but the user rings
// a collection's bell, so a follow goes straight back to off.
const followBusy = ref(false);

const followState = computed(() => {
    const next = nextFollowMode({ follow: props.list.followMode, followSource: null, followVia: null });

    if (props.list.followMode === 'follow') {
        return { icon: BellRing, label: trans('common.following_active'), active: true, next };
    }

    if (props.list.followMode === 'mute') {
        return { icon: BellOff, label: trans('common.muted'), active: false, next };
    }

    return { icon: Bell, label: trans('common.follow_releases'), active: false, next };
});

const { followCollection } = useCreatorActions();

async function toggleFollow(): Promise<void> {
    const source = collectionSource.value;

    if (!source) {
        return;
    }

    followBusy.value = true;

    try {
        // The bell's state rides in the list prop.
        await followCollection(source.provider, source.ref, followState.value.next, ['list']);
    } finally {
        followBusy.value = false;
    }
}

// ---- List actions ----

// A file and not the clipboard: a browser refuses the clipboard to a page
// served over plain http, which is how a home server often is.
function download(content: string, type: string, extension: 'txt' | 'csv'): void {
    const url = URL.createObjectURL(new Blob([content], { type }));
    const link = document.createElement('a');

    link.href = url;
    link.download = listFileName(title.value, extension, trans('lists.file_name_fallback'));
    link.click();
    URL.revokeObjectURL(url);
}

function downloadText(): void {
    download(listText(props.positions.map((position) => position.media)), 'text/plain;charset=utf-8', 'txt');
}

function downloadCsv(): void {
    download(listCsv(props.positions), 'text/csv;charset=utf-8', 'csv');
}

const { copied, copy } = useCopyToClipboard(trans('common.could_not_copy_the_link'));

async function share(): Promise<void> {
    await copy(window.location.href);
}

function toggleVisibility(): void {
    void lists.update(props.list.id, { title: props.list.title, description: props.list.description, is_public: !props.list.isPublic });
}

function duplicate(): void {
    void lists.duplicate(props.list.id);
}

const editOpen = ref(false);
const saving = ref(false);

const editForm = reactive({
    title: props.list.title ?? '',
    description: props.list.description ?? '',
    is_public: props.list.isPublic,
});

function openEdit(): void {
    editForm.title = props.list.title ?? '';
    editForm.description = props.list.description ?? '';
    editForm.is_public = props.list.isPublic;
    editOpen.value = true;
}

async function saveEdit(): Promise<void> {
    saving.value = true;

    try {
        if (await lists.update(props.list.id, { ...editForm, description: editForm.description === '' ? null : editForm.description })) {
            editOpen.value = false;
        }
    } finally {
        saving.value = false;
    }
}

async function destroy(): Promise<void> {
    const confirmed = await confirm({
        title: trans('lists.delete_list'),
        message: trans('lists.this_will_permanently_delete_title_and', {
            title: title.value,
            count: String(props.itemCount),
        }),
        confirmLabel: trans('lists.delete'),
    });

    if (confirmed) {
        void lists.destroy(props.list.id);
    }
}
</script>

<template>
    <div class="contents">
        <button
            v-if="list.isOwner && itemCount > 0"
            type="button"
            class="flex h-10 items-center gap-2 rounded-xl border px-4 text-base font-semibold transition-colors duration-150"
            :class="editing ? 'border-brand bg-brand text-on-accent' : 'border-line bg-surface text-ink hover:border-line-strong'"
            @click="editing = !editing"
        >
            <Pencil class="size-4" />
            {{ editing ? trans('lists.done_editing') : trans('lists.edit_list') }}
        </button>
        <!-- Collections only: follow the series to hear about new parts. -->
        <button
            v-if="collectionSource"
            type="button"
            :disabled="followBusy"
            :aria-pressed="followState.active"
            class="flex h-10 items-center gap-2 rounded-xl border px-4 text-base font-semibold transition-colors duration-150 disabled:opacity-50"
            :class="followState.active ? 'border-brand bg-brand text-on-accent' : 'border-line bg-surface text-ink hover:border-line-strong'"
            @click="toggleFollow"
        >
            <component :is="followState.icon" class="size-4" :stroke-width="2.2" />
            {{ followState.label }}
        </button>
        <button
            type="button"
            class="border-line bg-surface text-ink hover:border-line-strong flex h-10 items-center gap-2 rounded-xl border px-4 text-base font-semibold transition-colors duration-150"
            @click="share"
        >
            <Check v-if="copied" class="text-action-watched size-4" />
            <Share2 v-else class="size-4" />
            {{ copied ? trans('lists.link_copied') : trans('lists.share') }}
        </button>
        <DropdownMenu>
            <DropdownMenuTrigger
                v-tooltip="trans('common.more_options')"
                class="border-line bg-surface hover:border-line-strong flex size-10 items-center justify-center rounded-xl border transition-colors duration-150"
            >
                <MoreVertical class="size-4" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="border-line-subtle bg-surface w-56 rounded-xl">
                <DropdownMenuItem v-if="list.isOwner && !list.systemKey" @click="openEdit">
                    {{ trans('lists.edit_details') }}
                </DropdownMenuItem>
                <DropdownMenuItem v-if="positions.length > 0" @click="downloadText">
                    <FileDown class="size-4" />
                    {{ trans('lists.download_as_txt') }}
                </DropdownMenuItem>
                <DropdownMenuItem v-if="positions.length > 0" @click="downloadCsv">
                    <FileSpreadsheet class="size-4" />
                    {{ trans('lists.download_as_csv') }}
                </DropdownMenuItem>
                <DropdownMenuItem @click="duplicate">{{ trans('lists.duplicate_list') }}</DropdownMenuItem>
                <DropdownMenuItem v-if="list.isOwner" @click="toggleVisibility">
                    {{ list.isPublic ? trans('lists.make_private') : trans('lists.make_public') }}
                </DropdownMenuItem>
                <template v-if="list.canDelete">
                    <DropdownMenuSeparator />
                    <DropdownMenuItem class="text-danger" @click="destroy">
                        {{ trans('lists.delete_list') }}
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>

        <!-- Edit details -->
        <Dialog :open="editOpen" @update:open="(open: boolean) => (editOpen = open)">
            <DialogContent class="border-line-subtle bg-surface max-w-md rounded-2xl p-5 sm:p-7">
                <DialogTitle class="text-2xl font-semibold">{{ trans('lists.edit_details') }}</DialogTitle>
                <form class="flex flex-col gap-5" @submit.prevent="saveEdit">
                    <div class="flex flex-col gap-2">
                        <Label for="list-title">{{ trans('common.title') }}</Label>
                        <Input id="list-title" v-model="editForm.title" type="text" maxlength="100" required />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="list-description">{{ trans('lists.description') }}</Label>
                        <textarea
                            id="list-description"
                            v-model="editForm.description"
                            rows="3"
                            maxlength="1000"
                            class="border-line bg-surface-raised text-ink focus:border-brand resize-none rounded-xl border px-4 py-3 text-base focus:outline-none"
                        ></textarea>
                    </div>
                    <label class="flex items-center justify-between gap-4">
                        <span class="text-ink text-base font-semibold">{{ trans('lists.visible_to_other_users') }}</span>
                        <ToggleSwitch v-model="editForm.is_public" />
                    </label>
                    <Button type="submit" :disabled="saving || editForm.title.trim() === ''">
                        {{ trans('common.save') }}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
