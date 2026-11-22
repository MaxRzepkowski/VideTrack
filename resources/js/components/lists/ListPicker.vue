<script setup lang="ts">
import type { UserList } from '@/types/media';
import { trans, transChoice } from 'laravel-vue-i18n';
import { Check, Plus } from '@lucide/vue';
import { ref, useId } from 'vue';

/**
 * The user's lists to tick, for a title or a creator, each saying how much it
 * holds, and a new list made with the thing already on it. Ticking writes at
 * once; the dialog holding the picker owns the writes.
 */
defineProps<{
    lists: UserList[];
    /** The lists the thing is on now. */
    on: number[];
    /** The membership is not known yet: nothing can be ticked. */
    waiting?: boolean;
}>();

const emit = defineEmits<{ toggle: [list: UserList]; create: [title: string] }>();

const fieldId = useId();
const newTitle = ref('');

// The count is the server's: a tick reloads the lists, so it always holds what is on them.
const count = (list: UserList): string => transChoice('lists.count_items', list.itemCount, { count: String(list.itemCount) });

function create(): void {
    const title = newTitle.value.trim();

    if (title !== '') {
        emit('create', title);
        newTitle.value = '';
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-col">
        <!-- Five rows or so; the rest scrolls, so many lists never push the dialog past the screen. -->
        <div class="scroll-subtle max-h-80 overflow-y-auto pb-2" :class="{ 'pointer-events-none opacity-60': waiting }">
            <slot name="empty" />
            <button
                v-for="list in lists"
                :key="list.id"
                type="button"
                :aria-pressed="on.includes(list.id)"
                class="hover:bg-surface-overlay focus-visible:bg-surface-overlay flex w-full cursor-pointer items-center gap-3.5 px-5 py-2.5 text-left transition-colors duration-150 focus-visible:outline-none"
                @click="emit('toggle', list)"
            >
                <span
                    class="flex size-5.5 shrink-0 items-center justify-center rounded-md transition-colors duration-150"
                    :class="on.includes(list.id) ? 'bg-action-watchlist' : 'border-line-strong border-2'"
                    aria-hidden="true"
                >
                    <Check v-if="on.includes(list.id)" class="text-on-bright size-4" :stroke-width="3" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="text-ink block truncate text-base font-semibold">{{ list.title }}</span>
                    <span class="text-ink-muted block text-sm">{{ count(list) }}</span>
                </span>
            </button>
        </div>

        <div class="border-line border-t" />

        <form class="flex items-center gap-2 px-5 pt-3 pb-3" @submit.prevent="create">
            <label :for="fieldId" class="sr-only">{{ trans('common.new_list_name') }}</label>
            <input
                :id="fieldId"
                v-model="newTitle"
                type="text"
                maxlength="100"
                :placeholder="trans('common.new_list_name')"
                class="bg-surface-raised text-ink placeholder:text-ink-faint focus:ring-action-watchlist h-12 min-w-0 flex-1 rounded-[10px] px-4 text-base focus:ring-1 focus:outline-none"
            />
            <button
                type="submit"
                :disabled="newTitle.trim() === '' || waiting"
                class="bg-action-watchlist text-on-bright flex h-12 shrink-0 cursor-pointer items-center gap-1.5 rounded-[10px] pr-4 pl-3.5 text-base font-semibold transition-opacity duration-150 hover:opacity-90 disabled:cursor-default disabled:opacity-40"
            >
                <Plus class="size-4.5" :stroke-width="2.5" />
                {{ trans('lists.create_list_button') }}
            </button>
        </form>
    </div>
</template>
