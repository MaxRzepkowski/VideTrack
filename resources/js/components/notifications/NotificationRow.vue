<script setup lang="ts">
import MetaLine from '@/components/MetaLine.vue';
import { mediaImage } from '@/lib/images';
import { eventLook, notificationAge } from '@/lib/notifications';
import type { NotificationEntry } from '@/types/notifications';
import { trans } from 'laravel-vue-i18n';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';

/**
 * One notification as a line of the bell: the title it is about, what
 * happened (glyph and words in the event's colour), then the value it moved,
 * the old one struck, or the facts it adds. A real link, so a middle click
 * opens it in a new tab; whoever lists it decides what a plain click does.
 */
const props = defineProps<{ item: NotificationEntry }>();

defineEmits<{ open: [event: MouseEvent] }>();

const look = computed(() => eventLook(props.item.event));
const poster = computed(() => mediaImage(props.item.imagePath, 'w342'));
</script>

<template>
    <!-- The title link opens the notification and its ::after stretches over
         the whole row, so the row clicks as one; an action of the row (delete)
         comes through the `actions` slot, beside the link, never inside it. It
         takes the place of the age and the unread mark while the row is pointed
         at or holds the focus; a touch screen, with nothing to point, shows it always. -->
    <div
        class="group/row hover:bg-surface-overlay has-[a:focus-visible]:bg-surface-overlay relative flex gap-3.5 px-5 py-3 transition-colors duration-150"
    >
        <img
            v-if="poster"
            :src="poster"
            :alt="item.mediaTitle"
            width="48"
            height="72"
            loading="lazy"
            class="bg-surface-overlay h-18 w-12 shrink-0 rounded-md object-cover"
        />
        <span v-else class="bg-surface-overlay h-18 w-12 shrink-0 rounded-md" aria-hidden="true" />

        <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="flex items-center gap-2.5">
                <a
                    v-tooltip.truncated="item.subject"
                    :href="item.href"
                    class="text-ink min-w-0 flex-1 truncate text-base leading-[22px] font-semibold outline-none after:absolute after:inset-0"
                    @click="$emit('open', $event)"
                    @auxclick="$emit('open', $event)"
                    ><template v-if="!item.readAt"
                        ><span class="sr-only">{{ trans('notifications.unread_title', { title: item.subject }) }}</span
                        ><span aria-hidden="true">{{ item.subject }}</span></template
                    ><template v-else>{{ item.subject }}</template></a
                >
                <span
                    class="flex shrink-0 items-center gap-2.5"
                    :class="$slots.actions ? 'pointer-fine:group-focus-within/row:hidden pointer-fine:group-hover/row:hidden' : ''"
                >
                    <span class="text-ink-muted text-sm">{{ notificationAge(item.createdAt) }}</span>
                    <span v-if="!item.readAt" class="bg-brand-soft size-2 rounded-full" aria-hidden="true" />
                </span>
                <span
                    v-if="$slots.actions"
                    class="relative z-10 -my-1 hidden shrink-0 pointer-coarse:flex pointer-fine:group-focus-within/row:flex pointer-fine:group-hover/row:flex"
                >
                    <slot name="actions" />
                </span>
            </span>

            <span v-if="item.label !== ''" class="flex items-center gap-2">
                <component :is="look.icon" class="size-4 shrink-0" :class="look.iconClass" aria-hidden="true" />
                <span class="truncate text-[15px] leading-5 font-medium" :class="look.labelClass">{{ item.label }}</span>
            </span>

            <template v-if="item.change">
                <!-- Read out as one sentence; the struck value and the arrow are for the eye. -->
                <span v-if="item.change.from !== null" class="sr-only">{{
                    trans('notifications.changed_from_to', { from: item.change.from, to: item.change.to })
                }}</span>
                <span class="flex min-w-0 flex-wrap items-center gap-x-2 text-[15px] leading-5" :aria-hidden="item.change.from !== null">
                    <template v-if="item.change.from !== null">
                        <span class="text-ink-muted line-through">{{ item.change.from }}</span>
                        <ArrowRight class="text-ink-muted size-3.5 shrink-0" />
                    </template>
                    <span class="text-ink font-medium">{{ item.change.to }}</span>
                </span>
            </template>
            <MetaLine v-if="item.facts.length" :parts="item.facts" class="text-ink-muted text-[15px] leading-5" />
        </span>
    </div>
</template>
