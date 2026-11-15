<script setup lang="ts">
import WebhookEndpointPanel from '@/components/settings/integrations/WebhookEndpointPanel.vue';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import { glyphFor } from '@/lib/serviceGlyphs';
import type { ScrobbleSourceData } from '@/types/integrations';
import { router } from '@inertiajs/vue3';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ChevronDown, Server } from '@lucide/vue';

/**
 * One connectable source, rendered from what the scrobble registry says
 * about it. Nothing here knows the word "Jellyfin": a source added later
 * gets this card without a line of UI being written.
 */
const props = defineProps<{ source: ScrobbleSourceData }>();

const { copied, copy } = useCopyToClipboard();

function connect(): void {
    router.post(route('settings.integrations.store', { source: props.source.code }), {}, { preserveScroll: true });
}
</script>

<template>
    <div class="border-line-subtle bg-surface rounded-xl border px-5 py-4">
        <div class="flex items-start gap-3">
            <svg v-if="glyphFor(props.source.code)" class="text-ink-muted mt-0.5 size-6 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path :d="glyphFor(props.source.code) ?? ''" fill="currentColor" />
            </svg>
            <Server v-else class="text-ink-muted mt-0.5 size-6 shrink-0" aria-hidden="true" />

            <div class="min-w-0 flex-1">
                <div class="text-ink text-base font-semibold">{{ props.source.name }}</div>
                <p class="text-ink-muted mt-0.5 text-sm">{{ props.source.description }}</p>
                <p v-if="props.source.notice" class="text-status-onhold mt-1 text-sm">{{ props.source.notice }}</p>
            </div>
        </div>

        <div v-if="props.source.endpoints.length > 0" class="divide-line-subtle mt-4 divide-y">
            <div v-for="endpoint in props.source.endpoints" :key="endpoint.id" class="py-4 first:pt-0 last:pb-0">
                <WebhookEndpointPanel :source="props.source" :endpoint="endpoint" />
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
            <button
                v-if="props.source.endpoints.length === 0"
                type="button"
                class="bg-brand hover:bg-brand/90 text-on-accent inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold"
                @click="connect"
            >
                {{ trans('common.connect') }}
            </button>
            <button
                v-else-if="props.source.endpoints.length < props.source.endpointLimit"
                type="button"
                class="text-brand-soft text-sm font-medium hover:underline"
                @click="connect"
            >
                {{ trans('integrations.add_another_address') }}
            </button>
            <p v-else class="text-ink-muted text-sm">
                {{
                    transChoice('integrations.up_to_count_addresses_for_one_server', props.source.endpointLimit, {
                        count: String(props.source.endpointLimit),
                    })
                }}
            </p>

            <Collapsible v-if="props.source.instructions.length > 0" v-slot="{ open }" class="w-full">
                <CollapsibleTrigger class="text-brand-soft flex items-center gap-1 text-sm font-medium hover:underline">
                    {{ trans('integrations.how_to_set_it_up') }}
                    <ChevronDown class="size-4 transition-transform" :class="{ 'rotate-180': open }" aria-hidden="true" />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ol class="mt-3 space-y-3">
                        <li v-for="(step, index) in props.source.instructions" :key="index" class="text-sm">
                            <div class="text-ink font-medium">{{ index + 1 }}. {{ step.title }}</div>
                            <p class="text-ink-muted mt-0.5">{{ step.body }}</p>
                            <div v-if="step.code" class="mt-2">
                                <pre
                                    class="border-line-subtle bg-surface-base text-ink-secondary overflow-x-auto rounded-md border px-3 py-2 font-mono text-sm"
                                ><code>{{ step.code }}</code></pre>
                                <button type="button" class="text-brand-soft mt-1 text-sm font-medium hover:underline" @click="copy(step.code ?? '')">
                                    {{ copied ? trans('common.copied') : trans('common.copy') }}
                                </button>
                            </div>
                        </li>
                    </ol>
                </CollapsibleContent>
            </Collapsible>
        </div>
    </div>
</template>
