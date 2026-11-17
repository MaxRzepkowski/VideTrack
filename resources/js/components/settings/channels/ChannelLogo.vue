<script setup lang="ts">
import { channelIcon, channelLogo } from '@/lib/channelLogos';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';

/**
 * A service's mark at the height its caller sets and as wide as it is: a
 * wordmark is not squeezed into a square. Decorative: the service's name
 * always stands beside it. A service with no mark yet gets a quiet bell.
 */
const props = defineProps<{ kind: string }>();

const icon = computed(() => channelIcon(props.kind));
const logo = computed(() => (icon.value === null ? channelLogo(props.kind) : null));
</script>

<template>
    <span class="inline-flex shrink-0 items-center justify-center" aria-hidden="true">
        <component :is="icon" v-if="icon" class="text-ink-secondary aspect-square h-full w-auto p-1" />
        <!-- A little room inside: a mark drawn to the edge of its file would look cut off beside one with a margin. -->
        <img
            v-else-if="logo"
            :src="logo.url"
            alt=""
            :width="logo.width"
            :height="logo.height"
            loading="lazy"
            class="h-full w-auto max-w-full object-contain p-1"
        />
        <Bell v-else class="text-ink-faint aspect-square h-full w-auto p-1" />
    </span>
</template>
