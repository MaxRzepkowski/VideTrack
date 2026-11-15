<script setup lang="ts">
import { MEDIA_TYPE_META, mediaTypeMeta } from '@/lib/mediaTypes';
import { computed, type Component } from 'vue';

const props = defineProps<{
    type: string;
    compactOnMobile?: boolean;
    /** Something of the medium that is no title of it (an event): the type's colour, its own name and glyph. */
    label?: string;
    icon?: Component;
}>();

// Label / icon / colour come from the shared type vocabulary — see
// `lib/mediaTypes.ts`. An unknown type keeps showing its raw name here rather
// than borrowing the fallback entry's label.
const meta = computed(() => mediaTypeMeta(props.type));
const label = computed(() => props.label ?? MEDIA_TYPE_META[props.type]?.label() ?? props.type);
const icon = computed(() => props.icon ?? meta.value.icon);
const background = computed(() => meta.value.background);
const iconSize = computed(() => {
    const gamepad = props.type === 'game' && props.icon === undefined;

    if (!props.compactOnMobile) {
        return gamepad ? 'size-4' : 'size-3.25';
    }

    return gamepad ? 'size-3 xs:size-4' : 'size-2.75 xs:size-3.25';
});
</script>

<template>
    <span
        class="text-ink shadow-card flex items-center rounded-md font-extrabold"
        :class="[background, compactOnMobile ? 'xs:gap-1.5 xs:px-2.5 xs:py-1 gap-1 px-1.5 py-0.5 text-sm' : 'gap-1.5 px-2.5 py-1 text-sm']"
    >
        <!-- The gamepad glyph has more padding than tv/clapperboard — a
             slightly larger box keeps the badges optically equal. -->
        <component :is="icon" :class="iconSize" />
        {{ label }}
    </span>
</template>
