<script setup lang="ts">
import type { CollectionRelations } from '@/types/generated';
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

// Where a series sits among its provider's series: the one above, the ones
// under it, the spin-off series beside it, each opening its own collection.
const props = defineProps<{ provider: string; relations: CollectionRelations }>();

const rows = computed(() => {
    const href = (ref: string) => route('collections.show', { provider: props.provider, ref });

    return [
        { label: trans('lists.part_of_series'), links: props.relations.parent ? [props.relations.parent] : [] },
        { label: trans('lists.subseries'), links: props.relations.subseries },
        { label: trans('lists.spin_off_series'), links: props.relations.spinOffSeries },
    ]
        .filter((row) => row.links.length > 0)
        .map((row) => ({ label: row.label, links: row.links.map((link) => ({ name: link.name, href: href(link.ref) })) }));
});
</script>

<template>
    <div v-if="rows.length" class="m-0 mb-5 flex flex-col gap-1 text-base">
        <p v-for="row in rows" :key="row.label" class="text-ink-muted m-0">
            {{ row.label }}
            <template v-for="(link, i) in row.links" :key="link.href">
                <Link :href="link.href" class="text-brand-soft hover:text-ink font-semibold transition-colors duration-150">{{
                    link.name
                }}</Link><span v-if="i < row.links.length - 1">, </span>
            </template>
        </p>
    </div>
</template>
