<script setup lang="ts">
import LiveBadge from '@/components/events/LiveBadge.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import { useNow } from '@/composables/useNow';
import type { CalendarPreview } from '@/lib/calendarPreview';
import { isLive } from '@/lib/liveEvents';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { X } from '@lucide/vue';
import { computed } from 'vue';

// The box a calendar card opens on hover (a tap on a phone): a release and an
// event alike, each described by `lib/calendarPreview.ts`.
const props = defineProps<{ preview: CalendarPreview }>();

const emit = defineEmits<{ close: [] }>();

const now = useNow();
const live = computed<boolean>(() => props.preview.airs !== undefined && isLive(props.preview.airs, now.value));

// A poster stands beside the text; wide art (an event's 16:9) spans the box above it.
const wide = computed<boolean>(() => props.preview.image.shape === 'wide');
</script>

<template>
    <aside
        class="border-line-subtle bg-surface-overlay shadow-float fixed right-3 bottom-3 z-50 w-[min(22rem,calc(100vw-1.5rem))] overflow-hidden rounded-2xl border backdrop-blur-xl md:right-6 md:bottom-6 md:w-[26rem]"
        role="region"
        :aria-label="trans('calendar.preview')"
        @keydown.esc="emit('close')"
    >
        <button
            type="button"
            :aria-label="trans('common.close')"
            class="text-ink-secondary hover:bg-surface-raised hover:text-ink bg-scrim/70 absolute top-2 right-2 z-10 flex size-8 items-center justify-center rounded-full transition-colors md:hidden"
            @click="emit('close')"
        >
            <X class="size-4" />
        </button>

        <Link :href="preview.href" class="flex" :class="wide ? 'flex-col' : 'min-h-38 items-stretch md:min-h-48'">
            <div class="bg-surface-raised relative shrink-0 overflow-hidden" :class="wide ? 'aspect-video w-full' : 'aspect-2/3 w-26 md:w-32'">
                <img
                    v-if="preview.image.src"
                    :src="preview.image.src"
                    :srcset="preview.image.srcset"
                    :sizes="wide ? '(min-width: 768px) 416px, calc(100vw - 1.5rem)' : '(min-width: 768px) 128px, 104px'"
                    :alt="preview.title"
                    :width="wide ? 889 : 342"
                    :height="wide ? 500 : 513"
                    loading="lazy"
                    class="absolute inset-0 h-full w-full object-cover"
                />
                <div
                    v-else
                    class="absolute inset-0"
                    :style="{ background: `linear-gradient(160deg, ${preview.image.fallback}, var(--color-surface))` }"
                ></div>
            </div>

            <div class="flex min-w-0 flex-1 flex-col items-start justify-center gap-1.5 p-3" :class="wide ? '' : 'pr-10'">
                <MediaTypeBadge :type="preview.badge.type" :label="preview.badge.label" :icon="preview.badge.icon" compact-on-mobile />
                <div class="text-ink line-clamp-2 text-base leading-tight font-bold md:text-lg">{{ preview.title }}</div>
                <div v-if="preview.context" class="text-ink-muted line-clamp-2 text-sm leading-snug">{{ preview.context }}</div>
                <div class="text-brand-soft flex flex-wrap items-center gap-2 text-sm font-semibold first-letter:uppercase">
                    <span>{{ preview.date }}</span>
                    <template v-if="preview.time">
                        <span class="bg-line h-2 w-px shrink-0" aria-hidden="true"></span>
                        <span>{{ preview.time }}</span>
                    </template>
                </div>
                <LiveBadge v-if="live" class="text-ink-secondary text-sm" />
                <div v-if="preview.note" class="text-ink-secondary line-clamp-1 text-sm font-semibold">{{ preview.note }}</div>
            </div>
        </Link>
    </aside>
</template>

<style scoped>
.calendar-preview-enter-active,
.calendar-preview-leave-active {
    transition: opacity 150ms ease;
}

.calendar-preview-enter-from,
.calendar-preview-leave-to {
    opacity: 0;
}
</style>
