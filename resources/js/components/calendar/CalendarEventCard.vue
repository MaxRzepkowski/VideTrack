<script setup lang="ts">
import LiveBadge from '@/components/events/LiveBadge.vue';
import MetaLine from '@/components/MetaLine.vue';
import { useNow } from '@/composables/useNow';
import { formatTime } from '@/lib/format';
import { isLive } from '@/lib/liveEvents';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import type { CalendarEvent } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Radio } from '@lucide/vue';
import { computed } from 'vue';

// A showcase on the calendar, set like the release cards beside it (see
// CalendarEntryCard): its name, what it is, its hour in the viewer's zone, and
// its glyph in the corner of the text, in the colour of the medium it shows.
// While it is on the air, the hour gives way to the live mark.
const props = defineProps<{ entry: CalendarEvent }>();

const now = useNow();
const live = computed(() => isLive(props.entry.event, now.value));
const color = computed<string>(() => mediaTypeMeta(props.entry.mediumType).color);
</script>

<template>
    <Link
        :href="route('events.show', { provider: entry.event.provider, ref: entry.event.ref })"
        class="border-line-subtle bg-surface hover:border-brand flex flex-col overflow-hidden rounded-lg border transition-colors duration-150"
    >
        <!-- `pr-9` keeps the corner glyph a gutter of its own, as on a release card. -->
        <div class="relative min-w-0 p-2.5 pr-9">
            <Radio class="absolute right-2.5 bottom-2.5 size-4.5" :style="{ color }" aria-hidden="true" />
            <div class="text-ink text-base font-semibold wrap-break-word">{{ entry.event.name }}</div>
            <MetaLine :parts="[trans('calendar.event')]" wrap class="text-ink-muted text-sm" />
            <LiveBadge v-if="live" class="text-ink-secondary text-sm" />
            <div v-else class="text-ink-secondary text-sm font-semibold">{{ formatTime(entry.event.startsAt) }}</div>
        </div>
    </Link>
</template>
