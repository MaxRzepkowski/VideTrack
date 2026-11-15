<script setup lang="ts">
import LiveBadge from '@/components/events/LiveBadge.vue';
import MediaCard from '@/components/media/MediaCard.vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import { useNow } from '@/composables/useNow';
import AppLayout from '@/layouts/AppLayout.vue';
import { jsonFetch } from '@/lib/http';
import { eventWhen, isLive } from '@/lib/liveEvents';
import { safeUrl } from '@/lib/url';
import type { EventPage } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Radio } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// An event's page: when it runs in the viewer's zone, where it streams, what
// it showed (its provider names the titles after it aired), and whether it is
// on the viewer's calendar — every event is, until they take it off.
const props = defineProps<{ page: EventPage }>();

const event = computed(() => props.page.event);
const stream = computed(() => safeUrl(event.value.liveStreamUrl));
const now = useNow();
const live = computed(() => isLive(event.value, now.value));
const when = computed(() => eventWhen(event.value));

// The server's answer is the state: nothing is assumed before it lands.
const hidden = ref(props.page.hidden);
const saving = ref(false);

async function toggleHidden(): Promise<void> {
    saving.value = true;

    try {
        const response = await jsonFetch(route('ui.events.hidden.update', { provider: event.value.provider, ref: event.value.ref }), {
            method: hidden.value ? 'DELETE' : 'PUT',
        });

        if (response.ok) {
            const answer = (await response.json()) as { data: { hidden: boolean } };
            hidden.value = answer.data.hidden;
            // The "live" mark reads the shared events: one taken off goes from it too, now.
            router.reload({ only: ['liveEvents'] });
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head :title="event.name" />

    <AppLayout>
        <div class="max-w-content mx-auto w-full flex-1 space-y-10 px-4 pt-8 pb-20 md:px-10">
            <div class="flex flex-col items-start gap-8 md:flex-row">
                <!-- The event's key art, as an episode wears its still. -->
                <div class="bg-surface shadow-scrim/60 aspect-video w-full shrink-0 overflow-hidden rounded-2xl shadow-2xl md:w-120">
                    <img
                        v-if="event.logoPath"
                        :src="event.logoPath"
                        :alt="event.name"
                        width="889"
                        height="500"
                        loading="lazy"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="from-surface-raised to-line flex h-full w-full items-center justify-center bg-gradient-to-br">
                        <Radio class="text-ink-faint size-14" :stroke-width="1.5" aria-hidden="true" />
                    </div>
                </div>

                <div class="min-w-0 flex-1 pt-1">
                    <LiveBadge v-if="live" class="text-ink mb-1 text-base" />
                    <div v-else class="text-brand-soft text-sm font-bold">{{ trans('calendar.event') }}</div>
                    <h1 class="text-hero m-0 mb-3 leading-tight font-semibold">{{ event.name }}</h1>
                    <p class="text-ink-secondary m-0 mb-1 text-base font-semibold">{{ when }}</p>
                    <p v-if="page.timeZone" class="text-ink-muted m-0 mb-5 text-sm">
                        {{ trans('calendar.event_time_zone', { zone: page.timeZone }) }}
                    </p>

                    <div class="mb-6 flex flex-wrap items-center gap-3">
                        <a
                            v-if="stream"
                            :href="stream"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="bg-brand text-on-accent flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold transition-opacity duration-150 hover:opacity-90"
                        >
                            <Radio class="size-4.5" :stroke-width="2.25" aria-hidden="true" />
                            {{ trans('calendar.watch_stream') }}
                        </a>
                        <!-- Only an event on the calendar can be taken off it; one long past is not there. -->
                        <button
                            v-if="hidden !== null"
                            type="button"
                            :disabled="saving"
                            class="border-line bg-surface-raised text-ink-secondary hover:border-brand hover:text-ink rounded-xl border px-4 py-2.5 text-sm font-semibold transition-colors duration-150 disabled:opacity-60"
                            @click="toggleHidden"
                        >
                            {{ hidden ? trans('calendar.show_event_in_calendar') : trans('calendar.hide_event_from_calendar') }}
                        </button>
                    </div>

                    <p v-if="page.description" class="text-ink-secondary max-w-reading-lg m-0 text-base leading-relaxed">{{ page.description }}</p>
                </div>
            </div>

            <section v-if="page.titles.length">
                <SectionHeading :title="trans('calendar.event_titles')" />
                <MediaGrid>
                    <MediaCard v-for="media in page.titles" :key="`${media.type}-${media.ref}`" :media="media" />
                </MediaGrid>
            </section>
        </div>
    </AppLayout>
</template>
