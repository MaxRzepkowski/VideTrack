<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import ConnectionPanel from '@/components/settings/import/ConnectionPanel.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { jsonFetch } from '@/lib/http';
import type { ImportManifest, ImportSourceDef } from '@/types/generated';
import type { ConnectionCard } from '@/types/integrations';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

// Everything about a source comes from the import registry; a new source
// shows up here without touching this file. Texts arrive as i18n keys.
const props = defineProps<{
    sources: ImportSourceDef[];
    maxFileMegabytes: number;
}>();

const form = useForm<{
    source: string;
    file: File | null;
    credentials: Record<string, string>;
    categories: string[];
    list_ids: string[];
}>({
    source: props.sources[0]?.code ?? '',
    file: null,
    credentials: {},
    categories: [],
    list_ids: [],
});

const source = computed(() => props.sources.find((candidate) => candidate.code === form.source) ?? null);

// What each connection source holds, kept here so a connect finished on the
// card enables the import at once; refreshed whenever the page props change
// (after saving auto-import or disconnecting).
function heldConnections(): Record<string, ConnectionCard | null> {
    return Object.fromEntries(
        props.sources.filter((candidate) => candidate.connect).map((candidate) => [candidate.code, candidate.connect?.connection ?? null]),
    );
}

const connections = ref<Record<string, ConnectionCard | null>>(heldConnections());

watch(
    () => props.sources,
    () => (connections.value = heldConnections()),
);

const dragging = ref(false);
const analyzing = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);

// The analysis of the dropped file: whether it is the chosen source's export
// and, for sources that offer a choice, what it holds. Categories default to
// everything present, unless the source asks the user to pick (a media
// server's log holds the whole household); lists are opt-in (an export can
// hold hundreds, each item a provider round-trip).
const manifest = ref<ImportManifest | null>(null);
// Why the file could not be analyzed at all (too large, unreadable, network),
// as opposed to a readable file that is not this source's export.
const analysisError = ref<string | null>(null);
const selectedCategories = ref<Record<string, boolean>>({});
const selectedLists = ref<Set<string>>(new Set());

const anySelection = computed(() => Object.values(selectedCategories.value).some(Boolean) || selectedLists.value.size > 0);

const canSubmit = computed(() => {
    if (!source.value || form.processing || analyzing.value) {
        return false;
    }

    if (source.value.inputKind === 'connection') {
        return connections.value[source.value.code]?.status === 'active';
    }

    if (source.value.inputKind === 'credentials') {
        // A checkbox is an opt-in; every other field has to be filled.
        return source.value.credentials.every((field) => field.type === 'checkbox' || (form.credentials[field.key] ?? '').trim() !== '');
    }

    if (!form.file || !manifest.value?.valid) {
        return false;
    }

    return manifest.value.supportsSelection ? anySelection.value : true;
});

async function analyze(file: File, code: string): Promise<void> {
    analyzing.value = true;

    try {
        const body = new FormData();
        body.append('file', file);

        const response = await jsonFetch(route('settings.import.analyze', { source: code }), { method: 'POST', body });

        const result = response.ok ? ((await response.json()) as ImportManifest) : null;
        const failure = response.ok ? null : await analysisFailure(response);

        // The user may have switched source or file while this was in flight.
        if (form.source !== code || form.file !== file) {
            return;
        }

        manifest.value = result;
        analysisError.value = failure;
        selectedCategories.value = Object.fromEntries(
            (result?.categories ?? []).map((category) => [category.key, result?.preselected === true && category.count > 0]),
        );
    } catch {
        manifest.value = null;
        analysisError.value = trans('import.the_file_could_not_be_read');
    } finally {
        analyzing.value = false;
    }
}

/** The validation message for the file when the server gave one, otherwise why the upload failed. */
async function analysisFailure(response: Response): Promise<string> {
    if (response.status === 413) {
        return trans('import.the_file_is_too_large');
    }

    if (response.status === 422) {
        const body = (await response.json()) as { errors?: { file?: string[] } };

        return body.errors?.file?.[0] ?? trans('import.the_file_could_not_be_read');
    }

    return trans('import.the_file_could_not_be_read');
}

