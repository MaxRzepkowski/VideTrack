<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ServiceLogo from '@/components/ServiceLogo.vue';
import SetupNotes from '@/components/settings/SetupNotes.vue';
import ConnectionPanel from '@/components/settings/integrations/ConnectionPanel.vue';
import { Button } from '@/components/ui/button';
import { SETTINGS_CARD_CLASS } from '@/lib/ui';
import type { ConnectionCard, ConnectionSourceData } from '@/types/integrations';
import { Link, useForm } from '@inertiajs/vue3';
import { Plug } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

/**
 * An import source that reads through an account: connecting it, what it
 * holds once connected, and a run on demand beside the automatic one. The
 * page keeps the connection, so a card connected here moves at once.
 */
const props = defineProps<{ source: ConnectionSourceData }>();
const connection = defineModel<ConnectionCard | null>('connection', { required: true });

// The same request the import page sends, after the user's own click.
const run = useForm({ source: props.source.code });

function importNow(): void {
    run.post(route('settings.import.store'), { preserveScroll: true });
}
</script>

<template>
    <div :class="SETTINGS_CARD_CLASS">
        <div class="flex items-start gap-4">
            <ServiceLogo :code="props.source.code" :fallback="Plug" class="h-12 max-w-24" />

            <div class="min-w-0 flex-1">
                <div class="text-ink text-base font-semibold">{{ props.source.label }}</div>
                <p class="text-ink-muted mt-0.5 text-sm">{{ props.source.description }}</p>
            </div>
        </div>

        <div class="mt-4 space-y-4 sm:pl-16">
            <SetupNotes :notes="props.source.notes" />

            <ConnectionPanel v-model:connection="connection" :connect="props.source.connect" />

            <div v-if="connection?.status === 'active'" class="flex flex-wrap items-center gap-4">
                <Button type="button" variant="outline" class="h-10 py-2" :disabled="run.processing" @click="importNow">
                    {{ trans('integrations.import_now') }}
                </Button>
                <p v-if="run.recentlySuccessful" class="text-action-watched text-sm">
                    {{ trans('import.import_queued') }}
                    <Link :href="route('settings.tasks.index')" class="underline">{{ trans('import.track_it_in_tasks') }}</Link>
                </p>
                <InputError :message="run.errors.source" />
            </div>
        </div>
    </div>
</template>
