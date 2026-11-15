<script setup lang="ts">
import LiveBadge from '@/components/events/LiveBadge.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import { useNow } from '@/composables/useNow';
import { formatTimestampDate, withCount } from '@/lib/format';
import { isLive } from '@/lib/liveEvents';
import type { EventEntry } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { Radio } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// The events a title was shown at, the latest first: each its key art, its
// name and its day, opening its page. A row of four, the rest behind the toggle.
const props = defineProps<{ title: string; events: EventEntry[] }>();

const LIMIT = 4;
const expanded = ref(false);
const visible = computed(() => (expanded.value ? props.events : props.events.slice(0, LIMIT)));

const now = useNow();
</script>

<template>
    <section v-if="events.length" class="pt-16">
        <SectionHeading :title="withCount(title, events.length)" />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Link
                v-for="event in visible"
                :key="`${event.provider}-${event.ref}`"
                :href="route('events.show', { provider: event.provider, ref: event.ref })"
                class="border-line-subtle bg-surface hover:border-brand relative block aspect-video overflow-hidden rounded-2xl border transition-colors duration-150"
            >
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
                    <Radio class="text-ink-faint size-10" :stroke-width="1.5" aria-hidden="true" />
                </div>
                <div class="from-scrim/95 via-scrim/50 absolute inset-0 bg-gradient-to-t via-40% to-transparent"></div>

                <div class="absolute inset-x-0 bottom-0 p-3">
                    <div class="text-on-photo line-clamp-2 text-base font-bold">{{ event.name }}</div>
                    <LiveBadge v-if="isLive(event, now)" class="text-on-photo text-sm" />
                    <div v-else class="text-on-photo/80 text-sm font-semibold">{{ formatTimestampDate(event.startsAt) }}</div>
                </div>
            </Link>
        </div>
        <ShowMoreToggle v-if="events.length > LIMIT" v-model="expanded" :hidden-count="events.length - LIMIT" />
    </section>
</template>