function resetAnalysis(): void {
    manifest.value = null;
    analysisError.value = null;
    selectedCategories.value = {};
    selectedLists.value = new Set();
}

function onFileChosen(file: File | null): void {
    form.file = file;
    resetAnalysis();

    if (file && source.value?.inputKind === 'file') {
        void analyze(file, source.value.code);
    }
}

// A file dropped for one source says nothing about another: read it again.
watch(
    () => form.source,
    () => onFileChosen(form.file),
);

function pickFile(event: Event): void {
    onFileChosen((event.target as HTMLInputElement).files?.[0] ?? null);
}

function dropFile(event: DragEvent): void {
    dragging.value = false;
    onFileChosen(event.dataTransfer?.files[0] ?? null);
}

function toggleList(id: string, checked: boolean): void {
    const next = new Set(selectedLists.value);
    if (checked) {
        next.add(id);
    } else {
        next.delete(id);
    }
    selectedLists.value = next;
}

// Laravel reports nested fields under dotted keys the form type does not know.
function credentialError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`credentials.${key}`];
}

function displayUrl(url: string): string {
    return url.replace(/^https?:\/\//, '');
}

function submit(): void {
    const categories = manifest.value?.categories ?? [];

    form.categories = categories.filter((category) => selectedCategories.value[category.key] && category.count > 0).map((category) => category.key);
    form.list_ids = [...selectedLists.value];

    form.transform((data) => ({
        ...data,
        // Only the chosen source's input travels.
        file: source.value?.inputKind === 'file' ? data.file : null,
        credentials: source.value?.inputKind === 'credentials' ? data.credentials : {},
    })).post(route('settings.import.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('file', 'credentials', 'categories', 'list_ids');
            resetAnalysis();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="trans('common.import_data')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="trans('common.import_data')" :description="trans('import.restore_a_videtrack_backup_or_bring')" />

                <form class="space-y-6" @submit.prevent="submit">
                    <div class="space-y-3">
                        <label
                            v-for="candidate in sources"
                            :key="candidate.code"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border px-5 py-4"
                            :class="form.source === candidate.code ? 'border-brand bg-surface' : 'border-line-subtle bg-surface'"
                        >
                            <input v-model="form.source" type="radio" name="source" :value="candidate.code" class="accent-brand mt-1" />
                            <span class="min-w-0">
                                <span class="text-ink block text-base font-semibold">{{ candidate.label }}</span>
                                <span class="text-ink-muted mt-0.5 block text-sm">{{ candidate.description }}</span>
                            </span>
                        </label>
                        <InputError :message="form.errors.source" />
                        <div v-if="source?.notes.length" class="text-ink-muted space-y-1 text-sm">
                            <p v-for="(note, index) in source.notes" :key="index">
                                {{ note.text }}
                                <a
                                    v-if="note.url"
                                    :href="note.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-brand-soft font-medium hover:underline"
                                    >{{ displayUrl(note.url) }}</a
                                >
                            </p>
                        </div>
                    </div>

                    <div v-if="source?.inputKind === 'credentials'" class="space-y-4">
                        <template v-for="field in source.credentials" :key="field.key">
                            <label v-if="field.type === 'checkbox'" class="flex cursor-pointer items-start gap-3">
                                <Checkbox
                                    :checked="form.credentials[field.key] === '1'"
                                    class="mt-0.5"
                                    @update:checked="(v: boolean) => (form.credentials[field.key] = v ? '1' : '')"
                                />
                                <span class="min-w-0">
                                    <span class="text-ink block text-sm">{{ field.label }}</span>
                                    <span v-if="field.help" class="text-ink-muted mt-0.5 block text-sm">{{ field.help }}</span>
                                </span>
                            </label>
                            <div v-else class="grid gap-2">
                                <Label :for="`credential-${field.key}`">{{ field.label }}</Label>
                                <Input :id="`credential-${field.key}`" v-model="form.credentials[field.key]" :type="field.type" autocomplete="off" />
                                <p v-if="field.help" class="text-ink-muted text-sm">{{ field.help }}</p>
                                <InputError :message="credentialError(field.key)" />
                            </div>
                        </template>
                    </div>

                    <ConnectionPanel
                        v-else-if="source?.inputKind === 'connection' && source.connect"
                        :key="source.code"
                        :connection="connections[source.code] ?? null"
                        :connect="source.connect"
                        @update:connection="connections[source.code] = $event"
                    />

                    <div
                        v-else-if="source?.inputKind === 'file'"
                        class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-5 py-10 text-center"
                        :class="dragging ? 'border-brand bg-brand/5' : 'border-line-subtle bg-surface'"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="dropFile"
                    >
                        <p v-if="form.file" class="text-ink text-sm font-medium">{{ form.file.name }}</p>
                        <p v-else class="text-ink-muted text-sm">{{ trans('import.drag_and_drop_your_export_file') }}</p>
                        <button type="button" class="text-brand-soft text-sm font-medium hover:underline" @click="fileInput?.click()">
                            {{ trans('import.browse_files') }}
                        </button>
                        <input ref="fileInput" type="file" class="hidden" :accept="source.accept ?? undefined" @change="pickFile" />
                        <p class="text-ink-muted text-sm">{{ trans('import.maximum_file_size', { size: String(props.maxFileMegabytes) }) }}</p>
                        <InputError :message="form.errors.file" />
                    </div>

                    <template v-if="source?.inputKind === 'file' && form.file">
                        <p v-if="analyzing" class="text-ink-muted text-sm">{{ trans('import.reading_the_export') }}</p>

                        <p v-else-if="analysisError" class="text-danger text-sm">{{ analysisError }}</p>

                        <p v-else-if="manifest && !manifest.valid" class="text-danger text-sm">{{ manifest.error }}</p>

                        <!-- Pick what to import once the export has been read. -->
                        <div v-else-if="manifest?.supportsSelection" class="space-y-6">
                            <div class="space-y-3">
                                <p class="text-ink text-sm font-semibold">{{ trans('import.what_to_import') }}</p>
                                <label
                                    v-for="category in manifest.categories"
                                    :key="category.key"
                                    class="flex items-center gap-3"
                                    :class="category.count === 0 ? 'opacity-50' : 'cursor-pointer'"
                                >
                                    <Checkbox v-model:checked="selectedCategories[category.key]" :disabled="category.count === 0" />
                                    <span class="text-ink text-sm">{{ category.label }}</span>
                                    <span class="text-ink-muted text-sm">
                                        {{ transChoice('common.count_item', category.count, { count: String(category.count) }) }}
                                    </span>
                                </label>
                            </div>

                            <div v-if="manifest.lists.length" class="space-y-3">
                                <p class="text-ink text-sm font-semibold">{{ trans('import.custom_lists') }}</p>
                                <p class="text-ink-muted text-sm">{{ trans('import.pick_the_lists_to_import_none') }}</p>
                                <div class="border-line-subtle bg-surface max-h-72 space-y-2 overflow-y-auto rounded-xl border p-3">
                                    <label v-for="list in manifest.lists" :key="list.id" class="flex cursor-pointer items-center gap-3">
                                        <Checkbox :checked="selectedLists.has(list.id)" @update:checked="(v: boolean) => toggleList(list.id, v)" />
                                        <span class="text-ink min-w-0 flex-1 truncate text-sm">{{ list.name }}</span>
                                        <span class="text-ink-muted shrink-0 text-sm">
                                            {{ transChoice('common.count_item', list.itemCount, { count: String(list.itemCount) }) }}
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <p class="text-ink-muted text-sm">{{ trans('import.nothing_you_already_have_is_overwritten') }}</p>
                        </div>
                    </template>

                    <div class="flex items-center gap-4">
                        <Button type="submit" class="h-10 py-2" :disabled="!canSubmit">{{ trans('import.start_import') }}</Button>
                        <p v-if="form.recentlySuccessful" class="text-action-watched text-sm">
                            {{ trans('import.import_queued') }}
                            <Link :href="route('settings.tasks.index')" class="underline">{{ trans('import.track_it_in_tasks') }}</Link>
                        </p>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
