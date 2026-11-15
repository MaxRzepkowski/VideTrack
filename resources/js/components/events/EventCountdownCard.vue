<script setup lang="ts">
import CountdownTimer from '@/components/countdown/CountdownTimer.vue';
import LiveBadge from '@/components/events/LiveBadge.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import { useNow } from '@/composables/useNow';
import { eventWhen, isLive } from '@/lib/liveEvents';
import type { EventEntry } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { CalendarDays, Radio } from '@lucide/vue';
import { computed } from 'vue';

// An event among the countdowns, in its medium's colour: ticking to its
// start, then marked live until the show is over (a premiere's card says "Out now" at its moment;
// a show is worth watching while it lasts).
const props = defineProps<{ event: EventEntry; mediumType: string }>();

const now = useNow();
const start = computed(() => Date.parse(props.event.startsAt));
const live = computed(() => isLive(props.event, now.value));

const when = computed(() => eventWhen(props.event));
</script>

<template>
    <Link
        :href="route('events.show', { provider: event.provider, ref: event.ref })"
        class="border-line-subtle bg-surface hover:border-brand group flex flex-col overflow-hidden rounded-2xl border transition-colors duration-150"
    >
        <div class="relative aspect-video w-full overflow-hidden">
            <img
                v-if="event.logoPath"
                :src="event.logoPath"
                alt=""
                width="889"
                height="500"
                loading="lazy"
                class="absolute inset-0 h-full w-full object-cover"
            />
            <div v-else class="from-surface-raised to-line absolute inset-0 flex items-center justify-center bg-gradient-to-br">
                <Radio class="text-ink-faint size-12" :stroke-width="1.5" aria-hidden="true" />
            </div>

            <div class="from-scrim/95 via-scrim/50 absolute inset-0 bg-gradient-to-t via-30% to-transparent"></div>

            <div class="absolute top-3 left-3">
                <MediaTypeBadge :type="mediumType" :label="trans('calendar.event')" :icon="Radio" />
            </div>

            <div class="absolute inset-x-0 bottom-0 p-3.5">
                <div class="text-on-photo line-clamp-2 text-lg font-bold">{{ event.name }}</div>
            </div>
        </div>

        <!-- The same height as the timer's, so the card keeps its size when the show starts. -->
        <div v-if="live" class="border-line-subtle text-ink flex min-h-20.5 items-center justify-center border-t text-lg">
            <LiveBadge />
        </div>
        <CountdownTimer v-else :target="start" />

        <div class="border-line-subtle text-ink-muted flex items-center gap-2 border-t px-3.5 py-2.5 text-sm">
            <CalendarDays class="size-4 shrink-0" aria-hidden="true" />
            {{ when }}
        </div>
    </Link>
</template>
