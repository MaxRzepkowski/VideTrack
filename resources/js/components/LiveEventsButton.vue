<script setup lang="ts">
import LiveBadge from '@/components/events/LiveBadge.vue';
import { useNow } from '@/composables/useNow';
import { dismissedEvents, dismissEvent, eventKey, liveAt } from '@/lib/liveEvents';
import { safeUrl } from '@/lib/url';
import type { SharedData } from '@/types';
import type { EventEntry } from '@/types/generated';
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Play, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// A small "Live" mark in the bottom-left corner of every page while a
// showcase on the viewer's calendar is on the air. Its name opens its page,
// the play button its stream. The page brought the events starting within
// hours; the shared clock switches the mark on at the start and off at the
// end, without a word to the server.
const page = usePage<SharedData>();
const now = useNow();

const known = computed(() => page.props.liveEvents ?? []);
const dismissed = ref<Set<string>>(typeof window === 'undefined' ? new Set() : dismissedEvents());

const live = computed(() => liveAt(known.value, now.value).filter((event) => !dismissed.value.has(eventKey(event))));

const open = ref(false);

function dismiss(event: EventEntry): void {
    dismissEvent(event, known.value);
    dismissed.value = dismissedEvents();
}

const pageHref = (event: EventEntry) => route('events.show', { provider: event.provider, ref: event.ref });

const ICON_BUTTON = 'text-ink-muted hover:text-ink flex size-7 shrink-0 items-center justify-center rounded-full transition-colors duration-150';
</script>

<template>
    <!-- Above the phone's bottom bars (a title's actions, a settings save bar), clear of them. -->
    <div v-if="live.length" class="fixed bottom-24 left-4 z-40 max-w-[calc(100vw-2rem)] md:bottom-5 md:left-5">
        <!-- One event: its page, its stream, and a way to put it away. -->
        <div
            v-if="live.length === 1"
            class="border-line bg-surface-overlay shadow-scrim/50 flex items-center gap-0.5 rounded-full border py-0.5 pr-0.5 pl-3 shadow-xl"
        >
            <Link
                :href="pageHref(live[0]!)"
                class="text-ink hover:text-brand-soft flex min-w-0 items-center gap-2 py-1 pr-1.5 text-sm transition-colors duration-150"
            >
                <LiveBadge class="shrink-0" />
                <span class="max-w-56 truncate font-semibold">{{ live[0]!.name }}</span>
            </Link>
            <a
                v-if="safeUrl(live[0]!.liveStreamUrl)"
                v-tooltip="trans('calendar.watch_stream')"
                :href="safeUrl(live[0]!.liveStreamUrl)!"
                target="_blank"
                rel="noopener noreferrer"
                :aria-label="trans('calendar.watch_stream')"
                :class="ICON_BUTTON"
            >
                <Play class="size-4" :stroke-width="2.25" aria-hidden="true" />
            </a>
            <button type="button" :aria-label="trans('calendar.hide_live_event')" :class="ICON_BUTTON" @click="dismiss(live[0]!)">
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>

        <!-- Several at once: one mark, the list behind it. -->
        <div v-else class="relative">
            <div
                v-if="open"
                class="border-line-subtle bg-surface-overlay shadow-scrim/50 absolute bottom-full left-0 mb-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border p-1.5 shadow-xl"
            >
                <div v-for="event in live" :key="eventKey(event)" class="flex items-center gap-0.5 rounded-xl p-0.5">
                    <Link
                        :href="pageHref(event)"
                        class="text-ink hover:text-brand-soft min-w-0 flex-1 truncate rounded-lg px-2 py-1.5 text-sm font-semibold transition-colors duration-150"
                    >
                        {{ event.name }}
                    </Link>
                    <a
                        v-if="safeUrl(event.liveStreamUrl)"
                        v-tooltip="trans('calendar.watch_stream')"
                        :href="safeUrl(event.liveStreamUrl)!"
                        target="_blank"
                        rel="noopener noreferrer"
                        :aria-label="trans('calendar.watch_stream')"
                        :class="ICON_BUTTON"
                    >
                        <Play class="size-4" :stroke-width="2.25" aria-hidden="true" />
                    </a>
                    <button type="button" :aria-label="trans('calendar.hide_live_event')" :class="ICON_BUTTON" @click="dismiss(event)">
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </div>
            </div>
            <button
                type="button"
                :aria-expanded="open"
                class="border-line bg-surface-overlay text-ink shadow-scrim/50 hover:text-brand-soft flex items-center rounded-full border px-3 py-1.5 text-sm shadow-xl transition-colors duration-150"
                @click="open = !open"
            >
                <LiveBadge :label="trans('calendar.live_now_count', { count: String(live.length) })" />
            </button>
        </div>
    </div>
</template>
