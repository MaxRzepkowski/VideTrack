<script setup lang="ts">
import { useNow } from '@/composables/useNow';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

// Four ticking units (days, hours, minutes, seconds) to an instant, for a
// countdown card: a premiere's or an event's.
const props = defineProps<{ target: number }>();

const now = useNow();
const remaining = computed<number>(() => Math.max(0, props.target - now.value));

const pad = (n: number): string => String(n).padStart(2, '0');

const units = computed(() => {
    const total = Math.floor(remaining.value / 1000);

    return [
        { key: 'd', display: String(Math.floor(total / 86400)), label: trans('calendar.days') },
        { key: 'h', display: pad(Math.floor((total % 86400) / 3600)), label: trans('calendar.hours') },
        { key: 'm', display: pad(Math.floor((total % 3600) / 60)), label: trans('calendar.mins') },
        { key: 's', display: pad(total % 60), label: trans('calendar.secs') },
    ];
});
</script>

<template>
    <div class="border-line-subtle grid min-h-20.5 grid-cols-4 border-t">
        <div
            v-for="(unit, i) in units"
            :key="unit.key"
            class="flex flex-col items-center gap-0.5 py-3.5"
            :class="i > 0 ? 'border-line-subtle border-l' : ''"
        >
            <div class="relative h-8 w-full overflow-hidden text-center">
                <Transition name="tick">
                    <span :key="unit.display" class="text-ink absolute inset-x-0 block text-3xl font-bold tabular-nums">
                        {{ unit.display }}
                    </span>
                </Transition>
            </div>
            <span class="text-ink-faint text-sm font-bold">{{ unit.label }}</span>
        </div>
    </div>
</template>

<style scoped>
.tick-enter-active,
.tick-leave-active {
    transition:
        transform 0.3s ease,
        opacity 0.3s ease;
}

.tick-enter-from {
    opacity: 0;
    transform: translateY(-70%);
}

.tick-leave-to {
    opacity: 0;
    transform: translateY(70%);
}
</style>
