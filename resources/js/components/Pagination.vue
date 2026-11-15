<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    currentPage: number;
    totalPages: number;
}>();

const emit = defineEmits<{ goto: [page: number] }>();

/**
 * Compact page list: first and last always visible, a window around the
 * current page, `null` marks an ellipsis.
 */
const pages = computed<(number | null)[]>(() => {
    const total = props.totalPages;
    const current = props.currentPage;

    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const window = [current - 1, current, current + 1].filter((page) => page > 1 && page < total);
    const result: (number | null)[] = [1];

    if ((window[0] ?? total) > 2) {
        result.push(null);
    }

    result.push(...window);

    if ((window[window.length - 1] ?? 1) < total - 1) {
        result.push(null);
    }

    result.push(total);

    return result;
});

function goto(page: number): void {
    if (page >= 1 && page <= props.totalPages && page !== props.currentPage) {
        emit('goto', page);
    }
}
</script>

<template>
    <nav v-if="totalPages > 1" class="flex flex-wrap items-center justify-center gap-2" :aria-label="trans('app.pagination')">
        <button
            v-tooltip="trans('common.previous')"
            :aria-label="trans('common.previous')"
            type="button"
            class="border-line bg-surface text-ink-secondary hover:border-line-strong flex size-10 items-center justify-center rounded-lg border transition-colors duration-150 disabled:cursor-default disabled:opacity-40"
            :disabled="currentPage <= 1"
            @click="goto(currentPage - 1)"
        >
            <ChevronLeft class="size-4" />
        </button>

        <template v-for="(page, index) in pages" :key="`${index}-${page ?? 'gap'}`">
            <span v-if="page === null" class="text-ink-faint px-1 text-sm">…</span>
            <button
                v-else
                type="button"
                class="flex h-10 min-w-10 items-center justify-center rounded-lg border px-2 text-sm font-semibold transition-colors duration-150"
                :class="
                    page === currentPage
                        ? 'border-brand bg-brand text-on-accent'
                        : 'border-line bg-surface text-ink-secondary hover:border-line-strong'
                "
                :aria-current="page === currentPage ? 'page' : undefined"
                @click="goto(page)"
            >
                {{ page }}
            </button>
        </template>

        <button
            v-tooltip="trans('common.next')"
            :aria-label="trans('common.next')"
            type="button"
            class="border-line bg-surface text-ink-secondary hover:border-line-strong flex size-10 items-center justify-center rounded-lg border transition-colors duration-150 disabled:cursor-default disabled:opacity-40"
            :disabled="currentPage >= totalPages"
            @click="goto(currentPage + 1)"
        >
            <ChevronRight class="size-4" />
        </button>
    </nav>
</template>
