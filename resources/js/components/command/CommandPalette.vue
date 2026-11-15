<script setup lang="ts">
import CommandMediaRow from '@/components/command/CommandMediaRow.vue';
import { Command, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { allCommands, matchCommands, type Command as PaletteCommand, type PaletteItem } from '@/lib/commands';
import { jsonFetch } from '@/lib/http';
import { mediaUrl } from '@/lib/urls';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

// The endpoint reads the local library, not a provider, so this only has to
// outrun typing — not a network round trip.
const DEBOUNCE_MS = 120;

const { open, close } = useCommandPalette();

const term = ref('');
const results = ref<PaletteItem[]>([]);
const recent = ref<PaletteItem[]>([]);
const loading = ref(false);
const highlighted = ref<string | undefined>(undefined);

// Library rows land one debounce after the keystroke that asked for them, so
// they appear ABOVE a highlight that radix will happily leave where it was —
// which would point Enter at the bottom escape row while the real hit sits at
// the top. Clearing it makes radix highlight the first row again. Guarded on
// there being rows: with none, the row count does not change, radix's own reset
// never runs and the palette would be left with nothing highlighted at all.
watch(results, (rows) => {
    if (rows.length > 0) {
        highlighted.value = undefined;
    }
});

// Only the newest request may write the rows or clear the spinner: a keystroke
// aborts its predecessor, and an aborted one must not undo the state of the
// request that replaced it.
let controller: AbortController | null = null;
let latest = 0;

function fetchPalette(query: string): void {
    controller?.abort();
    controller = new AbortController();

    const token = ++latest;

    loading.value = true;

    void jsonFetch(route('palette', query === '' ? {} : { q: query }), { signal: controller.signal })
        .then((response) => (response.ok ? (response.json() as Promise<{ recent: PaletteItem[]; results: PaletteItem[] }>) : null))
        .then((payload) => {
            if (payload && token === latest) {
                recent.value = payload.recent;
                results.value = payload.results;
            }
        })
        // An abort is the expected end of every keystroke but the last, and a
        // dropped connection still leaves the commands, which need no request.
        .catch(() => undefined)
        .finally(() => {
            if (token === latest) {
                loading.value = false;
            }
        });
}

// The box is read when the wait is over, not when the keystroke asked: a box
// emptied (or a palette closed) meanwhile has nothing left to look up, and a
// stale query landing then would sit under whatever is typed next.
const search = useDebounceFn(() => {
    const query = term.value.trim();

    if (query !== '') {
        fetchPalette(query);
    }
}, DEBOUNCE_MS);

watch(term, (value) => {
    const query = value.trim();

    if (query === '') {
        // Back to an empty box: the history is already in hand, nothing to ask.
        controller?.abort();
        latest++;
        loading.value = false;
        results.value = [];

        return;
    }

    void search();
});

watch(open, (isOpen) => {
    if (!isOpen) {
        // Cleared on close, not on open: resetting the term schedules the watch
        // above, which would abort the very fetch that opening starts.
        term.value = '';
        results.value = [];

        return;
    }

    fetchPalette('');
});

const commands = computed(() => matchCommands(allCommands(), term.value));

// Two headings: navigation, and every other group under "Actions" until the
// palette learns a heading for it.
const navigation = computed(() => commands.value.filter((command) => command.group === 'navigation'));
const actions = computed(() => commands.value.filter((command) => command.group !== 'navigation'));

const searching = computed(() => term.value.trim() !== '');

function go(item: PaletteItem): void {
    close();
    router.visit(mediaUrl(item));
}

function openSearchPage(): void {
    const query = term.value.trim();

    close();
    router.visit(route('search', { q: query }));
}

function run(command: PaletteCommand): void {
    close();
    command.perform();
}
</script>

<template>
    <Dialog :open="open" @update:open="(isOpen: boolean) => !isOpen && close()">
        <DialogContent hide-close class="border-line-subtle bg-surface top-[12dvh] max-w-2xl translate-y-0 gap-0 overflow-y-hidden rounded-2xl p-0">
            <DialogTitle class="sr-only">{{ trans('palette.command_palette') }}</DialogTitle>

            <Command v-model:search-term="term" v-model:selected-value="highlighted">
                <CommandInput :placeholder="trans('palette.search_or_jump_to')">
                    <template #icon>
                        <Search class="text-ink-faint size-5 shrink-0" />
                    </template>
                    <template #trailing>
                        <LoaderCircle v-if="loading" class="text-ink-faint size-5 shrink-0 animate-spin" />
                    </template>
                </CommandInput>

                <!--
                    Row order IS the answer to Enter: radix highlights the first
                    row, so whatever sits at the top is what typing commits to.
                    One DOM order serves both states, because each group states
                    when it applies:

                      nothing typed → Recently viewed, then every command
                      typing       → matched commands, then library hits, then
                                     the way out to the providers

                    Typing a page name therefore opens that page ("calendar" →
                    Calendar), which is the whole point of the shortcut. With
                    nothing typed the commands do not lead, because then they are
                    not matches — they are simply all of them, and the history is
                    what the palette is being opened for.
                -->
                <CommandList>
                    <CommandGroup v-if="!searching && recent.length" :heading="trans('common.recently_viewed')">
                        <CommandItem v-for="item in recent" :key="item.type + item.ref" :value="`recent:${item.type}:${item.ref}`" @select="go(item)">
                            <CommandMediaRow :item="item" />
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup v-if="navigation.length" :heading="trans('palette.go_to')">
                        <CommandItem v-for="command in navigation" :key="command.id" :value="command.id" @select="run(command)">
                            <component :is="command.icon" v-if="command.icon" class="text-ink-muted size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{ command.label() }}</span>
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup v-if="actions.length" :heading="trans('palette.actions')">
                        <CommandItem v-for="command in actions" :key="command.id" :value="command.id" @select="run(command)">
                            <component :is="command.icon" v-if="command.icon" class="text-ink-muted size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{ command.label() }}</span>
                        </CommandItem>
                    </CommandGroup>

                    <CommandGroup v-if="searching && results.length" :heading="trans('palette.search_results')">
                        <CommandItem v-for="item in results" :key="item.type + item.ref" :value="`media:${item.type}:${item.ref}`" @select="go(item)">
                            <CommandMediaRow :item="item" />
                        </CommandItem>
                    </CommandGroup>

                    <!-- The palette only knows what is already tracked, so the way
                         out to the providers is the last row — present even when
                         nothing local matched, never in anyone's way. -->
                    <div v-if="searching" class="border-line-subtle mt-2 border-t pt-2">
                        <CommandItem value="search:providers" @select="openSearchPage()">
                            <Search class="text-ink-muted size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{ trans('palette.search_providers_for_query', { query: term.trim() }) }}</span>
                        </CommandItem>
                    </div>
                </CommandList>

                <div class="border-line-subtle text-ink-muted flex flex-wrap items-center gap-4 border-t px-4 py-3 text-sm">
                    <span class="flex items-center gap-2">
                        <kbd class="border-line bg-surface-raised rounded border px-1.5 py-0.5 text-sm">↑</kbd>
                        <kbd class="border-line bg-surface-raised rounded border px-1.5 py-0.5 text-sm">↓</kbd>
                        {{ trans('palette.navigate') }}
                    </span>
                    <span class="flex items-center gap-2">
                        <kbd class="border-line bg-surface-raised rounded border px-1.5 py-0.5 text-sm">↵</kbd>
                        {{ trans('palette.open') }}
                    </span>
                    <span class="flex items-center gap-2">
                        <kbd class="border-line bg-surface-raised rounded border px-1.5 py-0.5 text-sm">Esc</kbd>
                        {{ trans('common.close') }}
                    </span>
                </div>
            </Command>
        </DialogContent>
    </Dialog>
</template>
